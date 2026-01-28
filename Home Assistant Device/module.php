<?php

/** @noinspection AutoloadingIssuesInspection */

declare(strict_types=1);

require_once __DIR__ . '/../libs/HALightDefinitions.php';
require_once __DIR__ . '/../libs/HAIds.php';
require_once __DIR__ . '/../libs/HADebug.php';
require_once __DIR__ . '/../libs/HANumberDefinitions.php';
require_once __DIR__ . '/../libs/HASensorDefinitions.php';
require_once __DIR__ . '/../libs/HAVacuumDefinitions.php';
require_once __DIR__ . '/../libs/HALockDefinitions.php';

class HomeAssistantDevice extends IPSModuleStrict
{
    use HADebugTrait;

    private const KEY_STATE = 'state';
    private const KEY_ATTRIBUTES = 'attributes';
    private const KEY_SUPPORTED_FEATURES = 'supported_features';

    private array $topicMapping    = [];

    private array $entities        = [];

    private array $entityPositions = [];

    public function Create(): void
    {
        parent::Create();

        // Nachrichten registrieren, um auf Gateway-Änderungen zu reagieren
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);

        $this->RegisterAttributeString('MQTTBaseTopic', '');
        $this->RegisterAttributeString('CurrentFilter', '');
        $this->RegisterAttributeString('LastMQTTMessage', '');
        $this->RegisterAttributeString('LastRESTFetch', '');
        $this->RegisterAttributeString('EntityStateCache', '{}');

