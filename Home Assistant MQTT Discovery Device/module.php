<?php /** @noinspection PhpUnused */

/** @noinspection AutoloadingIssuesInspection */

declare(strict_types=1);

require_once __DIR__ . '/../libs/HACommonIncludes.php';

class HomeAssistantMQTTDiscoveryDevice extends IPSModuleStrict
{
    use ModuleDebugTrait;
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

    /** @var string[] */
    private const array SUPPORTED_COMPONENTS = [
        HABinarySensorDefinitions::DOMAIN,
        HASensorDefinitions::DOMAIN,
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
        if ($Message === FM_CONNECT || $Message === FM_DISCONNECT || $Message === IM_CHANGESTATUS) {
            $this->ApplyChanges();
        }
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->syncParentStatusMessageRegistration();

        if ($this->getConfiguredDeviceId() === '') {
            $deviceDefinition = $this->buildPropertyDeviceDefinition();
            $this->SetStatus(self::STATUS_DEVICE_ID_MISSING);
            $this->SetReceiveDataFilter('^$');
            $entities = $this->normalizeConfiguredEntities($deviceDefinition['entities'] ?? []);
            $this->writeTopicProcessingIndex($this->buildTopicProcessingIndex($entities));
            $this->writeStateWarnings([]);
            $this->updateDiagnosticsLabels($entities, []);
            $this->updateInstanceSummary($entities);
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
            return;
        }

        $deviceDefinition = $this->resolveRuntimeDeviceDefinition();
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
        $entity = $this->findEntityByIdent($entities, (string)$Ident);
        if ($entity === null) {
            $this->debugExpert(__FUNCTION__, 'Entity fuer Ident nicht gefunden', ['Ident' => $Ident], true);
            return;
        }

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

        return $this->applyEntitySelectionOverrides($entities);
    }

    private function resolveRuntimeDeviceDefinition(): array
    {
        $fallback = $this->buildOfflineDeviceDefinition();
        $deviceId = (string)($fallback['device_id'] ?? '');
        if ($deviceId === '') {
            return $fallback;
        }

        if ($this->getDiscoveryParentRuntimeState() !== 'active') {
            return $fallback;
        }

        $response = $this->sendDiscoveryRequestToParent('GetDiscoveryConfigs');
        if ($response === null) {
            return $fallback;
        }

        $records = $response['Items'] ?? [];
        if (!is_array($records) || $records === []) {
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
            return $resolved;
        }

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

        $metadata = $this->normalizeMetadata($row['metadata'] ?? null);
        $createVar = array_key_exists('create_var', $row)
            ? (bool)$row['create_var']
            : (bool)($metadata['enabled_by_default'] ?? true);

        return [
            'entity_key' => $entityKey ?? ($component . '.' . $objectId),
            'component' => $component,
            'object_id' => $objectId,
            'name' => $this->normalizeNullableString($row['name'] ?? null) ?? $objectId,
            'ident' => $this->buildEntityIdent($component, $objectId),
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
            'origin' => is_array($metadata['origin'] ?? null) ? $metadata['origin'] : []
        ];
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
        foreach ($entities as $entity) {
            if (!(bool)$entity['create_var']) {
                continue;
            }

            $ident = $entity['ident'];
            $idents[] = $ident;
            $position = count($idents);

            $variableType = $this->determineVariableType($entity, $cachedTopics);
            $this->recreateVariableIfTypeChanged($ident, $variableType);
            $presentation = $this->buildVariablePresentation($entity, $variableType);
            $this->MaintainVariable($ident, $entity['name'], $variableType, $presentation, $position, true);

            $variableId = @$this->GetIDForIdent($ident);
            if ($variableId === false) {
                continue;
            }

            if ($this->isEntityWritable($entity)) {
                $this->EnableAction($ident);
            } else {
                IPS_SetVariableCustomAction($variableId, 0);
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
            HASensorDefinitions::DOMAIN => $this->determineSensorVariableType($entity, $cachedTopics),
            default => VARIABLETYPE_STRING
        };
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

            IPS_DeleteVariable($childId);
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
        if ((string)($entity['component'] ?? '') !== HALightDefinitions::DOMAIN) {
            return $value;
        }

        return HAMqttDiscoveryLightRuntime::extractStateValue($value);
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

        return null;
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
        }
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
        return $this->sanitizeIdent($component . '_' . $objectId);
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
}
