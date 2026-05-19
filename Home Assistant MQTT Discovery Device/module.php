<?php /** @noinspection PhpUnused */

/** @noinspection AutoloadingIssuesInspection */

declare(strict_types=1);

require_once __DIR__ . '/../libs/HACommonIncludes.php';

class HomeAssistantMQTTDiscoveryDevice extends IPSModuleStrict
{
    use ModuleDebugTrait;
    use HAIdentNamingTrait;
    use HAEntityVariableNamingTrait;
    use HALegacyVariableMigrationTrait;
    use HAMqttDiscoveryParentClientTrait;

    private HAMqttDiscoveryParser $parser;
    private HAMqttDiscoveryGrouping $grouping;

    private const string PROP_DEVICE_ID = 'DeviceID';
    private const string PROP_ENTITY_SELECTION = 'EntitySelection';
    private const string PROP_ENABLE_EXPERT_DEBUG = 'EnableExpertDebug';

    private const int STATUS_PARENT_INVALID = 201;
    private const int STATUS_DISCOVERY_CACHE_MISSING = 202;
    private const int STATUS_PARENT_INACTIVE = 203;
    private const int STATUS_DEVICE_ID_MISSING = 204;

    private const string ATTR_LAST_MQTT_MESSAGE = 'LastMQTTMessage';
    private const string ATTR_AVAILABILITY_STATE = 'AvailabilityState';
    private const string ATTR_RESOLVED_DEVICE_DEFINITION = 'ResolvedDeviceDefinition';
    private const string ATTR_STATE_WARNINGS = 'StateWarnings';
    private const string ATTR_TOPIC_PROCESSING_INDEX = 'TopicProcessingIndex';
    private const int ENTITY_POSITION_STEP = 10;
    private const int TRIGGER_RESET_VALUE = -1;
    private const string COVER_ACTION_SUFFIX = '_cover_action';
    private const string COVER_TILT_ACTION_SUFFIX = '_cover_tilt_action';
    private const int COVER_ACTION_POSITION_OFFSET = 5;
    private const int COVER_TILT_ACTION_POSITION_OFFSET = 6;

    /** @var string[] */
    private const array SUPPORTED_COMPONENTS = [
        HABinarySensorDefinitions::DOMAIN,
        HASensorDefinitions::DOMAIN,
        HAClimateDefinitions::DOMAIN,
        HANumberDefinitions::DOMAIN,
        HACoverDefinitions::DOMAIN,
        HASwitchDefinitions::DOMAIN,
        HASelectDefinitions::DOMAIN,
        HAButtonDefinitions::DOMAIN,
        HAEventDefinitions::DOMAIN,
        HALightDefinitions::DOMAIN
    ];

    /** @var string[] */
    private const array NUMERIC_SENSOR_DEVICE_CLASSES = [
        'battery',
        'current',
        'distance',
        'duration',
        'energy',
        'frequency',
        'humidity',
        'illuminance',
        'monetary',
        'power',
        'pressure',
        'signal_strength',
        'speed',
        'temperature',
        'voltage',
        'weight'
    ];

    public function Create(): void
    {
        parent::Create();

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);

        $this->RegisterPropertyString(self::PROP_DEVICE_ID, '');
        $this->RegisterPropertyString(self::PROP_ENTITY_SELECTION, '[]');
        $this->RegisterPropertyBoolean(self::PROP_ENABLE_EXPERT_DEBUG, false);

        $this->RegisterAttributeString(self::ATTR_LAST_MQTT_MESSAGE, '');
        $this->RegisterAttributeString(self::ATTR_AVAILABILITY_STATE, '{}');
        $this->RegisterAttributeString(self::ATTR_RESOLVED_DEVICE_DEFINITION, '{}');
        $this->RegisterAttributeString(self::ATTR_STATE_WARNINGS, '{}');
        $this->RegisterAttributeString(self::ATTR_TOPIC_PROCESSING_INDEX, '{}');
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if (($Message === IPS_KERNELMESSAGE) && (($Data[0] ?? null) === KR_READY)) {
            $this->ApplyChanges();
            return;
        }

