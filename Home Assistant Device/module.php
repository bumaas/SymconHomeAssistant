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
require_once __DIR__ . '/../libs/HASelectDefinitions.php';
require_once __DIR__ . '/../libs/HABinarySensorDefinitions.php';
require_once __DIR__ . '/../libs/HASwitchDefinitions.php';
require_once __DIR__ . '/../libs/HAClimateDefinitions.php';
require_once __DIR__ . '/../libs/HACoverDefinitions.php';
require_once __DIR__ . '/../libs/HAEventDefinitions.php';
require_once __DIR__ . '/../libs/HAAttributeFilter.php';
require_once __DIR__ . '/../libs/Device/HADomainStateHandlers.php';
require_once __DIR__ . '/../libs/Device/HAAttributeHandlers.php';
require_once __DIR__ . '/../libs/Device/HAPresentation.php';
require_once __DIR__ . '/../libs/Device/HAEntityStore.php';

/**
 * @phpstan-type EntityAttributes array<string, mixed>
 * @phpstan-type Entity array{
 *     entity_id: string,
 *     domain?: string,
 *     name?: string,
 *     device_class?: string,
 *     attributes?: EntityAttributes,
 *     create_var?: bool,
 *     position_base?: int
 * }
 * @phpstan-type ConfigRow Entity
 * @phpstan-type StatePayload array{state?: string, attributes?: EntityAttributes}
 * @phpstan-type StateMap array<string, StatePayload>
 */
class HomeAssistantDevice extends IPSModuleStrict
{
    use HADebugTrait;
    use HADomainStateHandlersTrait;
    use HAAttributeHandlersTrait;
    use HAPresentationTrait;
    use HAEntityStoreTrait;

    private const string KEY_STATE      = 'state';
    private const string KEY_ATTRIBUTES = 'attributes';
    private const string KEY_SUPPORTED_FEATURES = 'supported_features';
    private const string LOCK_ACTION_SUFFIX = '_lock_action';
    private const string VACUUM_ACTION_SUFFIX = '_vacuum_action';
    private const string VACUUM_FAN_SPEED_SUFFIX = '_vacuum_fan_speed';

    private array $topicMapping    = [];

    private array $entities        = [];

    private array $entityPositions = [];

    private const string PROP_DEVICE_CONFIG = 'DeviceConfig';
    private const string PROP_DEVICE_AREA = 'DeviceArea';
    private const string PROP_DEVICE_NAME = 'DeviceName';
    private const string PROP_DEVICE_ID = 'DeviceID';
    private const string PROP_ENABLE_EXPERT_DEBUG = 'EnableExpertDebug';

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
        $this->RegisterPropertyString(self::PROP_DEVICE_ID, '');
        $this->RegisterPropertyString(self::PROP_DEVICE_AREA, '');
        $this->RegisterPropertyString(self::PROP_DEVICE_NAME, '');
        $this->RegisterPropertyString(self::PROP_DEVICE_CONFIG, '[]');
        $this->RegisterPropertyBoolean(self::PROP_ENABLE_EXPERT_DEBUG, false);

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
        $configData = $this->decodeJsonArray($this->ReadPropertyString(self::PROP_DEVICE_CONFIG), 'ApplyChanges');
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
        $this->SetSummary($this->ReadPropertyString(self::PROP_DEVICE_ID));

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

        if ($this->handleLockAction($Ident, $Value)) {
            return;
        }
        if ($this->handleVacuumAction($Ident, $Value)) {
            return;
        }
        if ($this->handleVacuumFanSpeedAction($Ident, $Value)) {
            return;
        }