        // Properties
        $this->RegisterPropertyString('DeviceID', '');
        $this->RegisterPropertyString('DeviceArea', '');
        $this->RegisterPropertyString('DeviceName', '');
        $this->RegisterPropertyString('DeviceConfig', '[]');
        $this->RegisterPropertyBoolean('EnableExpertDebug', false);

    }


    /**
     * Reagiert auf Änderungen am Parent (Gateway)
     */
    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        // Wenn sich die Verbindung ändert (z. B. neuer MQTT Server), Konfiguration neu laden
        if ($Message === FM_CONNECT || $Message === FM_DISCONNECT) {
            $this->debugExpert('MessageSink', 'Verbindungsstatus geändert. Aktualisiere...');
            $this->ApplyChanges();
        }
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $instance = IPS_GetInstance($this->InstanceID);
        $parentID = $instance['ConnectionID'];
        if ($parentID <= 0) {
            $this->SetStatus(201);
            $this->debugExpert('ApplyChanges', 'Kein Parent verbunden');
            return;
        }
        if (!$this->hasCompatibleParent()) {
            $this->SetStatus(201);
            $this->debugExpert('ApplyChanges', 'Parent ist nicht Home Assistant Splitter');
            return;
        }

        // 1. MQTT Base Topic ermitteln (Attribut oder Auto-Discovery vom Parent)
        $baseTopic = $this->determineBaseTopic();
        $this->debugExpert('ApplyChanges', 'Konfiguration geladen', ['BaseTopic' => $baseTopic]);

        // 2. Konfiguration laden
        $configData = $this->decodeJsonArray($this->ReadPropertyString('DeviceConfig'), 'ApplyChanges');
        if ($configData === null) {
            return;
        }

        $stateMap = $this->fetchStateMap($configData);
        if ($stateMap !== []) {
            $configData = $this->mergeStateAttributes($configData, $stateMap);
        }

        if ($baseTopic === '') {
            $this->SetStatus(202);
            $this->debugExpert('ApplyChanges', 'MQTTBaseTopic ist leer. MQTT Statestream Updates kommen dann nicht an.');
        } else {
            $this->SetStatus(IS_ACTIVE);
        }
        $this->SetSummary($this->ReadPropertyString('DeviceID'));

        // 3. Entitäten verarbeiten und Topics sammeln
        $filterTopics = $this->processEntities($configData, $baseTopic);
        $this->updateDiagnosticsLabels();

        // 4. Empfangsfilter setzen
        $this->updateReceiveFilter($filterTopics);

        // 5. Einmalige REST-Initialisierung für aktuelle States
        if ($stateMap === []) {
            $this->initializeStatesFromHa($configData);
        } else {
            $this->applyInitialStatesFromMap($configData, $stateMap);
        }
    }

    /**
     * Verarbeitet eingehende MQTT-Nachrichten
     */
    public function ReceiveData(string $JSONString): string
    {
        $data = $this->decodeJsonArray($JSONString, 'ReceiveData');
        if ($data === null) {
            return '';
        }
        $topic   = $data['Topic'] ?? '';
        $payload = hex2bin($data['Payload']);
        if ($payload === false) {
            $payload = '';
        }

        $this->debugExpert('ReceiveData', 'Eingangsdaten', ['Topic' => $topic, 'Payload' => $payload]);
        $this->WriteAttributeString('LastMQTTMessage', date('Y-m-d H:i:s'));
        $this->updateDiagnosticsLabels();

        if ($this->tryHandleStateFromTopic($topic, $payload)) {
            return '';
        }

        if (isset($this->topicMapping[$topic])) {
            $entityId = $this->topicMapping[$topic];
            $this->updateEntityValue($entityId, $payload);
        }

        return '';
    }

    /**
     * Verarbeitet Schaltvorgänge aus dem Webfront
     */
    public function RequestAction(string $Ident, $Value): void
    {
        $this->debugExpert(__FUNCTION__, 'Input', ['Ident' => $Ident, 'Value' => $Value], true);

        $entity = $this->findEntityByIdent($Ident);
        if ($entity !== null && !empty($entity['entity_id'])) {
            $entityId = $entity['entity_id'];
            $domain   = $entity['domain'] ?? null;
            if ($domain === null && str_contains($entityId, '.')) {
                [$domain] = explode('.', $entityId, 2);
            }
            $this->debugExpert('RequestAction', 'Entity aufgelöst', ['EntityID' => $entityId, 'Domain' => $domain]);

            // Payload formatieren (ON/OFF vs. Wert)
            $mqttPayload = $this->formatPayloadForMqtt($domain ?? '', $Value);
            if ($mqttPayload === '') {
                $this->debugExpert('RequestAction', 'Payload leer', ['Domain' => $domain, 'Value' => $Value], true);
                return;
            }
            $this->debugExpert('RequestAction', 'Payload formatiert', ['Payload' => $mqttPayload]);

            $topic = $this->getSetTopicForEntity($entityId);
            if ($topic === '') {
                return;
            }
            $this->debugExpert(__FUNCTION__, 'MQTT publish | Topic=' . $topic . ' | Payload=' . $mqttPayload, [], true);

            $this->sendMqttMessage($topic, $mqttPayload);
            return;
        }

        $attributeInfo = $this->resolveAttributeByIdent($Ident);
        if ($attributeInfo === null) {
            $this->debugExpert(__FUNCTION__, 'Entity/Attribut nicht gefunden', ['Ident' => $Ident], true);
            return;
        }

        $entityId  = $attributeInfo['entity_id'];
        $attribute = $attributeInfo['attribute'];

        $payload = $this->buildLightAttributePayload($attribute, $Value);
        if ($payload === '') {
            $this->debugExpert(__FUNCTION__, 'Attribut Payload leer', ['Attribute' => $attribute], true);
            return;
        }

        $topic = $this->getSetTopicForEntity($entityId);
        if ($topic === '') {
            $this->debugExpert('Action', 'Kein Set-Topic für Entity | EntityID=' . $entityId, [], true);
            return;
        }
        $this->debugExpert(__FUNCTION__, 'MQTT publish | Topic=' . $topic . ' | Payload=' . $payload, [], true);
        $this->sendMqttMessage($topic, $payload);
    }

    public function GetConfigurationForm(): string
    {
        $form   = json_decode(file_get_contents(__DIR__ . '/form.json'), true, 512, JSON_THROW_ON_ERROR);
        $config = $this->decodeJsonArray($this->ReadPropertyString('DeviceConfig'), __FUNCTION__);
        $values = [];

        if (is_array($config)) {
            foreach ($config as $row) {
                // Defaults setzen
                if (!isset($row['create_var'])) {
                    $row['create_var'] = true;
                }

                // Attribute als JSON-String für die Tabelle formatieren
                if (isset($row['attributes']) && is_array($row['attributes'])) {
                    $row['attributes'] = json_encode($row['attributes'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                } else {
                    $row['attributes'] = '{}';
                }
                $values[] = $row;
            }
        }
        $form['elements'][0]['values'] = $values;
        foreach ($form['actions'] as &$action) {
            if (($action['name'] ?? '') === 'CURRENT_FILTER') {
                $action['caption'] = 'Aktueller Filter (Regex): ' . $this->ReadAttributeString('CurrentFilter');
            }
        }
        unset($action);
        return json_encode($form, JSON_THROW_ON_ERROR);
    }

    // --- Private Hilfsmethoden (Business Logic) ---

    /**
     * Ermittelt das BaseTopic.
     * Priorität: 1. Automatisch vom Parent (Subscription), 2. Gespeichertes Attribut
     */
    private function determineBaseTopic(): string
    {
        $baseTopic = $this->ReadAttributeString('MQTTBaseTopic');
        $instance  = IPS_GetInstance($this->InstanceID);
        $parentID  = $instance['ConnectionID'];

        // Normalfall: Subscriptions am IO (Parent des Splitters) auslesen
        if ($parentID > 0) {
            $baseTopic = $this->readBaseTopicFromParentProperty($parentID, $baseTopic);

            $parent = IPS_GetInstance($parentID);
            $ioID   = (int)($parent['ConnectionID'] ?? 0);
            if ($ioID > 0) {
                $baseTopic = $this->readBaseTopicFromSubscriptions($ioID, $baseTopic);
            }
        }

        return $baseTopic;
    }

    private function readBaseTopicFromParentProperty(int $parentId, string $currentBase): string
    {
        $parentBase = (string)@IPS_GetProperty($parentId, 'MQTTBaseTopic');
        $parentBase = trim($parentBase);
        if ($parentBase !== '' && $parentBase !== $currentBase) {
            $currentBase = $parentBase;
            $this->WriteAttributeString('MQTTBaseTopic', $currentBase);
            $this->debugExpert('Config', 'Base Topic vom Parent übernommen: ' . $currentBase);
        }
        return $currentBase;
    }

    private function readBaseTopicFromSubscriptions(int $instanceId, string $currentBase): string
    {
        $subscriptions = (string)@IPS_GetProperty($instanceId, 'Subscriptions');
        if ($subscriptions === '') {
            return $currentBase;
        }

        $json = json_decode($subscriptions, true, 512, JSON_THROW_ON_ERROR);
        // Wir nehmen an, das erste Subscription-Topic ist das BaseTopic (z.B. "homeassistant/#")
        if (is_array($json) && count($json) > 0 && isset($json[0]['Topic'])) {
            $newTopic = rtrim($json[0]['Topic'], '/#');
            if ($newTopic !== '' && $newTopic !== $currentBase) {
                $currentBase = $newTopic;
                $this->WriteAttributeString('MQTTBaseTopic', $currentBase);
                $this->debugExpert('Config', 'Base Topic automatisch aktualisiert: ' . $currentBase);
            }
        }
        return $currentBase;
    }

    /**
     * Iteriert über die Konfiguration, legt Variablen an und baut die Topic-Map auf.
     *
     * @return array Liste der Topics für den Filter
     * @throws \JsonException
     * @throws \JsonException
     */
    private function processEntities(array $configData, string $baseTopic): array
    {
        $this->entities        = [];
        $this->topicMapping    = [];
        $this->entityPositions = [];
        $filterTopics          = [];
        $positionIndex         = 0;

        foreach ($configData as $row) {
            $entity = $this->normalizeEntity($row, 'processEntities');
            if ($entity === null || !($entity['create_var'] ?? true)) {
                continue;
            }
            if (($entity['domain'] ?? '') === '') {
                $this->debugExpert('processEntities', 'Entity ohne Domain', $entity);
                continue;
            }

            $positionIndex++;
            $basePosition                                = $positionIndex * 10;
            $entity['position_base']                     = $basePosition;
            $this->entityPositions[$entity['entity_id']] = $basePosition;
            $this->entities[$entity['entity_id']]        = $entity;
            $this->debugExpert('processEntities', 'Entity registriert', ['EntityID' => $entity['entity_id'], 'Domain' => $entity['domain'] ?? null]);

            $this->maintainEntityVariable($entity);

            if ($baseTopic !== '') {
                $stateTopic                      = $this->deriveStateTopic($baseTopic, $entity['entity_id']);
                $this->topicMapping[$stateTopic] = $entity['entity_id'];
                $entityPrefix                    = $this->deriveEntityTopicPrefix($baseTopic, $entity['entity_id']);
                $filterTopics[]                  = $entityPrefix;
                $this->debugExpert('processEntities', 'Topic Mapping', ['StateTopic' => $stateTopic, 'Prefix' => $entityPrefix]);
            }
        }
        return $filterTopics;
    }

    /**
     * Erstellt den JSON-Filter für den Datenaustausch
     */
    private function updateReceiveFilter(array $topics): void
    {
        if (count($topics) === 0) {
            $filter = '.*ThisShouldNotMatchAnything.*';
            $this->SetReceiveDataFilter($filter);
            $this->WriteAttributeString('CurrentFilter', $filter);
            $this->updateFormFieldSafe('CURRENT_FILTER', 'caption', 'Aktueller Filter (Regex): ' . $filter);
            return;
        }

        $regexParts = [];
        foreach ($topics as $t) {
            // Escaping für Regex
            $quoted = preg_quote($t, '/');
            // Spezialbehandlung: Slash im JSON kann / oder \/ sein
            $quoted       = str_replace('\/', '\\\\?\/', $quoted);
            $regexParts[] = $quoted . '(\\\\?\/[^"]*)?';
        }

        $filter = '.*"Topic":"(' . implode('|', $regexParts) . ')".*';
        $this->debugExpert('Filter', 'Setze Filter', ['Regex' => $filter]);
        $this->SetReceiveDataFilter($filter);
        $this->WriteAttributeString('CurrentFilter', $filter);
        $this->updateFormFieldSafe('CURRENT_FILTER', 'caption', 'Aktueller Filter (Regex): ' . $filter);
    }

    /**
     * Erstellt oder aktualisiert die Symcon Variable für eine Entität
     */
    private function maintainEntityVariable(array $entity): void
    {
        $ident  = $this->sanitizeIdent($entity['entity_id']);
        $domain = $entity['domain'];
        $name   = $this->getEntityVariableName($domain, $entity);

        $type         = $this->getVariableType($domain, $entity['attributes'] ?? []);
        $position     = $this->getEntityPosition($entity['entity_id']);
        $presentation = $this->getEntityPresentation($domain, $entity, $type);

        $this->MaintainVariable($ident, $name, $type, $presentation, $position, true);

        // Aktionsskript aktivieren, wenn schreibbar
        if ($this->isWriteable($domain)) {
            $this->EnableAction($ident);
        }

        if ($domain === 'light') {
            $this->maintainLightAttributeVariables($entity);
        }
    }

    /**
     * Aktualisiert den Wert einer Variable basierend auf dem MQTT Payload
     */
    private function updateEntityValue(string $entityId, string $payload): void
    {
        $ident  = $this->sanitizeIdent($entityId);
        $domain = $this->getEntityDomain($entityId);
        if ($domain === '') {
            $this->debugExpert('updateEntityValue', 'Domain nicht ermittelbar', ['EntityID' => $entityId]);
            return;
        }
        $parsed     = $this->parseEntityPayload($payload);
        $finalValue = $this->convertValueByDomain($domain, $parsed[self::KEY_STATE]);

        $this->SetValue($ident, $finalValue);
        $this->updateEntityCache($entityId, $parsed[self::KEY_STATE], $parsed['attributes'] ?? null);

        if (!empty($parsed[self::KEY_ATTRIBUTES])) {
            $this->storeEntityAttributes($entityId, $parsed[self::KEY_ATTRIBUTES]);
            $this->updateEntityPresentation($entityId, $this->entities[$entityId][self::KEY_ATTRIBUTES] ?? []);
            if ($domain === 'light') {
                $this->updateLightAttributeValues($entityId, $parsed['attributes']);
            }
        }
    }

    private function tryHandleStateFromTopic(string $topic, string $payload): bool
    {
        if ($topic === '') {
            $this->debugExpert('StateTopic', 'Leeres Topic, ignoriert.');
            return false;
        }

        $parts = explode('/', trim($topic, '/'));
        if (count($parts) < 3) {
            $this->debugExpert('StateTopic', 'Topic zu kurz', ['Topic' => $topic]);
            return false;
        }

        $suffix = $parts[count($parts) - 1];
        if ($suffix !== 'state' && $suffix !== 'set') {
            return $this->tryHandleAttributeFromTopic($topic, $payload);
        }

        $entity = $parts[count($parts) - 2];
        $domain = $parts[count($parts) - 3];

        $ident = $this->sanitizeIdent($domain . '_' . $entity);
        $varId = @$this->GetIDForIdent($ident);
        if ($varId === false) {
            $this->debugExpert('StateTopic', 'Variable nicht gefunden', ['Ident' => $ident]);
            return false;
        }

        $parsed = $this->parseEntityPayload($payload);

        $value = $this->convertValueByDomain($domain, $parsed[self::KEY_STATE]);

        $this->debugExpert(
            'StateTopic',
            'SetValue',
            ['Ident' => $ident, 'Domain' => $domain, 'Entity' => $entity, 'Value' => $value]
        );
        $this->SetValue($ident, $value);
        $this->updateEntityCache($domain . '.' . $entity, $parsed[self::KEY_STATE], $parsed[self::KEY_ATTRIBUTES] ?? null);

        if ($domain === 'light' && !empty($parsed[self::KEY_ATTRIBUTES])) {
            $this->updateLightAttributeValues($domain . '.' . $entity, $parsed[self::KEY_ATTRIBUTES]);
        }
        if (!empty($parsed[self::KEY_ATTRIBUTES])) {
            $this->updateEntityPresentation($domain . '.' . $entity, $this->entities[$domain . '.' . $entity][self::KEY_ATTRIBUTES] ?? []);
        }
        return true;
    }

    private function tryHandleAttributeFromTopic(string $topic, string $payload): bool
    {
        // Attribute topics come as .../<domain>/<entity>/<attribute>
        $parts = explode('/', trim($topic, '/'));
        if (count($parts) < 3) {
            $this->debugExpert('AttributeTopic', 'Topic zu kurz', ['Topic' => $topic]);
            return false;
        }

        $attribute = $parts[count($parts) - 1];
        $entity    = $parts[count($parts) - 2];
        $domain    = $parts[count($parts) - 3];
        $entityId  = $domain . '.' . $entity;

        $currentDomain = $this->entities[$entityId]['domain'] ?? $domain;
        if ($currentDomain !== 'light' && $currentDomain !== 'select') {
            $this->debugExpert('AttributeTopic', 'Domain nicht unterstützt', ['EntityID' => $entityId, 'Domain' => $domain]);
            return false;
        }
        if (!isset($this->entities[$entityId])) {
            // Ensure the entity exists even if it wasn't part of the initial config list.
            $this->entities[$entityId] = [
                'entity_id' => $entityId,
                'domain'    => $domain,
                'name'      => $entity
            ];
            $this->debugExpert('AttributeTopic', 'Entity aus Topic angelegt', ['EntityID' => $entityId]);
        }

        if ($currentDomain === 'select') {
            $value = $this->parseAttributePayload($payload);
            if ($value !== null) {
                $this->storeEntityAttribute($entityId, $attribute, $value);
                $this->updateEntityCache($entityId, null, [$attribute => $value]);
                $this->updateEntityPresentation($entityId, $this->entities[$entityId]['attributes'] ?? []);
            }
            return true;
        }

        if (!array_key_exists($attribute, HALightDefinitions::ATTRIBUTE_DEFINITIONS)) {
            $value = $this->parseAttributePayload($payload);
            if ($value !== null) {
                $this->storeEntityAttribute($entityId, $attribute, $value);
                $this->updateEntityCache($entityId, null, [$attribute => $value]);
                $this->updateEntityPresentation($entityId, $this->entities[$entityId]['attributes'] ?? []);
            }
            return true;
        }

        if (!$this->ensureLightAttributeVariable($entityId, $attribute)) {
            $this->debugExpert('AttributeTopic', 'Keine Variable für Attribut', ['EntityID' => $entityId, 'Attribute' => $attribute]);
            return false;
        }

        $value = $this->parseAttributePayload($payload);
        if ($value === null) {
            $this->debugExpert('AttributeTopic', 'Payload null', ['EntityID' => $entityId, 'Attribute' => $attribute]);
            return true;
        }

        $meta = HALightDefinitions::ATTRIBUTE_DEFINITIONS[$attribute] ?? null;
        if ($meta === null) {
            $this->debugExpert('AttributeTopic', 'Attribut nicht definiert', ['Attribute' => $attribute]);
            return false;
        }

        if (is_array($value)) {
            $value = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $value = $this->castVariableValue($value, $meta['type']);
        $ident = $this->sanitizeIdent($entityId . '_' . $attribute);
        $this->SetValue($ident, $value);
        $this->debugExpert('AttributeTopic', 'SetValue', ['Ident' => $ident, 'Value' => $value]);
        $this->updateEntityCache($entityId, null, [$attribute => $value]);

        if ($attribute === 'supported_features') {
            $this->updateLightSupportedFeaturesText($entityId, (int)$value);
        }
        return true;
    }

    private function parseAttributePayload(string $payload): mixed
    {
        $trimmed = trim($payload);
        if ($trimmed === '') {
            return '';
        }

        try {
            $json = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->debugExpert('AttributeTopic', 'Invalid JSON payload', ['Error' => $e->getMessage()]);
            return $payload;
        }
        if ($json !== null || $trimmed === 'null') {
            return $json;
        }

        return $payload;
    }

    private function parseEntityPayload(string $payload): array
    {
        $result = [
            self::KEY_STATE      => $payload,
            self::KEY_ATTRIBUTES => []
        ];

        // HA state payloads can be raw values or JSON objects with "state"/"attributes".
        $trimmed = trim($payload);
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            try {
                $json = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                $this->debugExpert('ReceiveData', 'Invalid JSON payload', ['Error' => $e->getMessage()]);
                return $result;
            }
            if (is_array($json)) {
                if (array_key_exists(self::KEY_STATE, $json)) {
                    $result[self::KEY_STATE] = (string)$json[self::KEY_STATE];
                }
                if (isset($json[self::KEY_ATTRIBUTES]) && is_array($json[self::KEY_ATTRIBUTES])) {
                    $result[self::KEY_ATTRIBUTES] = $json[self::KEY_ATTRIBUTES];
                }
            } elseif ($json !== null) {
                $result[self::KEY_STATE] = (string)$json;
            }
        }

        return $result;
    }

    private function convertValueByDomain(string $domain, string $valueData): string|bool|float
    {
        $normalized = strtoupper(trim($valueData));
        return match ($domain) {
            'light', 'switch', 'binary_sensor' => $normalized === 'ON',
            'sensor', 'climate', 'number' => (float)$valueData,
            default => $valueData,
        };
    }

    // --- Technische Helper ---

    private function deriveStateTopic(string $base, string $entityId): string
    {
        [$domain, $name] = explode('.', $entityId, 2);
        if ($domain === 'event') {
            return "$base/$domain/$name/event_type";
        }
        return "$base/$domain/$name/state";
    }

    private function deriveEntityTopicPrefix(string $base, string $entityId): string
    {
        [$domain, $name] = explode('.', $entityId, 2);
        return "$base/$domain/$name";
    }

    private function sendMqttMessage(string $topic, string $payload): void
    {
        if (!$this->hasActiveParent()) {
            $this->debugExpert('MQTT', 'No active parent');
            return;
        }
        $this->debugExpert('MQTT', 'SendDataToParent', ['Topic' => $topic, 'Payload' => $payload]);

        $json = json_encode([
                                'DataID'           => HAIds::DATA_DEVICE_TO_SPLITTER,
                                'PacketType'       => 3,
                                'QualityOfService' => 0,
                                'Retain'           => false,
                                'Topic'            => $topic,
                                'Payload'          => bin2hex($payload)
                            ],
                            JSON_THROW_ON_ERROR);

        $this->SendDataToParent($json);
    }

    private function decodeJsonArray(string $json, string $context): ?array
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->debugExpert($context, 'Invalid JSON', ['Error' => $e->getMessage()]);
            return null;
        }

        return is_array($data) ? $data : null;
    }

    private function normalizeEntity(array $row, string $context): ?array
    {
        if (empty($row['entity_id'])) {
            $this->debugExpert($context, 'Entity ohne entity_id', $row);
            return null;
        }

        if (!isset($row['domain']) && str_contains($row['entity_id'], '.')) {
            [$row['domain']] = explode('.', $row['entity_id'], 2);
        }

        if (isset($row['attributes']) && is_string($row['attributes'])) {
            $decoded = $this->decodeJsonArray($row['attributes'], $context);
            if ($decoded !== null) {
                $row['attributes'] = $decoded;
            }
        }

        $entityId = (string)$row['entity_id'];
        if (str_ends_with($entityId, '_ein_level') || str_ends_with($entityId, '.ein_level')) {
            if (!isset($row['attributes']) || !is_array($row['attributes'])) {
                $row['attributes'] = [];
            }
            if (!isset($row['attributes']['unit_of_measurement']) || $row['attributes']['unit_of_measurement'] === '') {
                $row['attributes']['unit_of_measurement'] = '%';
            }
        }

        return $row;
    }

    private function getEntityDomain(string $entityId): string
    {
        $domain = $this->entities[$entityId]['domain'] ?? null;
        if ($domain === null && str_contains($entityId, '.')) {
            [$domain] = explode('.', $entityId, 2);
        }
        return $domain ?? '';
    }

    private function sanitizeIdent(string $id): string
    {
        return str_replace(['.', ' ', '-'], '_', $id);
    }

    private function getEntityIdByIdent(string $ident): ?string
    {
        return array_find_key($this->entities, fn($_data, $id) => $this->sanitizeIdent($id) === $ident);
    }

    private function findEntityByIdent(string $ident): ?array
    {
        $entityId = $this->getEntityIdByIdent($ident);
        if ($entityId !== null && isset($this->entities[$entityId])) {
            $entity              = $this->entities[$entityId];
            $entity['entity_id'] ??= $entityId;
            return $entity;
        }

        $configData = $this->decodeJsonArray($this->ReadPropertyString('DeviceConfig'), 'findEntityByIdent');
        if ($configData === null) {
            return null;
        }
        foreach ($configData as $row) {
            $row = $this->normalizeEntity($row, 'findEntityByIdent');
            if ($row === null) {
                continue;
            }
            if (($row['create_var'] ?? true) === false) {
                continue;
            }
            if ($this->sanitizeIdent($row['entity_id']) !== $ident) {
                continue;
            }
            return $row;
        }
        return null;
    }

    private function isWriteable(string $domain): bool
    {
        return in_array($domain, ['light', 'switch', 'climate', 'number', 'lock', 'cover', 'select']);
    }

    private function formatPayloadForMqtt(string $domain, mixed $value): string
    {
        return match ($domain) {
            'light', 'switch' => $value ? 'ON' : 'OFF',
            'lock' => $this->formatLockPayload($value),
            default => (string)$value,
        };
    }

    private function formatLockPayload(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'lock' : 'unlock';
        }
        $text = strtolower(trim((string)$value));
        return match ($text) {
            'locked', 'lock', 'lock_on' => 'lock',
            'unlocked', 'unlock', 'unlock_off' => 'unlock',
            'open', 'open_latch', 'unlatch' => 'open',
            default => ''
        };
    }

    private function getVariableType(string $domain, array $attributes = []): int
    {
        if ($domain === 'sensor') {
            $deviceClass = $attributes['device_class'] ?? '';
            if (is_string($deviceClass) && trim($deviceClass) === HASensorDefinitions::DEVICE_CLASS_ENUM) {
                return VARIABLETYPE_STRING;
            }
        }
        return match ($domain) {
            'light', 'switch', 'binary_sensor' => VARIABLETYPE_BOOLEAN,
            'sensor', 'climate', 'number' => VARIABLETYPE_FLOAT,
            default => VARIABLETYPE_STRING,
        };
    }

     private function getEntityPresentation(string $domain, array $entity, int $type): array
    {
        $attributes = $entity['attributes'] ?? [];
        if (!is_array($attributes)) {
            $attributes = [];
        }

        if ($domain === 'binary_sensor') {
            return $this->getBinarySensorPresentation($attributes);
        }

        if ($domain === 'number') {
            return $this->getNumberPresentation($attributes);
        }

        if ($domain === 'sensor') {
            $deviceClass = $attributes['device_class'] ?? '';
            if (is_string($deviceClass) && trim($deviceClass) === HASensorDefinitions::DEVICE_CLASS_ENUM) {
                $options = $this->getPresentationOptions($attributes['options'] ?? null);
                if ($options !== null) {
                    return $this->filterPresentation([
                        'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                        'OPTIONS'      => $options
                    ]);
                }
            }
        }

        if ($domain === 'lock') {
            return $this->getLockPresentation($attributes);
        }

        if ($domain === 'vacuum') {
            return $this->getVacuumPresentation();
        }

        if ($type === VARIABLETYPE_BOOLEAN) {
            return [
                'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH
            ];
        }

        $suffix = $this->getPresentationSuffix($attributes);
        if ($domain === 'select') {
            $options = $this->getPresentationOptions($attributes['options'] ?? null);
            if ($options !== null) {
                return $this->filterPresentation([
                    'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                    'OPTIONS'      => $options
                ]);
            }
        }

        if ($type === VARIABLETYPE_INTEGER || $type === VARIABLETYPE_FLOAT) {
            $slider = $this->getNumericSliderPresentation($attributes);
            if ($slider !== null) {
                return $slider;
            }
        }

        return $this->filterPresentation([
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX'       => $this->formatPresentationSuffix($suffix)
        ]);
    }

    private function getVacuumPresentation(): array
    {
        $options = [];
        foreach (HAVacuumDefinitions::STATE_OPTIONS as $value => $meta) {
            $caption = (string)($meta['caption'] ?? $value);
            $icon = (string)($meta['icon'] ?? '');
            $options[] = [
                'Value' => $value,
                'Caption' => $caption,
                'IconActive' => $icon !== '',
                'IconValue' => $icon,
                'ColorActive' => false,
                'ColorValue' => -1
            ];
        }

        return $this->filterPresentation([
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'OPTIONS'      => json_encode($options, JSON_THROW_ON_ERROR)
        ]);
    }

    private function getLockPresentation(array $attributes): array
    {
        $supported = (int)($attributes['supported_features'] ?? 0);
        $allowOpen = ($supported & HALockDefinitions::FEATURE_OPEN) === HALockDefinitions::FEATURE_OPEN;

        $options = [];
        foreach (HALockDefinitions::STATE_OPTIONS as $value => $meta) {
            if ($value === 'open' && !$allowOpen) {
                continue;
            }
            $options[] = [
                'Value' => $value,
                'Caption' => (string)($meta['caption'] ?? $value),
                'IconActive' => false,
                'IconValue' => '',
                'ColorActive' => false,
                'ColorValue' => -1
            ];
        }

        return $this->filterPresentation([
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'OPTIONS'      => json_encode($options, JSON_THROW_ON_ERROR)
        ]);
    }

    private function getBinarySensorPresentation(array $attributes): array
    {
        $deviceClass = $attributes['device_class'] ?? '';
        if (!is_string($deviceClass)) {
            $deviceClass = '';
        }
        $deviceClass = trim($deviceClass);

        $map = [
            'battery' => ['Batterie niedrig', 'Batterie ok', 'battery-exclamation'],
            'battery_charging' => ['lädt', 'lädt nicht', 'battery-bolt'],
            'cold' => ['kalt', 'normal', 'snowflake'],
            'connectivity' => ['verbunden', 'getrennt', 'wifi'],
            'door' => ['offen', 'geschlossen', 'door-open'],
            'garage_door' => ['offen', 'geschlossen', 'garage-open'],
            'gas' => ['Gas erkannt', 'kein Gas', 'cloud-bolt'],
            'heat' => ['heiss', 'normal', 'fire'],
            'light' => ['Licht erkannt', 'kein Licht', 'lightbulb-on'],
            'lock' => ['entsperrt', 'gesperrt', 'lock-open'],
            'moisture' => ['nass', 'trocken', 'droplet'],
            'motion' => ['Bewegung', 'keine Bewegung', 'person-running'],
            'moving' => ['in Bewegung', 'still', 'person-running'],
            'occupancy' => ['belegt', 'frei', 'house-person-return'],
            'opening' => ['offen', 'geschlossen', 'up-right-from-square'],
            'plug' => ['eingesteckt', 'ausgesteckt', 'plug'],
            'power' => ['Strom erkannt', 'kein Strom', 'bolt'],
            'presence' => ['anwesend', 'abwesend', 'user'],
            'problem' => ['Problem', 'kein Problem', 'triangle-exclamation'],
            'running' => ['läuft', 'gestoppt', 'play'],
            'safety' => ['gefährlich', 'sicher', 'shield-exclamation'],
            'smoke' => ['Rauch', 'kein Rauch', 'fire-smoke'],
            'sound' => ['Geräusch', 'kein Geräusch', 'volume-high'],
            'tamper' => ['Manipulation', 'keine Manipulation', 'hand'],
            'update' => ['Update verfügbar', 'aktuell', 'arrows-rotate'],
            'vibration' => ['Vibration', 'keine Vibration', 'chart-fft'],
            'window' => ['offen', 'geschlossen', 'window-frame-open']
        ];

        $trueCaption = 'an';
        $falseCaption = 'aus';
        $icon = '';
        if ($deviceClass !== '' && isset($map[$deviceClass])) {
            [$trueCaption, $falseCaption, $icon] = $map[$deviceClass];
        }

        $options = [
            [
                'Value' => false,
                'Caption' => $falseCaption,
                'IconActive' => false,
                'IconValue' => '',
                'ColorActive' => false,
                'ColorValue' => -1
            ],
            [
                'Value' => true,
                'Caption' => $trueCaption,
                'IconActive' => false,
                'IconValue' => '',
                'ColorActive' => false,
                'ColorValue' => -1
            ]
        ];

        return $this->filterPresentation([
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'OPTIONS' => json_encode($options, JSON_THROW_ON_ERROR),
            'ICON' => $icon !== '' ? $icon : null
        ]);
    }

    private function getLightAttributePresentation(string $attribute, array $attributes, array $meta): array
    {
        $suffix = $meta['suffix'] ?? '';
        if (!is_string($suffix)) {
            $suffix = '';
        }
        $suffix = trim($suffix);
        $isPercent = $suffix === '%';
        $presentationSuffix = $this->formatPresentationSuffix($suffix);

        if ($attribute === 'brightness') {
            return $this->filterPresentation([
                'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                'MIN'          => 0,
                'MAX'          => 255,
                'STEP_SIZE'    => 1,
                'PERCENTAGE'   => $isPercent,
                'DIGITS'       => 0,
                'SUFFIX'       => $presentationSuffix
            ]);
        }
        if ($attribute === 'color_temp') {
            $min = $attributes['min_mireds'] ?? null;
            $max = $attributes['max_mireds'] ?? null;
            if (is_numeric($min) && is_numeric($max)) {
                return $this->filterPresentation([
                    'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                    'MIN'          => (float)$min,
                    'MAX'          => (float)$max,
                    'STEP_SIZE'    => 1,
                    'PERCENTAGE'   => $isPercent,
                    'DIGITS'       => 0,
                    'SUFFIX'       => $presentationSuffix
                ]);
            }
        }
        if ($attribute === 'color_temp_kelvin') {
            $min = $attributes['min_color_temp_kelvin'] ?? null;
            $max = $attributes['max_color_temp_kelvin'] ?? null;
            if (is_numeric($min) && is_numeric($max)) {
                return $this->filterPresentation([
                    'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                    'MIN'          => (float)$min,
                    'MAX'          => (float)$max,
                    'STEP_SIZE'    => 1,
                    'PERCENTAGE'   => $isPercent,
                    'DIGITS'       => 0,
                    'SUFFIX'       => $presentationSuffix
                ]);
            }
        }

        return $this->filterPresentation([
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX'       => $presentationSuffix
        ]);
    }

    private function getNumericSliderPresentation(array $attributes): ?array
    {
        $min = $attributes['min'] ?? $attributes['native_min_value'] ?? null;
        $max = $attributes['max'] ?? $attributes['native_max_value'] ?? null;
        if (!is_numeric($min) || !is_numeric($max)) {
            return null;
        }

        $step   = $attributes['step'] ?? $attributes['native_step'] ?? 1;
        $digits = (str_contains((string)$step, '.')) ? 2 : 0;
        $suffix = $this->getPresentationSuffix($attributes);
        $presentationSuffix = $this->formatPresentationSuffix($suffix);

        return $this->filterPresentation([
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'MIN'          => (float)$min,
            'MAX'          => (float)$max,
            'STEP_SIZE'    => (float)$step,
            'PERCENTAGE'   => $suffix === '%',
            'DIGITS'       => $digits,
            'SUFFIX'       => $presentationSuffix
        ]);
    }

    private function getPresentationSuffix(array $attributes): string
    {
        $rawUnit = $attributes['unit_of_measurement'] ?? '';
        if (!is_string($rawUnit)) {
            $rawUnit = '';
        }

        $unit = trim($rawUnit);
        if ($unit === '') {
            $fallback = '';
            $altUnit = $attributes['unit'] ?? '';
            if (is_string($altUnit)) {
                $fallback = trim($altUnit);
            }
            if ($fallback === '') {
                $altUnit = $attributes['display_unit'] ?? '';
                if (is_string($altUnit)) {
                    $fallback = trim($altUnit);
                }
            }
            if ($fallback === '') {
                $altUnit = $attributes['native_unit_of_measurement'] ?? '';
                if (is_string($altUnit)) {
                    $fallback = trim($altUnit);
                }
            }
            $unit = $fallback;
        }

        if ($unit === '') {
            $deviceClass = $attributes['device_class'] ?? '';
            if (is_string($deviceClass)) {
                $deviceClass = trim($deviceClass);
            } else {
                $deviceClass = '';
            }
            if ($deviceClass !== '' && isset(HANumberDefinitions::DEVICE_CLASS_SUFFIX[$deviceClass])) {
                $unit = HANumberDefinitions::DEVICE_CLASS_SUFFIX[$deviceClass];
            } elseif ($deviceClass !== '' && isset(HASensorDefinitions::DEVICE_CLASS_SUFFIX[$deviceClass])) {
                $unit = HASensorDefinitions::DEVICE_CLASS_SUFFIX[$deviceClass];
            }
        }

        $suffix = '';
        $suffixSource = '';
        if ($unit !== '') {
            $suffix = $unit;
            if ($rawUnit !== '') {
                $suffixSource = 'unit_of_measurement';
            } elseif (isset($attributes['unit']) && is_string($attributes['unit']) && trim($attributes['unit']) !== '') {
                $suffixSource = 'unit';
            } elseif (isset($attributes['display_unit']) && is_string($attributes['display_unit']) && trim($attributes['display_unit']) !== '') {
                $suffixSource = 'display_unit';
            } elseif (isset($attributes['native_unit_of_measurement']) && is_string($attributes['native_unit_of_measurement']) && trim($attributes['native_unit_of_measurement']) !== '') {
                $suffixSource = 'native_unit_of_measurement';
            } elseif (isset($attributes['device_class'])) {
                $suffixSource = 'device_class';
            }
        }

        $this->debugExpert('Presentation', 'Suffix berechnet', [
            'unit_of_measurement' => $rawUnit,
            'unit' => $attributes['unit'] ?? null,
            'display_unit' => $attributes['display_unit'] ?? null,
            'suffix' => $suffix,
            'suffix_source' => $suffixSource
        ]);

        return $suffix;
    }

    private function formatPresentationSuffix(string $suffix): ?string
    {
        $suffix = trim($suffix);
        if ($suffix === '') {
            return null;
        }
        return ' ' . $suffix;
    }

    private function getNumberPresentation(array $attributes): array
    {
        $slider = $this->getNumericSliderPresentation($attributes);
        if ($slider !== null) {
            return $slider;
        }

        $suffix = $this->getPresentationSuffix($attributes);
        return $this->filterPresentation([
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX'       => $this->formatPresentationSuffix($suffix)
        ]);
    }

    private function filterPresentation(array $presentation): array
    {
        return array_filter(
            $presentation,
            static fn($value) => $value !== null
        );
    }

    private function getPresentationOptions(mixed $options): ?string
    {
        if (!is_array($options) || count($options) === 0) {
            return null;
        }

        $formatted = [];
        foreach ($options as $value) {
            $formatted[] = [
                'Value'       => $value,
                'Caption'     => (string)$value,
                'IconActive'  => false,
                'IconValue'   => '',
                'ColorActive' => false,
                'ColorValue'  => -1
            ];
        }

        return json_encode($formatted, JSON_THROW_ON_ERROR);
    }

    private function getEntityVariableName(string $domain, array $entity): string
    {
        $type = $this->getVariableType($domain, $entity['attributes'] ?? []);
        if ($type === VARIABLETYPE_BOOLEAN) {
            return 'Status';
        }
        return $entity['name'] ?? $entity['entity_id'];
    }

    private function maintainLightAttributeVariables(array $entity): void
    {
        $attributes = $entity['attributes'] ?? [];
        if (!is_array($attributes)) {
            $this->debugExpert('LightVars', 'Attributes kein Array', ['EntityID' => $entity['entity_id'] ?? null]);
            return;
        }

        $baseIdent = $this->sanitizeIdent($entity['entity_id']);

        foreach (HALightDefinitions::ATTRIBUTE_DEFINITIONS as $key => $meta) {
            if (!array_key_exists($key, $attributes)) {
                continue;
            }

            $ident        = $baseIdent . '_' . $key;
            $name         = $meta['caption'];
            $basePosition = $this->getEntityPosition($entity['entity_id']);
            $position     = $this->getLightAttributePosition($key, $basePosition);
            $presentation = $this->getLightAttributePresentation($key, $attributes, $meta);
            $this->MaintainVariable($ident, $name, $meta['type'], $presentation, $position, true);
            $this->debugExpert('LightVars', 'Variable angelegt', ['Ident' => $ident, 'Name' => $name, 'Presentation' => $presentation]);
            if ($this->isWritableLightAttribute($key, $attributes)) {
                $this->EnableAction($ident);
            }
        }
    }


    private function ensureLightAttributeVariable(string $entityId, string $attribute): bool
    {
        $meta = HALightDefinitions::ATTRIBUTE_DEFINITIONS[$attribute] ?? null;
        if ($meta === null) {
            return false;
        }

        $entity = $this->entities[$entityId] ?? [
            'entity_id' => $entityId,
            'name'      => $entityId
        ];

        $ident = $this->sanitizeIdent($entityId . '_' . $attribute);
        if (@$this->GetIDForIdent($ident) !== false) {
            return true;
        }

        $attributes   = $entity['attributes'] ?? null;
        $name         = $meta['caption'];
        $basePosition = $this->getEntityPosition($entityId);
        $position     = $this->getLightAttributePosition($attribute, $basePosition);
        $presentation = $this->getLightAttributePresentation($attribute, is_array($attributes) ? $attributes : [], $meta);
        $this->MaintainVariable($ident, $name, $meta['type'], $presentation, $position, true);
        $this->debugExpert('LightVars', 'Variable nachträglich angelegt', ['Ident' => $ident, 'Name' => $name, 'Presentation' => $presentation]);
        if (is_array($attributes) && $this->isWritableLightAttribute($attribute, $attributes)) {
            $this->EnableAction($ident);
        }
        return true;
    }


    private function updateLightAttributeValues(string $entityId, array $attributes): void
    {
        foreach (HALightDefinitions::ATTRIBUTE_DEFINITIONS as $key => $meta) {
            if (!array_key_exists($key, $attributes)) {
                continue;
            }

            $ident = $this->sanitizeIdent($entityId . '_' . $key);
            $varId = @$this->GetIDForIdent($ident);
            if ($varId === false) {
                continue;
            }

            $value = $attributes[$key];
            if (is_array($value)) {
                $value = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $value = $this->castVariableValue($value, $meta['type']);
            $this->SetValue($ident, $value);
        }

        if (array_key_exists('supported_features', $attributes)) {
            $this->updateLightSupportedFeaturesText($entityId, (int)$attributes['supported_features']);
        }
    }

    private function getLightAttributePosition(string $attribute, int $basePosition): int
    {
        $ordered = HALightDefinitions::ATTRIBUTE_ORDER;
        $index   = array_search($attribute, $ordered, true);
        if ($index !== false) {
            return $basePosition + 10 + $index;
        }

        $remaining = array_diff(array_keys(HALightDefinitions::ATTRIBUTE_DEFINITIONS), $ordered);
        sort($remaining, SORT_STRING);
        $fallbackIndex = array_search($attribute, $remaining, true);
        if ($fallbackIndex === false) {
            return $basePosition + 200;
        }

        return $basePosition + 100 + $fallbackIndex;
    }

    private function getEntityPosition(string $entityId): int
    {
        return (int)($this->entityPositions[$entityId] ?? 10);
    }

    private function storeEntityAttributes(string $entityId, array $attributes): void
    {
        if (!isset($this->entities[$entityId])) {
            $this->entities[$entityId] = [
                'entity_id' => $entityId,
                'domain'    => $this->getEntityDomain($entityId),
                'name'      => $entityId
            ];
        }

        $existing = $this->entities[$entityId]['attributes'] ?? [];
        if (!is_array($existing)) {
            $existing = [];
        }

        $this->entities[$entityId]['attributes'] = array_merge($existing, $attributes);
    }

    private function storeEntityAttribute(string $entityId, string $attribute, mixed $value): void
    {
        $this->storeEntityAttributes($entityId, [$attribute => $value]);
    }

    private function getSetTopicForEntity(string $entityId): string
    {
        $baseTopic = $this->ReadAttributeString('MQTTBaseTopic');
        if ($baseTopic === '') {
            $baseTopic = $this->determineBaseTopic();
        }
        if ($baseTopic === '') {
            $this->debugExpert('Error', 'Kein BaseTopic vorhanden, kann nicht senden.');
            return '';
        }

        [$domain, $name] = explode('.', $entityId, 2);
        return "$baseTopic/$domain/$name/set";
    }

    private function initializeStatesFromHa(array $configData): void
    {
        if (!$this->hasActiveParent()) {
            return;
        }

        foreach ($configData as $row) {
            $entity = $this->normalizeEntity($row, __FUNCTION__);
            if ($entity === null || !($entity['create_var'] ?? true)) {
                continue;
            }

            $entityId = $entity['entity_id'] ?? '';
            if ($entityId === '') {
                continue;
            }

            $state = $this->requestHaState($entityId);
            if ($state === null) {
                continue;
            }

            $this->applyInitialState($entityId, $state);
        }
    }

    private function fetchStateMap(array $configData): array
    {
        if (!$this->hasActiveParent()) {
            return [];
        }

        $stateMap = [];
        foreach ($configData as $row) {
            $entity = $this->normalizeEntity($row, __FUNCTION__);
            if ($entity === null || !($entity['create_var'] ?? true)) {
                continue;
            }
            $entityId = $entity['entity_id'] ?? '';
            if ($entityId === '') {
                continue;
            }
            $state = $this->requestHaState($entityId);
            if (is_array($state)) {
                $stateMap[$entityId] = $state;
            }
        }
        return $stateMap;
    }

    private function mergeStateAttributes(array $configData, array $stateMap): array
    {
        foreach ($configData as &$row) {
            $entity = $this->normalizeEntity($row, __FUNCTION__);
            if ($entity === null || !isset($entity['entity_id'])) {
                continue;
            }
            $entityId = $entity['entity_id'];
            if (!isset($stateMap[$entityId])) {
                continue;
            }
            $state = $stateMap[$entityId];
            if (!is_array($state)) {
                continue;
            }
            $attrs = $state['attributes'] ?? [];
            if (!is_array($attrs)) {
                continue;
            }
            $existing = $entity['attributes'] ?? [];
            if (!is_array($existing)) {
                $existing = [];
            }
            $row['attributes'] = array_merge($existing, $attrs);
        }
        unset($row);
        return $configData;
    }

    private function applyInitialStatesFromMap(array $configData, array $stateMap): void
    {
        foreach ($configData as $row) {
            $entity = $this->normalizeEntity($row, __FUNCTION__);
            if ($entity === null || !($entity['create_var'] ?? true)) {
                continue;
            }
            $entityId = $entity['entity_id'] ?? '';
            if ($entityId === '' || !isset($stateMap[$entityId])) {
                continue;
            }
            $state = $stateMap[$entityId];
            if (is_array($state)) {
                $this->applyInitialState($entityId, $state);
            }
        }
    }

    private function requestHaState(string $entityId): ?array
    {
        $endpoint = '/api/states/' . rawurlencode($entityId);
        $response = $this->sendRestRequestToParent($endpoint, null);
        if (!is_array($response)) {
            return null;
        }
        $this->WriteAttributeString('LastRESTFetch', date('Y-m-d H:i:s'));
        $this->updateDiagnosticsLabels();
        return $response;
    }

    private function applyInitialState(string $entityId, array $state): void
    {
        $domain   = $this->getEntityDomain($entityId);
        $rawState = (string)($state[self::KEY_STATE] ?? '');
        $value    = $this->convertValueByDomain($domain, $rawState);

        $ident = $this->sanitizeIdent($entityId);
        $varId = @$this->GetIDForIdent($ident);
        if ($varId !== false) {
            $this->SetValue($ident, $value);
        }

        $attributes = $state[self::KEY_ATTRIBUTES] ?? [];
        if (!is_array($attributes)) {
            return;
        }

        $this->storeEntityAttributes($entityId, $attributes);
        $this->updateEntityCache($entityId, $rawState, $attributes);
        $this->updateEntityPresentation($entityId, $this->entities[$entityId]['attributes'] ?? []);
        if ($domain === 'light') {
            $this->updateLightAttributeValues($entityId, $attributes);
        }
    }

    private function sendRestRequestToParent(string $endpoint, ?string $postData): ?array
    {
        if (!$this->HasActiveParent()) {
            $this->debugExpert('REST', 'No active parent');
            return null;
        }

        $payload = json_encode([
                                   'DataID'   => HAIds::DATA_DEVICE_TO_SPLITTER,
                                   'Endpoint' => $endpoint,
                                   'Method'   => $postData !== null ? 'POST' : 'GET',
                                   'Body'     => $postData
                               ],
                               JSON_THROW_ON_ERROR);

        $responseJson = $this->SendDataToParent($payload);
        if ($responseJson === '') {
            $this->debugExpert('REST', 'Empty response from parent');
            return null;
        }

        try {
            $response = json_decode($responseJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->debugExpert('REST', 'Invalid response: ' . $e->getMessage());
            return null;
        }
        if (!is_array($response)) {
            $this->debugExpert('REST', 'Invalid response: ' . $responseJson);
            return null;
        }
        if (isset($response['Error'])) {
            $this->debugExpert('REST', 'Parent error: ' . json_encode($response, JSON_THROW_ON_ERROR));
            return null;
        }

        $body = (string)($response['Response'] ?? '');
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->debugExpert('REST', 'Non-JSON response: ' . $e->getMessage());
            return null;
        }
        if (!is_array($decoded)) {
            $this->debugExpert('REST', 'Non-JSON response: ' . $body);
            return null;
        }
        return $decoded;
    }


    private function castVariableValue(mixed $value, int $type): string|int|bool|float
    {
        return match ($type) {
            VARIABLETYPE_BOOLEAN => (bool)$value,
            VARIABLETYPE_INTEGER => (int)$value,
            VARIABLETYPE_FLOAT => (float)$value,
            default => (string)$value,
        };
    }

    private function updateLightSupportedFeaturesText(string $entityId, int $mask): void
    {
        if (!$this->ensureLightAttributeVariable($entityId, 'supported_features_text')) {
            return;
        }

        $text  = $this->formatLightSupportedFeatures($mask);
        $ident = $this->sanitizeIdent($entityId . '_supported_features_text');
        $this->SetValue($ident, $text);
    }

    private function formatLightSupportedFeatures(int $mask): string
    {
        $features = [];
        foreach (HALightDefinitions::SUPPORTED_FEATURES as $bit => $label) {
            if (($mask & $bit) === $bit) {
                $features[] = $label;
            }
        }

        if (empty($features)) {
            return 'None';
        }
        return implode(', ', $features);
    }

    private function updateEntityCache(string $entityId, mixed $state, ?array $attributes): void
    {
        $cache = json_decode($this->ReadAttributeString('EntityStateCache'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($cache)) {
            $cache = [];
        }

        $entry = $cache[$entityId] ?? [];
        if ($state !== null) {
            $entry[self::KEY_STATE] = $state;
        }
        if (is_array($attributes)) {
            $existing            = isset($entry[self::KEY_ATTRIBUTES]) && is_array($entry[self::KEY_ATTRIBUTES]) ? $entry[self::KEY_ATTRIBUTES] : [];
            $entry[self::KEY_ATTRIBUTES] = array_merge($existing, $attributes);
        }
        $entry['ts']      = time();
        $cache[$entityId] = $entry;

        $this->WriteAttributeString('EntityStateCache', json_encode($cache, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->updateDiagnosticsLabels();
    }

    private function updateEntityPresentation(string $entityId, array $attributes): void
    {
        if (!isset($this->entities[$entityId])) {
            return;
        }

        $domain = $this->entities[$entityId]['domain'] ?? $this->getEntityDomain($entityId);
        if ($domain === '') {
            return;
        }

        $ident = $this->sanitizeIdent($entityId);
        if (@$this->GetIDForIdent($ident) === false) {
            return;
        }

        $entity = $this->entities[$entityId];
        $entity['attributes'] = $attributes;
        $type = $this->getVariableType($domain, $entity['attributes']);
        $presentation = $this->getEntityPresentation($domain, $entity, $type);
        $position = $this->getEntityPosition($entityId);
        $name = $this->getEntityVariableName($domain, $entity);

        $this->MaintainVariable($ident, $name, $type, $presentation, $position, true);
    }

    private function updateDiagnosticsLabels(): void
    {
        $lastMqtt = $this->ReadAttributeString('LastMQTTMessage');
        if ($lastMqtt === '') {
            $lastMqtt = 'nie';
        }
        $this->updateFormFieldSafe('DiagLastMQTT', 'caption', 'Letzte MQTT-Message: ' . $lastMqtt);

        $lastRest = $this->ReadAttributeString('LastRESTFetch');
        if ($lastRest === '') {
            $lastRest = 'nie';
        }
        $this->updateFormFieldSafe('DiagLastREST', 'caption', 'Letzter REST-Abruf: ' . $lastRest);

        $count = count($this->entities);
        $this->updateFormFieldSafe('DiagEntityCount', 'caption', 'Entitäten (aktiv): ' . $count);
    }

    private function hasCompatibleParent(): bool
    {
        $instance = IPS_GetInstance($this->InstanceID);
        $parentId = (int)($instance['ConnectionID'] ?? 0);
        if ($parentId <= 0 || !IPS_InstanceExists($parentId)) {
            return false;
        }
        $parent   = IPS_GetInstance($parentId);
        $moduleId = (string)($parent['ModuleInfo']['ModuleID'] ?? '');
        return $moduleId === HAIds::MODULE_SPLITTER;
    }

    private function updateFormFieldSafe(string $name, string $property, mixed $value): void
    {
        @ $this->UpdateFormField($name, $property, $value);
    }

    private function isWritableLightAttribute(string $attribute, array $entityAttributes = []): bool
    {
        $meta = HALightDefinitions::ATTRIBUTE_DEFINITIONS[$attribute] ?? null;
        if (!is_array($meta)) {
            return false;
        }
        if (!($meta['writable'] ?? false)) {
            return false;
        }

        if (!empty($entityAttributes)) {
            if (!$this->checkSupportedFeatures($meta, $entityAttributes)) {
                return false;
            }
            if (!$this->checkSupportedColorModes($meta, $entityAttributes)) {
                return false;
            }
        }

        return true;
    }

    private function resolveAttributeByIdent(string $ident): ?array
    {
        foreach (HALightDefinitions::ATTRIBUTE_DEFINITIONS as $attribute => $meta) {
            if (!($meta['writable'] ?? false)) {
                continue;
            }
            $suffix = '_' . $attribute;
            if (!str_ends_with($ident, $suffix)) {
                continue;
            }

            $baseIdent = substr($ident, 0, -strlen($suffix));
            if ($baseIdent === '') {
                continue;
            }

            $entityId   = $this->getEntityIdByIdent($baseIdent);
            $entity     = null;
            $attributes = [];
            if ($entityId !== null && isset($this->entities[$entityId])) {
                $entity     = $this->entities[$entityId];
                $attributes = is_array($entity['attributes'] ?? null) ? $entity['attributes'] : [];
            } else {
                $fromConfig = $this->findEntityByBaseIdentInConfig($baseIdent);
                if ($fromConfig !== null) {
                    $entityId   = $fromConfig['entity_id'];
                    $entity     = $fromConfig;
                    $attributes = $fromConfig['attributes'] ?? [];
                }
            }

            if ($entityId === null || $entity === null) {
                return null;
            }

            $domain = $entity['domain'] ?? $this->getEntityDomain($entityId);
            if ($domain !== 'light' || !$this->isWritableLightAttribute($attribute, $attributes)) {
                return null;
            }

            return [
                'entity_id' => $entityId,
                'attribute' => $attribute,
                'domain'    => $domain
            ];
        }
        return null;
    }

    private function buildLightAttributePayload(string $attribute, mixed $value): string
    {
        $meta = HALightDefinitions::ATTRIBUTE_DEFINITIONS[$attribute] ?? null;
        if (!is_array($meta) || !($meta['writable'] ?? false)) {
            return '';
        }
        $payloadValue = $this->parseLightAttributeValue($attribute, $value);
        return json_encode([$attribute => $payloadValue], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function parseLightAttributeValue(string $attribute, mixed $value): string|array|int|float
    {
        return match ($attribute) {
            'brightness', 'color_temp', 'color_temp_kelvin' => (int)$value,
            'transition' => (float)$value,
            'hs_color', 'xy_color' => $this->parseNumberList($value, 2, true),
            'rgb_color' => $this->parseNumberList($value, 3, false),
            'rgbw_color' => $this->parseNumberList($value, 4, false),
            'rgbww_color' => $this->parseNumberList($value, 5, false),
            default => (string)$value,
        };
    }

    private function parseNumberList(mixed $value, int $expectedCount, bool $useFloat): array
    {
        $items = $value;
        if (!is_array($items)) {
            $text = trim((string)$value);
            if ($text === '') {
                return [];
            }
            if ($text[0] === '[' || $text[0] === '{') {
                try {
                    $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($decoded)) {
                        $items = $decoded;
                    }
                } catch (JsonException) {
                    $items = null;
                }
            }
            if (!is_array($items)) {
                $split = preg_split('/[;,]/', $text) ? : [];
                $items = array_map('trim', $split);
            }
        }

        $numbers = [];
        foreach ($items as $item) {
            $numbers[] = $useFloat ? (float)$item : (int)$item;
        }

        if ($expectedCount > 0 && count($numbers) > $expectedCount) {
            $numbers = array_slice($numbers, 0, $expectedCount);
        }
        return $numbers;
    }

    private function findEntityByBaseIdentInConfig(string $baseIdent): ?array
    {
        $configData = $this->decodeJsonArray($this->ReadPropertyString('DeviceConfig'), 'findEntityByBaseIdentInConfig');
        if ($configData === null) {
            return null;
        }

        foreach ($configData as $row) {
            $row = $this->normalizeEntity($row, 'findEntityByBaseIdentInConfig');
            if ($row === null) {
                continue;
            }
            if (($row['create_var'] ?? true) === false) {
                continue;
            }
            $entityId = $row['entity_id'];
            if ($this->sanitizeIdent($entityId) !== $baseIdent) {
                continue;
            }
            if (!isset($row['domain']) && str_contains($entityId, '.')) {
                [$row['domain']] = explode('.', $entityId, 2);
            }

            if (isset($row['attributes']) && is_string($row['attributes'])) {
                $decoded = $this->decodeJsonArray($row['attributes'], 'findEntityByBaseIdentInConfig');
                if (is_array($decoded)) {
                    $row['attributes'] = $decoded;
                }
            }
            return $row;
        }

        return null;
    }

    private function checkSupportedFeatures(array $meta, array $attributes): bool
    {
        $required = $meta['requires_features'] ?? [];
        if (!is_array($required) || count($required) === 0) {
            return true;
        }

        $mask = (int)($attributes['supported_features'] ?? 0);
        return array_all($required, static fn($bit) => ($mask & (int)$bit) === (int)$bit);
    }

    private function checkSupportedColorModes(array $meta, array $attributes): bool
    {
        $required = $meta['requires_color_modes'] ?? [];
        if (!is_array($required) || count($required) === 0) {
            return true;
        }

        $modes = $attributes['supported_color_modes'] ?? null;
        if (!is_array($modes) || count($modes) === 0) {
            return true;
        }

        return array_any($required, static fn($mode) => in_array($mode, $modes, true));
    }
}