        if ($Message === FM_CONNECT || $Message === FM_DISCONNECT || $Message === IM_CHANGESTATUS) {
            $this->ApplyChanges();
        }
    }

    public function ApplyChanges(): void
    {
        $startedAt = microtime(true);
        $this->logPerformanceMarker(__FUNCTION__, 'start', [
            'DeviceID' => $this->getConfiguredDeviceId()
        ]);
        parent::ApplyChanges();
        $this->syncParentStatusMessageRegistration();
        if (!$this->isKernelReady()) {
            $this->debugExpert('ApplyChanges', 'Kernel noch nicht bereit. Initialisierung wird bis KR_READY verschoben.', [], true);
            return;
        }

        if ($this->getConfiguredDeviceId() === '') {
            $deviceDefinition = $this->buildPropertyDeviceDefinition();
            $this->SetStatus(self::STATUS_DEVICE_ID_MISSING);
            $this->SetReceiveDataFilter('^$');
            $entities = $this->normalizeConfiguredEntities($deviceDefinition['entities'] ?? []);
            $this->writeTopicProcessingIndex($this->buildTopicProcessingIndex($entities));
            $this->writeStateWarnings([]);
            $this->updateDiagnosticsLabels($entities, []);
            $this->updateInstanceSummary($entities);
            $this->logPerformanceSample(__FUNCTION__, $startedAt, [
                'Result' => 'missing_device_id'
            ], true);
            return;
        }

        $parentState = $this->getDiscoveryParentRuntimeState();
        if ($parentState !== 'active') {
            $fallbackDefinition = $this->buildOfflineDeviceDefinition();
            $this->storeResolvedDeviceDefinitionIfUsable($fallbackDefinition);
            $this->SetStatus($parentState === 'inactive' ? self::STATUS_PARENT_INACTIVE : self::STATUS_PARENT_INVALID);
            $this->SetReceiveDataFilter('^$');
            $message = match ($parentState) {
                'missing' => 'Kein Parent verbunden',
                'inactive' => 'Home Assistant MQTT Discovery Splitter Parent ist nicht aktiv',
                default => 'Parent ist nicht Home Assistant MQTT Discovery Splitter'
            };
            $this->debugExpert('ApplyChanges', $message, $this->getCurrentParentDebugContext(), true);
            $fallbackEntities = $this->normalizeConfiguredEntities($fallbackDefinition['entities'] ?? []);
            $this->writeTopicProcessingIndex($this->buildTopicProcessingIndex($fallbackEntities));
            $this->pruneAvailabilityState($fallbackEntities);
            $this->writeStateWarnings([]);
            $this->updateDiagnosticsLabels($fallbackEntities, []);
            $this->updateInstanceSummary($fallbackEntities);
            $this->logPerformanceSample(__FUNCTION__, $startedAt, [
                'Result' => 'parent_' . $parentState,
                'DeviceID' => $this->getConfiguredDeviceId()
            ], true);
            return;
        }

        $this->logPerformanceMarker(__FUNCTION__, 'before_resolveRuntimeDeviceDefinition');
        $deviceDefinition = $this->resolveRuntimeDeviceDefinition();
        $this->logPerformanceMarker(__FUNCTION__, 'after_resolveRuntimeDeviceDefinition');
        $entities = $this->normalizeConfiguredEntities($deviceDefinition['entities'] ?? []);

        if ($entities === []) {
            $this->SetStatus(self::STATUS_DISCOVERY_CACHE_MISSING);
            $this->SetReceiveDataFilter('^$');
            $this->debugExpert('ApplyChanges', 'Keine MQTT Discovery Entities konfiguriert', [
                'DeviceID' => (string)($deviceDefinition['device_id'] ?? $this->ReadPropertyString(self::PROP_DEVICE_ID)),
                'Source' => (string)($deviceDefinition['source'] ?? 'unknown')
            ], true);
            $this->writeTopicProcessingIndex($this->buildTopicProcessingIndex($entities));
            $this->writeStateWarnings([]);
            $this->updateDiagnosticsLabels($entities, []);
            $this->updateInstanceSummary($entities);
            $this->logPerformanceSample(__FUNCTION__, $startedAt, [
                'Result' => 'no_entities',
                'DeviceID' => (string)($deviceDefinition['device_id'] ?? $this->ReadPropertyString(self::PROP_DEVICE_ID)),
                'Source' => (string)($deviceDefinition['source'] ?? 'unknown')
            ], true);
            return;
        }

        $this->storeResolvedDeviceDefinitionIfUsable($deviceDefinition);
        $this->pruneAvailabilityState($entities);
        $cachedTopics = $this->loadCachedTopicPayloads($entities);
        $warningMap = $this->synchronizeStateWarnings($entities, $cachedTopics);
        $activeIdents = $this->maintainEntityVariables($entities, $cachedTopics);
        $this->cleanupObsoleteVariables($activeIdents);

        $topics = $this->collectRelevantTopics($entities);
        $this->writeTopicProcessingIndex($this->buildTopicProcessingIndex($entities));
        $this->updateReceiveFilter($topics);
        $this->applyCachedTopicPayloads($entities, $cachedTopics);

        $this->SetSummary($this->ReadPropertyString(self::PROP_DEVICE_ID));
        $this->SetStatus(IS_ACTIVE);
        $this->updateDiagnosticsLabels($entities, $topics, $warningMap);
        $this->updateInstanceSummary($entities);
        $this->logPerformanceSample(__FUNCTION__, $startedAt, [
            'Result' => 'active',
            'DeviceID' => $this->ReadPropertyString(self::PROP_DEVICE_ID),
            'EntityCount' => count($entities),
            'TopicCount' => count($topics)
        ], true);
    }

    public function ReceiveData(string $JSONString): string
    {
        try {
            $data = json_decode($JSONString, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->debugExpert(__FUNCTION__, 'Invalid JSON', ['Error' => $e->getMessage()]);
            return '';
        }

        $topic = trim((string)($data['Topic'] ?? ''), '/');
        if ($topic === '') {
            return '';
        }

        $payload = hex2bin((string)($data['Payload'] ?? ''));
        if ($payload === false) {
            $payload = '';
        }

        $this->WriteAttributeString(self::ATTR_LAST_MQTT_MESSAGE, date('Y-m-d H:i:s'));

        $entities = $this->getConfiguredEntities();
        $entityLookup = $this->buildEntityLookup($entities);
        $topicIndex = $this->readTopicProcessingIndex();
        $result = $this->applyTopicPayloadToEntities($entityLookup, $topicIndex, $topic, $payload);
        if ($result['diagnostics_changed']) {
            $this->updateDiagnosticsLabels($entities, $topicIndex['topics']);
            $this->updateInstanceSummary($entities);
        }
        return '';
    }

    public function RequestAction($Ident, $Value): void
    {
        $entities = $this->getConfiguredEntities();
        $target = $this->resolveActionTarget($entities, (string) $Ident);
        if ($target === null) {
            $this->debugExpert(__FUNCTION__, 'Entity fuer Ident nicht gefunden', ['Ident' => $Ident], true);
            return;
        }

        if ($target['type'] === 'cover_action') {
            $this->handleCoverActionRequest($Ident, $target['entity'], $Value, false);
            return;
        }

        if ($target['type'] === 'cover_tilt_action') {
            $this->handleCoverActionRequest($Ident, $target['entity'], $Value, true);
            return;
        }

        if ($target['type'] === 'light_attribute') {
            $entity = $target['entity'];
            $attribute = $target['attribute'];
            $context = $this->buildLightAttributeContext($entity, []);
            if (!$this->isLightAttributeWritable($attribute, $context)) {
                $this->debugExpert(__FUNCTION__, 'Light-Attribut ist nicht schreibbar', [
                    'Ident' => $Ident,
                    'EntityKey' => $entity['entity_key'],
                    'Attribute' => $attribute
                ], true);
                return;
            }

            $payload = HAMqttDiscoveryLightRuntime::buildAttributeCommandPayload($attribute, $Value);
            if ($payload === null) {
                $this->debugExpert(__FUNCTION__, 'Light-Attributpayload konnte nicht erstellt werden', [
                    'Ident' => $Ident,
                    'EntityKey' => $entity['entity_key'],
                    'Attribute' => $attribute,
                    'Value' => $Value
                ], true);
                return;
            }

            $this->debugExpert(__FUNCTION__, 'Sende Light-Attribut', [
                'Ident' => $Ident,
                'EntityKey' => $entity['entity_key'],
                'Attribute' => $attribute,
                'CommandTopic' => $entity['command_topic'],
                'Payload' => $payload,
                'QoS' => (int)$entity['qos'],
                'Retain' => (bool)$entity['retain']
            ]);

            $this->sendMqttMessage(
                $entity['command_topic'],
                $payload,
                (int)$entity['qos'],
                (bool)$entity['retain']
            );

            return;
        }

        $entity = $target['entity'];
        if (!$this->isEntityWritable($entity)) {
            $this->debugExpert(__FUNCTION__, 'Entity ist nicht schreibbar', ['Ident' => $Ident, 'EntityKey' => $entity['entity_key']], true);
            return;
        }

        $payload = $this->buildCommandPayload($entity, $Value);
        if ($payload === null) {
            $this->debugExpert(__FUNCTION__, 'Command payload konnte nicht erstellt werden', ['Ident' => $Ident, 'Value' => $Value], true);
            return;
        }

        $this->debugExpert(__FUNCTION__, 'Sende Command', [
            'Ident' => $Ident,
            'EntityKey' => $entity['entity_key'],
            'CommandTopic' => $entity['command_topic'],
            'Payload' => $payload,
            'QoS' => (int)$entity['qos'],
            'Retain' => (bool)$entity['retain']
        ]);

        $this->sendMqttMessage(
            $entity['command_topic'],
            $payload,
            (int)$entity['qos'],
            (bool)$entity['retain']
        );

        if ((bool)$entity['optimistic'] || $entity['state_topic'] === '') {
            $this->applyOptimisticValue($entity, $Value);
        }
    }

    /** @noinspection PhpUnused */
    public function SelectAllEntities(): void
    {
        $this->persistEntitySelection(static fn(array $entity): bool => true);
    }

    /** @noinspection PhpUnused */
    public function DeselectAllEntities(): void
    {
        $this->persistEntitySelection(static fn(array $entity): bool => false);
    }

    /** @noinspection PhpUnused */
    public function ResetEntitySelectionToDefaults(): void
    {
        $this->persistEntitySelection(static fn(array $entity): bool => (bool)($entity['create_var'] ?? true), true);
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true, 512, JSON_THROW_ON_ERROR);
        $deviceDefinition = $this->resolveRuntimeDeviceDefinition();
        $entities = $this->normalizeConfiguredEntities($deviceDefinition['entities'] ?? []);
        $cachedTopics = $this->loadCachedTopicPayloads($entities);
        $warningMap = $this->synchronizeStateWarnings($entities, $cachedTopics);
        $this->applyResolvedDeviceInformationToForm($form, $deviceDefinition);
        $this->applyCurrentDiagnosticsToForm($form, $entities, $warningMap);
        $this->applyEntitySelectionToForm($form, $entities, $cachedTopics, $warningMap);
        $runtimeState = $this->determineRuntimeState($entities);

        if ($runtimeState['message'] !== '') {
            $form['actions'][] = [
                'type' => 'Label',
                'caption' => $runtimeState['message']
            ];
        }

        return json_encode($form, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function getConfiguredEntities(): array
    {
        $deviceDefinition = $this->readResolvedDeviceDefinition();
        return $this->normalizeConfiguredEntities($deviceDefinition['entities'] ?? []);
    }

    private function normalizeConfiguredEntities(array $rows): array
    {
        try {
            $rows = is_array($rows) ? $rows : [];
        } catch (Throwable) {
            $rows = [];
        }
        $entities = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $entity = $this->normalizeEntityConfig($row);
            if ($entity === null) {
                continue;
            }

            $entities[] = $entity;
        }

        return $this->applySharedEntityIdents($this->applyEntitySelectionOverrides($entities));
    }

    private function resolveRuntimeDeviceDefinition(): array
    {
        $startedAt = microtime(true);
        $fallback = $this->buildOfflineDeviceDefinition();
        $deviceId = (string)($fallback['device_id'] ?? '');
        if ($deviceId === '') {
            $this->logPerformanceSample(__FUNCTION__, $startedAt, [
                'Result' => 'fallback_empty_device_id'
            ], true);
            return $fallback;
        }

        if ($this->getDiscoveryParentRuntimeState() !== 'active') {
            $this->logPerformanceSample(__FUNCTION__, $startedAt, [
                'Result' => 'fallback_parent_inactive',
                'DeviceID' => $deviceId
            ], true);
            return $fallback;
        }

        $response = $this->sendDiscoveryRequestToParent('GetDiscoveryConfigs');
        if ($response === null) {
            $this->logPerformanceSample(__FUNCTION__, $startedAt, [
                'Result' => 'fallback_no_response',
                'DeviceID' => $deviceId
            ], true);
            return $fallback;
        }

        $records = $response['Items'] ?? [];
        if (!is_array($records) || $records === []) {
            $this->logPerformanceSample(__FUNCTION__, $startedAt, [
                'Result' => 'fallback_no_records',
                'DeviceID' => $deviceId
            ], true);
            return $fallback;
        }

        $this->ensureHelpers();
        $entities = $this->parser->parseConfigMessages($records);
        $groups = $this->grouping->groupEntitiesToDevices($entities);
        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }

            if ((string)($group['device_id'] ?? '') !== $deviceId) {
                continue;
            }

            $resolved = $this->grouping->buildDeviceConfig($group);
            $resolved['source'] = 'parent';
            $this->debugExpert(__FUNCTION__, 'Resolved discovery device from parent', [
                'DeviceID' => $deviceId,
                'EntityCount' => count(is_array($resolved['entities'] ?? null) ? $resolved['entities'] : [])
            ]);
            $this->logPerformanceSample(__FUNCTION__, $startedAt, [
                'Result' => 'resolved_parent',
                'DeviceID' => $deviceId,
                'RecordCount' => count($records),
                'EntityCount' => count(is_array($resolved['entities'] ?? null) ? $resolved['entities'] : [])
            ], true);
            return $resolved;
        }

        $this->logPerformanceSample(__FUNCTION__, $startedAt, [
            'Result' => 'fallback_no_matching_group',
            'DeviceID' => $deviceId,
            'RecordCount' => count($records)
        ], true);
        return $fallback;
    }

    private function buildOfflineDeviceDefinition(): array
    {
        $cachedDefinition = $this->readCachedResolvedDeviceDefinition();
        if ($cachedDefinition !== null) {
            return $cachedDefinition;
        }

        return $this->buildPropertyDeviceDefinition();
    }

    private function buildPropertyDeviceDefinition(): array
    {
        return [
            'device_id' => $this->getConfiguredDeviceId(),
            'device_name' => '',
            'manufacturer' => '',
            'model' => '',
            'entities' => [],
            'source' => 'property'
        ];
    }

    private function storeResolvedDeviceDefinition(array $deviceDefinition): void
    {
        $this->WriteAttributeString(
            self::ATTR_RESOLVED_DEVICE_DEFINITION,
            json_encode($deviceDefinition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    private function storeResolvedDeviceDefinitionIfUsable(array $deviceDefinition): void
    {
        $entities = $deviceDefinition['entities'] ?? null;
        if (($deviceDefinition['source'] ?? 'property') === 'property' && (!is_array($entities) || $entities === [])) {
            return;
        }

        $this->storeResolvedDeviceDefinition($deviceDefinition);
    }

    private function readResolvedDeviceDefinition(): array
    {
        $decoded = $this->readCachedResolvedDeviceDefinition();
        if ($decoded !== null) {
            return $decoded;
        }

        return $this->buildPropertyDeviceDefinition();
    }

    private function readCachedResolvedDeviceDefinition(): ?array
    {
        try {
            $decoded = json_decode($this->ReadAttributeString(self::ATTR_RESOLVED_DEVICE_DEFINITION), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($decoded) || !isset($decoded['entities']) || !is_array($decoded['entities'])) {
            return null;
        }

        if (!$this->isDefinitionUsableForConfiguredDeviceId((string)($decoded['device_id'] ?? ''))) {
            return null;
        }

        return $decoded;
    }

    private function isDefinitionUsableForConfiguredDeviceId(string $deviceId): bool
    {
        $configuredDeviceId = $this->getConfiguredDeviceId();
        if ($configuredDeviceId === '' || $deviceId === '') {
            return false;
        }

        return $configuredDeviceId === trim($deviceId);
    }

    private function applyResolvedDeviceInformationToForm(array &$form, array $deviceDefinition): void
    {
        foreach ($form['elements'] as &$element) {
            if (!isset($element['items']) || !is_array($element['items'])) {
                continue;
            }

            foreach ($element['items'] as &$item) {
                $name = (string)($item['name'] ?? '');
                if ($name === self::PROP_DEVICE_ID) {
                    $item['readOnly'] = false;
                    $item['value'] = (string)($deviceDefinition['device_id'] ?? $this->ReadPropertyString(self::PROP_DEVICE_ID));
                    continue;
                }

                if ($name === 'ResolvedDeviceName') {
                    $item['caption'] = 'Device Name: ' . (string)($deviceDefinition['device_name'] ?? '');
                    continue;
                }

                if ($name === 'ResolvedManufacturer') {
                    $item['caption'] = 'Manufacturer: ' . (string)($deviceDefinition['manufacturer'] ?? '');
                    continue;
                }

                if ($name === 'ResolvedModel') {
                    $item['caption'] = 'Model: ' . (string)($deviceDefinition['model'] ?? '');
                }
            }
            unset($item);
        }
        unset($element);
    }

    private function applyEntitySelectionToForm(array &$form, array $entities, array $cachedTopics, array $warningMap): void
    {
        foreach ($form['elements'] as &$element) {
            $name = (string)($element['name'] ?? '');
            if ($name !== self::PROP_ENTITY_SELECTION) {
                continue;
            }

            $element['visible'] = $entities !== [];
            $element['values'] = $this->buildEntitySelectionValues($entities, $cachedTopics, $warningMap);
            return;
        }
        unset($element);
    }

    private function applyCurrentDiagnosticsToForm(array &$form, array $entities, array $warningMap): void
    {
        $lastMqtt = $this->ReadAttributeString(self::ATTR_LAST_MQTT_MESSAGE);
        if ($lastMqtt === '') {
            $lastMqtt = 'nie';
        }

        $topics = $this->collectRelevantTopics($entities);
        $activeEntityCount = count(array_filter($entities, static fn(array $entity): bool => (bool)$entity['create_var']));
        $runtimeState = $this->determineRuntimeState($entities);
        $captions = [
            'DiagLastMQTT' => 'Letzte MQTT-Message: ' . $lastMqtt,
            'DiagTopics' => 'Topics: ' . count($topics),
            'DiagEntities' => 'Entities (aktiv/gesamt): ' . $activeEntityCount . '/' . count($entities),
            'DiagResolution' => 'Auflösung: ' . $runtimeState['resolution'],
            'DiagAvailability' => 'Availability: ' . $this->buildAvailabilitySummary($entities),
            'DiagWarnings' => 'Warnungen: ' . $this->buildWarningSummary($warningMap)
        ];

        foreach ($form['actions'] as &$action) {
            if (!isset($action['items']) || !is_array($action['items'])) {
                continue;
            }

            foreach ($action['items'] as &$item) {
                $name = (string)($item['name'] ?? '');
                if ($name === '' || !array_key_exists($name, $captions)) {
                    continue;
                }
                $item['caption'] = $captions[$name];
            }
            unset($item);
        }
        unset($action);
    }

    private function ensureHelpers(): void
    {
        if (!isset($this->parser)) {
            $this->parser = new HAMqttDiscoveryParser();
        }
        if (!isset($this->grouping)) {
            $this->grouping = new HAMqttDiscoveryGrouping();
        }
    }

    private function normalizeEntityConfig(array $row): ?array
    {
        $component = $this->normalizeNullableString($row['component'] ?? null);
        if ($component === null || !in_array($component, self::SUPPORTED_COMPONENTS, true)) {
            return null;
        }

        $entityKey = $this->normalizeNullableString($row['entity_key'] ?? null);
        $objectId = $this->normalizeNullableString($row['object_id'] ?? null);
        if ($objectId === null) {
            return null;
        }
        $deviceName = $this->normalizeNullableString($row['device_name'] ?? null) ?? '';
        $localObjectId = $this->buildLocalObjectId($objectId, $deviceName);
        $resolvedName = $this->normalizeNullableString($row['name'] ?? null) ?? '';

        $metadata = $this->normalizeMetadata($row['metadata'] ?? null);
        $createVar = array_key_exists('create_var', $row)
            ? (bool)$row['create_var']
            : (bool)($metadata['enabled_by_default'] ?? true);

        return [
            'entity_key' => $entityKey ?? ($component . '.' . $objectId),
            'entity_id' => $component . '.' . $objectId,
            'component' => $component,
            'object_id' => $objectId,
            'device_name' => $deviceName,
            'name' => $resolvedName,
            'ident' => $this->buildEntityIdent($component, $localObjectId !== '' ? $localObjectId : $objectId),
            'create_var' => $createVar,
            'state_topic' => $this->normalizeNullableString($row['state_topic'] ?? null) ?? '',
            'command_topic' => $this->normalizeNullableString($row['command_topic'] ?? null) ?? '',
            'json_attributes_topic' => $this->normalizeNullableString($row['json_attributes_topic'] ?? null) ?? '',
            'qos' => max(0, min(2, (int)($row['qos'] ?? 0))),
            'retain' => (bool)($row['retain'] ?? false),
            'optimistic' => (bool)($row['optimistic'] ?? false),
            'value_template' => $this->normalizeTemplate($row['value_template'] ?? null),
            'command_template' => $this->normalizeTemplate($row['command_template'] ?? null),
            'command_mode' => $this->normalizeNullableString($row['command_mode'] ?? null) ?? 'none',
            'availability' => $this->normalizeAvailability($row['availability'] ?? null),
            'payload_on' => $row['payload_on'] ?? null,
            'payload_off' => $row['payload_off'] ?? null,
            'payload_press' => $row['payload_press'] ?? null,
            'event_payload' => $this->normalizeNullableString($this->scalarToString($row['event_payload'] ?? null)),
            'state_on' => $row['state_on'] ?? null,
            'state_off' => $row['state_off'] ?? null,
            'options' => $this->normalizeOptions($row['options'] ?? null),
            'metadata' => $metadata
        ];
    }

    private function normalizeMetadata(mixed $metadata): array
    {
        if (is_string($metadata) && trim($metadata) !== '') {
            try {
                $metadata = json_decode($metadata, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $metadata = [];
            }
        }

        if (!is_array($metadata)) {
            $metadata = [];
        }

        return [
            'device_class' => $this->normalizeNullableString($metadata['device_class'] ?? null),
            'state_class' => $this->normalizeNullableString($metadata['state_class'] ?? null),
            'unit' => $this->normalizeNullableString($metadata['unit'] ?? null),
            'min' => $this->normalizeNumericMetadataValue($metadata['min'] ?? null),
            'max' => $this->normalizeNumericMetadataValue($metadata['max'] ?? null),
            'step' => $this->normalizeNumericMetadataValue($metadata['step'] ?? null),
            'native_min_value' => $this->normalizeNumericMetadataValue($metadata['native_min_value'] ?? null),
            'native_max_value' => $this->normalizeNumericMetadataValue($metadata['native_max_value'] ?? null),
            'native_step' => $this->normalizeNumericMetadataValue($metadata['native_step'] ?? null),
            'mode' => $this->normalizeNullableString($metadata['mode'] ?? null),
            'entity_category' => $this->normalizeNullableString($metadata['entity_category'] ?? null),
            'enabled_by_default' => !array_key_exists('enabled_by_default', $metadata) || (bool)$metadata['enabled_by_default'],
            'icon' => $this->normalizeNullableString($metadata['icon'] ?? null),
            'brightness' => (bool)($metadata['brightness'] ?? false),
            'brightness_scale' => is_numeric($metadata['brightness_scale'] ?? null) ? (int)$metadata['brightness_scale'] : null,
            'effect' => (bool)($metadata['effect'] ?? false),
            'effect_list' => HASelectDefinitions::normalizeOptions($metadata['effect_list'] ?? null),
            'supported_features' => is_numeric($metadata['supported_features'] ?? null) ? (int)$metadata['supported_features'] : 0,
            'supported_color_modes' => HASelectDefinitions::normalizeOptions($metadata['supported_color_modes'] ?? null),
            'min_mireds' => is_numeric($metadata['min_mireds'] ?? null) ? (int)$metadata['min_mireds'] : null,
            'max_mireds' => is_numeric($metadata['max_mireds'] ?? null) ? (int)$metadata['max_mireds'] : null,
            'min_color_temp_kelvin' => is_numeric($metadata['min_color_temp_kelvin'] ?? null) ? (int)$metadata['min_color_temp_kelvin'] : null,
            'max_color_temp_kelvin' => is_numeric($metadata['max_color_temp_kelvin'] ?? null) ? (int)$metadata['max_color_temp_kelvin'] : null,
            'schema' => $this->normalizeNullableString($metadata['schema'] ?? null),
            'reports_position' => $this->normalizeMetadataBoolean($metadata['reports_position'] ?? false),
            'state_open' => $this->normalizeNullableString($metadata['state_open'] ?? null),
            'state_closed' => $this->normalizeNullableString($metadata['state_closed'] ?? null),
            'state_opening' => $this->normalizeNullableString($metadata['state_opening'] ?? null),
            'state_closing' => $this->normalizeNullableString($metadata['state_closing'] ?? null),
            'state_stopped' => $this->normalizeNullableString($metadata['state_stopped'] ?? null),
            'action_topic' => $this->normalizeNullableString($metadata['action_topic'] ?? null),
            'action_command_template' => $this->normalizeNullableString($metadata['action_command_template'] ?? null),
            'tilt_action_topic' => $this->normalizeNullableString($metadata['tilt_action_topic'] ?? null),
            'tilt_action_command_template' => $this->normalizeNullableString($metadata['tilt_action_command_template'] ?? null),
            'origin' => is_array($metadata['origin'] ?? null) ? $metadata['origin'] : []
        ];
    }

    private function normalizeMetadataBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int)$value !== 0;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes', 'ja'], true);
        }

        return false;
    }

    private function normalizeNumericMetadataValue(mixed $value): ?float
    {
        if (is_string($value)) {
            $value = str_replace(',', '.', trim($value));
        }

        return is_numeric($value) ? (float)$value : null;
    }

    private function normalizeAvailability(mixed $availability): array
    {
        if (is_string($availability) && trim($availability) !== '') {
            try {
                $availability = json_decode($availability, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $availability = [];
            }
        }

        if (!is_array($availability)) {
            return [
                'mode' => 'latest',
                'entries' => []
            ];
        }

        $mode = $this->normalizeNullableString($availability['mode'] ?? null);
        $entries = [];
        $rawEntries = $availability['entries'] ?? [];
        if (is_array($rawEntries)) {
            foreach ($rawEntries as $entry) {
                if (is_string($entry) && trim($entry) !== '') {
                    try {
                        $entry = json_decode($entry, true, 512, JSON_THROW_ON_ERROR);
                    } catch (JsonException) {
                        $entry = [];
                    }
                }
                if (!is_array($entry)) {
                    continue;
                }

                $topic = $this->normalizeNullableString($entry['topic'] ?? null);
                if ($topic === null) {
                    continue;
                }

                $entries[] = [
                    'topic' => trim($topic, '/'),
                    'value_template' => $this->normalizeTemplate($entry['value_template'] ?? null),
                    'payload_available' => $entry['payload_available'] ?? 'online',
                    'payload_not_available' => $entry['payload_not_available'] ?? 'offline'
                ];
            }
        }

        return [
            'mode' => in_array($mode, ['all', 'any', 'latest'], true) ? $mode : 'latest',
            'entries' => $entries
        ];
    }

    private function normalizeTemplate(mixed $template): ?array
    {
        if (is_string($template) && trim($template) !== '') {
            try {
                $template = json_decode($template, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return null;
            }
        }

        return is_array($template) ? $template : null;
    }

    private function normalizeOptions(mixed $options): array
    {
        if (is_string($options) && trim($options) !== '') {
            try {
                $options = json_decode($options, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $options = [];
            }
        }

        if (!is_array($options)) {
            return [];
        }

        $result = [];
        foreach ($options as $option) {
            $option = $this->normalizeNullableString($option);
            if ($option === null) {
                continue;
            }
            $result[] = $option;
        }

        return $result;
    }

    private function applyEntitySelectionOverrides(array $entities): array
    {
        $selectionMap = $this->readEntitySelectionMap();
        if ($selectionMap === []) {
            return $entities;
        }

        foreach ($entities as &$entity) {
            $entityKey = (string)($entity['entity_key'] ?? '');
            if ($entityKey === '' || !array_key_exists($entityKey, $selectionMap)) {
                continue;
            }

            $entity['create_var'] = (bool)$selectionMap[$entityKey];
        }
        unset($entity);

        return $entities;
    }

    private function readEntitySelectionMap(): array
    {
        try {
            $rows = json_decode($this->ReadPropertyString(self::PROP_ENTITY_SELECTION), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (!is_array($rows)) {
            return [];
        }

        $selectionMap = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $entityKey = $this->normalizeNullableString($row['entity_key'] ?? null);
            if ($entityKey === null) {
                continue;
            }

            $selectionMap[$entityKey] = (bool)($row['create_var'] ?? false);
        }

        return $selectionMap;
    }

    private function determineRuntimeState(array $entities): array
    {
        if ($this->getConfiguredDeviceId() === '') {
            return [
                'status' => self::STATUS_DEVICE_ID_MISSING,
                'message' => 'Keine Discovery DeviceID konfiguriert.',
                'resolution' => 'keine DeviceID konfiguriert'
            ];
        }

        $parentState = $this->getDiscoveryParentRuntimeState();
        if ($parentState === 'missing') {
            return [
                'status' => self::STATUS_PARENT_INVALID,
                'message' => 'Kein Parent verbunden.',
                'resolution' => 'kein Parent verbunden'
            ];
        }

        if ($parentState === 'invalid') {
            return [
                'status' => self::STATUS_PARENT_INVALID,
                'message' => 'Parent ist nicht Home Assistant MQTT Discovery Splitter.',
                'resolution' => 'kein kompatibler Parent'
            ];
        }

        if ($parentState === 'inactive') {
            return [
                'status' => self::STATUS_PARENT_INACTIVE,
                'message' => 'Home Assistant MQTT Discovery Splitter Parent ist nicht aktiv.',
                'resolution' => $entities === [] ? 'Parent inaktiv' : 'gecachter Stand, Parent inaktiv'
            ];
        }

        if ($entities === []) {
            return [
                'status' => self::STATUS_DISCOVERY_CACHE_MISSING,
                'message' => 'Keine Discovery-Infos fuer diese DeviceID im Splitter-Cache gefunden.',
                'resolution' => 'keine Discovery-Infos fuer DeviceID im Cache'
            ];
        }

        return [
            'status' => IS_ACTIVE,
            'message' => '',
            'resolution' => 'aus MQTT Discovery Splitter aufgeloest'
        ];
    }

    private function persistEntitySelection(callable $selector, bool $resetToDefaults = false): void
    {
        $deviceDefinition = $this->resolveRuntimeDeviceDefinition();
        $entities = $resetToDefaults
            ? $this->normalizeResolvedEntitiesWithoutSelection($deviceDefinition['entities'] ?? [])
            : $this->normalizeConfiguredEntities($deviceDefinition['entities'] ?? []);

        $rows = [];
        foreach ($entities as $entity) {
            $rows[] = [
                'entity_key' => (string)$entity['entity_key'],
                'create_var' => (bool)$selector($entity)
            ];
        }

        IPS_SetProperty(
            $this->InstanceID,
            self::PROP_ENTITY_SELECTION,
            json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        IPS_ApplyChanges($this->InstanceID);
    }

    private function normalizeResolvedEntitiesWithoutSelection(array $rows): array
    {
        $rows = is_array($rows) ? $rows : [];
        $entities = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $entity = $this->normalizeEntityConfig($row);
            if ($entity === null) {
                continue;
            }

            $entities[] = $entity;
        }

        return $entities;
    }

    private function buildEntitySelectionValues(array $entities, array $cachedTopics, array $warningMap): array
    {
        $values = [];
        foreach ($entities as $entity) {
            $values[] = [
                'entity_key' => (string)$entity['entity_key'],
                'create_var' => (bool)$entity['create_var'],
                'component' => (string)$entity['component'],
                'name' => (string)$entity['name'],
                'state_topic' => (string)$entity['state_topic'],
                'command_topic' => (string)$entity['command_topic'],
                'availability' => $this->buildEntitySelectionAvailabilitySummary($entity),
                'cache' => $this->buildEntitySelectionCacheSummary($entity, $cachedTopics),
                'mapping' => $this->buildEntitySelectionMappingSummary($entity),
                'warning' => (string)($warningMap[(string)$entity['entity_key']] ?? ''),
                'mode' => $this->isEntityWritable($entity) ? 'rw' : 'ro'
            ];
        }

        return $values;
    }

    private function maintainEntityVariables(array $entities, array $cachedTopics): array
    {
        $idents = [];
        $entityOrder = 0;
        $hasMultipleStatusEntities = $this->countActiveStatusEntities($entities) > 1;
        foreach ($entities as $entity) {
            if (!(bool)$entity['create_var']) {
                continue;
            }

            $entityOrder++;
            $ident = $entity['ident'];
            $idents[] = $ident;
            $basePosition = $entityOrder * self::ENTITY_POSITION_STEP;

            $variableType = $this->determineVariableType($entity, $cachedTopics);
            $this->recreateVariableIfTypeChanged($ident, $variableType);
            $presentation = $this->buildVariablePresentation($entity, $variableType);
            $this->MaintainVariable($ident, $this->getEntityVariableName($entity, $hasMultipleStatusEntities), $variableType, $presentation, $basePosition, true);

            $variableId = @$this->GetIDForIdent($ident);
            if ($variableId === false) {
                continue;
            }

            if ($this->isEntityWritable($entity)) {
                $this->EnableAction($ident);
            } else {
                IPS_SetVariableCustomAction($variableId, 0);
            }

            if ((string)($entity['component'] ?? '') === HALightDefinitions::DOMAIN) {
                $lightAttributes = $this->extractLightAttributesFromCachedTopics($entity, $cachedTopics);
                $attributeContext = $this->buildLightAttributeContext($entity, $lightAttributes);
                $idents = array_merge($idents, $this->maintainLightAttributeVariables($entity, $attributeContext, $basePosition));
                continue;
            }

            if ((string)($entity['component'] ?? '') === HACoverDefinitions::DOMAIN) {
                $idents = array_merge($idents, $this->maintainCoverActionVariables($entity, $basePosition));
            }
        }

        return $idents;
    }

    private function determineVariableType(array $entity, array $cachedTopics): int
    {
        return match ($entity['component']) {
            HABinarySensorDefinitions::DOMAIN, HASwitchDefinitions::DOMAIN, HALightDefinitions::DOMAIN => VARIABLETYPE_BOOLEAN,
            HASelectDefinitions::DOMAIN => VARIABLETYPE_INTEGER,
            HAButtonDefinitions::DOMAIN, HAEventDefinitions::DOMAIN => VARIABLETYPE_INTEGER,
            HAClimateDefinitions::DOMAIN => HAClimateDefinitions::VARIABLE_TYPE,
            HACoverDefinitions::DOMAIN => $this->determineCoverVariableType($entity, $cachedTopics),
            HANumberDefinitions::DOMAIN => $this->determineNumberVariableType($entity, $cachedTopics),
            HASensorDefinitions::DOMAIN => $this->determineSensorVariableType($entity, $cachedTopics),
            default => VARIABLETYPE_STRING
        };
    }

    private function determineCoverVariableType(array $entity, array $cachedTopics): int
    {
        $metadata = is_array($entity['metadata'] ?? null) ? $entity['metadata'] : [];
        $value = $this->extractCachedEntityValue($entity, $cachedTopics);
        return $this->isCoverPositionEntity($metadata, $value) ? VARIABLETYPE_FLOAT : VARIABLETYPE_STRING;
    }

    private function determineNumberVariableType(array $entity, array $cachedTopics): int
    {
        $metadata = is_array($entity['metadata'] ?? null) ? $entity['metadata'] : [];
        return $this->inferNumberVariableType($metadata, $this->extractCachedEntityValue($entity, $cachedTopics));
    }

    private function determineSensorVariableType(array $entity, array $cachedTopics): int
    {
        $value = $this->extractCachedEntityValue($entity, $cachedTopics);
        if (is_int($value)) {
            return VARIABLETYPE_INTEGER;
        }
        if (is_float($value)) {
            return VARIABLETYPE_FLOAT;
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed !== '' && is_numeric($trimmed)) {
                return str_contains($trimmed, '.') ? VARIABLETYPE_FLOAT : VARIABLETYPE_INTEGER;
            }
        }

        $unit = $entity['metadata']['unit'] ?? null;
        $stateClass = $entity['metadata']['state_class'] ?? null;
        $deviceClass = $entity['metadata']['device_class'] ?? null;
        if ($unit !== null || $stateClass !== null) {
            return VARIABLETYPE_FLOAT;
        }
        if (is_string($deviceClass) && in_array($deviceClass, self::NUMERIC_SENSOR_DEVICE_CLASSES, true)) {
            return VARIABLETYPE_INTEGER;
        }

        return VARIABLETYPE_STRING;
    }

    private function extractCachedEntityValue(array $entity, array $cachedTopics): mixed
    {
        $topic = $entity['state_topic'];
        if ($topic === '' || !isset($cachedTopics[$topic])) {
            return null;
        }

        return $this->extractStateValue($entity, (string)$cachedTopics[$topic]['payload']);
    }

    private function buildVariablePresentation(array $entity, int $variableType): array|string
    {
        if ((string)($entity['component'] ?? '') === HAButtonDefinitions::DOMAIN) {
            return $this->buildButtonPresentation($entity);
        }

        if ((string)($entity['component'] ?? '') === HAEventDefinitions::DOMAIN) {
            return ['PRESENTATION' => HAEventDefinitions::PRESENTATION];
        }

        if ((string)($entity['component'] ?? '') === HASelectDefinitions::DOMAIN) {
            return $this->buildSelectPresentation($entity['options'] ?? []);
        }

        if ((string)($entity['component'] ?? '') === HASwitchDefinitions::DOMAIN && $variableType === VARIABLETYPE_BOOLEAN) {
            return ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH];
        }

        if ((string)($entity['component'] ?? '') === HALightDefinitions::DOMAIN && $variableType === VARIABLETYPE_BOOLEAN) {
            return ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH];
        }

        if ((string)($entity['component'] ?? '') === HANumberDefinitions::DOMAIN) {
            return $this->buildNumberPresentation(is_array($entity['metadata'] ?? null) ? $entity['metadata'] : [], $variableType);
        }

        if ((string)($entity['component'] ?? '') === HACoverDefinitions::DOMAIN) {
            return $this->buildCoverPresentation(is_array($entity['metadata'] ?? null) ? $entity['metadata'] : [], $variableType);
        }

        if ((string)($entity['component'] ?? '') === HAClimateDefinitions::DOMAIN) {
            return $this->buildClimatePresentation(is_array($entity['metadata'] ?? null) ? $entity['metadata'] : [], $variableType);
        }

        if ($variableType === VARIABLETYPE_INTEGER || $variableType === VARIABLETYPE_FLOAT) {
            return $this->buildNumericPresentation($variableType, $entity);
        }

        return '';
    }

    private function buildButtonPresentation(array $entity): array
    {
        $caption = (string)($entity['name'] ?? 'Press');
        return [
            'PRESENTATION' => HAButtonDefinitions::PRESENTATION,
            'OPTIONS' => json_encode([[
                'Value' => HAButtonDefinitions::ACTION_PRESS,
                'Caption' => $caption,
                'IconActive' => false,
                'IconValue' => '',
                'Color' => -1
            ]], JSON_THROW_ON_ERROR)
        ];
    }

    private function buildSelectPresentation(array $options): array|string
    {
        if ($options === []) {
            return '';
        }

        $presentationOptions = [];
        foreach (array_values($options) as $index => $option) {
            $presentationOptions[] = [
                'Value' => $index,
                'Caption' => (string)$option,
                'IconActive' => false,
                'IconValue' => '',
                'Color' => -1
            ];
        }

        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'OPTIONS' => json_encode($presentationOptions, JSON_THROW_ON_ERROR)
        ];
    }

    private function buildNumericPresentation(int $variableType, array $entity): array|string
    {
        $unit = (string)($entity['metadata']['unit'] ?? '');
        $suffix = trim($unit);
        return array_filter([
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'DIGITS' => $variableType === VARIABLETYPE_FLOAT ? 2 : 0,
            'SUFFIX' => $suffix === '' ? null : ' ' . $suffix
        ], static fn(mixed $value): bool => $value !== null);
    }

    private function buildCoverPresentation(array $metadata, int $variableType): array
    {
        if ($variableType === VARIABLETYPE_FLOAT) {
            $deviceClass = trim((string)($metadata['device_class'] ?? ''));
            if ($deviceClass !== '' && HACoverDefinitions::usesShutterPresentation($deviceClass)) {
                return [
                    'CLOSE_INSIDE_VALUE' => 0,
                    'USAGE_TYPE' => 0,
                    'OPEN_OUTSIDE_VALUE' => 100,
                    'PRESENTATION' => VARIABLE_PRESENTATION_SHUTTER
                ];
            }

            return [
                'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                'MIN' => 0,
                'MAX' => 100,
                'STEP_SIZE' => 1,
                'DIGITS' => 1,
                'SUFFIX' => ' %'
            ];
        }

        $options = [];
        foreach (array_merge(HACoverDefinitions::STATE_OPTIONS, ['stopped' => 'Cover state: stopped']) as $value => $caption) {
            $options[] = [
                'Value' => $value,
                'Caption' => $this->Translate($caption),
                'IconActive' => false,
                'IconValue' => '',
                'Color' => -1
            ];
        }

        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'OPTIONS' => json_encode($options, JSON_THROW_ON_ERROR)
        ];
    }

    private function buildNumberPresentation(array $metadata, int $variableType): array|string
    {
        $min = $this->extractNumericMetadata($metadata, ['min', 'native_min_value']);
        $max = $this->extractNumericMetadata($metadata, ['max', 'native_max_value']);
        if ($min === null && $max !== null) {
            $min = 0.0;
        }
        $step = $this->extractNumericMetadata($metadata, ['step', 'native_step']) ?? 1.0;
        $suffix = $this->resolveNumberPresentationSuffix($metadata);
        $digits = $this->getNumberPresentationDigits($metadata, $step);

        if ($min !== null && $max !== null) {
            $usageType = null;
            $isPercentage = false;
            $displaySuffix = $suffix === '' ? null : ' ' . $suffix;
            if ($this->isNumberIntensityRange($min, $max) && $suffix === '' && $digits === 0) {
                $usageType = 2;
                $isPercentage = true;
                $displaySuffix = ' %';
            }

            return array_filter([
                'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                'MIN' => $min,
                'MAX' => $max,
                'STEP_SIZE' => $step,
                'DIGITS' => $digits,
                'PERCENTAGE' => $isPercentage,
                'USAGE_TYPE' => $usageType,
                'SUFFIX' => $displaySuffix
            ], static fn(mixed $value): bool => $value !== null);
        }

        return array_filter([
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'DIGITS' => $variableType === VARIABLETYPE_INTEGER ? 0 : $digits,
            'SUFFIX' => $suffix === '' ? null : ' ' . $suffix
        ], static fn(mixed $value): bool => $value !== null);
    }

    private function buildClimatePresentation(array $metadata, int $variableType): array|string
    {
        $min = $this->extractNumericMetadata($metadata, ['min', 'native_min_value']);
        $max = $this->extractNumericMetadata($metadata, ['max', 'native_max_value']);
        $step = $this->extractNumericMetadata($metadata, ['step', 'native_step']) ?? 1.0;
        $digits = $this->getNumberPresentationDigits($metadata, $step);
        $suffix = $this->resolveClimatePresentationSuffix($metadata);

        if ($min !== null && $max !== null) {
            return array_filter([
                'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                'MIN' => $min,
                'MAX' => $max,
                'STEP_SIZE' => $step,
                'DIGITS' => $digits,
                'USAGE_TYPE' => 1,
                'SUFFIX' => $suffix === '' ? null : ' ' . $suffix
            ], static fn(mixed $value): bool => $value !== null);
        }

        return array_filter([
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'DIGITS' => $variableType === VARIABLETYPE_FLOAT ? $digits : 0,
            'SUFFIX' => $suffix === '' ? null : ' ' . $suffix
        ], static fn(mixed $value): bool => $value !== null);
    }

    private function resolveClimatePresentationSuffix(array $metadata): string
    {
        $unit = trim((string)($metadata['unit'] ?? ''));
        if ($unit !== '') {
            return $unit;
        }

        return '°C';
    }

    private function resolveNumberPresentationSuffix(array $metadata): string
    {
        $unit = trim((string)($metadata['unit'] ?? ''));
        if ($unit !== '') {
            return $unit;
        }

        $deviceClass = trim((string)($metadata['device_class'] ?? ''));
        if ($deviceClass !== '' && isset(HANumberDefinitions::DEVICE_CLASS_SUFFIX[$deviceClass])) {
            return HANumberDefinitions::DEVICE_CLASS_SUFFIX[$deviceClass];
        }

        if ($deviceClass !== '' && isset(HASensorDefinitions::DEVICE_CLASS_SUFFIX[$deviceClass])) {
            return HASensorDefinitions::DEVICE_CLASS_SUFFIX[$deviceClass];
        }

        return '';
    }

    private function getNumberPresentationDigits(array $metadata, ?float $step = null, mixed $value = null): int
    {
        $digits = null;
        foreach ([$step, $metadata['step'] ?? null, $metadata['native_step'] ?? null, $value] as $candidate) {
            if ($candidate === null) {
                continue;
            }

            if (is_string($candidate)) {
                $candidate = str_replace(',', '.', trim($candidate));
            }

            if (!is_numeric($candidate)) {
                continue;
            }

            $number = (float)$candidate;
            if (abs($number - round($number)) < 0.0000001) {
                $digits = 0;
                continue;
            }

            $text = rtrim(rtrim(sprintf('%.6F', $number), '0'), '.');
            $decimalPos = strpos($text, '.');
            if ($decimalPos === false) {
                $digits = 0;
                continue;
            }

            $digits = strlen(substr($text, $decimalPos + 1));
            break;
        }

        return min(3, max(0, (int)($digits ?? 0)));
    }

    private function isNumberIntensityRange(float $min, float $max): bool
    {
        return abs($min - 0.0) < 0.0000001
            && (abs($max - 100.0) < 0.0000001 || abs($max - 255.0) < 0.0000001);
    }

    private function extractLightAttributesFromCachedTopics(array $entity, array $cachedTopics): array
    {
        $attributes = [];
        foreach ([$entity['state_topic'], $entity['json_attributes_topic']] as $topic) {
            $topic = trim((string) $topic, '/');
            if ($topic === '' || !isset($cachedTopics[$topic])) {
                continue;
            }

            $attributes = array_merge(
                $attributes,
                $this->extractLightAttributesFromPayload($entity, (string) ($cachedTopics[$topic]['payload'] ?? ''))
            );
        }

        return $attributes;
    }

    private function extractLightAttributesFromPayload(array $entity, string $payload): array
    {
        $rawValue = $this->extractRawPayloadValue($payload);
        return HAMqttDiscoveryLightRuntime::extractAttributes($rawValue, $entity['metadata'] ?? []);
    }

    private function buildLightAttributeContext(array $entity, array $runtimeAttributes): array
    {
        $metadata = is_array($entity['metadata'] ?? null) ? $entity['metadata'] : [];
        $context = array_merge($metadata, $runtimeAttributes);
        $context['effect_list'] = HASelectDefinitions::normalizeOptions($context['effect_list'] ?? null);
        $context['supported_color_modes'] = HASelectDefinitions::normalizeOptions($context['supported_color_modes'] ?? null);
        $context['supported_features'] = is_numeric($context['supported_features'] ?? null) ? (int) $context['supported_features'] : 0;
        return $context;
    }

    private function maintainLightAttributeVariables(array $entity, array $attributeContext, int $basePosition): array
    {
        $idents = [];
        foreach ($this->getOrderedLightAttributeNames() as $attribute) {
            if (!$this->shouldCreateLightAttribute($attribute, $attributeContext)) {
                continue;
            }

            $meta = HALightDefinitions::ATTRIBUTE_DEFINITIONS[$attribute] ?? null;
            if (!is_array($meta)) {
                continue;
            }

            $ident = $this->buildLightAttributeIdent((string) ($entity['ident_prefix'] ?? $entity['ident']), $attribute);
            $variableType = (int) ($meta['type'] ?? VARIABLETYPE_STRING);
            $this->recreateVariableIfTypeChanged($ident, $variableType);
            $presentation = $this->buildLightAttributePresentation($attribute, $attributeContext, $meta);
            $position = $basePosition + $this->getLightAttributePositionOffset($attribute);
            $this->MaintainVariable($ident, (string) ($meta['caption'] ?? $attribute), $variableType, $presentation, $position, true);

            $variableId = @$this->GetIDForIdent($ident);
            if ($variableId !== false) {
                if ($this->isLightAttributeWritable($attribute, $attributeContext)) {
                    $this->EnableAction($ident);
                } else {
                    IPS_SetVariableCustomAction($variableId, 0);
                }
            }

            $idents[] = $ident;
        }

        return $idents;
    }

    private function maintainCoverActionVariables(array $entity, int $basePosition): array
    {
        $metadata = is_array($entity['metadata'] ?? null) ? $entity['metadata'] : [];
        $idents = [];

        $actionOptions = $this->buildCoverActionOptions($metadata);
        if (($metadata['action_topic'] ?? '') !== '' && $actionOptions !== [] && $this->isCoverActionTemplateSupported($metadata, false)) {
            $ident = $this->buildCoverActionIdent((string)($entity['ident_prefix'] ?? $entity['ident']));
            $this->maintainEnumerationTriggerVariable(
                $ident,
                'Aktion',
                $basePosition + self::COVER_ACTION_POSITION_OFFSET,
                $actionOptions
            );
            $idents[] = $ident;
        }

        $tiltActionOptions = $this->buildCoverTiltActionOptions($metadata);
        if (($metadata['tilt_action_topic'] ?? '') !== '' && $tiltActionOptions !== [] && $this->isCoverActionTemplateSupported($metadata, true)) {
            $ident = $this->buildCoverTiltActionIdent((string)($entity['ident_prefix'] ?? $entity['ident']));
            $this->maintainEnumerationTriggerVariable(
                $ident,
                $this->Translate('Tilt Action'),
                $basePosition + self::COVER_TILT_ACTION_POSITION_OFFSET,
                $tiltActionOptions
            );
            $idents[] = $ident;
        }

        return $idents;
    }

    private function maintainEnumerationTriggerVariable(string $ident, string $caption, int $position, array $options): void
    {
        if ($options === []) {
            return;
        }

        $needsInitialization = @$this->GetIDForIdent($ident) === false;
        $variableId = @$this->GetIDForIdent($ident);
        if ($variableId !== false) {
            $existingType = (int)(IPS_GetVariable($variableId)['VariableType'] ?? -1);
            if ($existingType !== VARIABLETYPE_INTEGER) {
                IPS_DeleteVariable($variableId);
                $needsInitialization = true;
            }
        }

        $this->MaintainVariable($ident, $caption, VARIABLETYPE_INTEGER, [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'OPTIONS' => json_encode($options, JSON_THROW_ON_ERROR)
        ], $position, true);
        $this->EnableAction($ident);

        if ($needsInitialization) {
            $this->SetValue($ident, self::TRIGGER_RESET_VALUE);
        }
    }

    private function buildCoverActionOptions(array $metadata): array
    {
        $supported = $this->getSupportedFeatureFlags($metadata);
        $addAll = $supported === 0;
        $options = [];

        if ($addAll || $this->supportsFeatureFlag($supported, HACoverDefinitions::FEATURE_OPEN)) {
            $options[] = $this->buildEnumerationOption(HACoverDefinitions::ACTION_OPEN, $this->Translate('Open'));
        }
        if ($addAll || $this->supportsFeatureFlag($supported, HACoverDefinitions::FEATURE_CLOSE)) {
            $options[] = $this->buildEnumerationOption(HACoverDefinitions::ACTION_CLOSE, $this->Translate('Close'));
        }
        if ($addAll || $this->supportsFeatureFlag($supported, HACoverDefinitions::FEATURE_STOP)) {
            $options[] = $this->buildEnumerationOption(HACoverDefinitions::ACTION_STOP, $this->Translate('Stop'));
        }

        return $options;
    }

    private function buildCoverTiltActionOptions(array $metadata): array
    {
        $supported = $this->getSupportedFeatureFlags($metadata);
        $addAll = $supported === 0;
        $options = [];

        if ($addAll || $this->supportsFeatureFlag($supported, HACoverDefinitions::FEATURE_OPEN_TILT)) {
            $options[] = $this->buildEnumerationOption(HACoverDefinitions::ACTION_OPEN_TILT, $this->Translate('Open Tilt'));
        }
        if ($addAll || $this->supportsFeatureFlag($supported, HACoverDefinitions::FEATURE_CLOSE_TILT)) {
            $options[] = $this->buildEnumerationOption(HACoverDefinitions::ACTION_CLOSE_TILT, $this->Translate('Close Tilt'));
        }
        if ($addAll || $this->supportsFeatureFlag($supported, HACoverDefinitions::FEATURE_STOP_TILT)) {
            $options[] = $this->buildEnumerationOption(HACoverDefinitions::ACTION_STOP_TILT, $this->Translate('Stop Tilt'));
        }

        return $options;
    }

    private function buildEnumerationOption(int $value, string $caption): array
    {
        return [
            'Value' => $value,
            'Caption' => $caption,
            'IconActive' => false,
            'IconValue' => '',
            'Color' => -1
        ];
    }

    private function getSupportedFeatureFlags(array $metadata): int
    {
        return is_numeric($metadata['supported_features'] ?? null) ? (int)$metadata['supported_features'] : 0;
    }

    private function supportsFeatureFlag(int $supported, int $feature): bool
    {
        return ($supported & $feature) === $feature;
    }

    private function isCoverActionTemplateSupported(array $metadata, bool $tilt): bool
    {
        $templateKey = $tilt ? 'tilt_action_command_template' : 'action_command_template';
        $template = HAMqttDiscoveryTemplate::parseCommandTemplate($this->normalizeNullableString($metadata[$templateKey] ?? null));
        return $template === null || (bool)($template['supported'] ?? false);
    }

    private function applyLightRuntimeAttributes(array $entity, string $payload): void
    {
        $runtimeAttributes = $this->extractLightAttributesFromPayload($entity, $payload);
        if ($runtimeAttributes === []) {
            return;
        }

        $context = $this->buildLightAttributeContext($entity, $runtimeAttributes);
        $this->maintainLightAttributeVariables($entity, $context, $this->getLightEntityBasePosition($entity));

        foreach ($runtimeAttributes as $attribute => $value) {
            if (!isset(HALightDefinitions::ATTRIBUTE_DEFINITIONS[$attribute])) {
                continue;
            }

            $ident = $this->buildLightAttributeIdent((string) ($entity['ident_prefix'] ?? $entity['ident']), $attribute);
            if (@$this->GetIDForIdent($ident) === false) {
                continue;
            }

            $storedValue = HAMqttDiscoveryLightRuntime::formatAttributeValueForStorage($attribute, $value);
            if ($storedValue === null) {
                continue;
            }

            $this->SetValue($ident, $storedValue);
        }
    }

    private function shouldCreateLightAttribute(string $attribute, array $context): bool
    {
        if (array_key_exists($attribute, $context)) {
            return true;
        }

        $meta = HALightDefinitions::ATTRIBUTE_DEFINITIONS[$attribute] ?? null;
        if (!is_array($meta)) {
            return false;
        }

        if ($attribute === 'effect' && HASelectDefinitions::normalizeOptions($context['effect_list'] ?? null) !== []) {
            return true;
        }

        if ($attribute === 'color_mode') {
            return HASelectDefinitions::normalizeOptions($context['supported_color_modes'] ?? null) !== [];
        }

        return $this->checkLightAttributeFeatures($meta, $context) && $this->checkLightAttributeColorModes($meta, $context);
    }

    private function isLightAttributeWritable(string $attribute, array $context): bool
    {
        if (!array_key_exists($attribute, HAMqttDiscoveryLightRuntime::ATTRIBUTE_COMMANDS)) {
            return false;
        }

        $meta = HALightDefinitions::ATTRIBUTE_DEFINITIONS[$attribute] ?? null;
        if (!is_array($meta) || !($meta['writable'] ?? false)) {
            return false;
        }

        return $this->checkLightAttributeFeatures($meta, $context) && $this->checkLightAttributeColorModes($meta, $context);
    }

    private function checkLightAttributeFeatures(array $meta, array $context): bool
    {
        $requiredFeatures = $meta['requires_features'] ?? [];
        if (!is_array($requiredFeatures) || $requiredFeatures === []) {
            return true;
        }

        $supportedFeatures = is_numeric($context['supported_features'] ?? null) ? (int) $context['supported_features'] : 0;
        foreach ($requiredFeatures as $feature) {
            if (!is_numeric($feature) || (($supportedFeatures & (int) $feature) === 0)) {
                return false;
            }
        }

        return true;
    }

    private function checkLightAttributeColorModes(array $meta, array $context): bool
    {
        $requiredModes = $meta['requires_color_modes'] ?? [];
        if (!is_array($requiredModes) || $requiredModes === []) {
            return true;
        }

        $supportedModes = array_map('strtolower', HASelectDefinitions::normalizeOptions($context['supported_color_modes'] ?? null));
        if ($supportedModes === []) {
            return false;
        }

        foreach ($requiredModes as $mode) {
            if (in_array(strtolower((string) $mode), $supportedModes, true)) {
                return true;
            }
        }

        return false;
    }

    private function buildLightAttributePresentation(string $attribute, array $context, array $meta): array|string
    {
        if ($attribute === 'brightness') {
            return [
                'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                'MIN' => 0,
                'MAX' => 255,
                'STEP_SIZE' => 1,
                'PERCENTAGE' => true,
                'DIGITS' => 0,
                'SUFFIX' => ' %'
            ];
        }

        if ($attribute === 'color_temp' && is_numeric($context['min_mireds'] ?? null) && is_numeric($context['max_mireds'] ?? null)) {
            return [
                'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                'MIN' => (float) $context['min_mireds'],
                'MAX' => (float) $context['max_mireds'],
                'STEP_SIZE' => 1,
                'DIGITS' => 0,
                'SUFFIX' => ' mired'
            ];
        }

        if ($attribute === 'color_temp_kelvin' && is_numeric($context['min_color_temp_kelvin'] ?? null) && is_numeric($context['max_color_temp_kelvin'] ?? null)) {
            return [
                'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                'MIN' => (float) $context['min_color_temp_kelvin'],
                'MAX' => (float) $context['max_color_temp_kelvin'],
                'STEP_SIZE' => 1,
                'DIGITS' => 0,
                'SUFFIX' => ' K'
            ];
        }

        if ($attribute === 'effect') {
            $options = $this->buildLightStringValueOptions($context['effect_list'] ?? []);
            if ($options !== null) {
                return [
                    'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                    'OPTIONS' => $options
                ];
            }
        }

        if ($attribute === 'color_mode') {
            $modes = $context['supported_color_modes'] ?? [];
            $currentMode = $this->normalizeNullableString($context['color_mode'] ?? null);
            if ($currentMode !== null && !in_array($currentMode, $modes, true)) {
                $modes[] = $currentMode;
            }
            $options = $this->buildLightStringValueOptions($modes);
            if ($options !== null) {
                return [
                    'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                    'OPTIONS' => $options
                ];
            }
        }

        if ($attribute === 'flash') {
            return [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'OPTIONS' => $this->buildLightStringValueOptions(['short', 'long'])
            ];
        }

        $suffix = trim((string) ($meta['suffix'] ?? ''));
        return array_filter([
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX' => $suffix === '' ? null : ' ' . $suffix
        ], static fn(mixed $value): bool => $value !== null);
    }

    private function buildLightStringValueOptions(array $values): ?string
    {
        $options = [];
        foreach (array_values(array_filter($values, static fn(mixed $value): bool => is_string($value) && trim($value) !== '')) as $value) {
            $options[] = [
                'Value' => (string) $value,
                'Caption' => (string) $value,
                'IconActive' => false,
                'IconValue' => '',
                'Color' => -1
            ];
        }

        if ($options === []) {
            return null;
        }

        return json_encode($options, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function getOrderedLightAttributeNames(): array
    {
        $ordered = HALightDefinitions::ATTRIBUTE_ORDER;
        $remaining = array_diff(array_keys(HALightDefinitions::ATTRIBUTE_DEFINITIONS), $ordered);
        sort($remaining, SORT_STRING);
        return array_values(array_merge($ordered, $remaining));
    }

    private function getLightAttributePositionOffset(string $attribute): int
    {
        $ordered = $this->getOrderedLightAttributeNames();
        $index = array_search($attribute, $ordered, true);
        return ($index === false ? 90 : ((int) $index + 1));
    }

    private function getLightEntityBasePosition(array $entity): int
    {
        $variableId = @$this->GetIDForIdent((string) $entity['ident']);
        if ($variableId === false) {
            return self::ENTITY_POSITION_STEP;
        }

        return (int) (IPS_GetObject($variableId)['ObjectPosition'] ?? self::ENTITY_POSITION_STEP);
    }

    private function buildLightAttributeIdent(string $entityIdentPrefix, string $attribute): string
    {
        return $this->buildSharedAttributeIdentFromPrefix($entityIdentPrefix, $attribute);
    }

    private function buildCoverActionIdent(string $entityIdentPrefix): string
    {
        return $this->buildSharedSuffixIdentFromPrefix($entityIdentPrefix, self::COVER_ACTION_SUFFIX);
    }

    private function buildCoverTiltActionIdent(string $entityIdentPrefix): string
    {
        return $this->buildSharedSuffixIdentFromPrefix($entityIdentPrefix, self::COVER_TILT_ACTION_SUFFIX);
    }

    private function getEntityVariableName(array $entity, bool $hasMultipleStatusEntities): string
    {
        return $this->buildSharedEntityVariableName((string)($entity['component'] ?? ''), $entity, $hasMultipleStatusEntities);
    }

    private function countActiveStatusEntities(array $entities): int
    {
        $count = 0;
        foreach ($entities as $entity) {
            if (!(bool) ($entity['create_var'] ?? false)) {
                continue;
            }

            $component = (string) ($entity['component'] ?? '');
            if (HADomainCatalog::isStatusDomain($component)) {
                $count++;
            }
        }

        return $count;
    }

    private function recreateVariableIfTypeChanged(string $ident, int $variableType): void
    {
        $variableId = @$this->GetIDForIdent($ident);
        if ($variableId === false) {
            return;
        }

        $existingType = (int)(IPS_GetVariable($variableId)['VariableType'] ?? -1);
        if ($existingType === $variableType) {
            return;
        }

        IPS_DeleteVariable($variableId);
    }

    private function cleanupObsoleteVariables(array $activeIdents): void
    {
        $activeLookup = array_fill_keys($activeIdents, true);
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childId) {
            if (IPS_GetObject($childId)['ObjectType'] !== OBJECTTYPE_VARIABLE) {
                continue;
            }

            $ident = IPS_GetObject($childId)['ObjectIdent'] ?? '';
            if ($ident === '' || isset($activeLookup[$ident])) {
                continue;
            }

            if ($this->markVariableAsLegacy($childId)) {
                $this->debugExpert(__FUNCTION__, 'Variable als veraltet markiert', [
                    'ObjectID' => $childId,
                    'Ident' => $ident
                ]);
            }
        }
    }

    private function collectRelevantTopics(array $entities): array
    {
        $topics = [];
        foreach ($entities as $entity) {
            if (!(bool)$entity['create_var']) {
                continue;
            }

            foreach ([$entity['state_topic'], $entity['json_attributes_topic']] as $topic) {
                if ($topic !== '') {
                    $topics[$topic] = true;
                }
            }

            $eventFallback = $this->getEventStateFallback($entity);
            if ($eventFallback !== null) {
                $topics[$eventFallback['topic']] = true;
            }

            foreach ($entity['availability']['entries'] as $entry) {
                $topic = trim((string)($entry['topic'] ?? ''), '/');
                if ($topic !== '') {
                    $topics[$topic] = true;
                }
            }
        }

        return array_keys($topics);
    }

    private function buildTopicProcessingIndex(array $entities): array
    {
        $index = [
            'topics' => [],
            'warnings' => [],
            'state' => [],
            'attributes' => [],
            'availability' => []
        ];

        foreach ($entities as $entity) {
            $entityKey = (string)($entity['entity_key'] ?? '');
            if ($entityKey === '') {
                continue;
            }

            $stateTopic = trim((string)($entity['state_topic'] ?? ''), '/');
            if ($stateTopic !== '') {
                $index['topics'][$stateTopic] = true;
                $index['warnings'][$stateTopic][$entityKey] = true;
                if ((bool)$entity['create_var']) {
                    $index['state'][$stateTopic][$entityKey] = true;
                }
            }

            $eventFallback = $this->getEventStateFallback($entity);
            if ((bool)$entity['create_var'] && $eventFallback !== null) {
                $fallbackTopic = trim((string)($eventFallback['topic'] ?? ''), '/');
                if ($fallbackTopic !== '') {
                    $index['topics'][$fallbackTopic] = true;
                    $index['state'][$fallbackTopic][$entityKey] = true;
                }
            }

            if (!(bool)$entity['create_var']) {
                continue;
            }

            $attributesTopic = trim((string)($entity['json_attributes_topic'] ?? ''), '/');
            if ($attributesTopic !== '') {
                $index['topics'][$attributesTopic] = true;
                $index['attributes'][$attributesTopic][$entityKey] = true;
            }

            foreach (($entity['availability']['entries'] ?? []) as $entry) {
                $topic = trim((string)($entry['topic'] ?? ''), '/');
                if ($topic === '') {
                    continue;
                }
                $index['topics'][$topic] = true;
                $index['availability'][$topic][$entityKey] = true;
            }
        }

        $index['topics'] = array_keys($index['topics']);
        sort($index['topics'], SORT_STRING);

        foreach (['warnings', 'state', 'availability'] as $bucket) {
            ksort($index[$bucket], SORT_STRING);
            foreach ($index[$bucket] as $topic => $entityLookup) {
                $keys = array_keys($entityLookup);
                sort($keys, SORT_STRING);
                $index[$bucket][$topic] = $keys;
            }
        }

        return $index;
    }

    private function readTopicProcessingIndex(): array
    {
        try {
            $decoded = json_decode($this->ReadAttributeString(self::ATTR_TOPIC_PROCESSING_INDEX), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $decoded = [];
        }

        if (!is_array($decoded)) {
            $decoded = [];
        }

        $normalizeBucket = static function (mixed $bucket): array {
            if (!is_array($bucket)) {
                return [];
            }

            $result = [];
            foreach ($bucket as $topic => $keys) {
                if (!is_string($topic) || trim($topic, '/') === '' || !is_array($keys)) {
                    continue;
                }

                $normalizedKeys = [];
                foreach ($keys as $key) {
                    if (!is_string($key) || trim($key) === '') {
                        continue;
                    }
                    $normalizedKeys[$key] = true;
                }

                $result[trim($topic, '/')] = array_keys($normalizedKeys);
            }

            ksort($result, SORT_STRING);
            return $result;
        };

        $topics = [];
        foreach (($decoded['topics'] ?? []) as $topic) {
            if (!is_string($topic) || trim($topic, '/') === '') {
                continue;
            }
            $topics[trim($topic, '/')] = true;
        }

        return [
            'topics' => array_keys($topics),
            'warnings' => $normalizeBucket($decoded['warnings'] ?? []),
            'state' => $normalizeBucket($decoded['state'] ?? []),
            'attributes' => $normalizeBucket($decoded['attributes'] ?? []),
            'availability' => $normalizeBucket($decoded['availability'] ?? [])
        ];
    }

    private function writeTopicProcessingIndex(array $index): void
    {
        $this->WriteAttributeString(
            self::ATTR_TOPIC_PROCESSING_INDEX,
            json_encode($index, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    private function updateReceiveFilter(array $topics): void
    {
        if ($topics === []) {
            $this->SetReceiveDataFilter('^$');
            return;
        }

        $escapedTopics = array_map(fn(string $topic): string => $this->buildReceiveFilterTopicPattern($topic), $topics);
        $pattern = '.*"Topic":"(?:' . implode('|', $escapedTopics) . ')".*';
        $this->SetReceiveDataFilter($pattern);
    }

    private function buildReceiveFilterTopicPattern(string $topic): string
    {
        $quoted = preg_quote($topic, '/');
        return str_replace('\/', '(?:\\\\/|/)', $quoted);
    }

    private function loadCachedTopicPayloads(array $entities): array
    {
        $topics = $this->collectRelevantTopics($entities);
        if ($topics === []) {
            return [];
        }

        $response = $this->sendDiscoveryRequestToParent('GetTopicPayloads', ['Topics' => $topics]);
        if ($response === null) {
            return [];
        }

        $items = $response['Items'] ?? [];
        if (!is_array($items)) {
            return [];
        }

        $result = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $topic = trim((string)($item['topic'] ?? ''), '/');
            if ($topic === '') {
                continue;
            }
            $result[$topic] = [
                'payload' => (string)($item['payload'] ?? ''),
                'received_at' => (int)($item['received_at'] ?? 0),
                'is_current_session' => (bool)($item['is_current_session'] ?? false)
            ];
        }

        return $result;
    }

    private function applyCachedTopicPayloads(array $entities, array $cachedTopics): void
    {
        $entityLookup = $this->buildEntityLookup($entities);
        $topicIndex = $this->buildTopicProcessingIndex($entities);
        foreach ($cachedTopics as $topic => $item) {
            $this->applyTopicPayloadToEntities($entityLookup, $topicIndex, $topic, (string)($item['payload'] ?? ''), (int)($item['received_at'] ?? 0));
        }
    }

    private function applyTopicPayloadToEntities(array $entityLookup, array $topicIndex, string $topic, string $payload, int $receivedAt = 0): array
    {
        $diagnosticsChanged = false;

        // Shared topics like zigbee2mqtt/bridge/state can fan out widely. The prebuilt index keeps
        // runtime processing on the affected entity slice instead of rescanning every configured entity.
        $warningKeys = $topicIndex['warnings'][$topic] ?? [];
        if ($warningKeys !== [] && $this->updateStateWarningsForTopic($entityLookup, $warningKeys, $payload)) {
            $diagnosticsChanged = true;
        }

        foreach (($topicIndex['state'][$topic] ?? []) as $entityKey) {
            $entity = $entityLookup[$entityKey] ?? null;
            if (!is_array($entity)) {
                continue;
            }

            $this->applyStatePayload($entity, $topic, $payload, $receivedAt);
        }

        foreach (($topicIndex['attributes'][$topic] ?? []) as $entityKey) {
            $entity = $entityLookup[$entityKey] ?? null;
            if (!is_array($entity)) {
                continue;
            }

            $this->applyAttributesPayload($entity, $payload);
        }

        $availabilityKeys = $topicIndex['availability'][$topic] ?? [];
        if ($availabilityKeys !== [] && $this->applyAvailabilityPayloads($entityLookup, $availabilityKeys, $topic, $payload)) {
            $diagnosticsChanged = true;
        }

        return [
            'diagnostics_changed' => $diagnosticsChanged
        ];
    }

    private function updateStateWarningsForTopic(array $entityLookup, array $entityKeys, string $payload): bool
    {
        $warnings = $this->readStateWarnings();
        $warningsChanged = false;

        foreach ($entityKeys as $entityKey) {
            $entity = $entityLookup[$entityKey] ?? null;
            if (!is_array($entity)) {
                continue;
            }

            $warning = $this->detectBooleanRuntimeWarning($entity, $payload);
            if ($warning === null) {
                if (isset($warnings[$entityKey])) {
                    unset($warnings[$entityKey]);
                    $warningsChanged = true;
                }
                continue;
            }

            if (($warnings[$entityKey] ?? null) !== $warning) {
                $warnings[$entityKey] = $warning;
                $warningsChanged = true;
            }
        }

        if ($warningsChanged) {
            $this->writeStateWarnings($warnings);
        }

        return $warningsChanged;
    }

    private function applyAvailabilityPayloads(array $entityLookup, array $entityKeys, string $topic, string $payload): bool
    {
        $state = $this->readAvailabilityState();
        $stateChanged = false;
        $diagnosticsChanged = false;
        $updatedAt = time();

        foreach ($entityKeys as $entityKey) {
            $entity = $entityLookup[$entityKey] ?? null;
            if (!is_array($entity)) {
                continue;
            }

            $entries = $entity['availability']['entries'] ?? [];
            if (!is_array($entries) || $entries === []) {
                continue;
            }

            $matchedEntry = null;
            foreach ($entries as $entry) {
                if ((string)($entry['topic'] ?? '') === $topic) {
                    $matchedEntry = $entry;
                    break;
                }
            }

            if (!is_array($matchedEntry)) {
                continue;
            }

            $entityState = $state[$entityKey] ?? [
                'mode' => (string)$entity['availability']['mode'],
                'entries' => []
            ];
            if (!is_array($entityState)) {
                $entityState = [
                    'mode' => (string)$entity['availability']['mode'],
                    'entries' => []
                ];
            }
            if (!isset($entityState['entries']) || !is_array($entityState['entries'])) {
                $entityState['entries'] = [];
            }

            $available = $this->evaluateAvailabilityEntry($matchedEntry, $payload);
            $currentEntry = $entityState['entries'][$topic] ?? null;
            $availableChanged = !is_array($currentEntry) || !array_key_exists('available', $currentEntry) || (bool)$currentEntry['available'] !== $available;
            $needsTimestampRefresh = count($entries) > 1 && (string)($entity['availability']['mode'] ?? 'latest') === 'latest';
            if (!$availableChanged && !$needsTimestampRefresh) {
                continue;
            }

            $previousAvailability = $this->computeEntityAvailability($entity, $entityState);
            $entityState['mode'] = (string)$entity['availability']['mode'];
            $entityState['entries'][$topic] = [
                'available' => $available,
                'updated_at' => $updatedAt
            ];
            $state[$entityKey] = $entityState;
            $stateChanged = true;

            if ($previousAvailability !== $this->computeEntityAvailability($entity, $entityState)) {
                $diagnosticsChanged = true;
            }
        }

        if ($stateChanged) {
            $this->writeAvailabilityState($state);
        }

        return $diagnosticsChanged;
    }

    private function applyStatePayload(array $entity, string $topic, string $payload, int $receivedAt = 0): void
    {
        $ident = $entity['ident'];
        $variableId = @$this->GetIDForIdent($ident);
        if ($variableId === false) {
            return;
        }

        $value = $this->extractStateValue($entity, $payload);
        if ($this->isIndeterminateStateValue($value)) {
            return;
        }

        $component = $entity['component'];

        if ($component === HAEventDefinitions::DOMAIN) {
            $eventPayload = $entity['event_payload'] ?? null;
            $currentValue = $this->resolveEventStateValue($entity, $topic, $payload);
            if ($eventPayload === null || $currentValue === null || $currentValue !== $eventPayload) {
                return;
            }

            $timestamp = $receivedAt > 0 ? $receivedAt : time();
            $currentTimestamp = GetValueInteger($variableId);
            if ($currentTimestamp > $timestamp) {
                return;
            }
            $this->SetValue($ident, $timestamp);
            return;
        }

        if ($component === HABinarySensorDefinitions::DOMAIN || $component === HASwitchDefinitions::DOMAIN || $component === HALightDefinitions::DOMAIN) {
            $boolValue = $this->normalizeBooleanState($value, $entity);
            if ($boolValue === null) {
                return;
            }
            $this->SetValue($ident, $boolValue);
            if ($component === HALightDefinitions::DOMAIN) {
                $this->applyLightRuntimeAttributes($entity, $payload);
            }
            return;
        }

        if ($component === HASelectDefinitions::DOMAIN) {
            $selected = $this->normalizeNullableString($this->scalarToString($value));
            if ($selected === null) {
                return;
            }
            $index = array_search($selected, $entity['options'], true);
            if ($index === false) {
                return;
            }
            $this->SetValue($ident, (int)$index);
            return;
        }

        $variable = IPS_GetVariable($variableId);
        $castValue = $this->castSensorValue($value, (int)$variable['VariableType']);
        if ($castValue === null) {
            return;
        }

        $this->SetValue($ident, $castValue);
    }

    private function applyAttributesPayload(array $entity, string $payload): void
    {
        if ((string)($entity['component'] ?? '') !== HALightDefinitions::DOMAIN) {
            return;
        }

        $this->applyLightRuntimeAttributes($entity, $payload);
    }

    private function extractStateValue(array $entity, string $payload): mixed
    {
        $template = $entity['value_template'];
        if ($template === null || !($template['supported'] ?? false)) {
            return $this->normalizeComponentStateValue($entity, $this->extractRawPayloadValue($payload));
        }

        $kind = (string)($template['kind'] ?? '');
        if ($kind === 'raw_value') {
            return $this->normalizeComponentStateValue($entity, $this->applyTemplateFilters($payload, $template['filters'] ?? []));
        }

        if ($kind === 'json_path') {
            try {
                $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return null;
            }

            $value = $decoded;
            foreach (($template['path'] ?? []) as $segment) {
                if (!is_array($value) || !array_key_exists($segment, $value)) {
                    return null;
                }
                $value = $value[$segment];
            }

            return $this->normalizeComponentStateValue($entity, $this->applyTemplateFilters($value, $template['filters'] ?? []));
        }

        return $this->normalizeComponentStateValue($entity, $this->extractRawPayloadValue($payload));
    }

    private function extractRawPayloadValue(string $payload): mixed
    {
        $trimmed = trim($payload);
        if ($trimmed === '') {
            return '';
        }

        if (($trimmed[0] ?? '') === '{' || ($trimmed[0] ?? '') === '[' || ($trimmed[0] ?? '') === '"') {
            try {
                return json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return $trimmed;
            }
        }

        return $trimmed;
    }

    private function normalizeComponentStateValue(array $entity, mixed $value): mixed
    {
        $component = (string)($entity['component'] ?? '');
        if ($component === HALightDefinitions::DOMAIN) {
            return HAMqttDiscoveryLightRuntime::extractStateValue($value);
        }

        if ($component === HACoverDefinitions::DOMAIN) {
            return $this->normalizeCoverStateValue($value, is_array($entity['metadata'] ?? null) ? $entity['metadata'] : []);
        }

        return $value;
    }

    private function normalizeCoverStateValue(mixed $value, array $metadata): mixed
    {
        if (is_numeric($value)) {
            return (float)$value;
        }

        $text = $this->normalizeNullableString($this->scalarToString($value));
        if ($text === null) {
            return $value;
        }

        if ($this->isCoverPositionEntity($metadata, $text)) {
            if (is_numeric(str_replace(',', '.', $text))) {
                return (float)str_replace(',', '.', $text);
            }

            return match ($this->normalizeCoverStateToken($text, $metadata)) {
                'open' => 100.0,
                'closed' => 0.0,
                default => null
            };
        }

        return $this->normalizeCoverStateToken($text, $metadata) ?? strtolower($text);
    }

    private function normalizeCoverStateToken(string $value, array $metadata): ?string
    {
        $normalizedValue = strtolower(trim($value));
        if ($normalizedValue === '') {
            return null;
        }

        $candidates = [
            'open' => $metadata['state_open'] ?? 'open',
            'closed' => $metadata['state_closed'] ?? 'closed',
            'opening' => $metadata['state_opening'] ?? 'opening',
            'closing' => $metadata['state_closing'] ?? 'closing',
            'stopped' => $metadata['state_stopped'] ?? 'stopped'
        ];

        foreach ($candidates as $normalized => $raw) {
            $raw = strtolower(trim((string)$raw));
            if ($raw !== '' && $normalizedValue === $raw) {
                return $normalized;
            }
        }

        return match ($normalizedValue) {
            'open', 'opened' => 'open',
            'close', 'closed' => 'closed',
            'opening', 'up' => 'opening',
            'closing', 'down' => 'closing',
            'stop', 'stopped' => 'stopped',
            default => null
        };
    }

    private function resolveEventStateValue(array $entity, string $topic, string $payload): ?string
    {
        if ($entity['state_topic'] === $topic) {
            return $this->normalizeNullableString($this->scalarToString($this->extractStateValue($entity, $payload)));
        }

        $eventFallback = $this->getEventStateFallback($entity);
        if ($eventFallback === null || $eventFallback['topic'] !== $topic) {
            return null;
        }

        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $field = $eventFallback['field'];
        if (!is_array($decoded) || !array_key_exists($field, $decoded)) {
            return null;
        }

        return $this->normalizeNullableString($this->scalarToString($decoded[$field]));
    }

    private function getEventStateFallback(array $entity): ?array
    {
        if ((string)($entity['component'] ?? '') !== HAEventDefinitions::DOMAIN) {
            return null;
        }

        $stateTopic = trim((string)($entity['state_topic'] ?? ''), '/');
        if ($stateTopic === '' || !str_contains($stateTopic, '/')) {
            return null;
        }

        $segments = explode('/', $stateTopic);
        $field = trim((string)end($segments));
        if ($field === '' || !preg_match('/^[A-Za-z0-9_]+$/', $field)) {
            return null;
        }

        if (!str_ends_with($stateTopic, '/' . $field)) {
            return null;
        }

        $fallbackTopic = substr($stateTopic, 0, -strlen('/' . $field));
        if ($fallbackTopic === '') {
            return null;
        }

        return [
            'topic' => $fallbackTopic,
            'field' => $field
        ];
    }

    private function applyTemplateFilters(mixed $value, array $filters): mixed
    {
        foreach ($filters as $filter) {
            $stringValue = $this->scalarToString($value);
            if ($stringValue === null) {
                return null;
            }

            $value = match ($filter) {
                'lower' => mb_strtolower($stringValue),
                'upper' => mb_strtoupper($stringValue),
                'trim' => trim($stringValue),
                default => $stringValue
            };
        }

        return $value;
    }

    private function normalizeBooleanState(mixed $value, array $entity): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value != 0;
        }

        $stringValue = $this->scalarToString($value);
        if ($stringValue === null) {
            return null;
        }

        $normalized = strtolower(trim($stringValue));
        if ($normalized === '') {
            return null;
        }

        foreach ([
            'state_on' => true,
            'payload_on' => true,
            'state_off' => false,
            'payload_off' => false
        ] as $field => $targetValue) {
            $expected = $this->normalizeNullableString($this->scalarToString($entity[$field] ?? null));
            if ($expected !== null && $normalized === strtolower($expected)) {
                return $targetValue;
            }
        }

        return match ($normalized) {
            '1', 'on', 'true', 'online', 'open', 'enabled', 'yes', 'ja', 'an', 'ein' => true,
            '0', 'off', 'false', 'offline', 'closed', 'disabled', 'no', 'nein', 'aus' => false,
            default => null
        };
    }

    private function castSensorValue(mixed $value, int $variableType): string|int|float|null
    {
        if ($variableType === VARIABLETYPE_STRING) {
            $stringValue = $this->scalarToString($value);
            return $stringValue ?? json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (is_bool($value)) {
            return $variableType === VARIABLETYPE_INTEGER ? (int)$value : (float)(int)$value;
        }

        if (is_int($value) || is_float($value)) {
            return $variableType === VARIABLETYPE_INTEGER ? (int)$value : (float)$value;
        }

        if (is_string($value)) {
            $value = trim($value);
            if ($value === '' || !is_numeric(str_replace(',', '.', $value))) {
                return null;
            }
            $normalized = str_replace(',', '.', $value);
            return $variableType === VARIABLETYPE_INTEGER ? (int)$normalized : (float)$normalized;
        }

        return null;
    }

    private function evaluateAvailabilityEntry(array $entry, string $payload): bool
    {
        $value = $payload;
        $template = $entry['value_template'] ?? null;
        if (is_array($template) && ($template['supported'] ?? false)) {
            $value = $this->extractValueFromTemplate($template, $payload);
        }

        $normalized = strtolower(trim((string)$this->scalarToString($value)));
        return $normalized === strtolower(trim((string)$entry['payload_available']));
    }

    private function extractValueFromTemplate(array $template, string $payload): mixed
    {
        $kind = (string)($template['kind'] ?? '');
        if ($kind === 'raw_value') {
            return $this->applyTemplateFilters($payload, $template['filters'] ?? []);
        }

        if ($kind !== 'json_path') {
            return $payload;
        }

        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $payload;
        }

        $value = $decoded;
        foreach (($template['path'] ?? []) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $this->applyTemplateFilters($value, $template['filters'] ?? []);
    }

    private function isEntityWritable(array $entity): bool
    {
        if (!(bool)$entity['create_var']) {
            return false;
        }
        if ($entity['command_topic'] === '') {
            return false;
        }

        $component = $entity['component'];
        if ($component === HAButtonDefinitions::DOMAIN) {
            return true;
        }
        if ($component === HASwitchDefinitions::DOMAIN) {
            return true;
        }
        if ($component === HALightDefinitions::DOMAIN) {
            return $entity['command_mode'] === 'payload';
        }
        if ($component === HASelectDefinitions::DOMAIN) {
            return $entity['options'] !== [] && ($entity['command_mode'] === 'payload' || $entity['command_mode'] === 'template');
        }
        if ($component === HAClimateDefinitions::DOMAIN) {
            return in_array($entity['command_mode'], ['payload', 'template'], true);
        }
        if ($component === HANumberDefinitions::DOMAIN) {
            return in_array($entity['command_mode'], ['payload', 'template'], true);
        }
        if ($component === HACoverDefinitions::DOMAIN) {
            return in_array($entity['command_mode'], ['payload', 'template'], true);
        }

        return false;
    }

    private function buildCommandPayload(array $entity, mixed $value): ?string
    {
        if ($entity['component'] === HAButtonDefinitions::DOMAIN) {
            $payload = $this->scalarToString($entity['payload_press'] ?? null);
            return $payload ?? '';
        }

        if ($entity['component'] === HASwitchDefinitions::DOMAIN) {
            $boolValue = $this->coerceBooleanActionValue($value);
            if ($boolValue === null) {
                return null;
            }
            $payload = $boolValue ? $entity['payload_on'] : $entity['payload_off'];
            if ($payload === null) {
                $payload = $boolValue ? 'ON' : 'OFF';
            }

            return $this->scalarToString($payload);
        }

        if ($entity['component'] === HALightDefinitions::DOMAIN) {
            return HAMqttDiscoveryLightRuntime::buildCommandPayload($value);
        }

        if ($entity['component'] === HACoverDefinitions::DOMAIN) {
            return $this->buildCoverCommandPayload($entity, $value);
        }

        if ($entity['component'] === HAClimateDefinitions::DOMAIN) {
            return $this->buildClimateCommandPayload($entity, $value);
        }

        if ($entity['component'] === HASelectDefinitions::DOMAIN) {
            $option = $this->resolveSelectOption($entity['options'], $value);
            if ($option === null) {
                return null;
            }

            if ($entity['command_mode'] === 'template') {
                return $this->renderCommandTemplate($entity['command_template'], $option);
            }

            return $option;
        }

        if ($entity['component'] === HANumberDefinitions::DOMAIN) {
            $payload = $this->formatNumberCommandPayload($value, is_array($entity['metadata'] ?? null) ? $entity['metadata'] : []);
            if ($payload === null) {
                return null;
            }

            if ($entity['command_mode'] === 'template') {
                return $this->renderCommandTemplate($entity['command_template'], $payload);
            }

            return $payload;
        }

        return null;
    }

    private function buildCoverCommandPayload(array $entity, mixed $value): ?string
    {
        $metadata = is_array($entity['metadata'] ?? null) ? $entity['metadata'] : [];
        if ($this->isCoverPositionEntity($metadata)) {
            $payload = $this->formatCoverPositionPayload($value);
            if ($payload === null) {
                return null;
            }

            if ($entity['command_mode'] === 'template') {
                return $this->renderCommandTemplate($entity['command_template'], $payload);
            }

            return $payload;
        }

        $payload = $this->normalizeNullableString(HACoverDefinitions::normalizeCommand($value));
        if ($payload === null) {
            return null;
        }

        if ($entity['command_mode'] === 'template') {
            return $this->renderCommandTemplate($entity['command_template'], $payload);
        }

        return $payload;
    }

    private function buildClimateCommandPayload(array $entity, mixed $value): ?string
    {
        $metadata = is_array($entity['metadata'] ?? null) ? $entity['metadata'] : [];
        $payload = $this->formatClimateCommandPayload($value, $metadata);
        if ($payload === null) {
            return null;
        }

        if ($entity['command_mode'] === 'template') {
            return $this->renderCommandTemplate($entity['command_template'], $payload);
        }

        return $payload;
    }

    private function handleCoverActionRequest(string $ident, array $entity, mixed $value, bool $tilt): void
    {
        $metadata = is_array($entity['metadata'] ?? null) ? $entity['metadata'] : [];
        $action = is_numeric($value) ? (int)$value : null;
        [$requiredFeature, $payload] = $this->resolveCoverActionCommand($action, $tilt);
        if ($requiredFeature === 0 || $payload === null) {
            return;
        }

        $supported = $this->getSupportedFeatureFlags($metadata);
        if ($supported !== 0 && !$this->supportsFeatureFlag($supported, $requiredFeature)) {
            return;
        }

        $topicKey = $tilt ? 'tilt_action_topic' : 'action_topic';
        $templateKey = $tilt ? 'tilt_action_command_template' : 'action_command_template';
        $topic = trim((string)($metadata[$topicKey] ?? ''));
        if ($topic === '') {
            $this->debugExpert(__FUNCTION__, 'Cover-Aktion ohne Topic', [
                'Ident' => $ident,
                'EntityKey' => $entity['entity_key'] ?? '',
                'Tilt' => $tilt
            ], true);
            return;
        }

        $commandTemplate = HAMqttDiscoveryTemplate::parseCommandTemplate($this->normalizeNullableString($metadata[$templateKey] ?? null));
        if ($commandTemplate !== null) {
            if (!($commandTemplate['supported'] ?? false)) {
                $this->debugExpert(__FUNCTION__, 'Cover-Aktionstemplate wird nicht unterstuetzt', [
                    'Ident' => $ident,
                    'EntityKey' => $entity['entity_key'] ?? '',
                    'Template' => $commandTemplate['raw'] ?? '',
                    'Tilt' => $tilt
                ], true);
                return;
            }

            $payload = $this->renderCommandTemplate($commandTemplate, $payload);
            if ($payload === null) {
                return;
            }
        }

        $this->debugExpert(__FUNCTION__, 'Sende Cover-Aktion', [
            'Ident' => $ident,
            'EntityKey' => $entity['entity_key'] ?? '',
            'Topic' => $topic,
            'Payload' => $payload,
            'Tilt' => $tilt
        ]);

        $this->sendMqttMessage($topic, $payload, (int)$entity['qos'], (bool)$entity['retain']);
        $this->resetTriggerActionValue($ident);
    }

    private function resolveCoverActionCommand(?int $action, bool $tilt): array
    {
        if ($tilt) {
            return match ($action) {
                HACoverDefinitions::ACTION_OPEN_TILT => [HACoverDefinitions::FEATURE_OPEN_TILT, 'open_tilt'],
                HACoverDefinitions::ACTION_CLOSE_TILT => [HACoverDefinitions::FEATURE_CLOSE_TILT, 'close_tilt'],
                HACoverDefinitions::ACTION_STOP_TILT => [HACoverDefinitions::FEATURE_STOP_TILT, 'stop_tilt'],
                default => [0, null]
            };
        }

        return match ($action) {
            HACoverDefinitions::ACTION_OPEN => [HACoverDefinitions::FEATURE_OPEN, 'open'],
            HACoverDefinitions::ACTION_CLOSE => [HACoverDefinitions::FEATURE_CLOSE, 'close'],
            HACoverDefinitions::ACTION_STOP => [HACoverDefinitions::FEATURE_STOP, 'stop'],
            default => [0, null]
        };
    }

    private function formatCoverPositionPayload(mixed $value): ?string
    {
        if (!is_numeric($value)) {
            $normalized = trim((string)$value);
            if ($normalized === '' || !is_numeric(str_replace(',', '.', $normalized))) {
                return null;
            }

            $value = str_replace(',', '.', $normalized);
        }

        $position = max(0.0, min(100.0, (float)$value));
        return (string)(int)round($position);
    }

    private function formatNumberCommandPayload(mixed $value, array $metadata): ?string
    {
        if (!is_numeric($value)) {
            $normalized = trim((string)$value);
            if ($normalized === '' || !is_numeric(str_replace(',', '.', $normalized))) {
                return null;
            }
            $value = str_replace(',', '.', $normalized);
        }

        return $this->inferNumberVariableType($metadata, $value) === VARIABLETYPE_INTEGER
            ? (string)(int)$value
            : (string)(float)$value;
    }

    private function formatClimateCommandPayload(mixed $value, array $metadata): ?string
    {
        if (!is_numeric($value)) {
            $normalized = trim((string)$value);
            if ($normalized === '' || !is_numeric(str_replace(',', '.', $normalized))) {
                return null;
            }
            $value = str_replace(',', '.', $normalized);
        }

        $numericValue = (float)$value;
        $min = $this->extractNumericMetadata($metadata, ['min', 'native_min_value']);
        if ($min !== null) {
            $numericValue = max($min, $numericValue);
        }

        $max = $this->extractNumericMetadata($metadata, ['max', 'native_max_value']);
        if ($max !== null) {
            $numericValue = min($max, $numericValue);
        }

        $digits = $this->getNumberPresentationDigits(
            $metadata,
            $this->extractNumericMetadata($metadata, ['step', 'native_step']),
            $numericValue
        );

        return $digits <= 0
            ? (string)(int)round($numericValue)
            : rtrim(rtrim(number_format($numericValue, $digits, '.', ''), '0'), '.');
    }

    private function resolveSelectOption(array $options, mixed $value): ?string
    {
        if (is_int($value) && array_key_exists($value, $options)) {
            return $options[$value];
        }

        if (is_string($value) && in_array($value, $options, true)) {
            return $value;
        }

        return null;
    }

    private function renderCommandTemplate(?array $template, string $value): ?string
    {
        if ($template === null || !($template['supported'] ?? false)) {
            return null;
        }

        $raw = (string)($template['raw'] ?? '');
        if ($raw === '') {
            return null;
        }

        return preg_replace('/\{\{\s*value\s*\}\}/', $value, $raw, 1);
    }

    private function coerceBooleanActionValue(mixed $value): ?bool
    {
        return HAMqttDiscoveryLightRuntime::coerceBooleanActionValue($value);
    }

    private function applyOptimisticValue(array $entity, mixed $value): void
    {
        $ident = $entity['ident'];
        if (@$this->GetIDForIdent($ident) === false) {
            return;
        }

        if ($entity['component'] === HAButtonDefinitions::DOMAIN) {
            $this->SetValue($ident, HAButtonDefinitions::ACTION_PRESS);
            return;
        }

        if ($entity['component'] === HASwitchDefinitions::DOMAIN) {
            $boolValue = $this->coerceBooleanActionValue($value);
            if ($boolValue === null) {
                return;
            }
            $this->SetValue($ident, $boolValue);
            return;
        }

        if ($entity['component'] === HALightDefinitions::DOMAIN) {
            $boolValue = $this->coerceBooleanActionValue($value);
            if ($boolValue === null) {
                return;
            }
            $this->SetValue($ident, $boolValue);
            return;
        }

        if ($entity['component'] === HASelectDefinitions::DOMAIN) {
            $option = $this->resolveSelectOption($entity['options'], $value);
            if ($option === null) {
                return;
            }
            $index = array_search($option, $entity['options'], true);
            if ($index !== false) {
                $this->SetValue($ident, (int)$index);
            }
            return;
        }

        if ($entity['component'] === HANumberDefinitions::DOMAIN) {
            $variableId = @$this->GetIDForIdent($ident);
            if ($variableId === false) {
                return;
            }

            $castValue = $this->castSensorValue(
                $value,
                (int)(IPS_GetVariable($variableId)['VariableType'] ?? VARIABLETYPE_FLOAT)
            );
            if ($castValue !== null) {
                $this->SetValue($ident, $castValue);
            }
            return;
        }

        if ($entity['component'] === HAClimateDefinitions::DOMAIN) {
            $variableId = @$this->GetIDForIdent($ident);
            if ($variableId === false) {
                return;
            }

            $castValue = $this->castSensorValue(
                $value,
                (int)(IPS_GetVariable($variableId)['VariableType'] ?? VARIABLETYPE_FLOAT)
            );
            if ($castValue !== null) {
                $this->SetValue($ident, $castValue);
            }
            return;
        }

        if ($entity['component'] === HACoverDefinitions::DOMAIN) {
            $variableId = @$this->GetIDForIdent($ident);
            if ($variableId === false) {
                return;
            }

            $normalized = $this->normalizeCoverStateValue($value, is_array($entity['metadata'] ?? null) ? $entity['metadata'] : []);
            if ($normalized === null) {
                return;
            }

            $castValue = $this->castSensorValue(
                $normalized,
                (int)(IPS_GetVariable($variableId)['VariableType'] ?? VARIABLETYPE_STRING)
            );
            if ($castValue !== null) {
                $this->SetValue($ident, $castValue);
            }
        }
    }

    private function resetTriggerActionValue(string $ident): void
    {
        if (@$this->GetIDForIdent($ident) === false) {
            return;
        }

        $this->SetValue($ident, self::TRIGGER_RESET_VALUE);
    }

    private function isCoverPositionEntity(array $metadata, mixed $value = null): bool
    {
        $supported = is_numeric($metadata['supported_features'] ?? null) ? (int)$metadata['supported_features'] : 0;
        if (($supported & HACoverDefinitions::FEATURE_SET_POSITION) === HACoverDefinitions::FEATURE_SET_POSITION) {
            return true;
        }

        if ($this->normalizeMetadataBoolean($metadata['reports_position'] ?? false)) {
            return true;
        }

        if (is_int($value) || is_float($value)) {
            return true;
        }

        if (is_string($value)) {
            $normalized = str_replace(',', '.', trim($value));
            return $normalized !== '' && is_numeric($normalized);
        }

        return false;
    }

    private function inferNumberVariableType(array $metadata, mixed $value = null): int
    {
        $step = $this->extractNumericMetadata($metadata, ['step', 'native_step']);
        if ($step !== null && ($step <= 0.0 || !$this->isWholeNumber($step))) {
            return VARIABLETYPE_FLOAT;
        }

        $min = $this->extractNumericMetadata($metadata, ['min', 'native_min_value']);
        if ($min !== null && !$this->isWholeNumber($min)) {
            return VARIABLETYPE_FLOAT;
        }

        $max = $this->extractNumericMetadata($metadata, ['max', 'native_max_value']);
        if ($max !== null && !$this->isWholeNumber($max)) {
            return VARIABLETYPE_FLOAT;
        }

        $currentValue = $this->extractNumericMetadata(['value' => $value], ['value']);
        if ($currentValue !== null && !$this->isWholeNumber($currentValue)) {
            return VARIABLETYPE_FLOAT;
        }

        return VARIABLETYPE_INTEGER;
    }

    private function extractNumericMetadata(array $metadata, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $metadata)) {
                continue;
            }

            $value = $metadata[$key];
            if (is_string($value)) {
                $value = str_replace(',', '.', trim($value));
            }

            if (is_numeric($value)) {
                return (float)$value;
            }
        }

        return null;
    }

    private function isWholeNumber(float $value): bool
    {
        return abs($value - round($value)) < 0.0000001;
    }

    private function sendMqttMessage(string $topic, string $payload, int $qos, bool $retain): void
    {
        $parentState = $this->getDiscoveryParentRuntimeState();
        if ($parentState !== 'active') {
            $message = match ($parentState) {
                'missing' => 'MQTT Send uebersprungen, kein Parent verbunden',
                'inactive' => 'MQTT Send uebersprungen, Parent nicht aktiv',
                default => 'MQTT Send uebersprungen, Parent ist nicht Home Assistant MQTT Discovery Splitter'
            };
            $this->debugExpert(__FUNCTION__, $message, [
                'Topic' => $topic,
                'Payload' => $payload
            ], true);
            return;
        }

        $packet = [
            'DataID' => HAIds::DATA_MQTT_DISCOVERY_DEVICE_TO_SPLITTER,
            'PacketType' => 3,
            'QualityOfService' => max(0, min(2, $qos)),
            'Retain' => $retain,
            'Topic' => $topic,
            'Payload' => bin2hex($payload)
        ];

        $this->debugExpert(__FUNCTION__, 'MQTT Command an Splitter uebergeben', [
            'Topic' => $topic,
            'Payload' => $payload,
            'QoS' => max(0, min(2, $qos)),
            'Retain' => $retain
        ]);
        $this->SendDataToParent(json_encode($packet, JSON_THROW_ON_ERROR));
    }

    private function resolveActionTarget(array $entities, string $ident): ?array
    {
        $entity = $this->findEntityByIdent($entities, $ident);
        if ($entity !== null) {
            return [
                'type' => 'entity',
                'entity' => $entity
            ];
        }

        foreach ($entities as $lightEntity) {
            if ((string)($lightEntity['component'] ?? '') === HACoverDefinitions::DOMAIN) {
                $identPrefix = (string)($lightEntity['ident_prefix'] ?? $lightEntity['ident']);
                if ($this->buildCoverActionIdent($identPrefix) === $ident) {
                    return [
                        'type' => 'cover_action',
                        'entity' => $lightEntity
                    ];
                }

                if ($this->buildCoverTiltActionIdent($identPrefix) === $ident) {
                    return [
                        'type' => 'cover_tilt_action',
                        'entity' => $lightEntity
                    ];
                }
            }

            if ((string)($lightEntity['component'] ?? '') !== HALightDefinitions::DOMAIN) {
                continue;
            }

            foreach (array_keys(HALightDefinitions::ATTRIBUTE_DEFINITIONS) as $attribute) {
                if ($this->buildLightAttributeIdent((string) ($lightEntity['ident_prefix'] ?? $lightEntity['ident']), $attribute) !== $ident) {
                    continue;
                }

                return [
                    'type' => 'light_attribute',
                    'entity' => $lightEntity,
                    'attribute' => $attribute
                ];
            }
        }

        return null;
    }

    private function findEntityByIdent(array $entities, string $ident): ?array
    {
        foreach ($entities as $entity) {
            if ($entity['ident'] === $ident) {
                return $entity;
            }
        }

        return null;
    }

    private function buildEntityLookup(array $entities): array
    {
        $lookup = [];
        foreach ($entities as $entity) {
            $entityKey = (string)($entity['entity_key'] ?? '');
            if ($entityKey === '') {
                continue;
            }

            $lookup[$entityKey] = $entity;
        }

        return $lookup;
    }

    private function updateDiagnosticsLabels(array $entities, array $topics, ?array $warningMap = null): void
    {
        $lastMqtt = $this->ReadAttributeString(self::ATTR_LAST_MQTT_MESSAGE);
        if ($lastMqtt === '') {
            $lastMqtt = 'nie';
        }

        $activeEntityCount = count(array_filter($entities, static fn(array $entity): bool => (bool)$entity['create_var']));
        $runtimeState = $this->determineRuntimeState($entities);
        $warningMap ??= $this->readStateWarnings();

        $this->updateFormFieldSafe('DiagLastMQTT', 'caption', 'Letzte MQTT-Message: ' . $lastMqtt);
        $this->updateFormFieldSafe('DiagTopics', 'caption', 'Topics: ' . count($topics));
        $this->updateFormFieldSafe('DiagEntities', 'caption', 'Entities (aktiv/gesamt): ' . $activeEntityCount . '/' . count($entities));
        $this->updateFormFieldSafe('DiagResolution', 'caption', 'Auflösung: ' . $runtimeState['resolution']);
        $this->updateFormFieldSafe('DiagAvailability', 'caption', 'Availability: ' . $this->buildAvailabilitySummary($entities));
        $this->updateFormFieldSafe('DiagWarnings', 'caption', 'Warnungen: ' . $this->buildWarningSummary($warningMap));
    }

    private function updateInstanceSummary(array $entities): void
    {
        $summary = $this->ReadPropertyString(self::PROP_DEVICE_ID);
        $availability = $this->buildAvailabilitySummary($entities);
        if ($availability !== '') {
            $summary .= ' | ' . $availability;
        }
        $this->SetSummary(trim($summary, ' |'));
    }

    private function buildAvailabilitySummary(array $entities): string
    {
        $state = $this->readAvailabilityState();
        $online = 0;
        $offline = 0;
        $unknown = 0;
        $notApplicable = 0;

        foreach ($entities as $entity) {
            if (!(bool)$entity['create_var']) {
                continue;
            }

            if (($entity['availability']['entries'] ?? []) === []) {
                $notApplicable++;
                continue;
            }

            $availability = $this->computeEntityAvailability($entity, $state[$entity['entity_key']] ?? null);
            if ($availability === true) {
                $online++;
            } elseif ($availability === false) {
                $offline++;
            } else {
                $unknown++;
            }
        }

        return sprintf('online %d | offline %d | unbekannt %d | n/a %d', $online, $offline, $unknown, $notApplicable);
    }

    private function computeEntityAvailability(array $entity, mixed $entityState): ?bool
    {
        $entries = $entity['availability']['entries'];
        if ($entries === []) {
            return null;
        }

        if (!is_array($entityState)) {
            return null;
        }

        $entryStates = $entityState['entries'] ?? null;
        if (!is_array($entryStates) || $entryStates === []) {
            return null;
        }

        $values = [];
        $latestTimestamp = -1;
        $latestValue = null;
        foreach ($entries as $entry) {
            $topic = (string)($entry['topic'] ?? '');
            if ($topic === '' || !isset($entryStates[$topic]['available'])) {
                return null;
            }
            $available = (bool)$entryStates[$topic]['available'];
            $values[] = $available;
            $updatedAt = (int)($entryStates[$topic]['updated_at'] ?? 0);
            if ($updatedAt >= $latestTimestamp) {
                $latestTimestamp = $updatedAt;
                $latestValue = $available;
            }
        }

        $mode = (string)($entity['availability']['mode'] ?? 'latest');
        return match ($mode) {
            'all' => !in_array(false, $values, true),
            'any' => in_array(true, $values, true),
            default => $latestValue
        };
    }

    private function pruneAvailabilityState(array $entities): void
    {
        $allowedKeys = [];
        foreach ($entities as $entity) {
            $allowedKeys[$entity['entity_key']] = true;
        }

        $state = $this->readAvailabilityState();
        foreach (array_keys($state) as $key) {
            if (!isset($allowedKeys[$key])) {
                unset($state[$key]);
            }
        }
        $this->writeAvailabilityState($state);
    }

    private function readAvailabilityState(): array
    {
        try {
            $state = json_decode($this->ReadAttributeString(self::ATTR_AVAILABILITY_STATE), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($state) ? $state : [];
    }

    private function writeAvailabilityState(array $state): void
    {
        $this->WriteAttributeString(
            self::ATTR_AVAILABILITY_STATE,
            json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    private function getConfiguredDeviceId(): string
    {
        return trim($this->ReadPropertyString(self::PROP_DEVICE_ID));
    }

    private function buildEntityIdent(string $component, string $objectId): string
    {
        return (string)$this->createSharedIdentAssignment($component, $this->normalizeSharedIdentFragment($objectId), false)['ident'];
    }

    private function buildLocalObjectId(string $objectId, string $deviceName): string
    {
        return $this->buildSharedLocalObjectId($objectId, $deviceName);
    }

    private function sanitizeIdent(string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9_]+/', '_', $value) ?? $value;
        return trim($value, '_');
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        return $value === '' ? null : $value;
    }

    private function scalarToString(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return null;
    }

    private function buildEntitySelectionAvailabilitySummary(array $entity): string
    {
        $entries = $entity['availability']['entries'] ?? [];
        if (!is_array($entries) || $entries === []) {
            return 'none';
        }

        $mode = (string)($entity['availability']['mode'] ?? 'latest');
        return $mode . '/' . count($entries);
    }

    private function buildEntitySelectionCacheSummary(array $entity, array $cachedTopics): string
    {
        $topic = (string)($entity['state_topic'] ?? '');
        if ($topic === '') {
            return 'n/a';
        }

        $record = $cachedTopics[$topic] ?? null;
        if (is_array($record)) {
            return (bool)($record['is_current_session'] ?? false) ? 'current' : 'stale';
        }

        $eventFallback = $this->getEventStateFallback($entity);
        if ($eventFallback !== null) {
            $fallbackRecord = $cachedTopics[$eventFallback['topic']] ?? null;
            if (is_array($fallbackRecord)) {
                return (bool)($fallbackRecord['is_current_session'] ?? false) ? 'current' : 'stale';
            }
        }

        return 'missing';
    }

    private function buildEntitySelectionMappingSummary(array $entity): string
    {
        $parts = [$this->buildTemplateSummary($entity['value_template'] ?? null)];

        if (in_array((string)($entity['component'] ?? ''), [HABinarySensorDefinitions::DOMAIN, HASwitchDefinitions::DOMAIN], true)) {
            $boolSummary = $this->buildBooleanMappingSummary($entity);
            if ($boolSummary !== '') {
                $parts[] = $boolSummary;
            }
        }

        if ((string)($entity['component'] ?? '') === HAButtonDefinitions::DOMAIN) {
            $parts[] = 'press';
        }

        if ((string)($entity['component'] ?? '') === HAEventDefinitions::DOMAIN) {
            $eventPayload = $this->normalizeNullableString($this->scalarToString($entity['event_payload'] ?? null));
            if ($eventPayload !== null) {
                $parts[] = 'event:' . $eventPayload;
            }
        }

        if ((string)($entity['component'] ?? '') === HASelectDefinitions::DOMAIN) {
            $parts[] = 'opts:' . count($entity['options'] ?? []);
        }

        if ((string)($entity['component'] ?? '') === HALightDefinitions::DOMAIN) {
            $schema = $this->normalizeNullableString($entity['metadata']['schema'] ?? null);
            if ($schema !== null) {
                $parts[] = 'schema:' . $schema;
            }

            $modes = $entity['metadata']['supported_color_modes'] ?? [];
            if (is_array($modes) && $modes !== []) {
                $parts[] = 'modes:' . implode(',', $modes);
            }
        }

        return implode(' | ', array_values(array_filter($parts, static fn(string $part): bool => $part !== '')));
    }

    private function buildTemplateSummary(?array $template): string
    {
        if ($template === null) {
            return 'raw';
        }

        if (!($template['supported'] ?? false)) {
            return 'template';
        }

        $kind = (string)($template['kind'] ?? '');
        $filters = $template['filters'] ?? [];
        if (!is_array($filters)) {
            $filters = [];
        }

        $summary = match ($kind) {
            'json_path' => 'json:' . implode('.', $template['path'] ?? []),
            'raw_value' => 'raw',
            default => 'template'
        };

        if ($filters !== []) {
            $summary .= '|' . implode(',', $filters);
        }

        return $summary;
    }

    private function buildBooleanMappingSummary(array $entity): string
    {
        foreach ([['state_on', 'state_off'], ['payload_on', 'payload_off']] as [$onField, $offField]) {
            $onValue = $this->normalizeNullableString($this->scalarToString($entity[$onField] ?? null));
            $offValue = $this->normalizeNullableString($this->scalarToString($entity[$offField] ?? null));
            if ($onValue === null || $offValue === null) {
                continue;
            }

            return 'bool:' . $onValue . '/' . $offValue;
        }

        return '';
    }

    private function buildWarningSummary(array $warningMap): string
    {
        $count = count($warningMap);
        if ($count === 0) {
            return 'keine';
        }

        return $count . ' Laufzeit-Warnung' . ($count === 1 ? '' : 'en');
    }

    private function synchronizeStateWarnings(array $entities, array $cachedTopics): array
    {
        $warningMap = $this->buildStateWarningMap($entities, $cachedTopics);
        $this->writeStateWarnings($warningMap);
        return $warningMap;
    }

    private function buildStateWarningMap(array $entities, array $cachedTopics): array
    {
        $warningMap = [];
        foreach ($entities as $entity) {
            $warning = $this->detectRuntimeWarning($entity, $cachedTopics);
            if ($warning === null) {
                continue;
            }

            $warningMap[(string)$entity['entity_key']] = $warning;
        }

        return $warningMap;
    }

    private function detectRuntimeWarning(array $entity, array $cachedTopics): ?string
    {
        $component = (string)($entity['component'] ?? '');
        if ($component === HAEventDefinitions::DOMAIN) {
            return $this->detectEventRuntimeWarning($entity, $cachedTopics);
        }

        $topic = (string)($entity['state_topic'] ?? '');
        if ($topic === '' || !isset($cachedTopics[$topic])) {
            return null;
        }

        return $this->detectBooleanRuntimeWarning($entity, (string)$cachedTopics[$topic]['payload']);
    }

    private function detectEventRuntimeWarning(array $entity, array $cachedTopics): ?string
    {
        $stateTopic = trim((string)($entity['state_topic'] ?? ''), '/');
        $eventFallback = $this->getEventStateFallback($entity);
        if ($stateTopic === '' || $eventFallback === null) {
            return null;
        }

        if (isset($cachedTopics[$stateTopic])) {
            return null;
        }

        $fallbackRecord = $cachedTopics[$eventFallback['topic']] ?? null;
        if (!is_array($fallbackRecord)) {
            return null;
        }

        return 'disc topic fehlt; root-json fallback';
    }

    private function detectBooleanRuntimeWarning(array $entity, string $payload): ?string
    {
        if (!in_array((string)($entity['component'] ?? ''), [HABinarySensorDefinitions::DOMAIN, HASwitchDefinitions::DOMAIN], true)) {
            return null;
        }

        $mapping = $this->buildBooleanMappingSummary($entity);
        if ($mapping === '') {
            return null;
        }

        $value = $this->extractStateValue($entity, $payload);
        if ($this->isIndeterminateStateValue($value)) {
            return null;
        }

        $runtimeValue = $this->normalizeNullableString($this->scalarToString($value));
        if ($runtimeValue === null) {
            return null;
        }

        $configuredValues = $this->collectConfiguredBooleanTokens($entity);
        if ($configuredValues === []) {
            return null;
        }

        if (isset($configuredValues[strtolower($runtimeValue)])) {
            return null;
        }

        return 'cfg ' . substr($mapping, 5) . ' <> ' . $runtimeValue;
    }

    private function collectConfiguredBooleanTokens(array $entity): array
    {
        $tokens = [];
        foreach ([['state_on', 'state_off'], ['payload_on', 'payload_off']] as [$onField, $offField]) {
            foreach ([$onField, $offField] as $field) {
                $value = $this->normalizeNullableString($this->scalarToString($entity[$field] ?? null));
                if ($value === null) {
                    continue;
                }

                $tokens[strtolower($value)] = true;
            }
        }

        return $tokens;
    }

    private function readStateWarnings(): array
    {
        try {
            $warnings = json_decode($this->ReadAttributeString(self::ATTR_STATE_WARNINGS), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($warnings) ? $warnings : [];
    }

    private function writeStateWarnings(array $warnings): void
    {
        $this->WriteAttributeString(
            self::ATTR_STATE_WARNINGS,
            json_encode($warnings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    private function isIndeterminateStateValue(mixed $value): bool
    {
        $normalized = strtolower(trim((string)$this->scalarToString($value)));
        return $normalized === 'unknown' || $normalized === 'unavailable';
    }

    private function updateFormFieldSafe(string $name, string $property, mixed $value): void
    {
        @ $this->UpdateFormField($name, $property, $value);
    }

    private function logPerformanceSample(string $scope, float $startedAt, array $context = [], bool $force = false): void
    {
    }

    private function logPerformanceMarker(string $scope, string $phase, array $context = []): void
    {
    }
}