        $entity = $this->findEntityByIdent($Ident);
        if ($entity !== null && !empty($entity['entity_id'])) {
            $entityId = $entity['entity_id'];
            $domain   = $entity['domain'] ?? null;
            if ($domain === null && str_contains($entityId, '.')) {
                [$domain] = explode('.', $entityId, 2);
            }
            $this->debugExpert('RequestAction', 'Entity aufgelöst', ['EntityID' => $entityId, 'Domain' => $domain]);

            if (!$this->isEntityWritable($domain ?? '', $entity['attributes'] ?? [])) {
                $this->debugExpert('RequestAction', 'Variable ist nicht schreibbar', ['EntityID' => $entityId], true);
                return;
            }

            // Payload formatieren (ON/OFF vs. Wert)
            $mqttPayload = $this->formatPayloadForMqtt($domain ?? '', $Value, $entity['attributes'] ?? []);
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

        if ($attributeInfo['domain'] === HALightDefinitions::DOMAIN) {
            $payload = $this->buildLightAttributePayload($attribute, $Value);
        } elseif ($attributeInfo['domain'] === HACoverDefinitions::DOMAIN) {
            $payload = $this->buildCoverAttributePayload($attribute, $Value);
        } else {
            $this->debugExpert(__FUNCTION__, 'Attribut-Domain nicht unterstützt', ['Attribute' => $attribute, 'Domain' => $attributeInfo['domain']], true);
            return;
        }
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
        $resultPath = __DIR__ . '/form_result.jsonx';
        if (is_file($resultPath)) {
            $resultContent = file_get_contents($resultPath);
            if ($resultContent !== false) {
                return $resultContent;
            }
        }

        $form   = json_decode(file_get_contents(__DIR__ . '/form.json'), true, 512, JSON_THROW_ON_ERROR);
        $config = $this->decodeJsonArray($this->ReadPropertyString(self::PROP_DEVICE_CONFIG), __FUNCTION__);
        $this->debugExpert(__FUNCTION__, 'config:', $config);

        $values = [];

        if (is_array($config)) {
            foreach ($config as $row) {
                $row = $this->normalizeEntity($row, __FUNCTION__);
                if ($row === null) {
                    continue;
                }
                // Defaults setzen
                if (!isset($row['create_var'])) {
                    $row['create_var'] = true;
                }

                $attributes = $row['attributes'] ?? null;
                if (is_string($attributes)) {
                    $decoded = $this->decodeJsonArray($attributes, __FUNCTION__);
                    if ($decoded !== null) {
                        $attributes = $decoded;
                    }
                }

                // Attribute als JSON-String für die Tabelle formatieren
                if (is_array($attributes)) {
                    $row['attributes'] = json_encode($attributes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                } else {
                    $row['attributes'] = '{}';
                }
                $this->debugExpert(__FUNCTION__, 'row:', $row);

                $values[] = $row;
            }
        }
        foreach ($form['elements'] as &$element) {
            if (($element['name'] ?? '') === self::PROP_DEVICE_CONFIG) {
                $element['values'] = $values;
                break;
            }
        }
        unset($element);

        foreach ($form['actions'] as &$action) {
            if (($action['name'] ?? '') === 'CURRENT_FILTER') {
                $action['caption'] = 'Aktueller Filter (Regex): ' . $this->ReadAttributeString('CurrentFilter');
            }
        }
        unset($action);
        $this->debugExpert(__FUNCTION__, 'Form:', $form);

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
        $previousEntities      = $this->entities;
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
            if (isset($previousEntities[$entity['entity_id']]['attributes'])
                && is_array($previousEntities[$entity['entity_id']]['attributes'])) {
                $existingAttributes = $previousEntities[$entity['entity_id']]['attributes'];
                if (!isset($entity['attributes']) || !is_array($entity['attributes'])) {
                    $entity['attributes'] = $existingAttributes;
                } else {
                    $entity['attributes'] = array_merge($existingAttributes, $entity['attributes']);
                }
            }
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
        if ($domain === HALockDefinitions::DOMAIN) {
            $this->DisableAction($ident);
        } elseif ($domain === HAClimateDefinitions::DOMAIN) {
            if ($this->isClimateTargetWritable($entity['attributes'] ?? [])) {
                $this->EnableAction($ident);
            }
        } elseif ($this->isWriteable($domain)) {
            $this->EnableAction($ident);
        }

        if ($domain === HALightDefinitions::DOMAIN) {
            $this->maintainLightAttributeVariables($entity);
        }
        if ($domain === HAClimateDefinitions::DOMAIN) {
            $this->maintainClimateAttributeVariables($entity);
        }
        if ($domain === HALockDefinitions::DOMAIN) {
            $this->maintainLockActionVariable($entity);
        }
        if ($domain === HAVacuumDefinitions::DOMAIN) {
            $this->maintainVacuumActionVariable($entity);
            $this->maintainVacuumFanSpeedVariable($entity);
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
        $parsed = $this->parseEntityPayload($payload);
        if ($domain === HAEventDefinitions::DOMAIN) {
            if (!empty($parsed[self::KEY_ATTRIBUTES])) {
                $this->storeEntityAttributes($entityId, $parsed[self::KEY_ATTRIBUTES]);
                $this->updateEntityCache($entityId, null, $parsed[self::KEY_ATTRIBUTES]);
                $this->updateEntityPresentation($entityId, $this->entities[$entityId][self::KEY_ATTRIBUTES] ?? []);
            }
            return;
        }

        if ($domain === HAClimateDefinitions::DOMAIN) {
            $attributes = $parsed[self::KEY_ATTRIBUTES] ?? [];
            if (is_array($attributes) && $attributes !== []) {
                $attributes = $this->storeEntityAttributes($entityId, $attributes);
                $mainValue = $this->extractClimateMainValue($attributes);
                if ($mainValue !== null) {
                    $this->SetValue($ident, $mainValue);
                }
                $this->updateEntityCache($entityId, $mainValue, $attributes);
                $this->updateEntityPresentation($entityId, $this->entities[$entityId][self::KEY_ATTRIBUTES] ?? []);
                $this->updateClimateAttributeValues($entityId, $attributes);
                return;
            }

            if (is_numeric($parsed[self::KEY_STATE])) {
                $value = (float)$parsed[self::KEY_STATE];
                $this->SetValue($ident, $value);
                $this->updateEntityCache($entityId, $value, null);
            } elseif (is_string($parsed[self::KEY_STATE]) && $parsed[self::KEY_STATE] !== '') {
                $this->storeEntityAttribute($entityId, HAClimateDefinitions::ATTRIBUTE_HVAC_MODE, $parsed[self::KEY_STATE]);
                $this->updateEntityCache($entityId, null, [HAClimateDefinitions::ATTRIBUTE_HVAC_MODE => $parsed[self::KEY_STATE]]);
                $this->updateEntityPresentation($entityId, $this->entities[$entityId][self::KEY_ATTRIBUTES] ?? []);
            }
            return;
        }

        if ($domain === HACoverDefinitions::DOMAIN) {
            $attributes = $parsed[self::KEY_ATTRIBUTES] ?? [];
            $storedAttributes = null;
            if (is_array($attributes)) {
                if ($attributes !== []) {
                    $storedAttributes = $this->storeEntityAttributes($entityId, $attributes);
                }
                $position = $this->extractCoverPosition($storedAttributes ?? $attributes);
                if ($position !== null) {
                    $this->SetValue($ident, $position);
                    $this->updateEntityCache($entityId, $position, $storedAttributes ?? $attributes);
                    $this->updateEntityPresentation($entityId, $this->entities[$entityId][self::KEY_ATTRIBUTES] ?? []);
                    $this->updateCoverAttributeValues($entityId, $storedAttributes ?? $attributes);
                    return;
                }
            }

            $level = $this->normalizeCoverStateToLevel((string)$parsed[self::KEY_STATE]);
            if ($level !== null) {
                $this->SetValue($ident, $level);
                $this->updateEntityCache($entityId, $level, is_array($attributes ?? null) ? $attributes : null);
            }
            if (!empty($parsed[self::KEY_ATTRIBUTES])) {
                if ($storedAttributes === null) {
                    $storedAttributes = $this->storeEntityAttributes($entityId, $parsed[self::KEY_ATTRIBUTES]);
                }
                $this->updateEntityPresentation($entityId, $this->entities[$entityId][self::KEY_ATTRIBUTES] ?? []);
                $this->updateCoverAttributeValues($entityId, $storedAttributes);
            }
            return;
        }

        if ($domain === HALockDefinitions::DOMAIN) {
            $attributes = $parsed[self::KEY_ATTRIBUTES] ?? [];
            if (is_array($attributes) && $attributes !== []) {
                $attributes = $this->storeEntityAttributes($entityId, $attributes);
            }
            $displayState = $this->resolveLockDisplayState((string)$parsed[self::KEY_STATE], is_array($attributes) ? $attributes : null);
            if ($displayState !== null) {
                $this->SetValue($ident, $displayState);
            }
            $this->updateLockActionValue($entityId, (string)$parsed[self::KEY_STATE], is_array($attributes) ? $attributes : null);
            if (is_array($attributes) && $attributes !== []) {
                $this->updateEntityCache($entityId, $parsed[self::KEY_STATE], $attributes);
                $this->updateEntityPresentation($entityId, $this->entities[$entityId][self::KEY_ATTRIBUTES] ?? []);
            } else {
                $this->updateEntityCache($entityId, $parsed[self::KEY_STATE], null);
            }
            return;
        }

        if ($domain === HAVacuumDefinitions::DOMAIN) {
            $attributes = $parsed[self::KEY_ATTRIBUTES] ?? [];
            if (is_array($attributes) && $attributes !== []) {
                $attributes = $this->storeEntityAttributes($entityId, $attributes);
            }
            if (is_string($parsed[self::KEY_STATE]) && $parsed[self::KEY_STATE] !== '') {
                $this->SetValue($ident, $parsed[self::KEY_STATE]);
            }
            $this->updateVacuumFanSpeedValue($entityId, $attributes);
            if (is_array($attributes) && $attributes !== []) {
                $this->updateEntityCache($entityId, $parsed[self::KEY_STATE], $attributes);
                $this->updateEntityPresentation($entityId, $this->entities[$entityId][self::KEY_ATTRIBUTES] ?? []);
            } else {
                $this->updateEntityCache($entityId, $parsed[self::KEY_STATE], null);
            }
            return;
        }

        $finalValue = $this->convertValueByDomain($domain, $parsed[self::KEY_STATE]);

        $this->SetValue($ident, $finalValue);
        $this->updateEntityCache($entityId, $parsed[self::KEY_STATE], $parsed['attributes'] ?? null);

        if (!empty($parsed[self::KEY_ATTRIBUTES])) {
            $storedAttributes = $this->storeEntityAttributes($entityId, $parsed[self::KEY_ATTRIBUTES]);
            $this->updateEntityPresentation($entityId, $this->entities[$entityId][self::KEY_ATTRIBUTES] ?? []);
            if ($domain === HALightDefinitions::DOMAIN) {
                $this->updateLightAttributeValues($entityId, $storedAttributes);
            }
        }
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
        if ($trimmed !== '') {
            $first = $trimmed[0];
            if ($first === '{' || $first === '[' || $first === '"') {
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
        }

        return $result;
    }

    private function convertValueByDomain(string $domain, string $valueData): string|bool|float
    {
        $normalized = strtoupper(trim($valueData));
        return match ($domain) {
            HALightDefinitions::DOMAIN, HASwitchDefinitions::DOMAIN, HABinarySensorDefinitions::DOMAIN => $normalized === 'ON',
            HASensorDefinitions::DOMAIN, HAClimateDefinitions::DOMAIN, HANumberDefinitions::DOMAIN => (float)$valueData,
            default => $valueData,
        };
    }

    // --- Technische Helper ---

    private function deriveStateTopic(string $base, string $entityId): string
    {
        [$domain, $name] = explode('.', $entityId, 2);
        if ($domain === HAEventDefinitions::DOMAIN) {
            return HAEventDefinitions::buildStateTopic($base, $name);
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

        $row = $this->normalizeEntityDomain($row);
        $row = $this->normalizeEntityAttributes($row, $context);
        $row = $this->syncDeviceClass($row);
        $row = $this->normalizeDomainSpecificAttributes($row);
        $this->enrichSupportedFeaturesList($row);
        return $this->applyEinLevelDefaults($row);

    }

    private function normalizeEntityDomain(array $row): array
    {
        if (!isset($row['domain']) && str_contains($row['entity_id'], '.')) {
            [$row['domain']] = explode('.', $row['entity_id'], 2);
        }
        return $row;
    }

    private function normalizeEntityAttributes(array $row, string $context): array
    {
        if (isset($row['attributes']) && is_string($row['attributes'])) {
            $decoded = $this->decodeJsonArray($row['attributes'], $context);
            if ($decoded !== null) {
                $row['attributes'] = $decoded;
            }
        }

        if (!isset($row['attributes']) || !is_array($row['attributes'])) {
            $row['attributes'] = [];
        }

        return $row;
    }

    private function syncDeviceClass(array $row): array
    {
        $attributes = $row['attributes'] ?? [];
        if (!is_array($attributes)) {
            $attributes = [];
        }

        if (isset($attributes['device_class'])
            && is_string($attributes['device_class'])
            && (!isset($row['device_class']) || trim((string)$row['device_class']) === '')) {
            $row['device_class'] = trim($attributes['device_class']);
        }

        if (isset($row['device_class'])) {
            $deviceClass = $row['device_class'];
            if (!is_string($deviceClass)) {
                $deviceClass = '';
            }
            $deviceClass = trim($deviceClass);
            if (($deviceClass !== '') && ($attributes['device_class'] ?? '') === '') {
                $attributes['device_class'] = $deviceClass;
            }
        }

        $row['attributes'] = $attributes;
        return $row;
    }

    private function normalizeDomainSpecificAttributes(array $row): array
    {
        $domain = (string)($row['domain'] ?? '');
        if ($domain !== '' && is_array($row['attributes'])) {
            $row['attributes'] = $this->filterAttributesByDomain($domain, $row['attributes'], __FUNCTION__);
        }
        return $row;
    }

    private function applyEinLevelDefaults(array $row): array
    {
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

    private function filterAttributesByDomain(string $domain, array $attributes, string $context): array
    {
        if ($domain === HAClimateDefinitions::DOMAIN) {
            return $this->mapClimateAttributeAliases($attributes, $context);
        }

        return $attributes;
        /* das ausfiltern unbekannte attribute entfällt erst einmal
        $allowedAttributes = match ($domain) {
            HAClimateDefinitions::DOMAIN => HAClimateDefinitions::ALLOWED_ATTRIBUTES,
            HALightDefinitions::DOMAIN => HALightDefinitions::ALLOWED_ATTRIBUTES,
            HACoverDefinitions::DOMAIN => HACoverDefinitions::ALLOWED_ATTRIBUTES,
            default => null
        };
        if ($allowedAttributes === null) {
            return $attributes;
        }

        $logger = function (string $category, string $message, array $logContext): void {
            $this->debugExpert($category, $message, $logContext);
        };

        return HAAttributeFilter::filterAllowedAttributes(
            $attributes,
            $allowedAttributes,
            $logger,
            $context,
            'Nicht-offizielle Attribute entfernt',
            ['Domain' => $domain]
        );
        */
    }

    private function mapClimateAttributeAliases(array $attributes, string $context): array
    {
        $aliases = [
            'temperature' => HAClimateDefinitions::ATTRIBUTE_TARGET_TEMPERATURE,
            'target_temp_low' => HAClimateDefinitions::ATTRIBUTE_TARGET_TEMPERATURE_LOW,
            'target_temp_high' => HAClimateDefinitions::ATTRIBUTE_TARGET_TEMPERATURE_HIGH,
            'target_temp_step' => HAClimateDefinitions::ATTRIBUTE_TARGET_TEMPERATURE_STEP
        ];

        $mapped = [];
        foreach ($aliases as $alias => $target) {
            if (!array_key_exists($alias, $attributes) || array_key_exists($target, $attributes)) {
                continue;
            }
            $attributes[$target] = $attributes[$alias];
            unset($attributes[$alias]);
            $mapped[$alias] = $target;
        }

        if ($mapped !== []) {
            $this->debugExpert($context, 'Klima-Attribute umbenannt', ['Mapped' => $mapped]);
        }

        return $attributes;
    }

    private function enrichSupportedFeaturesList(array &$row): void
    {
        if (!isset($row['attributes']) || !is_array($row['attributes'])) {
            return;
        }
        if (isset($row['attributes']['supported_features_list'])) {
            return;
        }
        if (!isset($row['attributes']['supported_features']) || !is_numeric($row['attributes']['supported_features'])) {
            return;
        }

        $domain = (string)($row['domain'] ?? '');
        if ($domain === '' && isset($row['entity_id']) && str_contains($row['entity_id'], '.')) {
            [$domain] = explode('.', (string)$row['entity_id'], 2);
        }

        $list = $this->mapSupportedFeaturesByDomain($domain, (int)$row['attributes']['supported_features']);
        if ($list !== []) {
            $row['attributes']['supported_features_list'] = $list;
        }
    }

    private function mapSupportedFeaturesByDomain(string $domain, int $mask): array
    {
        $map = match ($domain) {
            HALightDefinitions::DOMAIN => HALightDefinitions::SUPPORTED_FEATURES,
            HAClimateDefinitions::DOMAIN => HAClimateDefinitions::SUPPORTED_FEATURES,
            HACoverDefinitions::DOMAIN => HACoverDefinitions::SUPPORTED_FEATURES,
            HALockDefinitions::DOMAIN => HALockDefinitions::SUPPORTED_FEATURES,
            HAVacuumDefinitions::DOMAIN => HAVacuumDefinitions::SUPPORTED_FEATURES,
            default => []
        };

        if ($map === []) {
            return [];
        }

        $list = [];
        foreach ($map as $bit => $label) {
            if (($mask & (int)$bit) === (int)$bit) {
                $list[] = $label;
            }
        }
        return $list;
    }


    private function sanitizeIdent(string $id): string
    {
        return str_replace(['.', ' ', '-'], '_', $id);
    }



    private function isWriteable(string $domain): bool
    {
        return in_array($domain, [
            HALightDefinitions::DOMAIN,
            HASwitchDefinitions::DOMAIN,
            HAClimateDefinitions::DOMAIN,
            HANumberDefinitions::DOMAIN,
            HALockDefinitions::DOMAIN,
            HACoverDefinitions::DOMAIN,
            HASelectDefinitions::DOMAIN
        ],              true);
    }

    private function formatPayloadForMqtt(string $domain, mixed $value, array $attributes = []): string
    {
        return match ($domain) {
            HALightDefinitions::DOMAIN => $value ? 'ON' : 'OFF',
            HASwitchDefinitions::DOMAIN => $value ? HASwitchDefinitions::STATE_ON : HASwitchDefinitions::STATE_OFF,
            HACoverDefinitions::DOMAIN => HACoverDefinitions::normalizeCommand($value),
            HALockDefinitions::DOMAIN => HALockDefinitions::normalizeCommand($value),
            HASelectDefinitions::DOMAIN => $this->formatSelectPayload($value, $attributes),
            default => (string)$value,
        };
    }

    private function formatSelectPayload(mixed $value, array $attributes): string
    {
        $options = $attributes['options'] ?? null;
        $normalized = HASelectDefinitions::normalizeSelection($value, $options);
        if ($normalized !== null) {
            return $normalized;
        }
        $this->debugExpert(
            'Select',
            'Ungültige Option',
            [
                'Value'   => trim((string)$value),
                'Options' => HASelectDefinitions::normalizeOptions($options)
            ],
            true
        );
        return '';
    }

    private function getVariableType(string $domain, array $attributes = []): int
    {
        if ($domain === HASensorDefinitions::DOMAIN) {
            $deviceClass = $attributes['device_class'] ?? '';
            if (is_string($deviceClass) && trim($deviceClass) === HASensorDefinitions::DEVICE_CLASS_ENUM) {
                return VARIABLETYPE_STRING;
            }
        }
        return match ($domain) {
            HALightDefinitions::DOMAIN => HALightDefinitions::VARIABLE_TYPE,
            HABinarySensorDefinitions::DOMAIN => HABinarySensorDefinitions::VARIABLE_TYPE,
            HASwitchDefinitions::DOMAIN => HASwitchDefinitions::VARIABLE_TYPE,
            HASensorDefinitions::DOMAIN => VARIABLETYPE_FLOAT,
            HANumberDefinitions::DOMAIN => HANumberDefinitions::VARIABLE_TYPE,
            HAClimateDefinitions::DOMAIN => HAClimateDefinitions::VARIABLE_TYPE,
            HALockDefinitions::DOMAIN => HALockDefinitions::VARIABLE_TYPE,
            HASelectDefinitions::DOMAIN => HASelectDefinitions::VARIABLE_TYPE,
            HAVacuumDefinitions::DOMAIN => HAVacuumDefinitions::VARIABLE_TYPE,
            HACoverDefinitions::DOMAIN => HACoverDefinitions::VARIABLE_TYPE,
            HAEventDefinitions::DOMAIN => HAEventDefinitions::VARIABLE_TYPE,
            default => VARIABLETYPE_STRING,
        };
    }


    private function isClimateTargetWritable(mixed $attributes): bool
    {
        if (!is_array($attributes)) {
            return false;
        }
        $supported = (int)($attributes[HAClimateDefinitions::ATTRIBUTE_SUPPORTED_FEATURES] ?? 0);
        $hasTargetFeature = ($supported & 1) === 1;
        $hasTargetAttribute = array_key_exists(HAClimateDefinitions::ATTRIBUTE_TARGET_TEMPERATURE, $attributes)
            || array_key_exists('temperature', $attributes);
        return $hasTargetFeature || $hasTargetAttribute;
    }

    private function isEntityWritable(string $domain, mixed $attributes): bool
    {
        if (!$this->isWriteable($domain)) {
            return false;
        }
        if ($domain === HAClimateDefinitions::DOMAIN) {
            return $this->isClimateTargetWritable($attributes);
        }
        return true;
    }



    private function resolveLockDisplayState(string $state, ?array $attributes): ?string
    {
        $state = strtolower(trim($state));
        if ($state !== '' && array_key_exists($state, HALockDefinitions::STATE_OPTIONS)) {
            return $state;
        }

        if (is_array($attributes)) {
            $flags = [
                'is_jammed' => 'jammed',
                'is_opening' => 'opening',
                'is_open' => 'open',
                'is_locking' => 'locking',
                'is_locked' => 'locked',
                'is_unlocking' => 'unlocking',
                'is_unlocked' => 'unlocked'
            ];
            foreach ($flags as $key => $value) {
                if (array_key_exists($key, $attributes) && (bool)$attributes[$key] === true) {
                    return $value;
                }
            }
        }

        return null;
    }


    private function isLockOpenSupported(array $attributes): bool
    {
        $supported = (int)($attributes[self::KEY_SUPPORTED_FEATURES] ?? 0);
        return ($supported & HALockDefinitions::FEATURE_OPEN) === HALockDefinitions::FEATURE_OPEN;
    }

    private function getLockActionIdent(string $entityId): string
    {
        return $this->sanitizeIdent($entityId) . self::LOCK_ACTION_SUFFIX;
    }

    private function getVacuumActionIdent(string $entityId): string
    {
        return $this->sanitizeIdent($entityId) . self::VACUUM_ACTION_SUFFIX;
    }

    private function getVacuumFanSpeedIdent(string $entityId): string
    {
        return $this->sanitizeIdent($entityId) . self::VACUUM_FAN_SPEED_SUFFIX;
    }

    private function maintainLockActionVariable(array $entity): void
    {
        $entityId = $entity['entity_id'] ?? '';
        if ($entityId === '') {
            return;
        }

        $attributes = $entity['attributes'] ?? [];
        $options = $this->getLockActionOptions(is_array($attributes) ? $attributes : []);
        if ($options === []) {
            return;
        }

        $ident = $this->getLockActionIdent($entityId);
        $position = $this->getEntityPosition($entityId) + 5;
        $presentation = [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'OPTIONS' => json_encode($options, JSON_THROW_ON_ERROR)
        ];

        $this->MaintainVariable($ident, 'Aktion', VARIABLETYPE_INTEGER, $presentation, $position, true);
        $this->EnableAction($ident);
    }

    private function maintainVacuumActionVariable(array $entity): void
    {
        $entityId = $entity['entity_id'] ?? '';
        if ($entityId === '') {
            return;
        }

        $attributes = $entity['attributes'] ?? [];
        if (!is_array($attributes)) {
            $attributes = [];
        }

        $options = $this->getVacuumActionOptions($attributes);
        if ($options === []) {
            return;
        }

        $ident = $this->getVacuumActionIdent($entityId);
        $position = $this->getEntityPosition($entityId) + 5;
        $presentation = [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'OPTIONS'      => json_encode($options, JSON_THROW_ON_ERROR)
        ];

        $this->MaintainVariable($ident, $this->Translate('Aktion'), VARIABLETYPE_INTEGER, $presentation, $position, true);
        $this->EnableAction($ident);
    }

    private function maintainVacuumFanSpeedVariable(array $entity): void
    {
        $entityId = $entity['entity_id'] ?? '';
        if ($entityId === '') {
            return;
        }

        $attributes = $entity['attributes'] ?? [];
        if (!is_array($attributes)) {
            return;
        }

        $fanSpeedList = $attributes['fan_speed_list'] ?? null;
        if (!is_array($fanSpeedList) || $fanSpeedList === []) {
            return;
        }

        $ident = $this->getVacuumFanSpeedIdent($entityId);
        $position = $this->getEntityPosition($entityId) + 6;
        $presentation = [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'OPTIONS'      => $this->getPresentationOptions($fanSpeedList)
        ];

        $this->MaintainVariable($ident, $this->Translate('Lüfterstufe'), VARIABLETYPE_STRING, $presentation, $position, true);
        $this->EnableAction($ident);
    }

    private function getLockActionOptions(array $attributes): array
    {
        $options = [
            [
                'Value' => HALockDefinitions::ACTION_LOCK,
                'Caption' => $this->Translate('Abgeschlossen'),
                'IconActive' => false,
                'IconValue' => '',
                'ColorActive' => false,
                'ColorValue' => -1
            ],
            [
                'Value' => HALockDefinitions::ACTION_UNLOCK,
                'Caption' => $this->Translate('Aufgeschlossen'),
                'IconActive' => false,
                'IconValue' => '',
                'ColorActive' => false,
                'ColorValue' => -1
            ]
        ];

        if ($this->isLockOpenSupported($attributes)) {
            $options[] = [
                'Value' => HALockDefinitions::ACTION_OPEN,
                'Caption' => $this->Translate('Öffnen'),
                'IconActive' => false,
                'IconValue' => '',
                'ColorActive' => false,
                'ColorValue' => -1
            ];
        }

        return $options;
    }

    private function handleLockAction(string $ident, mixed $value): bool
    {
        if (!str_ends_with($ident, self::LOCK_ACTION_SUFFIX)) {
            return false;
        }

        $entity = $this->findEntityByIdentSuffix($ident, self::LOCK_ACTION_SUFFIX, HALockDefinitions::DOMAIN);
        if ($entity === null) {
            return false;
        }

        $entityId = $entity['entity_id'] ?? '';
        if ($entityId === '') {
            return true;
        }

        $attributes = $entity['attributes'] ?? [];
        $allowOpen = is_array($attributes) && $this->isLockOpenSupported($attributes);

        $action = is_numeric($value) ? (int)$value : null;
        $command = match ($action) {
            HALockDefinitions::ACTION_LOCK => 'lock',
            HALockDefinitions::ACTION_UNLOCK => 'unlock',
            HALockDefinitions::ACTION_OPEN => $allowOpen ? 'open' : '',
            default => ''
        };
        if ($command === '') {
            return true;
        }

        $topic = $this->getSetTopicForEntity($entityId);
        if ($topic === '') {
            return true;
        }

        $this->debugExpert('RequestAction', 'Lock action', ['EntityID' => $entityId, 'Command' => $command]);
        $this->sendMqttMessage($topic, $command);
        return true;
    }

    private function handleVacuumAction(string $ident, mixed $value): bool
    {
        if (!str_ends_with($ident, self::VACUUM_ACTION_SUFFIX)) {
            return false;
        }

        $entity = $this->findEntityByIdentSuffix($ident, self::VACUUM_ACTION_SUFFIX, HAVacuumDefinitions::DOMAIN);
        if ($entity === null) {
            return false;
        }

        $entityId = $entity['entity_id'] ?? '';
        if ($entityId === '') {
            return true;
        }

        $action = is_numeric($value) ? (int)$value : null;
        $command = match ($action) {
            HAVacuumDefinitions::ACTION_START => 'start',
            HAVacuumDefinitions::ACTION_STOP => 'stop',
            HAVacuumDefinitions::ACTION_PAUSE => 'pause',
            HAVacuumDefinitions::ACTION_RETURN_HOME => 'return_to_base',
            HAVacuumDefinitions::ACTION_CLEAN_SPOT => 'clean_spot',
            HAVacuumDefinitions::ACTION_LOCATE => 'locate',
            default => ''
        };
        if ($command === '') {
            return true;
        }

        $topic = $this->getSetTopicForEntity($entityId);
        if ($topic === '') {
            return true;
        }

        $this->debugExpert('RequestAction', 'Vacuum action', ['EntityID' => $entityId, 'Command' => $command]);
        $this->sendMqttMessage($topic, $command);
        return true;
    }

    private function handleVacuumFanSpeedAction(string $ident, mixed $value): bool
    {
        if (!str_ends_with($ident, self::VACUUM_FAN_SPEED_SUFFIX)) {
            return false;
        }

        $entity = $this->findEntityByIdentSuffix($ident, self::VACUUM_FAN_SPEED_SUFFIX, HAVacuumDefinitions::DOMAIN);
        if ($entity === null) {
            return false;
        }

        $entityId = $entity['entity_id'] ?? '';
        if ($entityId === '') {
            return true;
        }

        $fanSpeed = trim((string)$value);
        if ($fanSpeed === '') {
            return true;
        }

        $payload = json_encode(['fan_speed' => $fanSpeed], JSON_THROW_ON_ERROR);
        $topic = $this->getSetTopicForEntity($entityId);
        if ($topic === '') {
            return true;
        }

        $this->debugExpert('RequestAction', 'Vacuum fan_speed', ['EntityID' => $entityId, 'fan_speed' => $fanSpeed]);
        $this->sendMqttMessage($topic, $payload);
        return true;
    }

    private function updateVacuumFanSpeedValue(string $entityId, ?array $attributes): void
    {
        if (!is_array($attributes)) {
            return;
        }
        $fanSpeed = $attributes['fan_speed'] ?? null;
        if (!is_string($fanSpeed) || trim($fanSpeed) === '') {
            return;
        }
        $ident = $this->getVacuumFanSpeedIdent($entityId);
        if (@$this->GetIDForIdent($ident) !== false) {
            $this->SetValue($ident, $fanSpeed);
        }
    }

    private function getVacuumActionOptions(array $attributes): array
    {
        $supported = (int)($attributes[self::KEY_SUPPORTED_FEATURES] ?? 0);
        $options = [];

        if (($supported & 128) === 128) {
            $options[] = ['Value' => HAVacuumDefinitions::ACTION_START, 'Caption' => $this->Translate('Start')];
        }
        if (($supported & 512) === 512) {
            $options[] = ['Value' => HAVacuumDefinitions::ACTION_STOP, 'Caption' => $this->Translate('Stop')];
        }
        if (($supported & 16) === 16) {
            $options[] = ['Value' => HAVacuumDefinitions::ACTION_PAUSE, 'Caption' => $this->Translate('Pause')];
        }
        if (($supported & 32) === 32) {
            $options[] = ['Value' => HAVacuumDefinitions::ACTION_RETURN_HOME, 'Caption' => $this->Translate('Zur Basis')];
        }
        if (($supported & 1) === 1) {
            $options[] = ['Value' => HAVacuumDefinitions::ACTION_CLEAN_SPOT, 'Caption' => $this->Translate('Punktreinigung')];
        }
        if (($supported & 4) === 4) {
            $options[] = ['Value' => HAVacuumDefinitions::ACTION_LOCATE, 'Caption' => $this->Translate('Lokalisieren')];
        }

        foreach ($options as &$option) {
            $option['IconActive'] = false;
            $option['IconValue'] = '';
            $option['ColorActive'] = false;
            $option['ColorValue'] = -1;
        }
        unset($option);

        return $options;
    }

    private function updateLockActionValue(string $entityId, string $state, ?array $attributes): void
    {
        $action = $this->mapLockActionFromState($state, $attributes);
        if ($action === null) {
            return;
        }

        $ident = $this->getLockActionIdent($entityId);
        if (@$this->GetIDForIdent($ident) !== false) {
            $this->SetValue($ident, $action);
        }
    }

    private function mapLockActionFromState(string $state, ?array $attributes): ?int
    {
        $displayState = $this->resolveLockDisplayState($state, $attributes);
        if ($displayState === null) {
            return null;
        }

        if (in_array($displayState, ['locked', 'locking'], true)) {
            return HALockDefinitions::ACTION_LOCK;
        }
        if (in_array($displayState, ['unlocked', 'unlocking'], true)) {
            return HALockDefinitions::ACTION_UNLOCK;
        }
        if (in_array($displayState, ['open', 'opening'], true)) {
            $allowOpen = is_array($attributes) && $this->isLockOpenSupported($attributes);
            return $allowOpen ? HALockDefinitions::ACTION_OPEN : HALockDefinitions::ACTION_UNLOCK;
        }

        return null;
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

    }

    private function maintainClimateAttributeVariables(array $entity): void
    {
        $attributes = $entity['attributes'] ?? [];
        if (!is_array($attributes)) {
            $this->debugExpert('ClimateVars', 'Attributes kein Array', ['EntityID' => $entity['entity_id'] ?? null]);
            return;
        }

        $baseIdent = $this->sanitizeIdent($entity['entity_id']);

        foreach (HAClimateDefinitions::ATTRIBUTE_DEFINITIONS as $key => $meta) {
            if (!array_key_exists($key, $attributes)) {
                continue;
            }

            $ident        = $baseIdent . '_' . $key;
            $name         = $meta['caption'];
            $basePosition = $this->getEntityPosition($entity['entity_id']);
            $position     = $this->getClimateAttributePosition($key, $basePosition);
            $presentation = $this->getClimateAttributePresentation($key, $attributes);
            $this->MaintainVariable($ident, $name, $meta['type'], $presentation, $position, true);
            $this->debugExpert('ClimateVars', 'Variable angelegt', ['Ident' => $ident, 'Name' => $name, 'Presentation' => $presentation]);
        }
    }

    private function ensureClimateAttributeVariable(string $entityId, string $attribute): bool
    {
        $meta = HAClimateDefinitions::ATTRIBUTE_DEFINITIONS[$attribute] ?? null;
        if ($meta === null) {
            return false;
        }

        $ident = $this->sanitizeIdent($entityId . '_' . $attribute);
        if (@$this->GetIDForIdent($ident) !== false) {
            return true;
        }

        $entity = $this->entities[$entityId] ?? [
            'entity_id' => $entityId,
            'name'      => $entityId
        ];

        $attributes   = $entity['attributes'] ?? [];
        $name         = $meta['caption'];
        $basePosition = $this->getEntityPosition($entityId);
        $position     = $this->getClimateAttributePosition($attribute, $basePosition);
        $presentation = $this->getClimateAttributePresentation($attribute, is_array($attributes) ? $attributes : []);
        $this->MaintainVariable($ident, $name, $meta['type'], $presentation, $position, true);
        $this->debugExpert('ClimateVars', 'Variable nachträglich angelegt', ['Ident' => $ident, 'Name' => $name, 'Presentation' => $presentation]);
        return true;
    }

    private function updateClimateAttributeValues(string $entityId, array $attributes): void
    {
        foreach (HAClimateDefinitions::ATTRIBUTE_DEFINITIONS as $key => $meta) {
            if (!array_key_exists($key, $attributes)) {
                continue;
            }

            $ident = $this->sanitizeIdent($entityId . '_' . $key);
            $varId = @$this->GetIDForIdent($ident);
            if ($varId === false) {
                continue;
            }

            $value = $this->castVariableValue($attributes[$key], $meta['type']);
            $this->SetValue($ident, $value);
        }

    }

    private function getClimateAttributePosition(string $attribute, int $basePosition): int
    {
        $ordered = array_keys(HAClimateDefinitions::ATTRIBUTE_DEFINITIONS);
        $index   = array_search($attribute, $ordered, true);
        if ($index === false) {
            return $basePosition + 200;
        }
        return $basePosition + 10 + $index;
    }







    private function extractClimateMainValue(array $attributes): ?float
    {
        $supported = (int)($attributes[HAClimateDefinitions::ATTRIBUTE_SUPPORTED_FEATURES] ?? 0);
        $preferTarget = ($supported & 1) === 1;

        $candidates = $preferTarget
            ? [
                HAClimateDefinitions::ATTRIBUTE_TARGET_TEMPERATURE,
                HAClimateDefinitions::ATTRIBUTE_CURRENT_TEMPERATURE
            ]
            : [
                HAClimateDefinitions::ATTRIBUTE_CURRENT_TEMPERATURE,
                HAClimateDefinitions::ATTRIBUTE_TARGET_TEMPERATURE
            ];
        foreach ($candidates as $key) {
            $value = $attributes[$key] ?? null;
            if (is_numeric($value)) {
                return (float)$value;
            }
        }
        return null;
    }

    private function ensureCoverAttributeVariable(string $entityId, string $attribute): bool
    {
        $meta = HACoverDefinitions::ATTRIBUTE_DEFINITIONS[$attribute] ?? null;
        if ($meta === null) {
            return false;
        }

        $ident = $this->sanitizeIdent($entityId . '_' . $attribute);
        if (@$this->GetIDForIdent($ident) !== false) {
            return true;
        }

        $name         = $meta['caption'];
        $basePosition = $this->getEntityPosition($entityId);
        $position     = $this->getCoverAttributePosition($attribute, $basePosition);
        $presentation = $this->getCoverAttributePresentation($meta);
        $this->MaintainVariable($ident, $name, $meta['type'], $presentation, $position, true);
        $this->debugExpert('CoverVars', 'Variable nachträglich angelegt', ['Ident' => $ident, 'Name' => $name, 'Presentation' => $presentation]);
        if (($meta['writable'] ?? false) === true) {
            $this->EnableAction($ident);
        }
        return true;
    }

    private function updateCoverAttributeValues(string $entityId, array $attributes): void
    {
        $position = $this->extractCoverPosition($attributes);
        if ($position === null) {
            return;
        }
        $ident = $this->sanitizeIdent($entityId);
        if (@$this->GetIDForIdent($ident) === false) {
            return;
        }
        $this->SetValue($ident, $position);
    }

    private function getCoverAttributePosition(string $attribute, int $basePosition): int
    {
        $ordered = array_keys(HACoverDefinitions::ATTRIBUTE_DEFINITIONS);
        $index   = array_search($attribute, $ordered, true);
        if ($index === false) {
            return $basePosition + 200;
        }
        return $basePosition + 10 + $index;
    }


    private function extractCoverPosition(array $attributes): ?float
    {
        $candidates = [
            HACoverDefinitions::ATTRIBUTE_POSITION,
            HACoverDefinitions::ATTRIBUTE_POSITION_ALT
        ];
        foreach ($candidates as $key) {
            $value = $attributes[$key] ?? null;
            if (is_numeric($value)) {
                return (float)$value;
            }
        }
        return null;
    }

    private function normalizeCoverStateToLevel(string $state): ?float
    {
        $text = strtolower(trim($state));
        if ($text === '') {
            return null;
        }
        if (is_numeric($text)) {
            return (float)$text;
        }
        return match ($text) {
            'open', 'opened' => 100.0,
            'closed' => 0.0,
            default => null
        };
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
        // Ohne aktiven Parent kann keine REST-Abfrage erfolgen.
        if (!$this->hasActiveParent()) {
            return;
        }

        foreach ($configData as $row) {
            // Konfigurationseintrag normalisieren und validieren.
            $entity = $this->normalizeEntity($row, __FUNCTION__);
            $entityId = $entity['entity_id'] ?? '';

            // Nur Entitäten mit aktivierter Variablen-Erstellung und gültiger ID initialisieren.
            if ($entity === null || !($entity['create_var'] ?? true) || $entityId === '') {
                continue;
            }

            // Initialen State per REST holen und anwenden.
            $state = $this->requestHaState($entityId);
            if ($state !== null) {
                $this->applyInitialState($entityId, $state);
            }
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
        $attributes = $state[self::KEY_ATTRIBUTES] ?? null;
        if (is_array($attributes)) {
            $attributes = $this->storeEntityAttributes($entityId, $attributes);
        }
        $coverAttributes = is_array($attributes) ? $attributes : [];

        $ident = $this->sanitizeIdent($entityId);
        $varId = @$this->GetIDForIdent($ident);
        if ($varId !== false && $domain !== HAEventDefinitions::DOMAIN) {
            if ($domain === HAClimateDefinitions::DOMAIN) {
                $climateAttributes = is_array($attributes) ? $attributes : [];
                $mainValue = $this->extractClimateMainValue($climateAttributes);
                if ($mainValue !== null) {
                    $this->SetValue($ident, $mainValue);
                } elseif (is_numeric($rawState)) {
                    $this->SetValue($ident, (float)$rawState);
                }
            } elseif ($domain === HALockDefinitions::DOMAIN) {
                $displayState = $this->resolveLockDisplayState($rawState, is_array($attributes) ? $attributes : null);
                if ($displayState !== null) {
                    $this->SetValue($ident, $displayState);
                }
                $this->updateLockActionValue($entityId, $rawState, is_array($attributes) ? $attributes : null);
            } elseif ($domain === HAVacuumDefinitions::DOMAIN) {
                if ($rawState !== '') {
                    $this->SetValue($ident, $rawState);
                }
                $this->updateVacuumFanSpeedValue($entityId, is_array($attributes) ? $attributes : null);
            } elseif ($domain === HACoverDefinitions::DOMAIN) {
                $value = $this->extractCoverPosition($coverAttributes);
                if ($value === null) {
                    $value = $this->normalizeCoverStateToLevel($rawState);
                }
                if ($value !== null) {
                    $this->SetValue($ident, $value);
                }
            } else {
                $value = $this->convertValueByDomain($domain, $rawState);
                $this->SetValue($ident, $value);
            }
        }

        if (!is_array($attributes)) {
            return;
        }

        $this->updateEntityCache($entityId, $rawState, $attributes);
        $this->updateEntityPresentation($entityId, $this->entities[$entityId]['attributes'] ?? []);
        if ($domain === HALightDefinitions::DOMAIN) {
            $this->updateLightAttributeValues($entityId, $attributes);
        }
        if ($domain === HACoverDefinitions::DOMAIN) {
            $this->updateCoverAttributeValues($entityId, $attributes);
        }
        if ($domain === HAClimateDefinitions::DOMAIN) {
            $this->updateClimateAttributeValues($entityId, $attributes);
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
            if ($domain !== HALightDefinitions::DOMAIN || !$this->isWritableLightAttribute($attribute, $attributes)) {
                return null;
            }

            return [
                'entity_id' => $entityId,
                'attribute' => $attribute,
                'domain'    => $domain
            ];
        }

        foreach (HACoverDefinitions::ATTRIBUTE_DEFINITIONS as $attribute => $meta) {
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

            $entityId = $this->getEntityIdByIdent($baseIdent);
            $entity   = null;
            if ($entityId !== null && isset($this->entities[$entityId])) {
                $entity = $this->entities[$entityId];
            } else {
                $fromConfig = $this->findEntityByBaseIdentInConfig($baseIdent);
                if ($fromConfig !== null) {
                    $entityId = $fromConfig['entity_id'];
                    $entity   = $fromConfig;
                }
            }

            if ($entityId === null || $entity === null) {
                return null;
            }

            $domain = $entity['domain'] ?? $this->getEntityDomain($entityId);
            if ($domain !== HACoverDefinitions::DOMAIN) {
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

    private function buildCoverAttributePayload(string $attribute, mixed $value): string
    {
        $meta = HACoverDefinitions::ATTRIBUTE_DEFINITIONS[$attribute] ?? null;
        if (!is_array($meta) || !($meta['writable'] ?? false)) {
            return '';
        }
        $payloadKey = $meta['payload_key'] ?? '';
        if (!is_string($payloadKey) || $payloadKey === '') {
            return '';
        }
        $payloadValue = $this->parseCoverAttributeValue($value);
        return json_encode([$payloadKey => $payloadValue], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function parseCoverAttributeValue(mixed $value): float
    {
        $numeric = (float)$value;
        if ($numeric < 0) {
            return 0.0;
        }
        if ($numeric > 100) {
            return 100.0;
        }
        return $numeric;
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
        $configData = $this->decodeJsonArray($this->ReadPropertyString(self::PROP_DEVICE_CONFIG), 'findEntityByBaseIdentInConfig');
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

