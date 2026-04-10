<?php /** @noinspection PhpUnused */

/** @noinspection AutoloadingIssuesInspection */

declare(strict_types=1);

require_once __DIR__ . '/../libs/HACommonIncludes.php';
require_once __DIR__ . '/../libs/HAAttributeFilter.php';
require_once __DIR__ . '/../libs/Device/HADomainRegistry.php';
require_once __DIR__ . '/../libs/Device/HADomainStateHandlers.php';
require_once __DIR__ . '/../libs/Device/HAAttributeHandlers.php';
require_once __DIR__ . '/../libs/Device/HAPresentation.php';
require_once __DIR__ . '/../libs/Device/HAStandardAttributeMaintenance.php';
require_once __DIR__ . '/../libs/Device/HADomainAttributeMaintenance.php';
require_once __DIR__ . '/../libs/Device/HADomainSpecialActions.php';
require_once __DIR__ . '/../libs/Device/HADomainValueMapping.php';
require_once __DIR__ . '/../libs/Device/HAMediaObjects.php';
require_once __DIR__ . '/../libs/Device/HAEntityNormalization.php';
require_once __DIR__ . '/../libs/Device/HAEntityStore.php';
require_once __DIR__ . '/../libs/Device/HAVariableMapping.php';
require_once __DIR__ . '/../libs/Device/HAAttributeActionMapping.php';

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
    use ModuleDebugTrait;
    use HADomainStateHandlersTrait;
    use HAAttributeHandlersTrait;
    use HADomainRegistryTrait;
    use HAPresentationTrait;
    use HAStandardAttributeMaintenanceTrait;
    use HADomainAttributeMaintenanceTrait;
    use HADomainSpecialActionsTrait;
    use HADomainValueMappingTrait;
    use HAMediaObjectsTrait;
    use HAEntityNormalizationTrait;
    use HAEntityStoreTrait;
    use HAVariableMappingTrait;
    use HAAttributeActionMappingTrait;
    use HASupportedFeaturesTrait;
    use HADiagnosticsTrait;
    use HARestParentClientTrait;

    private const string KEY_STATE      = 'state';
    private const string KEY_ATTRIBUTES = 'attributes';
    private const string KEY_SUPPORTED_FEATURES = 'supported_features';
    private const string LOCK_ACTION_SUFFIX = '_lock_action';
    private const string VACUUM_ACTION_SUFFIX = '_vacuum_action';
    private const string VACUUM_FAN_SPEED_SUFFIX = '_vacuum_fan_speed';
    private const string LAWN_MOWER_ACTION_SUFFIX = '_lawn_mower_action';
    private const string MEDIA_PLAYER_ACTION_SUFFIX = '_media_player_action';
    private const string MEDIA_PLAYER_POWER_SUFFIX = '_power';
    private const string CLIMATE_POWER_SUFFIX = '_power';
    private const string MEDIA_PLAYER_COVER_SUFFIX = '_media_cover';
    private const string CAMERA_STREAM_SUFFIX = '_camera_stream';
    private const string CAMERA_PREVIEW_SUFFIX = '_camera_preview';
    private const string IMAGE_PREVIEW_SUFFIX = '_image_preview';
    private const string EVENT_TYPE_SUFFIX = '_event_type';
    private const string UNAVAILABLE_ENTITIES_JSON_IDENT = 'unavailable_entities_json';
    private const string TIMER_MEDIA_PLAYER_PROGRESS = 'MediaPlayerProgressTimer';
    private const string BUFFER_MEDIA_PLAYER_PROGRESS_DEBUG = 'MediaPlayerProgressDebug';
    private const int MEDIA_PLAYER_PROGRESS_DEBUG_INTERVAL = 10;

    private array $topicMapping    = [];

    private array $entities        = [];

    private bool $hasMultipleStatusEntities = false;

    private const string PROP_DEVICE_CONFIG = 'DeviceConfig';
    private const string PROP_DEVICE_AREA = 'DeviceArea';
    private const string PROP_DEVICE_NAME = 'DeviceName';
    private const string PROP_DEVICE_ID = 'DeviceID';
    private const string PROP_ENABLE_EXPERT_DEBUG = 'EnableExpertDebug';
    private const string PROP_SHOW_UNAVAILABLE_ENTITIES_JSON = 'ShowUnavailableEntitiesJson';
    private const string PROP_OUTPUT_BUFFER_SIZE = 'OutputBufferSize';

    public function Create(): void
    {
        parent::Create();

        // Nachrichten registrieren, um auf Gateway-Änderungen zu reagieren.
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);

        $this->RegisterAttributeString('MQTTBaseTopic', '');
        $this->RegisterAttributeString('CurrentFilter', '');
        $this->RegisterAttributeString('LastMQTTMessage', '');
        $this->RegisterAttributeString('LastRESTFetch', '');
        $this->RegisterAttributeString('EntityStateCache', '{}');

        // Eigenschaften
        $this->RegisterPropertyString(self::PROP_DEVICE_ID, '');
        $this->RegisterPropertyString(self::PROP_DEVICE_AREA, '');
        $this->RegisterPropertyString(self::PROP_DEVICE_NAME, '');
        $this->RegisterPropertyString(self::PROP_DEVICE_CONFIG, '[]');
        $this->RegisterPropertyBoolean(self::PROP_ENABLE_EXPERT_DEBUG, false);
        $this->RegisterPropertyBoolean(self::PROP_SHOW_UNAVAILABLE_ENTITIES_JSON, false);
        $this->RegisterPropertyInteger(self::PROP_OUTPUT_BUFFER_SIZE, 10);

        $this->RegisterTimer(self::TIMER_MEDIA_PLAYER_PROGRESS, 0, 'HA_UpdateMediaPlayerProgress($_IPS["TARGET"]);');
    }


    /**
     * Reagiert auf Änderungen am Parent (Gateway).
     */
    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        // Wenn sich die Verbindung ändert, die Konfiguration neu laden.
        if ($Message === FM_CONNECT || $Message === FM_DISCONNECT) {
            $this->debugExpert('MessageSink', 'Verbindungsstatus geändert. Aktualisiere...');
            $this->ApplyChanges();
        }
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->SetTimerInterval(self::TIMER_MEDIA_PLAYER_PROGRESS, 0);
        $this->maintainUnavailableEntitiesJsonVariable();
        $this->updateUnavailableEntitiesJsonVariable();

        $instance = IPS_GetInstance($this->InstanceID);
        $parentID = $instance['ConnectionID'];
        if ($parentID <= 0) {
            $this->SetStatus(201);
            $this->debugExpert('ApplyChanges', 'Kein Parent verbunden');
            return;
        }
        if (!$this->hasCompatibleSplitterParent()) {
            $this->SetStatus(201);
            $this->debugExpert('ApplyChanges', 'Parent ist nicht Home Assistant Splitter');
            return;
        }

        // 1. MQTT-Basetopic ermitteln.
        $baseTopic = $this->determineBaseTopic();
        $this->debugExpert('ApplyChanges', 'Konfiguration geladen', ['BaseTopic' => $baseTopic]);

        // 2. Konfiguration laden.
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

        // 3. Entitäten verarbeiten und Topics sammeln.
        $filterTopics = $this->processEntities($configData, $baseTopic);
        $this->maintainUnavailableEntitiesJsonVariable();
        $this->updateUnavailableEntitiesJsonVariable();
        $this->updateDiagnosticsLabels();

        // 4. Empfangsfilter setzen.
        $this->updateReceiveFilter($filterTopics);

        // 5. Initiale REST-Synchronisierung der aktuellen States.
        if ($stateMap === []) {
            $this->initializeStatesFromHa($configData);
        } else {
            $this->applyInitialStatesFromMap($configData, $stateMap);
        }

        if ($baseTopic !== '' && $this->hasMediaPlayerEntities()) {
            $this->SetTimerInterval(self::TIMER_MEDIA_PLAYER_PROGRESS, 1000);
        }
    }

    private function hasMediaPlayerEntities(): bool
    {
        foreach ($this->entities as $entityId => $entity) {
            $domain = $entity['domain'] ?? $this->getEntityDomain($entityId);
            if ($domain !== HAMediaPlayerDefinitions::DOMAIN) {
                continue;
            }
            if (($entity['create_var'] ?? true) === false) {
                continue;
            }
            return true;
        }
        return false;
    }

    /** @noinspection PhpUnused */
    public function UpdateMediaPlayerProgress(): void
    {
        $cache = $this->readEntityStateCache();
        if ($cache === []) {
            return;
        }

        $now = time();
        foreach ($cache as $entityId => $entry) {
            if ($this->getEntityDomain($entityId) !== HAMediaPlayerDefinitions::DOMAIN) {
                continue;
            }

            if (!$this->isMediaPlayerPlaying($entry)) {
                continue;
            }

            $attributes = $this->getMediaPlayerProgressAttributes($entityId, $entry);
            if ($attributes === null) {
                continue;
            }

            $base = $this->getMediaPlayerProgressBase($attributes, $entry);
            if ($base === null) {
                continue;
            }

            $position = $this->computeMediaPlayerProgressPosition($base['base_position'], $base['updated_at_ts'], $attributes, $now);
            $ident = $this->sanitizeIdent($entityId . '_media_position');
            if (!$this->shouldUpdateMediaPlayerPosition($ident, $position)) {
                continue;
            }
            $this->setValueWithDebug($ident, $position);
        }
    }

    private function isMediaPlayerPlaying(array $entry): bool
    {
        $state = strtolower((string)($entry[self::KEY_STATE] ?? ''));
        return $state === 'playing';
    }

    private function getMediaPlayerProgressAttributes(string $entityId, array $entry): ?array
    {
        $attributes = [];
        $stored = $this->entities[$entityId]['attributes'] ?? null;
        if (is_array($stored)) {
            $attributes = $stored;
        }
        if (isset($entry[self::KEY_ATTRIBUTES]) && is_array($entry[self::KEY_ATTRIBUTES])) {
            $attributes = array_merge($attributes, $entry[self::KEY_ATTRIBUTES]);
        }

        $basePosition = $attributes['media_position'] ?? null;
        if (!is_numeric($basePosition)) {
            return null;
        }

        return $attributes;
    }

    private function getMediaPlayerProgressBase(array $attributes, array $entry): ?array
    {
        $updatedAt = $attributes['media_position_updated_at'] ?? null;
        $updatedAtTs = $this->parseMediaPositionUpdatedAt($updatedAt);
        if ($updatedAtTs === null) {
            $updatedAtTs = is_numeric($entry['ts'] ?? null) ? (int)$entry['ts'] : null;
        }
        if ($updatedAtTs === null) {
            return null;
        }

        return [
            'base_position' => (float)$attributes['media_position'],
            'updated_at_ts' => $updatedAtTs
        ];
    }

    private function computeMediaPlayerProgressPosition(
        float $basePosition,
        int $updatedAtTs,
        array $attributes,
        int $now
    ): int {
        $elapsed = max(0, $now - $updatedAtTs);
        $position = (int)max(0, $basePosition + $elapsed);
        $duration = $attributes['media_duration'] ?? null;
        if (is_numeric($duration)) {
            $position = min($position, (int)$duration);
        }
        return $position;
    }

    private function shouldUpdateMediaPlayerPosition(string $ident, int $position): bool
    {
        if (@$this->GetIDForIdent($ident) === false) {
            return false;
        }

        $current = $this->GetValue($ident);
        return !(is_int($current) && $current === $position);
    }

    private function parseMediaPositionUpdatedAt(mixed $value): ?int
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $dt = new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }

        return $dt->getTimestamp();
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

        $this->debugExpert(__FUNCTION__, 'Eingangsdaten', ['Topic' => $topic, 'Payload' => $payload]);
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
     * Verarbeitet Schaltvorgänge
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
        if ($this->handleLawnMowerAction($Ident, $Value)) {
            return;
        }
        if ($this->handleCameraPowerAction($Ident, $Value)) {
            return;
        }
        if ($this->handleMediaPlayerPowerAction($Ident, $Value)) {
            return;
        }
        if ($this->handleMediaPlayerAction($Ident, $Value)) {
            return;
        }
        if ($this->handleClimatePowerAction($Ident, $Value)) {
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

            // Payload in das erwartete MQTT-Format bringen.
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
            $this->resetVariableByDescriptor($Ident, $this->describeVariableByIdent($Ident, $domain));
            return;
        }

        $attributeInfo = $this->resolveAttributeByIdent($Ident);
        if ($attributeInfo === null) {
            $this->debugExpert(__FUNCTION__, 'Entity/Attribut nicht gefunden', ['Ident' => $Ident], true);
            return;
        }

        $entityId  = $attributeInfo['entity_id'];
        $attribute = $attributeInfo['attribute'];

        $payload = $this->buildDomainAttributePayload($attributeInfo['domain'], $attribute, $Value);
        if ($payload === '' && !HADomainCatalog::supportsAttributePayload($attributeInfo['domain'])) {
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
        if ($attributeInfo['domain'] === HAClimateDefinitions::DOMAIN && $attribute === HAClimateDefinitions::ATTRIBUTE_HVAC_MODE) {
            $hvacMode = trim((string)$Value);
            if ($hvacMode !== '') {
                $this->storeEntityAttribute($entityId, HAClimateDefinitions::ATTRIBUTE_HVAC_MODE, $hvacMode);
                $this->updateEntityCache($entityId, null, [HAClimateDefinitions::ATTRIBUTE_HVAC_MODE => $hvacMode]);
                $this->setValueWithDebug($Ident, $hvacMode);
            }
        }
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
                // Standardwerte für fehlende Spalten ergänzen.
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

                // Attribute für die Konfigurationstabelle als JSON-String ausgeben.
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
     * Priorität: 1. Automatisch vom Parent, 2. gespeichertes Attribut
     */
    private function determineBaseTopic(): string
    {
        $baseTopic = $this->ReadAttributeString('MQTTBaseTopic');
        $instance  = IPS_GetInstance($this->InstanceID);
        $parentID  = $instance['ConnectionID'];

        // Normalfall: Subscriptions am IO des Splitters auslesen.
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
        // Das erste Subscription-Topic wird als Basetopic interpretiert.
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
        $filterTopics          = [];
        $positionIndex         = 0;
        $this->hasMultipleStatusEntities = $this->countStatusEntities($configData) > 1;

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

    private function countStatusEntities(array $configData): int
    {
        $count = 0;
        foreach ($configData as $row) {
            $entity = $this->normalizeEntity($row, __FUNCTION__);
            if ($entity === null || !($entity['create_var'] ?? true)) {
                continue;
            }
            $domain = (string)($entity['domain'] ?? '');
            if ($domain === '') {
                continue;
            }
            if ($this->isStatusDomain($domain)) {
                $count++;
                if ($count > 1) {
                    return $count;
                }
            }
        }
        return $count;
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
            // Topic sicher für die Regex maskieren.
            $quoted = preg_quote($t, '/');
            // JSON kann Slashes maskiert oder unmaskiert enthalten.
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
    // Hauptvariable einer Entität anlegen oder aktualisieren.
    private function maintainEntityVariable(array $entity): void
    {
        $ident  = $this->sanitizeIdent($entity['entity_id']);
        $domain = $entity['domain'];
        $name       = $this->getEntityVariableName($domain, $entity);
        $exists     = @$this->GetIDForIdent($ident) !== false;
        $descriptor = $this->describeEntityMainVariable($entity);

        $type         = $this->getVariableType($domain, $entity['attributes'] ?? []);
        $position     = $this->getEntityPosition($entity['entity_id']);
        if ($domain === HAMediaPlayerDefinitions::DOMAIN) {
            $position = $this->getMediaPlayerOrderPosition(0, 'status');
        } else {
            $linkedPosition = $this->getMediaPlayerLinkedPosition($entity['entity_id'], $domain);
            if ($linkedPosition !== null) {
                $position = $linkedPosition;
            }
        }
        $presentation = $this->getEntityPresentation($domain, $entity, $type);

        $this->MaintainVariable($ident, $name, $type, $presentation, $position, true);
        $this->initializeVariableDescriptorValue($ident, $descriptor, $exists);

        $this->applyDomainActionState($domain, $ident, $entity);
        $this->applyDomainExtraMaintenance($domain, $entity);
    }

    /**
     * Aktualisiert den Wert einer Variable basierend auf dem MQTT Payload.
     * Hält dabei Hauptvariable und Attributcache synchron.
     */
    private function updateEntityValue(string $entityId, string $payload): void
    {
        $this->debugExpert(__FUNCTION__, 'Wert wird gesetzt', ['EntityID' => $entityId, 'Payload' => $payload], true);
        $parsed = $this->parseEntityPayload($payload);
        $rawState = (string)($parsed[self::KEY_STATE] ?? '');
        $this->updateEntityRawStateCache($entityId, $rawState);
        $this->updateAvailabilityValue($entityId, $rawState);
        $this->applyParsedEntityState($entityId, $parsed);
    }

    // REST initialization, statestream state and fallback updates share the same domain logic.
    private function applyParsedEntityState(string $entityId, array $parsed): void
    {
        $domain = $this->getEntityDomain($entityId);
        if ($domain === '') {
            $this->debugExpert(__FUNCTION__, 'Domain nicht ermittelbar', ['EntityID' => $entityId]);
            return;
        }

        $ident = $this->sanitizeIdent($entityId);
        $descriptor = $this->describeVariableByIdent($ident, $domain);
        if ($this->isTriggerVariableDescriptor($descriptor)) {
            $this->applyTriggerEntityStateUpdate($entityId, $parsed);
            return;
        }

        if ($this->handleDomainUpdateEntityValue($domain, $entityId, $ident, $parsed)) {
            return;
        }

        $attributes = $this->resolveEntityStateAttributes($entityId, $parsed[self::KEY_ATTRIBUTES] ?? null);
        $finalValue = $this->convertValueByDomain($domain, (string)($parsed[self::KEY_STATE] ?? ''), $attributes);
        $this->setEntityMainValue($entityId, $ident, $finalValue, $parsed[self::KEY_STATE] ?? null);
        $this->updateEntityCache($entityId, $parsed[self::KEY_STATE] ?? null, $parsed[self::KEY_ATTRIBUTES] ?? null);

        if (!empty($parsed[self::KEY_ATTRIBUTES]) && is_array($parsed[self::KEY_ATTRIBUTES])) {
            $storedAttributes = $this->storeEntityAttributes($entityId, $parsed[self::KEY_ATTRIBUTES]);
            $this->updateEntityPresentation($entityId, $this->entities[$entityId][self::KEY_ATTRIBUTES] ?? []);
            if ($domain === HALightDefinitions::DOMAIN) {
                $this->updateLightAttributeValues($entityId, $storedAttributes);
            }
        }
    }

    // Trigger domains keep metadata, but do not persist a main state value.
    private function applyTriggerEntityStateUpdate(string $entityId, array $parsed): void
    {
        $attributes = $parsed[self::KEY_ATTRIBUTES] ?? null;
        if (!is_array($attributes) || $attributes === []) {
            return;
        }

        $storedAttributes = $this->storeEntityAttributes($entityId, $attributes);
        $state = $parsed[self::KEY_STATE] ?? null;
        $this->updateEntityCache($entityId, is_string($state) && $state !== '' ? $state : null, $storedAttributes);
        $this->updateEntityPresentation($entityId, $this->entities[$entityId][self::KEY_ATTRIBUTES] ?? []);
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

        // HA-State-Payloads können rohe Werte oder JSON mit state/attributes sein.
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
            $this->debugExpert(__FUNCTION__, 'No active parent', [],true);
            return;
        }
        $this->debugExpert(__FUNCTION__, 'SendDataToParent', ['Topic' => $topic, 'Payload' => $payload], true);

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


    private function sanitizeIdent(string $id): string
    {
        return str_replace(['.', ' ', '-'], '_', $id);
    }



    private function isWriteable(string $domain): bool
    {
        return HADomainCatalog::isMainWritable($this->normalizeDomainAlias($domain));
    }

    private function formatPayloadForMqtt(string $domain, mixed $value, array $attributes = []): string
    {
        $domain = $this->normalizeDomainAlias($domain);
        return match ($domain) {
            HALightDefinitions::DOMAIN, HAFanDefinitions::DOMAIN, HAHumidifierDefinitions::DOMAIN => $value ? 'ON' : 'OFF',
            HASwitchDefinitions::DOMAIN => $value ? HASwitchDefinitions::STATE_ON : HASwitchDefinitions::STATE_OFF,
            HACoverDefinitions::DOMAIN => HACoverDefinitions::normalizeCommand($value),
            HALockDefinitions::DOMAIN => HALockDefinitions::normalizeCommand($value),
            HANumberDefinitions::DOMAIN => $this->formatNumberPayload($value, $attributes),
            HASelectDefinitions::DOMAIN => $this->formatSelectPayload($value, $attributes),
            HAButtonDefinitions::DOMAIN => 'press',
            default => (string)$value,
        };
    }

    private function formatNumberPayload(mixed $value, array $attributes): string
    {
        if (!is_numeric($value)) {
            $normalized = trim((string)$value);
            if ($normalized === '' || !is_numeric(str_replace(',', '.', $normalized))) {
                $this->debugExpert('Number', 'Ungültiger Wert', ['Value' => $value], true);
                return '';
            }
            $value = str_replace(',', '.', $normalized);
        }

        return $this->inferNumberVariableType($attributes) === VARIABLETYPE_INTEGER
            ? (string)(int)$value
            : (string)(float)$value;
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

    private function isFanToggleSupported(mixed $attributes): bool
    {
        if (!is_array($attributes)) {
            return false;
        }
        $supported = (int)($attributes[self::KEY_SUPPORTED_FEATURES] ?? 0);
        return (($supported & HAFanDefinitions::FEATURE_TURN_ON) === HAFanDefinitions::FEATURE_TURN_ON)
               || (($supported & HAFanDefinitions::FEATURE_TURN_OFF) === HAFanDefinitions::FEATURE_TURN_OFF);
    }

    private function isCoverMainWritable(mixed $attributes): bool
    {
        if (!is_array($attributes)) {
            return true;
        }

        $supported = (int)($attributes[self::KEY_SUPPORTED_FEATURES] ?? 0);
        if ($supported === 0) {
            return true;
        }

        if (($supported & HACoverDefinitions::FEATURE_SET_POSITION) === HACoverDefinitions::FEATURE_SET_POSITION) {
            return true;
        }

        return (($supported & HACoverDefinitions::FEATURE_OPEN) === HACoverDefinitions::FEATURE_OPEN)
               || (($supported & HACoverDefinitions::FEATURE_CLOSE) === HACoverDefinitions::FEATURE_CLOSE);
    }

    private function isEntityWritable(string $domain, mixed $attributes): bool
    {
        if (!$this->isWriteable($domain)) {
            return false;
        }
        if ($domain === HAClimateDefinitions::DOMAIN) {
            return $this->isClimateTargetWritable($attributes);
        }
        if ($domain === HAFanDefinitions::DOMAIN) {
            return $this->isFanToggleSupported($attributes);
        }
        if ($domain === HACoverDefinitions::DOMAIN) {
            return $this->isCoverMainWritable($attributes);
        }
        return true;
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
        // Ohne aktiven Parent ist keine REST-Abfrage möglich.
        if (!$this->hasActiveParent()) {
            return;
        }

        foreach ($configData as $row) {
            // Konfigurationseintrag normalisieren und validieren.
            $entity = $this->normalizeEntity($row, __FUNCTION__);
            $entityId = $entity['entity_id'] ?? '';

            // Nur aktivierte Entitäten mit gültiger ID initialisieren.
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
        $rawState = (string)($state[self::KEY_STATE] ?? '');
        $this->updateEntityRawStateCache($entityId, $rawState);
        $this->updateAvailabilityValue($entityId, $rawState);
        $attributes = $state[self::KEY_ATTRIBUTES] ?? null;
        $parsed = [
            self::KEY_STATE => $rawState
        ];
        if (is_array($attributes)) {
            $parsed[self::KEY_ATTRIBUTES] = $attributes;
        }

        $this->applyParsedEntityState($entityId, $parsed);
    }

    private function setValueWithDebug(string $ident, mixed $value): void
    {
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'] ?? '';
        if ($caller !== 'UpdateMediaPlayerProgress' || $this->shouldLogMediaPlayerProgress($ident)) {
            $this->debugExpert('SetValue', $caller, [
                'Ident' => $ident,
                'Value' => $value,
                'ValueType' => get_debug_type($value)
            ], true);
        }
        $variableId = @$this->GetIDForIdent($ident);
        if ($variableId === false) {
            return;
        }

        $type = IPS_GetVariable($variableId)['VariableType'];
        if (($type === VARIABLETYPE_INTEGER || $type === VARIABLETYPE_FLOAT)
            && !is_numeric($value)
            && !is_bool($value)) {
            $this->debugExpert('SetValue', 'Type mismatch', [
                'Ident' => $ident,
                'Value' => $value,
                'ValueType' => get_debug_type($value),
                'TargetType' => $type
            ], true);
            return;
        }
        if ($type === VARIABLETYPE_BOOLEAN
            && !is_bool($value)
            && !is_numeric($value)) {
            $this->debugExpert('SetValue', 'Type mismatch', [
                'Ident' => $ident,
                'Value' => $value,
                'ValueType' => get_debug_type($value),
                'TargetType' => $type
            ], true);
            return;
        }

        $this->SetValue($ident, $this->castVariableValue($value, $type));
    }

    private function setEntityMainValue(string $entityId, string $ident, mixed $value, mixed $rawState = null): void
    {
        if ($value === null || !$this->shouldApplyEntityMainValue($entityId, $rawState)) {
            return;
        }

        $this->setValueWithDebug($ident, $value);
    }

    private function shouldApplyEntityMainValue(string $entityId, mixed $rawState = null): bool
    {
        $effectiveState = $rawState;
        if (!is_string($effectiveState) || trim($effectiveState) === '') {
            // Attribut-Updates dürfen den letzten fachlichen Wert nicht bei unknown/unavailable überschreiben.
            $effectiveState = $this->getCachedEntityRawState($entityId) ?? $this->getCachedEntityState($entityId);
        }

        return !$this->isIndeterminateEntityState($effectiveState);
    }

    private function isUnavailableEntityState(mixed $state): bool
    {
        return $this->normalizeEntityStateToken($state) === 'unavailable';
    }

    private function isUnknownEntityState(mixed $state): bool
    {
        return $this->normalizeEntityStateToken($state) === 'unknown';
    }

    private function isIndeterminateEntityState(mixed $state): bool
    {
        return $this->isUnavailableEntityState($state) || $this->isUnknownEntityState($state);
    }

    private function normalizeEntityStateToken(mixed $state): string
    {
        if (!is_string($state)) {
            return '';
        }

        return strtolower(trim($state));
    }

    private function shouldLogMediaPlayerProgress(string $ident): bool
    {
        $buffer = $this->GetBuffer(self::BUFFER_MEDIA_PLAYER_PROGRESS_DEBUG);
        $map = [];
        if ($buffer !== '') {
            $decoded = json_decode($buffer, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                $map = $decoded;
            }
        }

        $now = time();
        $last = isset($map[$ident]) && is_int($map[$ident]) ? $map[$ident] : 0;
        if ($now - $last < self::MEDIA_PLAYER_PROGRESS_DEBUG_INTERVAL) {
            return false;
        }

        $map[$ident] = $now;
        $this->SetBuffer(self::BUFFER_MEDIA_PLAYER_PROGRESS_DEBUG, json_encode($map, JSON_THROW_ON_ERROR));
        return true;
    }


    private function updateFormFieldSafe(string $name, string $property, mixed $value): void
    {
        @ $this->UpdateFormField($name, $property, $value);
    }

}
