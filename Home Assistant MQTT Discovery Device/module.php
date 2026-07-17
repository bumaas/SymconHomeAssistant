<?php /** @noinspection PhpUnused */

/** @noinspection AutoloadingIssuesInspection */

declare(strict_types=1);

require_once __DIR__ . '/../libs/HACommonIncludes.php';

class HomeAssistantMQTTDiscoveryDevice extends IPSModuleStrict
{
    use ModuleDebugTrait;
    use HAIdentNamingTrait;
    use HAEntityVariableNamingTrait;
    use HASharedPresentationTrait;
    use HALegacyVariableMigrationTrait;
    use HAMqttDiscoveryParentClientTrait;

    private HAMqttDiscoveryParser $parser;
    private HAMqttDiscoveryGrouping $grouping;

    // Arbeitsspeicher-Cache der normalisierten Verarbeitungsstrukturen, damit ReceiveData nicht
    // bei jeder MQTT-Message die komplette Geraetedefinition neu dekodiert/normalisiert.
    private ?array $runtimeProcessingContextCache = null;
    private bool $runtimeProcessingContextCacheHit = false;

    private const string PROP_DEVICE_ID = 'DeviceID';
    private const string PROP_ENTITY_SELECTION = 'EntitySelection';
    private const string PROP_ENABLE_EXPERT_DEBUG = 'EnableExpertDebug';
    private const string PROP_ENABLE_PERFORMANCE_LOG = 'EnablePerformanceLog';
    private const string PROP_SHOW_EXPERT_COLUMNS = 'ShowExpertColumns';

    private const int STATUS_PARENT_INVALID = 201;
    private const int STATUS_DISCOVERY_CACHE_MISSING = 202;
    private const int STATUS_PARENT_INACTIVE = 203;
    private const int STATUS_DEVICE_ID_MISSING = 204;
    private const string TIMER_DEFERRED_APPLY = 'DeferredApply';
    private const int DEFERRED_APPLY_DELAY_MS = 750;

    private const string ATTR_LAST_MQTT_MESSAGE = 'LastMQTTMessage';
    private const string ATTR_AVAILABILITY_STATE = 'AvailabilityState';
    private const string ATTR_RESOLVED_DEVICE_DEFINITION = 'ResolvedDeviceDefinition';
    private const string ATTR_STATE_WARNINGS = 'StateWarnings';
    private const string ATTR_TOPIC_PROCESSING_INDEX = 'TopicProcessingIndex';
    private const int ENTITY_POSITION_STEP = 10;
    private const int TRIGGER_RESET_VALUE = -1;
    private const string IMAGE_PREVIEW_SUFFIX = '_image_preview';
    private const string LOCK_ACTION_SUFFIX = '_lock_action';
    private const string COVER_ACTION_SUFFIX = '_cover_action';
    private const string COVER_TILT_ACTION_SUFFIX = '_cover_tilt_action';
    private const int LOCK_ACTION_POSITION_OFFSET = 5;
    private const int COVER_ACTION_POSITION_OFFSET = 5;
    private const int COVER_TILT_ACTION_POSITION_OFFSET = 6;

    /** @var string[] */
    private const array ENTITY_SELECTION_EXPERT_COLUMNS = [
        'state_topic',
        'command_topic',
        'availability',
        'cache',
        'mapping'
    ];

    /** @var string[] */
    private const array SUPPORTED_COMPONENTS = [
        HABinarySensorDefinitions::DOMAIN,
        HASensorDefinitions::DOMAIN,
        HAClimateDefinitions::DOMAIN,
        HANumberDefinitions::DOMAIN,
        HAImageDefinitions::DOMAIN,
        HADeviceTrackerDefinitions::DOMAIN,
        HAUpdateDefinitions::DOMAIN,
        HALockDefinitions::DOMAIN,
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
        $this->RegisterTimer(self::TIMER_DEFERRED_APPLY, 0, 'IPS_ApplyChanges($_IPS["TARGET"]);');

        $this->RegisterPropertyString(self::PROP_DEVICE_ID, '');
        $this->RegisterPropertyString(self::PROP_ENTITY_SELECTION, '[]');
        $this->RegisterPropertyBoolean(self::PROP_ENABLE_EXPERT_DEBUG, false);
        $this->RegisterPropertyBoolean(self::PROP_ENABLE_PERFORMANCE_LOG, false);
        $this->RegisterPropertyBoolean(self::PROP_SHOW_EXPERT_COLUMNS, false);

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

        if (!$this->isModuleRuntimeReady()) {
            return;
        }

        if ($Message === IM_CHANGESTATUS) {
            $this->SetTimerInterval(self::TIMER_DEFERRED_APPLY, self::DEFERRED_APPLY_DELAY_MS);
            return;
        }

        if ($Message === FM_CONNECT || $Message === FM_DISCONNECT) {
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
        $this->runtimeProcessingContextCache = null;
        $this->SetTimerInterval(self::TIMER_DEFERRED_APPLY, 0);
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
        if (!$this->isModuleRuntimeReady()) {
            return '';
        }
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

        $messageStartedAt = microtime(true);

        $stepStartedAt = microtime(true);
        $this->WriteAttributeString(self::ATTR_LAST_MQTT_MESSAGE, date('Y-m-d H:i:s'));
        $this->logPerformanceSample('ReceiveData.writeLastMQTTMessage', $stepStartedAt);

        $stepStartedAt = microtime(true);
        $context = $this->getRuntimeProcessingContext();
        $this->logPerformanceSample('ReceiveData.getRuntimeProcessingContext', $stepStartedAt, [
            'cached' => $this->runtimeProcessingContextCacheHit,
            'entities' => count($context['entities'])
        ]);

        $entities = $context['entities'];
        $entityLookup = $context['lookup'];
        $topicIndex = $context['topicIndex'];

        $stepStartedAt = microtime(true);
        $result = $this->applyTopicPayloadToEntities($entityLookup, $topicIndex, $topic, $payload);
        $this->logPerformanceSample('ReceiveData.applyTopicPayloadToEntities', $stepStartedAt, ['topic' => $topic]);

        if ($result['diagnostics_changed']) {
            $stepStartedAt = microtime(true);
            $this->updateDiagnosticsLabels($entities, $topicIndex['topics']);
            $this->updateInstanceSummary($entities);
            $this->logPerformanceSample('ReceiveData.updateDiagnostics', $stepStartedAt);
        }

        $this->logPerformanceSample('ReceiveData.total', $messageStartedAt, ['topic' => $topic]);
        return '';
    }

    public function RequestAction($Ident, $Value): void
    {
        if (!$this->isModuleRuntimeReady()) {
            return;
        }
        $entities = $this->getConfiguredEntities();
        $target = $this->resolveActionTarget($entities, (string) $Ident);
        if ($target === null) {
            $this->debugExpert(__FUNCTION__, 'Entity für Ident nicht gefunden', ['Ident' => $Ident], true);
            return;
        }

        if ($target['type'] === 'cover_action') {
            $this->handleCoverActionRequest($Ident, $target['entity'], $Value, false);
            return;
        }

        if ($target['type'] === 'lock_action') {
            $this->handleLockActionRequest($Ident, $target['entity'], $Value);
            return;
        }

        if ($target['type'] === 'cover_tilt_action') {
            $this->handleCoverActionRequest($Ident, $target['entity'], $Value, true);
            return;
        }

        $entity = $target['entity'];
        if ($target['type'] === 'light_attribute') {
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

        if ($entity['component'] === HAButtonDefinitions::DOMAIN) {
            $this->resetTriggerActionValue($Ident);
            return;
        }

        if ($entity['optimistic'] || $entity['state_topic'] === '') {
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

    /**
     * Liefert die fuer die Message-Verarbeitung benoetigten, normalisierten Strukturen
     * (Entities, Lookup, Topic-Index). Diese werden im Arbeitsspeicher zwischengespeichert und nur
     * neu aufgebaut, wenn sich die zugrundeliegenden Attribute aendern (Signatur ueber Roh-Strings).
     * Das vermeidet das teure Dekodieren/Normalisieren der kompletten Geraetedefinition bei jeder
     * eingehenden MQTT-Message. Der Cache wird zusaetzlich in ApplyChanges invalidiert.
     */
    private function getRuntimeProcessingContext(): array
    {
        $definitionRaw = $this->ReadAttributeString(self::ATTR_RESOLVED_DEVICE_DEFINITION);
        $indexRaw = $this->ReadAttributeString(self::ATTR_TOPIC_PROCESSING_INDEX);
        $signature = strlen($definitionRaw) . ':' . crc32($definitionRaw) . '|' . strlen($indexRaw) . ':' . crc32($indexRaw);

        if (is_array($this->runtimeProcessingContextCache) && ($this->runtimeProcessingContextCache['signature'] ?? null) === $signature) {
            $this->runtimeProcessingContextCacheHit = true;
            return $this->runtimeProcessingContextCache;
        }

        $entities = $this->getConfiguredEntities();
        $this->runtimeProcessingContextCache = [
            'signature'  => $signature,
            'entities'   => $entities,
            'lookup'     => $this->buildEntityLookup($entities),
            'topicIndex' => $this->readTopicProcessingIndex()
        ];
        $this->runtimeProcessingContextCacheHit = false;

        return $this->runtimeProcessingContextCache;
    }

    private function getConfiguredEntities(): array
    {
        $deviceDefinition = $this->readResolvedDeviceDefinition();
        return $this->normalizeConfiguredEntities($deviceDefinition['entities'] ?? []);
    }

    private function normalizeConfiguredEntities(array $rows): array
    {
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
        return $cachedDefinition ?? $this->buildPropertyDeviceDefinition();
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
        return $decoded ?? $this->buildPropertyDeviceDefinition();
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
                    $item['caption'] = sprintf($this->Translate('Device name: %s'), $deviceDefinition['device_name'] ?? '');
                    continue;
                }

                if ($name === 'ResolvedManufacturer') {
                    $item['caption'] = sprintf($this->Translate('Manufacturer: %s'), $deviceDefinition['manufacturer'] ?? '');
                    continue;
                }

                if ($name === 'ResolvedModel') {
                    $item['caption'] = sprintf($this->Translate('Model: %s'), $deviceDefinition['model'] ?? '');
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
                if (!isset($element['items']) || !is_array($element['items'])) {
                    continue;
                }

                foreach ($element['items'] as &$item) {
                    if ((string)($item['name'] ?? '') !== self::PROP_ENTITY_SELECTION) {
                        continue;
                    }

                    $this->applyEntitySelectionColumnVisibility($item);
                    $item['visible'] = $entities !== [];
                    $item['values'] = $this->buildEntitySelectionValues($entities, $cachedTopics, $warningMap);
                    unset($item);
                    return;
                }
                unset($item);
                continue;
            }

            $this->applyEntitySelectionColumnVisibility($element);
            $element['visible'] = $entities !== [];
            $element['values'] = $this->buildEntitySelectionValues($entities, $cachedTopics, $warningMap);
            return;
        }
        unset($element);
    }

    private function applyEntitySelectionColumnVisibility(array &$list): void
    {
        if (!isset($list['columns']) || !is_array($list['columns'])) {
            return;
        }

        $showExpertColumns = $this->ReadPropertyBoolean(self::PROP_SHOW_EXPERT_COLUMNS);
        foreach ($list['columns'] as &$column) {
            $columnName = (string)($column['name'] ?? '');
            if (!in_array($columnName, self::ENTITY_SELECTION_EXPERT_COLUMNS, true)) {
                continue;
            }

            $column['visible'] = $showExpertColumns;
        }
        unset($column);
    }

    private function applyCurrentDiagnosticsToForm(array &$form, array $entities, array $warningMap): void
    {
        $lastMqtt = $this->ReadAttributeString(self::ATTR_LAST_MQTT_MESSAGE);
        if ($lastMqtt === '') {
            $lastMqtt = $this->Translate('never');
        }

        $topics = $this->collectRelevantTopics($entities);
        $activeEntityCount = count(array_filter($entities, static fn(array $entity): bool => (bool)$entity['create_var']));
        $runtimeState = $this->determineRuntimeState($entities);
        $captions = [
            'DiagLastMQTT' => sprintf($this->Translate('Last MQTT message: %s'), $lastMqtt),
            'DiagTopics' => sprintf($this->Translate('Topics: %d'), count($topics)),
            'DiagEntities' => sprintf($this->Translate('Entities (active/total): %d/%d'), $activeEntityCount, count($entities)),
            'DiagResolution' => sprintf($this->Translate('Resolution: %s'), $this->Translate($runtimeState['resolution'])),
            'DiagAvailability' => sprintf($this->Translate('Availability: %s'), $this->buildAvailabilitySummary($entities)),
            'DiagWarnings' => sprintf($this->Translate('Warnings: %s'), $this->buildWarningSummary($warningMap))
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
            'local_object_id' => $localObjectId,
            'device_name' => $deviceName,
            'name' => $resolvedName,
            'ident' => $this->buildEntityIdent($component, $localObjectId !== '' ? $localObjectId : $objectId),
            'create_var' => $createVar,
            'state_topic' => $this->normalizeNullableString($row['state_topic'] ?? null) ?? '',
            'latest_version_topic' => $this->normalizeNullableString($row['latest_version_topic'] ?? null) ?? '',
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
            'payload_home' => $row['payload_home'] ?? null,
            'payload_not_home' => $row['payload_not_home'] ?? null,
            'payload_reset' => $row['payload_reset'] ?? null,
            'payload_lock' => $row['payload_lock'] ?? null,
            'payload_unlock' => $row['payload_unlock'] ?? null,
            'payload_open' => $row['payload_open'] ?? null,
            'event_payload' => $this->normalizeNullableString($this->scalarToString($row['event_payload'] ?? null)),
            'state_on' => $row['state_on'] ?? null,
            'state_off' => $row['state_off'] ?? null,
            'state_locked' => $row['state_locked'] ?? null,
            'state_unlocked' => $row['state_unlocked'] ?? null,
            'state_locking' => $row['state_locking'] ?? null,
            'state_unlocking' => $row['state_unlocking'] ?? null,
            'state_jammed' => $row['state_jammed'] ?? null,
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
            'source_type' => $this->normalizeNullableString($metadata['source_type'] ?? null),
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
            'enabled_by_default' => !array_key_exists('enabled_by_default', $metadata) || $metadata['enabled_by_default'],
            'icon' => $this->normalizeNullableString($metadata['icon'] ?? null),
            'content_type' => $this->normalizeNullableString($metadata['content_type'] ?? null),
            'latest_version_template' => is_array($metadata['latest_version_template'] ?? null) ? $metadata['latest_version_template'] : null,
            'title' => $this->normalizeNullableString($metadata['title'] ?? null),
            'release_summary' => $this->normalizeNullableString($metadata['release_summary'] ?? null),
            'release_url' => $this->normalizeNullableString($metadata['release_url'] ?? null),
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
            'state_locked' => $this->normalizeNullableString($metadata['state_locked'] ?? null),
            'state_unlocked' => $this->normalizeNullableString($metadata['state_unlocked'] ?? null),
            'state_locking' => $this->normalizeNullableString($metadata['state_locking'] ?? null),
            'state_unlocking' => $this->normalizeNullableString($metadata['state_unlocking'] ?? null),
            'state_jammed' => $this->normalizeNullableString($metadata['state_jammed'] ?? null),
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
            return $value
                   |> trim(...)
                   |> strtolower(...)
                   |> (static fn($x) => in_array($x, ['1', 'true', 'on', 'yes', 'ja'], true));
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
                'message' => 'No discovery device ID configured.',
                'resolution' => 'no device ID configured'
            ];
        }

        $parentState = $this->getDiscoveryParentRuntimeState();
        if ($parentState === 'missing') {
            return [
                'status' => self::STATUS_PARENT_INVALID,
                'message' => 'No parent connected.',
                'resolution' => 'no parent connected'
            ];
        }

        if ($parentState === 'invalid') {
            return [
                'status' => self::STATUS_PARENT_INVALID,
                'message' => 'Parent is not Home Assistant MQTT Discovery Splitter.',
                'resolution' => 'no compatible parent'
            ];
        }

        if ($parentState === 'inactive') {
            return [
                'status' => self::STATUS_PARENT_INACTIVE,
                'message' => 'Home Assistant MQTT Discovery Splitter parent is inactive.',
                'resolution' => $entities === [] ? 'parent inactive' : 'cached state, parent inactive'
            ];
        }

        if ($entities === []) {
            return [
                'status' => self::STATUS_DISCOVERY_CACHE_MISSING,
                'message' => 'No discovery info for this device ID found in splitter cache.',
                'resolution' => 'no discovery info for device ID in cache'
            ];
        }

        return [
            'status' => IS_ACTIVE,
            'message' => '',
            'resolution' => 'resolved from MQTT discovery splitter'
        ];
    }

    private function persistEntitySelection(callable $selector, bool $resetToDefaults = false): void
    {
        $deviceDefinition = $this->resolveRuntimeDeviceDefinition();
        $entities = $resetToDefaults
            ? $this->normalizeResolvedEntitiesWithoutSelection($deviceDefinition['entities'] ?? [])
            : $this->normalizeConfiguredEntities($deviceDefinition['entities'] ?? []);

        foreach ($entities as &$entity) {
            $entity['create_var'] = (bool)$selector($entity);
        }
        unset($entity);

        $cachedTopics = $this->loadCachedTopicPayloads($entities);
        $values = $this->buildEntitySelectionValues($entities, $cachedTopics, []);
        $this->UpdateFormField(
            self::PROP_ENTITY_SELECTION,
            'values',
            json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    private function normalizeResolvedEntitiesWithoutSelection(array $rows): array
    {
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
                'mode' => $this->buildEntityAccessModeLabel($entity)
            ];
        }

        return $values;
    }

    private function buildEntityAccessModeLabel(array $entity): string
    {
        $hasStateTopic = trim((string)($entity['state_topic'] ?? '')) !== '';
        $isControllable = $this->isEntityControllable($entity);

        if ($isControllable && !$hasStateTopic) {
            return $this->Translate('Write only');
        }

        if ($isControllable) {
            return $this->Translate('Read and write');
        }

        return $this->Translate('Read only');
    }

    private function maintainEntityVariables(array $entities, array $cachedTopics): array
    {
        $idents = [];
        $entityOrder = 0;
        $hasMultipleStatusEntities = $this->countActiveStatusEntities($entities) > 1;
        foreach ($entities as $entity) {
            if (!$entity['create_var']) {
                continue;
            }

            $entityOrder++;
            $ident = $entity['ident'];
            $idents[] = $ident;
            $basePosition = $entityOrder * self::ENTITY_POSITION_STEP;
            $isButtonEntity = ((string)($entity['component'] ?? '') === HAButtonDefinitions::DOMAIN);

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
                $this->DisableAction($ident);
            }

            if ($isButtonEntity) {
                $this->resetTriggerActionValue($ident);
            }

            if ((string)($entity['component'] ?? '') === HALightDefinitions::DOMAIN) {
                $lightAttributes = $this->extractLightAttributesFromCachedTopics($entity, $cachedTopics);
                $attributeContext = $this->buildLightAttributeContext($entity, $lightAttributes);
                $idents = array_merge($idents, $this->maintainLightAttributeVariables($entity, $attributeContext, $basePosition));
                continue;
            }

            if ((string)($entity['component'] ?? '') === HAImageDefinitions::DOMAIN) {
                $idents = array_merge($idents, $this->maintainImagePreviewMedia($entity, $basePosition));
                continue;
            }

            if ((string)($entity['component'] ?? '') === HADeviceTrackerDefinitions::DOMAIN) {
                $idents = array_merge($idents, $this->maintainDeviceTrackerAttributeVariables($entity, $cachedTopics, $basePosition));
                continue;
            }

            if ((string)($entity['component'] ?? '') === HAUpdateDefinitions::DOMAIN) {
                $idents = array_merge($idents, $this->maintainUpdateAttributeVariables($entity, $cachedTopics, $basePosition));
                continue;
            }

            if ((string)($entity['component'] ?? '') === HACoverDefinitions::DOMAIN) {
                $idents = array_merge($idents, $this->maintainCoverActionVariables($entity, $basePosition));
                continue;
            }

            if ((string)($entity['component'] ?? '') === HALockDefinitions::DOMAIN) {
                $idents = array_merge($idents, $this->maintainLockActionVariable($entity, $basePosition));
            }
        }

        return $idents;
    }

    private function determineVariableType(array $entity, array $cachedTopics): int
    {
        return match ($entity['component']) {
            HABinarySensorDefinitions::DOMAIN, HASwitchDefinitions::DOMAIN, HALightDefinitions::DOMAIN, HAUpdateDefinitions::DOMAIN => VARIABLETYPE_BOOLEAN,
            HASelectDefinitions::DOMAIN, HAButtonDefinitions::DOMAIN, HAEventDefinitions::DOMAIN => VARIABLETYPE_INTEGER,
            HAClimateDefinitions::DOMAIN => HAClimateDefinitions::VARIABLE_TYPE,
            HAImageDefinitions::DOMAIN => HAImageDefinitions::VARIABLE_TYPE,
            HALockDefinitions::DOMAIN => HALockDefinitions::VARIABLE_TYPE,
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

        if ((string)($entity['component'] ?? '') === HALockDefinitions::DOMAIN) {
            return $this->buildLockPresentation(is_array($entity['metadata'] ?? null) ? $entity['metadata'] : []);
        }

        if ((string)($entity['component'] ?? '') === HAImageDefinitions::DOMAIN) {
            return $this->buildDateTimeValuePresentation(2);
        }

        if ((string)($entity['component'] ?? '') === HAUpdateDefinitions::DOMAIN) {
            return $this->buildUpdatePresentation();
        }

        if ((string)($entity['component'] ?? '') === HABinarySensorDefinitions::DOMAIN && $variableType === VARIABLETYPE_BOOLEAN) {
            $metadata = is_array($entity['metadata'] ?? null) ? $entity['metadata'] : [];
            $deviceClass = trim((string)($metadata['device_class'] ?? ''));
            [$trueCaption, $falseCaption, $icon] = HABinarySensorDefinitions::getPresentationMeta($deviceClass);
            return $this->buildSharedBinarySensorPresentation(
                $this->Translate($trueCaption),
                $this->Translate($falseCaption),
                $icon
            );
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

    private function buildLockPresentation(array $metadata): array
    {
        $supported = $this->getSupportedFeatureFlags($metadata);
        $allowOpen = $this->supportsFeatureFlag($supported, HALockDefinitions::FEATURE_OPEN);
        $options = [];

        foreach (HALockDefinitions::STATE_OPTIONS as $value => $stateMeta) {
            if ($value === 'open' && !$allowOpen) {
                continue;
            }

            $options[] = [
                'Value' => $value,
                'Caption' => $this->Translate((string)($stateMeta['caption'] ?? $value)),
                'IconActive' => false,
                'IconValue' => '',
                'ColorActive' => false,
                'ColorValue' => -1
            ];
        }

        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'OPTIONS' => json_encode($options, JSON_THROW_ON_ERROR)
        ];
    }

    private function buildDateTimeValuePresentation(int $time): array
    {
        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_DATE_TIME,
            'DATE' => 1,
            'DAY_OF_THE_WEEK' => false,
            'MONTH_TEXT' => false,
            'TIME' => $time
        ];
    }

    private function buildUpdatePresentation(): array
    {
        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'OPTIONS' => json_encode([
                [
                    'Value' => false,
                    'Caption' => $this->Translate('Up to Date'),
                    'IconActive' => false,
                    'IconValue' => '',
                    'ColorActive' => false,
                    'ColorValue' => -1
                ],
                [
                    'Value' => true,
                    'Caption' => $this->Translate('Update Available'),
                    'IconActive' => false,
                    'IconValue' => '',
                    'ColorActive' => false,
                    'ColorValue' => -1
                ]
            ], JSON_THROW_ON_ERROR),
            'ICON' => HAUpdateDefinitions::ICON
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
            if ($suffix === '' && $digits === 0 && $this->isNumberIntensityRange($min, $max)) {
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
            return $this->buildSharedTemperatureSliderPresentation(
                $min,
                $max,
                $step,
                $digits,
                $suffix === '' ? null : ' ' . $suffix
            );
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

            $text = sprintf('%.6F', $number)
                    |> (static fn($x) => rtrim($x, '0'))
                    |> (static function ($x) {
                        return rtrim($x, '.');
                    });
            $decimalPos = strpos($text, '.');
            if ($decimalPos === false) {
                $digits = 0;
                continue;
            }

            $digits = strlen(substr($text, $decimalPos + 1));
            break;
        }

        return min(3, max(0, ($digits ?? 0)));
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

            foreach ($this->extractLightAttributesFromPayload($entity, (string) ($cachedTopics[$topic]['payload'] ?? '')) as $name => $value) {
                $attributes[$name] = $value;
            }
        }

        return $attributes;
    }

    private function extractLightAttributesFromPayload(array $entity, string $payload): array
    {
        $rawValue = $this->extractRawPayloadValue($payload);
        return HAMqttDiscoveryLightRuntime::extractAttributes($rawValue, $entity['metadata'] ?? []);
    }

    private function extractDeviceTrackerAttributesFromCachedTopics(array $entity, array $cachedTopics): array
    {
        $attributes = [];

        $sourceType = $this->normalizeNullableString($entity['metadata']['source_type'] ?? null);
        if ($sourceType !== null) {
            $attributes['source_type'] = $sourceType;
        }

        $attributesTopic = trim((string)($entity['json_attributes_topic'] ?? ''), '/');
        if ($attributesTopic !== '' && isset($cachedTopics[$attributesTopic])) {
            $attributes = array_merge(
                $attributes,
                $this->extractDeviceTrackerAttributesFromPayload((string)($cachedTopics[$attributesTopic]['payload'] ?? ''))
            );
        }

        return $attributes;
    }

    private function extractDeviceTrackerAttributesFromPayload(string $payload): array
    {
        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $attributes = [];
        foreach (array_keys(HADeviceTrackerDefinitions::ATTRIBUTE_DEFINITIONS) as $attribute) {
            if (!array_key_exists($attribute, $decoded)) {
                continue;
            }
            $attributes[$attribute] = $decoded[$attribute];
        }

        if (isset($attributes['source_type']) && !is_string($attributes['source_type'])) {
            unset($attributes['source_type']);
        }

        foreach (['latitude', 'longitude', 'altitude'] as $attribute) {
            if (isset($attributes[$attribute]) && !is_numeric($attributes[$attribute])) {
                unset($attributes[$attribute]);
            }
        }
        if (isset($attributes['gps_accuracy']) && !is_numeric($attributes['gps_accuracy'])) {
            unset($attributes['gps_accuracy']);
        }

        return $attributes;
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
            $this->MaintainVariable($ident, $this->Translate((string) ($meta['caption'] ?? $attribute)), $variableType, $presentation, $position, true);

            $variableId = @$this->GetIDForIdent($ident);
            if ($variableId !== false) {
                if ($this->isLightAttributeWritable($attribute, $attributeContext)) {
                    $this->EnableAction($ident);
                } else {
                    $this->DisableAction($ident);
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

    private function maintainLockActionVariable(array $entity, int $basePosition): array
    {
        if (!$this->hasLockActionControl($entity)) {
            return [];
        }

        $ident = $this->buildLockActionIdent((string)($entity['ident_prefix'] ?? $entity['ident']));
        $this->maintainEnumerationTriggerVariable(
            $ident,
            $this->Translate('Select action'),
            $basePosition + self::LOCK_ACTION_POSITION_OFFSET,
            $this->buildLockActionOptions(is_array($entity['metadata'] ?? null) ? $entity['metadata'] : [])
        );

        return [$ident];
    }

    private function maintainImagePreviewMedia(array $entity, int $basePosition): array
    {
        $ident = $this->buildImagePreviewIdent((string)($entity['ident_prefix'] ?? $entity['ident']));
        $this->ensureImagePreviewMedia(
            $ident,
            $this->getImagePreviewMediaName($entity),
            $basePosition,
            $this->resolveImagePreviewExtension((string)($entity['metadata']['content_type'] ?? ''), '')
        );
        return [$ident];
    }

    private function maintainDeviceTrackerAttributeVariables(array $entity, array $cachedTopics, int $basePosition): array
    {
        $attributeContext = $this->extractDeviceTrackerAttributesFromCachedTopics($entity, $cachedTopics);
        $sourceType = strtolower(trim((string)($attributeContext['source_type'] ?? $entity['metadata']['source_type'] ?? '')));
        $expectsGpsAttributes = $sourceType === HADeviceTrackerDefinitions::SOURCE_TYPE_GPS
            || trim((string)($entity['json_attributes_topic'] ?? '')) !== '';
        $idents = [];

        foreach (HADeviceTrackerDefinitions::ATTRIBUTE_ORDER as $attribute) {
            $shouldCreate = array_key_exists($attribute, $attributeContext);
            if (!$shouldCreate && $expectsGpsAttributes && in_array($attribute, ['latitude', 'longitude', 'gps_accuracy', 'altitude'], true)) {
                $shouldCreate = true;
            }
            if (!$shouldCreate && $attribute === 'source_type' && ($sourceType !== '' || trim((string)($entity['json_attributes_topic'] ?? '')) !== '')) {
                $shouldCreate = true;
            }
            if (!$shouldCreate) {
                continue;
            }

            $meta = HADeviceTrackerDefinitions::ATTRIBUTE_DEFINITIONS[$attribute] ?? null;
            if (!is_array($meta)) {
                continue;
            }

            $ident = $this->buildDeviceTrackerAttributeIdent((string)($entity['ident_prefix'] ?? $entity['ident']), $attribute);
            $variableType = (int)($meta['type'] ?? VARIABLETYPE_STRING);
            $this->recreateVariableIfTypeChanged($ident, $variableType);
            $presentation = $this->buildDeviceTrackerAttributePresentation($attribute, $attributeContext);
            $position = $basePosition + $this->getDeviceTrackerAttributePositionOffset($attribute);
            $this->MaintainVariable($ident, $this->Translate((string)($meta['caption'] ?? $attribute)), $variableType, $presentation, $position, true);

            $variableId = @$this->GetIDForIdent($ident);
            if ($variableId !== false) {
                $this->DisableAction($ident);
                $value = array_key_exists($attribute, $attributeContext)
                    ? $this->castSensorValue($attributeContext[$attribute], $variableType)
                    : null;
                if ($value !== null) {
                    $this->SetValue($ident, $value);
                }
            }

            $idents[] = $ident;
        }

        return $idents;
    }

    private function maintainUpdateAttributeVariables(array $entity, array $cachedTopics, int $basePosition): array
    {
        $attributeContext = $this->extractUpdateContextFromCachedTopics($entity, $cachedTopics);
        $idents = [];

        foreach (HAUpdateDefinitions::ATTRIBUTE_ORDER as $attribute) {
            if (!$this->shouldCreateUpdateAttribute($entity, $attribute, $attributeContext)) {
                continue;
            }

            $meta = HAUpdateDefinitions::ATTRIBUTE_DEFINITIONS[$attribute] ?? null;
            if (!is_array($meta)) {
                continue;
            }

            $ident = $this->buildUpdateAttributeIdent((string)($entity['ident_prefix'] ?? $entity['ident']), $attribute);
            $variableType = (int)($meta['type'] ?? VARIABLETYPE_STRING);
            $this->recreateVariableIfTypeChanged($ident, $variableType);
            $presentation = $this->buildUpdateAttributePresentation($attribute);
            $position = $basePosition + $this->getUpdateAttributePositionOffset($attribute);
            $this->MaintainVariable($ident, $this->Translate((string)($meta['caption'] ?? $attribute)), $variableType, $presentation, $position, true);

            $variableId = @$this->GetIDForIdent($ident);
            if ($variableId !== false) {
                $this->DisableAction($ident);
                if (array_key_exists($attribute, $attributeContext)) {
                    $this->SetValue($ident, $attributeContext[$attribute]);
                }
            }

            $idents[] = $ident;
        }

        return $idents;
    }

    private function buildLockActionOptions(array $metadata): array
    {
        $options = [
            $this->buildEnumerationOption(HALockDefinitions::ACTION_LOCK, $this->Translate('Lock')),
            $this->buildEnumerationOption(HALockDefinitions::ACTION_UNLOCK, $this->Translate('Unlock'))
        ];

        if ($this->supportsFeatureFlag($this->getSupportedFeatureFlags($metadata), HALockDefinitions::FEATURE_OPEN)) {
            $options[] = $this->buildEnumerationOption(HALockDefinitions::ACTION_OPEN, $this->Translate('Open'));
        }

        return $options;
    }

    private function hasLockActionControl(array $entity): bool
    {
        if (!$entity['create_var']) {
            return false;
        }

        if ((string)($entity['component'] ?? '') !== HALockDefinitions::DOMAIN) {
            return false;
        }

        if ((string)($entity['command_topic'] ?? '') === '') {
            return false;
        }

        return in_array((string)($entity['command_mode'] ?? 'none'), ['payload', 'template'], true);
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
                $this->UnregisterVariable($ident);
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
        return $template === null || ($template['supported'] ?? false);
    }

    private function applyLightRuntimeAttributes(array $entity, string $payload): void
    {
        $runtimeAttributes = $this->extractLightAttributesFromPayload($entity, $payload);
        if ($runtimeAttributes === []) {
            return;
        }

        $context = $this->buildLightAttributeContext($entity, $runtimeAttributes);
        $this->maintainLightAttributeVariables($entity, $context, $this->getEntityBasePosition($entity));

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
        return array_all($requiredFeatures, static fn($feature) => is_numeric($feature) && ($supportedFeatures & (int)$feature) !== 0);
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

        return array_any($requiredModes, static fn($mode) => in_array(strtolower((string)$mode), $supportedModes, true));
    }

    private function buildLightAttributePresentation(string $attribute, array $context, array $meta): array|string
    {
        // Slider-/Farb-Presentations aus dem gemeinsamen Trait (identisch zum REST-Device-Pfad):
        // liefert USAGE_TYPE/GRADIENT_TYPE bzw. die Farb-Darstellung, die hier früher fehlten.
        $suffixRaw          = trim((string) ($meta['suffix'] ?? ''));
        $isPercent          = $suffixRaw === '%';
        $presentationSuffix = $this->sharedFormatPresentationSuffix($suffixRaw);
        $digits             = $this->sharedMetaDigitsOverride($meta);

        if ($attribute === 'brightness') {
            return $this->buildSharedLightBrightnessPresentation($isPercent, $digits, $presentationSuffix);
        }

        if ($attribute === 'rgb_color') {
            return $this->buildSharedLightRgbColorPresentation();
        }

        if ($attribute === 'xy_color') {
            return $this->buildSharedLightXyColorPresentation();
        }

        if ($attribute === 'hs_color') {
            return $this->buildSharedLightHsColorPresentation();
        }

        if ($attribute === 'color_temp') {
            $slider = $this->buildSharedLightColorTempSliderPresentation(
                $context['min_mireds'] ?? null,
                $context['max_mireds'] ?? null,
                $isPercent,
                $digits,
                $presentationSuffix
            );
            if ($slider !== null) {
                return $slider;
            }
        }

        if ($attribute === 'color_temp_kelvin') {
            $slider = $this->buildSharedLightColorTempSliderPresentation(
                $context['min_color_temp_kelvin'] ?? null,
                $context['max_color_temp_kelvin'] ?? null,
                $isPercent,
                $digits,
                $presentationSuffix
            );
            if ($slider !== null) {
                return $slider;
            }
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
        foreach (array_filter($values, static fn(mixed $value): bool => is_string($value) && trim($value) !== '') as $value) {
            $options[] = [
                'Value' => (string) $value,
                'Caption' => (string) $value,
                'IconActive' => false,
                'IconValue' => '',
                'ColorActive' => false,
                'ColorValue' => -1
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

    private function getEntityBasePosition(array $entity): int
    {
        $variableId = @$this->GetIDForIdent((string) $entity['ident']);
        if ($variableId === false) {
            return self::ENTITY_POSITION_STEP;
        }

        return (int) (IPS_GetObject($variableId)['ObjectPosition'] ?? self::ENTITY_POSITION_STEP);
    }

    private function detectImagePreviewExtension(string $contentType): string
    {
        $normalized = strtolower(trim($contentType));
        return match ($normalized) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/bmp' => 'bmp',
            'image/svg+xml' => 'svg',
            default => 'png'
        };
    }

    private function buildLightAttributeIdent(string $entityIdentPrefix, string $attribute): string
    {
        return $this->buildSharedAttributeIdentFromPrefix($entityIdentPrefix, $attribute);
    }

    private function buildDeviceTrackerAttributeIdent(string $entityIdentPrefix, string $attribute): string
    {
        return $this->buildSharedAttributeIdentFromPrefix($entityIdentPrefix, $attribute);
    }

    private function buildUpdateAttributeIdent(string $entityIdentPrefix, string $attribute): string
    {
        return $this->buildSharedAttributeIdentFromPrefix($entityIdentPrefix, $attribute);
    }

    private function buildImagePreviewIdent(string $entityIdentPrefix): string
    {
        return $this->buildSharedSuffixIdentFromPrefix($entityIdentPrefix, self::IMAGE_PREVIEW_SUFFIX);
    }

    private function buildLockActionIdent(string $entityIdentPrefix): string
    {
        return $this->buildSharedSuffixIdentFromPrefix($entityIdentPrefix, self::LOCK_ACTION_SUFFIX);
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
        if ((string)($entity['component'] ?? '') === HAImageDefinitions::DOMAIN) {
            return $this->getImageEntityVariableName($entity);
        }

        return $this->buildSharedEntityVariableName((string)($entity['component'] ?? ''), $entity, $hasMultipleStatusEntities);
    }

    private function getImageEntityVariableName(array $entity): string
    {
        if (!$this->isSharedEntityBoundToDevice($entity)) {
            return $this->Translate('Last Update');
        }

        $baseName = $this->getDiscoveryImageEntityBaseName($entity);
        if ($baseName === '') {
            return $this->Translate('Last Update');
        }

        return $baseName . ' (' . $this->Translate('Last Update') . ')';
    }

    private function getImagePreviewMediaName(array $entity): string
    {
        if (!$this->isSharedEntityBoundToDevice($entity)) {
            return $this->Translate('Image');
        }

        $baseName = $this->getDiscoveryImageEntityBaseName($entity);
        return $baseName !== '' ? $baseName : $this->Translate('Image');
    }

    private function getDiscoveryImageEntityBaseName(array $entity): string
    {
        $attributes = $this->getSharedEntityAttributesArray($entity);
        $friendlyName = $this->stripSharedCurrentInstanceNamePrefix(trim((string)($attributes['friendly_name'] ?? '')));
        if ($friendlyName !== '') {
            return $friendlyName;
        }

        $name = $this->getSharedEntityName($entity);
        if ($name !== '' && !$this->isGenericDiscoveryImageName($name)) {
            return $name;
        }

        $derived = $this->deriveDiscoveryImageLabel($entity);
        if ($derived !== '') {
            return $derived;
        }

        return $name;
    }

    private function isGenericDiscoveryImageName(string $name): bool
    {
        $normalized = strtolower(trim($name));
        if ($normalized === '') {
            return true;
        }

        return preg_match('/^(image|picture|preview)(\s*\([^)]+\))?$/i', $normalized) === 1;
    }

    private function deriveDiscoveryImageLabel(array $entity): string
    {
        $stateTopic = trim((string)($entity['state_topic'] ?? ''), '/');
        if ($stateTopic !== '') {
            $topicSegments = array_values(array_filter(explode('/', $stateTopic), static fn(string $segment): bool => $segment !== ''));
            $topicTail = (string)end($topicSegments);
            $topicLabel = $this->cleanupDiscoveryImageLabelSource($topicTail);
            if ($topicLabel !== '') {
                return $this->humanizeDiscoveryLabel($topicLabel);
            }
        }

        foreach (['local_object_id', 'object_id'] as $field) {
            $value = trim((string)($entity[$field] ?? ''));
            $labelSource = $this->cleanupDiscoveryImageLabelSource($value);
            if ($labelSource !== '') {
                return $this->humanizeDiscoveryLabel($labelSource);
            }
        }

        return '';
    }

    private function cleanupDiscoveryImageLabelSource(string $value): string
    {
        $value = trim($value, " \t\n\r\0\x0B/_-");
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/_image$/i', '', $value) ?? $value;
        return trim($value, " \t\n\r\0\x0B/_-");
    }

    private function humanizeDiscoveryLabel(string $value): string
    {
        $value = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', trim($value)) ?? trim($value);
        $value = preg_replace('/[^A-Za-z0-9]+/', ' ', $value) ?? $value;
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return ucwords(strtolower($value));
    }

    private function getDeviceTrackerAttributePositionOffset(string $attribute): int
    {
        $index = array_search($attribute, HADeviceTrackerDefinitions::ATTRIBUTE_ORDER, true);
        return $index === false ? 90 : (6 + (int)$index);
    }

    private function getUpdateAttributePositionOffset(string $attribute): int
    {
        $index = array_search($attribute, HAUpdateDefinitions::ATTRIBUTE_ORDER, true);
        return $index === false ? 90 : (6 + (int)$index);
    }

    private function buildDeviceTrackerAttributePresentation(string $attribute, array $attributes): array
    {
        unset($attributes);

        $suffix = match ($attribute) {
            'latitude', 'longitude' => "\u{00B0}",
            'gps_accuracy', 'altitude' => 'm',
            default => ''
        };

        $digits = match ($attribute) {
            'latitude', 'longitude' => 6,
            'altitude' => 1,
            default => null
        };

        return array_filter([
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'DIGITS' => $digits,
            'SUFFIX' => $suffix !== '' ? (' ' . $suffix) : null
        ], static fn(mixed $value): bool => $value !== null);
    }

    private function buildUpdateAttributePresentation(string $attribute): array
    {
        if ($attribute === HAUpdateDefinitions::ATTRIBUTE_IN_PROGRESS) {
            return [
                'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH
            ];
        }

        if ($attribute === HAUpdateDefinitions::ATTRIBUTE_UPDATE_PERCENTAGE) {
            return [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'DIGITS' => 1,
                'SUFFIX' => ' %'
            ];
        }

        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION
        ];
    }

    private function ensureImagePreviewMedia(string $ident, string $name, int $basePosition, string $extension): void
    {
        $objectId = @$this->GetIDForIdent($ident);
        if ($objectId !== false) {
            $object = IPS_GetObject($objectId);
            if (($object['ObjectType'] ?? null) !== OBJECTTYPE_MEDIA) {
                return;
            }

            $this->syncImagePreviewMediaMeta($objectId, $name, $basePosition, $extension);
            return;
        }

        $mediaId = IPS_CreateMedia(MEDIATYPE_IMAGE);
        IPS_SetParent($mediaId, $this->InstanceID);
        IPS_SetIdent($mediaId, $ident);
        $this->syncImagePreviewMediaMeta($mediaId, $name, $basePosition, $extension);
    }

    private function syncImagePreviewMediaMeta(int $mediaId, string $name, int $basePosition, string $extension): void
    {
        IPS_SetName($mediaId, $name);
        IPS_SetPosition($mediaId, $basePosition + 20);
        IPS_SetParent($mediaId, $this->InstanceID);

        $ident = (string)(IPS_GetObject($mediaId)['ObjectIdent'] ?? '');
        if ($ident === '') {
            return;
        }

        $safeIdent = preg_replace('/\W/', '_', $ident) ?? $ident;
        IPS_SetMediaFile($mediaId, 'media/ha_image_preview_' . $safeIdent . '.' . $extension, false);

        $media = IPS_GetMedia($mediaId);
        if ((int)($media['MediaSize'] ?? 0) === 0) {
            IPS_SetMediaContent($mediaId, 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMBA0b9XQAAAABJRU5ErkJggg==');
        }
    }

    private function countActiveStatusEntities(array $entities): int
    {
        $count = 0;
        foreach ($entities as $entity) {
            if (!($entity['create_var'] ?? false)) {
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

        $this->UnregisterVariable($ident);
    }

    private function cleanupObsoleteVariables(array $activeIdents): void
    {
        $activeLookup = array_fill_keys($activeIdents, true);
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childId) {
            $object = IPS_GetObject($childId);
            $objectType = (int)($object['ObjectType'] ?? 0);
            if (!in_array($objectType, [OBJECTTYPE_VARIABLE, OBJECTTYPE_MEDIA], true)) {
                continue;
            }

            $ident = $object['ObjectIdent'] ?? '';
            if ($ident === '' || isset($activeLookup[$ident])) {
                continue;
            }

            if ($objectType === OBJECTTYPE_VARIABLE && $this->markVariableAsLegacy($childId)) {
                $this->debugExpert(__FUNCTION__, 'Variable als veraltet markiert', [
                    'ObjectID' => $childId,
                    'Ident' => $ident
                ]);
            }

            if ($objectType === OBJECTTYPE_MEDIA && str_ends_with($ident, self::IMAGE_PREVIEW_SUFFIX)) {
                IPS_DeleteMedia($childId, true);
                $this->debugExpert(__FUNCTION__, 'Medienobjekt entfernt', [
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
            if (!$entity['create_var']) {
                continue;
            }

            foreach ([$entity['state_topic'], $entity['latest_version_topic'], $entity['json_attributes_topic']] as $topic) {
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
                if ($entity['create_var']) {
                    $index['state'][$stateTopic][$entityKey] = true;
                }
            }

            $latestVersionTopic = trim((string)($entity['latest_version_topic'] ?? ''), '/');
            if ($latestVersionTopic !== '') {
                $index['topics'][$latestVersionTopic] = true;
                if ($entity['create_var']) {
                    $index['state'][$latestVersionTopic][$entityKey] = true;
                }
            }

            $eventFallback = $this->getEventStateFallback($entity);
            if ($entity['create_var'] && $eventFallback !== null) {
                $fallbackTopic = trim((string)($eventFallback['topic'] ?? ''), '/');
                if ($fallbackTopic !== '') {
                    $index['topics'][$fallbackTopic] = true;
                    $index['state'][$fallbackTopic][$entityKey] = true;
                }
            }

            if (!$entity['create_var']) {
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

        foreach (['warnings', 'state', 'attributes', 'availability'] as $bucket) {
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
                if (!is_string($topic) || !is_array($keys) || trim($topic, '/') === '') {
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

        $pattern = '.*"Topic":"(?:' . implode('|', $this->buildReceiveFilterRegexParts($topics)) . ')".*';
        $this->SetReceiveDataFilter($pattern);
    }

    /**
     * Fasst verwandte Topics ueber ihren gemeinsamen Praefix zusammen (geteilter Cluster-Kern in
     * HADomainCatalog), statt jedes Topic einzeln aufzulisten. Reduziert die Zahl der Regex-Alternativen,
     * die der Kernel pro Nachricht je Kind-Instanz auswertet. Kollabierte Muster sind geringfuegig breiter;
     * unkritisch, da ReceiveData nicht zugeordnete Topics ueber den Topic-Index ohnehin verwirft.
     *
     * @param string[] $topics
     * @return string[]
     */
    private function buildReceiveFilterRegexParts(array $topics): array
    {
        $names = array_values(array_unique(array_filter($topics, static fn(string $t): bool => $t !== '')));
        sort($names, SORT_STRING);

        $minPrefix = 3;
        $parts = [];
        foreach (HADomainCatalog::clusterByCommonPrefix($names, $minPrefix) as $cluster) {
            $members = $cluster['members'];
            $prefix = $cluster['prefix'];

            if (count($members) >= 2 && strlen($prefix) >= $minPrefix) {
                $parts[] = $this->buildReceiveFilterTopicPattern($prefix) . '[^"]*';
                continue;
            }

            foreach ($members as $name) {
                $parts[] = $this->buildReceiveFilterTopicPattern($name);
            }
        }

        return $parts;
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

            $payload = (string)($item['payload'] ?? '');
            if (!empty($item['payload_is_binary'])) {
                $payloadBase64 = trim((string)($item['payload_base64'] ?? ''));
                if ($payloadBase64 !== '') {
                    $decoded = base64_decode($payloadBase64, true);
                    if ($decoded !== false) {
                        $payload = $decoded;
                    }
                }
            }

            $result[$topic] = [
                'payload' => $payload,
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

        if ((string)($entity['component'] ?? '') === HAImageDefinitions::DOMAIN) {
            $this->applyImagePayload($entity, $payload, $receivedAt);
            return;
        }

        if ((string)($entity['component'] ?? '') === HAUpdateDefinitions::DOMAIN) {
            $this->applyUpdatePayload($entity, $topic, $payload);
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
        $component = (string)($entity['component'] ?? '');
        if ($component === HALightDefinitions::DOMAIN) {
            $this->applyLightRuntimeAttributes($entity, $payload);
            return;
        }

        if ($component === HADeviceTrackerDefinitions::DOMAIN) {
            $this->applyDeviceTrackerAttributes($entity, $payload);
            return;
        }

        if ($component === HAUpdateDefinitions::DOMAIN) {
            $this->applyUpdatePayload($entity, (string)($entity['json_attributes_topic'] ?? ''), $payload);
        }
    }

    private function applyImagePayload(array $entity, string $payload, int $receivedAt = 0): void
    {
        $contentType = (string)($entity['metadata']['content_type'] ?? '');
        $payload = $this->normalizeImagePayload($payload, $contentType);
        if ($payload === null) {
            return;
        }

        $mediaIdent = $this->buildImagePreviewIdent((string)($entity['ident_prefix'] ?? $entity['ident']));
        $mediaId = @$this->GetIDForIdent($mediaIdent);
        if ($mediaId === false || (IPS_GetObject($mediaId)['ObjectType'] ?? null) !== OBJECTTYPE_MEDIA) {
            return;
        }

        $extension = $this->resolveImagePreviewExtension($contentType, $payload);
        $this->syncImagePreviewMediaMeta($mediaId, $this->getImagePreviewMediaName($entity), $this->getEntityBasePosition($entity), $extension);
        IPS_SetMediaContent($mediaId, base64_encode($payload));

        $timestamp = $receivedAt > 0 ? $receivedAt : time();
        if (@$this->GetIDForIdent((string)$entity['ident']) !== false) {
            $this->SetValue((string)$entity['ident'], $timestamp);
        }
    }

    private function normalizeImagePayload(string $payload, string $contentType): ?string
    {
        if ($payload === '' || $payload === '[binary payload omitted]') {
            return null;
        }

        if ($this->looksLikeImagePayload($payload, $contentType)) {
            return $payload;
        }

        $trimmed = trim($payload);
        if ($trimmed === '') {
            return null;
        }

        $candidate = preg_replace('/\s+/', '', $trimmed) ?? $trimmed;
        if ($candidate !== '' && strlen($candidate) % 4 === 0 && preg_match('/^[A-Za-z0-9+\/=]+$/', $candidate) === 1) {
            $decoded = base64_decode($candidate, true);
            if (is_string($decoded) && $decoded !== '' && $this->looksLikeImagePayload($decoded, $contentType)) {
                return $decoded;
            }
        }

        return $payload;
    }

    private function resolveImagePreviewExtension(string $contentType, string $payload): string
    {
        $extension = $this->detectImagePreviewExtension($contentType);
        if ($extension !== 'png' || str_contains(strtolower(trim($contentType)), 'png')) {
            return $extension;
        }

        return $this->detectImagePayloadExtension($payload);
    }

    private function detectImagePayloadExtension(string $payload): string
    {
        foreach ([
            'png' => static fn(string $value): bool => str_starts_with($value, "\x89PNG\r\n\x1A\n"),
            'jpg' => static fn(string $value): bool => str_starts_with($value, "\xFF\xD8\xFF"),
            'gif' => static fn(string $value): bool => str_starts_with($value, 'GIF87a') || str_starts_with($value, 'GIF89a'),
            'webp' => static fn(string $value): bool => str_starts_with($value, 'RIFF') && substr($value, 8, 4) === 'WEBP',
            'bmp' => static fn(string $value): bool => str_starts_with($value, 'BM'),
            'svg' => fn(string $value): bool => $this->looksLikeSvgImagePayload($value)
        ] as $extension => $matcher) {
            if ($matcher($payload)) {
                return $extension;
            }
        }

        return 'png';
    }

    private function looksLikeImagePayload(string $payload, string $contentType): bool
    {
        $detectedExtension = $this->detectImagePayloadExtension($payload);
        $normalizedType = strtolower(trim($contentType));
        if ($normalizedType === '') {
            return $detectedExtension !== 'png' || str_starts_with($payload, "\x89PNG\r\n\x1A\n");
        }

        return match ($normalizedType) {
            'image/jpeg', 'image/jpg' => $detectedExtension === 'jpg',
            'image/gif' => $detectedExtension === 'gif',
            'image/webp' => $detectedExtension === 'webp',
            'image/bmp' => $detectedExtension === 'bmp',
            'image/svg+xml' => $detectedExtension === 'svg',
            default => str_starts_with($payload, "\x89PNG\r\n\x1A\n")
        };
    }

    private function looksLikeSvgImagePayload(string $payload): bool
    {
        $trimmed = ltrim($payload);
        if ($trimmed === '') {
            return false;
        }

        if (str_starts_with($trimmed, '<svg')) {
            return true;
        }

        return str_starts_with($trimmed, '<?xml') && str_contains($trimmed, '<svg');
    }

    private function applyDeviceTrackerAttributes(array $entity, string $payload): void
    {
        $attributes = $this->extractDeviceTrackerAttributesFromPayload($payload);
        $sourceType = $this->normalizeNullableString($entity['metadata']['source_type'] ?? null);
        if ($sourceType !== null && !array_key_exists('source_type', $attributes)) {
            $attributes['source_type'] = $sourceType;
        }

        if ($attributes === []) {
            return;
        }

        foreach ($attributes as $attribute => $value) {
            $meta = HADeviceTrackerDefinitions::ATTRIBUTE_DEFINITIONS[$attribute] ?? null;
            if (!is_array($meta)) {
                continue;
            }

            $ident = $this->buildDeviceTrackerAttributeIdent((string)($entity['ident_prefix'] ?? $entity['ident']), $attribute);
            $variableId = @$this->GetIDForIdent($ident);
            if ($variableId === false) {
                continue;
            }

            $castValue = $this->castSensorValue($value, (int)($meta['type'] ?? VARIABLETYPE_STRING));
            if ($castValue !== null) {
                $this->SetValue($ident, $castValue);
            }
        }

    }

    private function applyUpdatePayload(array $entity, string $topic, string $payload): void
    {
        $normalizedTopic = trim($topic, '/');
        $latestVersionTopic = trim((string)($entity['latest_version_topic'] ?? ''), '/');
        $attributesTopic = trim((string)($entity['json_attributes_topic'] ?? ''), '/');

        $context = $this->readCurrentUpdateAttributeContext($entity);
        $incomingContext = [];

        if ($normalizedTopic !== '' && $normalizedTopic === $latestVersionTopic) {
            $latestVersion = $this->extractUpdateLatestVersionPayload($entity, $payload);
            if ($latestVersion !== null) {
                $incomingContext[HAUpdateDefinitions::ATTRIBUTE_LATEST_VERSION] = $latestVersion;
            }
        } elseif ($normalizedTopic !== '' && $normalizedTopic === $attributesTopic) {
            $incomingContext = $this->extractUpdateContextFromAttributesPayload($payload);
        } else {
            $incomingContext = $this->extractUpdateContextFromStatePayload($entity, $payload);
        }

        if ($incomingContext === [] && $context === []) {
            return;
        }

        $context = array_merge($context, $incomingContext);
        $this->applyUpdateContextToVariables($entity, $context);

        $availability = $this->resolveUpdateAvailability($context);
        if ($availability === null) {
            return;
        }

        $ident = (string)($entity['ident'] ?? '');
        if ($ident !== '' && @$this->GetIDForIdent($ident) !== false) {
            $this->SetValue($ident, $availability);
        }
    }

    private function extractUpdateContextFromCachedTopics(array $entity, array $cachedTopics): array
    {
        $context = $this->getStaticUpdateMetadataContext($entity);

        $stateTopic = trim((string)($entity['state_topic'] ?? ''), '/');
        if ($stateTopic !== '' && isset($cachedTopics[$stateTopic])) {
            $context = array_merge($context, $this->extractUpdateContextFromStatePayload($entity, (string)($cachedTopics[$stateTopic]['payload'] ?? '')));
        }

        $latestVersionTopic = trim((string)($entity['latest_version_topic'] ?? ''), '/');
        if ($latestVersionTopic !== '' && isset($cachedTopics[$latestVersionTopic])) {
            $latestVersion = $this->extractUpdateLatestVersionPayload($entity, (string)($cachedTopics[$latestVersionTopic]['payload'] ?? ''));
            if ($latestVersion !== null) {
                $context[HAUpdateDefinitions::ATTRIBUTE_LATEST_VERSION] = $latestVersion;
            }
        }

        $attributesTopic = trim((string)($entity['json_attributes_topic'] ?? ''), '/');
        if ($attributesTopic !== '' && isset($cachedTopics[$attributesTopic])) {
            $context = array_merge($context, $this->extractUpdateContextFromAttributesPayload((string)($cachedTopics[$attributesTopic]['payload'] ?? '')));
        }

        return $context;
    }

    private function extractUpdateContextFromStatePayload(array $entity, string $payload): array
    {
        $context = $this->extractUpdateContextFromPayload($this->extractRawPayloadValue($payload));
        $installedVersion = $this->castUpdateAttributeValue(
            HAUpdateDefinitions::ATTRIBUTE_INSTALLED_VERSION,
            $this->extractStateValue($entity, $payload)
        );
        if ($installedVersion !== null) {
            $context[HAUpdateDefinitions::ATTRIBUTE_INSTALLED_VERSION] = $installedVersion;
        }

        return $context;
    }

    private function extractUpdateContextFromAttributesPayload(string $payload): array
    {
        return $this->extractUpdateContextFromPayload($this->extractRawPayloadValue($payload));
    }

    private function extractUpdateContextFromPayload(mixed $payloadValue): array
    {
        if (!is_array($payloadValue)) {
            return [];
        }

        $context = [];
        foreach (HAUpdateDefinitions::ATTRIBUTE_ORDER as $attribute) {
            if (!array_key_exists($attribute, $payloadValue)) {
                continue;
            }

            $castValue = $this->castUpdateAttributeValue($attribute, $payloadValue[$attribute]);
            if ($castValue !== null) {
                $context[$attribute] = $castValue;
            }
        }

        return $context;
    }

    private function extractUpdateLatestVersionPayload(array $entity, string $payload): ?string
    {
        $template = is_array($entity['metadata']['latest_version_template'] ?? null) ? $entity['metadata']['latest_version_template'] : null;
        $value = (is_array($template) && ($template['supported'] ?? false))
            ? $this->extractValueFromTemplate($template, $payload)
            : $this->extractRawPayloadValue($payload);

        $castValue = $this->castUpdateAttributeValue(HAUpdateDefinitions::ATTRIBUTE_LATEST_VERSION, $value);
        return is_string($castValue) ? $castValue : null;
    }

    private function getStaticUpdateMetadataContext(array $entity): array
    {
        $metadata = is_array($entity['metadata'] ?? null) ? $entity['metadata'] : [];
        $context = [];

        foreach ([
            HAUpdateDefinitions::ATTRIBUTE_TITLE,
            HAUpdateDefinitions::ATTRIBUTE_RELEASE_SUMMARY,
            HAUpdateDefinitions::ATTRIBUTE_RELEASE_URL
        ] as $attribute) {
            $value = $this->castUpdateAttributeValue($attribute, $metadata[$attribute] ?? null);
            if ($value !== null) {
                $context[$attribute] = $value;
            }
        }

        return $context;
    }

    private function readCurrentUpdateAttributeContext(array $entity): array
    {
        $context = $this->getStaticUpdateMetadataContext($entity);
        $identPrefix = (string)($entity['ident_prefix'] ?? $entity['ident']);

        foreach (HAUpdateDefinitions::ATTRIBUTE_ORDER as $attribute) {
            $ident = $this->buildUpdateAttributeIdent($identPrefix, $attribute);
            $variableId = @$this->GetIDForIdent($ident);
            if ($variableId === false) {
                continue;
            }

            $value = GetValue($variableId);
            $castValue = $this->castUpdateAttributeValue($attribute, $value);
            if ($castValue !== null) {
                $context[$attribute] = $castValue;
            }
        }

        return $context;
    }

    private function applyUpdateContextToVariables(array $entity, array $context): void
    {
        foreach ($context as $attribute => $value) {
            if (!isset(HAUpdateDefinitions::ATTRIBUTE_DEFINITIONS[$attribute])) {
                continue;
            }

            $ident = $this->ensureUpdateAttributeVariable($entity, $attribute);
            if ($ident === null || @$this->GetIDForIdent($ident) === false) {
                continue;
            }

            $this->SetValue($ident, $value);
        }
    }

    private function ensureUpdateAttributeVariable(array $entity, string $attribute): ?string
    {
        $meta = HAUpdateDefinitions::ATTRIBUTE_DEFINITIONS[$attribute] ?? null;
        if (!is_array($meta)) {
            return null;
        }

        $ident = $this->buildUpdateAttributeIdent((string)($entity['ident_prefix'] ?? $entity['ident']), $attribute);
        $variableType = (int)($meta['type'] ?? VARIABLETYPE_STRING);
        $this->recreateVariableIfTypeChanged($ident, $variableType);
        $this->MaintainVariable(
            $ident,
            $this->Translate((string)($meta['caption'] ?? $attribute)),
            $variableType,
            $this->buildUpdateAttributePresentation($attribute),
            $this->getEntityBasePosition($entity) + $this->getUpdateAttributePositionOffset($attribute),
            true
        );

        $variableId = @$this->GetIDForIdent($ident);
        if ($variableId !== false) {
            $this->DisableAction($ident);
        }

        return $ident;
    }

    private function shouldCreateUpdateAttribute(array $entity, string $attribute, array $attributeContext): bool
    {
        if (array_key_exists($attribute, $attributeContext)) {
            return true;
        }

        $hasStateTopic = trim((string)($entity['state_topic'] ?? '')) !== '';
        $hasLatestVersionTopic = trim((string)($entity['latest_version_topic'] ?? '')) !== '';
        $hasAttributesTopic = trim((string)($entity['json_attributes_topic'] ?? '')) !== '';

        return match ($attribute) {
            HAUpdateDefinitions::ATTRIBUTE_INSTALLED_VERSION => $hasStateTopic,
            HAUpdateDefinitions::ATTRIBUTE_LATEST_VERSION => $hasStateTopic || $hasLatestVersionTopic,
            HAUpdateDefinitions::ATTRIBUTE_SKIPPED_VERSION,
            HAUpdateDefinitions::ATTRIBUTE_IN_PROGRESS,
            HAUpdateDefinitions::ATTRIBUTE_UPDATE_PERCENTAGE => $hasAttributesTopic,
            HAUpdateDefinitions::ATTRIBUTE_TITLE,
            HAUpdateDefinitions::ATTRIBUTE_RELEASE_SUMMARY,
            HAUpdateDefinitions::ATTRIBUTE_RELEASE_URL => $hasAttributesTopic,
            default => false
        };
    }

    private function resolveUpdateAvailability(array $context): ?bool
    {
        $installedVersion = $this->normalizeNullableString($this->scalarToString($context[HAUpdateDefinitions::ATTRIBUTE_INSTALLED_VERSION] ?? null));
        $latestVersion = $this->normalizeNullableString($this->scalarToString($context[HAUpdateDefinitions::ATTRIBUTE_LATEST_VERSION] ?? null));
        if ($installedVersion === null || $latestVersion === null) {
            return null;
        }

        if ($this->isIndeterminateStateValue($installedVersion) || $this->isIndeterminateStateValue($latestVersion)) {
            return null;
        }

        return $installedVersion !== $latestVersion;
    }

    private function castUpdateAttributeValue(string $attribute, mixed $value): string|bool|float|int|null
    {
        return match ($attribute) {
            HAUpdateDefinitions::ATTRIBUTE_IN_PROGRESS => $this->normalizeUpdateBooleanAttributeValue($value),
            HAUpdateDefinitions::ATTRIBUTE_UPDATE_PERCENTAGE => $this->normalizeUpdateNumericAttributeValue($value),
            default => $this->normalizeUpdateStringAttributeValue($value)
        };
    }

    private function normalizeUpdateBooleanAttributeValue(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (float)$value !== 0.0;
        }

        $stringValue = $this->normalizeNullableString($this->scalarToString($value));
        if ($stringValue === null) {
            return null;
        }

        return match (strtolower($stringValue)) {
            '1', 'true', 'on', 'yes', 'ja' => true,
            '0', 'false', 'off', 'no', 'nein' => false,
            default => null
        };
    }

    private function normalizeUpdateNumericAttributeValue(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float)$value;
        }

        if (!is_string($value)) {
            return null;
        }

        $normalized = str_replace(',', '.', trim($value));
        return is_numeric($normalized) ? (float)$normalized : null;
    }

    private function normalizeUpdateStringAttributeValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_scalar($value)) {
            return trim((string)$value);
        }

        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            return null;
        }
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

        if ($component === HADeviceTrackerDefinitions::DOMAIN) {
            return $this->normalizeDeviceTrackerStateValue($value, $entity);
        }

        if ($component === HALockDefinitions::DOMAIN) {
            return $this->normalizeLockStateValue($value, $entity);
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

    private function normalizeDeviceTrackerStateValue(mixed $value, array $entity): ?string
    {
        $text = $this->normalizeNullableString($this->scalarToString($value));
        if ($text === null) {
            return null;
        }

        $normalized = strtolower(trim($text));
        if ($normalized === '') {
            return null;
        }

        foreach ([
            $this->normalizeNullableString($this->scalarToString($entity['payload_home'] ?? null)) => 'home',
            $this->normalizeNullableString($this->scalarToString($entity['payload_not_home'] ?? null)) => 'not_home',
            $this->normalizeNullableString($this->scalarToString($entity['payload_reset'] ?? null)) => ''
        ] as $raw => $mapped) {
            if ($raw !== null && $normalized === strtolower($raw)) {
                return $mapped;
            }
        }

        return match ($normalized) {
            'home' => 'home',
            'not_home' => 'not_home',
            default => trim($text)
        };
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

    private function normalizeLockStateValue(mixed $value, array $entity): mixed
    {
        $text = $this->normalizeNullableString($this->scalarToString($value));
        if ($text === null) {
            return $value;
        }

        $normalizedValue = strtolower(trim($text));
        if ($normalizedValue === '') {
            return null;
        }

        $metadata = is_array($entity['metadata'] ?? null) ? $entity['metadata'] : [];
        $candidates = [
            'locked' => $entity['state_locked'] ?? ($metadata['state_locked'] ?? 'locked'),
            'unlocking' => $entity['state_unlocking'] ?? ($metadata['state_unlocking'] ?? 'unlocking'),
            'unlocked' => $entity['state_unlocked'] ?? ($metadata['state_unlocked'] ?? 'unlocked'),
            'locking' => $entity['state_locking'] ?? ($metadata['state_locking'] ?? 'locking'),
            'jammed' => $entity['state_jammed'] ?? ($metadata['state_jammed'] ?? 'jammed'),
            'opening' => 'opening',
            'open' => $metadata['state_open'] ?? 'open'
        ];

        foreach ($candidates as $normalized => $raw) {
            $raw = strtolower(trim((string)$raw));
            if ($raw !== '' && $normalizedValue === $raw) {
                return $normalized;
            }
        }

        return match ($normalizedValue) {
            'lock', 'locked' => 'locked',
            'unlock', 'unlocked' => 'unlocked',
            'locking' => 'locking',
            'unlocking' => 'unlocking',
            'jammed' => 'jammed',
            'opening' => 'opening',
            'open', 'opened', 'open_latch', 'unlatch' => 'open',
            default => $normalizedValue
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
        if ($field === '' || !preg_match('/^\w+$/', $field)) {
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
            return $value !== 0;
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
        if (!$entity['create_var']) {
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
        if ($component === HALockDefinitions::DOMAIN) {
            return false;
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

        if ($entity['component'] === HALockDefinitions::DOMAIN) {
            return $this->buildLockCommandPayload($entity, $value);
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

    private function buildLockCommandPayload(array $entity, mixed $value): ?string
    {
        $command = HALockDefinitions::normalizeCommand($value);
        if ($command === '') {
            return null;
        }

        return $this->buildLockCommandPayloadFromCommand($entity, $command);
    }

    private function buildLockCommandPayloadFromCommand(array $entity, string $command): ?string
    {
        $payload = match ($command) {
            'lock' => $entity['payload_lock'] ?? 'lock',
            'unlock' => $entity['payload_unlock'] ?? 'unlock',
            'open' => $entity['payload_open'] ?? 'open',
            default => null
        };

        $payload = $this->scalarToString($payload);
        if ($payload === null) {
            return null;
        }

        if ($entity['command_mode'] === 'template') {
            return $this->renderCommandTemplate($entity['command_template'], $payload);
        }

        return $payload;
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

    private function handleLockActionRequest(string $ident, array $entity, mixed $value): void
    {
        $metadata = is_array($entity['metadata'] ?? null) ? $entity['metadata'] : [];
        $action = is_numeric($value) ? (int)$value : null;
        [$requiredFeature, $command] = $this->resolveLockActionCommand($action);
        if ($command === null) {
            return;
        }

        $supported = $this->getSupportedFeatureFlags($metadata);
        if ($requiredFeature !== 0 && $supported !== 0 && !$this->supportsFeatureFlag($supported, $requiredFeature)) {
            return;
        }

        $payload = $this->buildLockCommandPayloadFromCommand($entity, $command);
        if ($payload === null) {
            $this->debugExpert(__FUNCTION__, 'Lock-Command konnte nicht erstellt werden', [
                'Ident' => $ident,
                'EntityKey' => $entity['entity_key'] ?? '',
                'Command' => $command
            ], true);
            return;
        }

        $this->debugExpert(__FUNCTION__, 'Sende Lock-Aktion', [
            'Ident' => $ident,
            'EntityKey' => $entity['entity_key'] ?? '',
            'Topic' => $entity['command_topic'] ?? '',
            'Payload' => $payload,
            'Command' => $command
        ]);

        $this->sendMqttMessage((string)$entity['command_topic'], $payload, (int)$entity['qos'], (bool)$entity['retain']);
        $this->resetTriggerActionValue($ident);

        if ($entity['optimistic'] || $entity['state_topic'] === '') {
            $this->applyOptimisticValue($entity, $command);
        }
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
                $this->debugExpert(__FUNCTION__, 'Cover-Aktionstemplate wird nicht unterstützt', [
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

    private function resolveLockActionCommand(?int $action): array
    {
        return match ($action) {
            HALockDefinitions::ACTION_LOCK => [0, 'lock'],
            HALockDefinitions::ACTION_UNLOCK => [0, 'unlock'],
            HALockDefinitions::ACTION_OPEN => [HALockDefinitions::FEATURE_OPEN, 'open'],
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
            : number_format($numericValue, $digits, '.', '')
              |> (static fn($x) => rtrim($x, '0'))
              |> (static fn($x) => rtrim($x, '.'));
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

        return preg_replace('/{{\s*value\s*}}/', $value, $raw, 1);
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

        if ($entity['component'] === HALockDefinitions::DOMAIN) {
            $normalized = $this->normalizeLockStateValue($value, $entity);
            if ($normalized === null) {
                return;
            }

            $stringValue = $this->scalarToString($normalized);
            if ($stringValue !== null) {
                $this->SetValue($ident, $stringValue);
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
                'missing' => 'MQTT Send übersprungen, kein Parent verbunden',
                'inactive' => 'MQTT Send übersprungen, Parent nicht aktiv',
                default => 'MQTT Send übersprungen, Parent ist nicht Home Assistant MQTT Discovery Splitter'
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

        $this->debugExpert(__FUNCTION__, 'MQTT Command an Splitter übergeben', [
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
            if ((string)($lightEntity['component'] ?? '') === HALockDefinitions::DOMAIN) {
                $identPrefix = (string)($lightEntity['ident_prefix'] ?? $lightEntity['ident']);
                if ($this->buildLockActionIdent($identPrefix) === $ident) {
                    return [
                        'type' => 'lock_action',
                        'entity' => $lightEntity
                    ];
                }
            }

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
        return array_find($entities, static fn($entity) => $entity['ident'] === $ident);
    }

    private function isEntityControllable(array $entity): bool
    {
        return $this->isEntityWritable($entity) || $this->hasLockActionControl($entity);
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
            $lastMqtt = $this->Translate('never');
        }

        $activeEntityCount = count(array_filter($entities, static fn(array $entity): bool => (bool)$entity['create_var']));
        $runtimeState = $this->determineRuntimeState($entities);
        $warningMap ??= $this->readStateWarnings();

        $this->updateFormFieldSafe('DiagLastMQTT', 'caption', sprintf($this->Translate('Last MQTT message: %s'), $lastMqtt));
        $this->updateFormFieldSafe('DiagTopics', 'caption', sprintf($this->Translate('Topics: %d'), count($topics)));
        $this->updateFormFieldSafe('DiagEntities', 'caption', sprintf($this->Translate('Entities (active/total): %d/%d'), $activeEntityCount, count($entities)));
        $this->updateFormFieldSafe('DiagResolution', 'caption', sprintf($this->Translate('Resolution: %s'), $this->Translate($runtimeState['resolution'])));
        $this->updateFormFieldSafe('DiagAvailability', 'caption', sprintf($this->Translate('Availability: %s'), $this->buildAvailabilitySummary($entities)));
        $this->updateFormFieldSafe('DiagWarnings', 'caption', sprintf($this->Translate('Warnings: %s'), $this->buildWarningSummary($warningMap)));
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
            if (!$entity['create_var']) {
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

        return sprintf($this->Translate('online %d | offline %d | unknown %d | n/a %d'), $online, $offline, $unknown, $notApplicable);
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
        $value = preg_replace('/\W+/', '_', $value) ?? $value;
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
            return ($record['is_current_session'] ?? false) ? 'current' : 'stale';
        }

        $eventFallback = $this->getEventStateFallback($entity);
        if ($eventFallback !== null) {
            $fallbackRecord = $cachedTopics[$eventFallback['topic']] ?? null;
            if (is_array($fallbackRecord)) {
                return ($fallbackRecord['is_current_session'] ?? false) ? 'current' : 'stale';
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

        if ((string)($entity['component'] ?? '') === HAImageDefinitions::DOMAIN) {
            $contentType = $this->normalizeNullableString($entity['metadata']['content_type'] ?? null);
            if ($contentType !== null) {
                $parts[] = 'image:' . $contentType;
            }
        }

        if ((string)($entity['component'] ?? '') === HADeviceTrackerDefinitions::DOMAIN) {
            $sourceType = $this->normalizeNullableString($entity['metadata']['source_type'] ?? null);
            if ($sourceType !== null) {
                $parts[] = 'tracker:' . $sourceType;
            }
        }

        return array_filter($parts, static fn(string $part): bool => $part !== '')
               |> array_values(...)
               |> (static fn($x) => implode(' | ', $x));
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
            return $this->Translate('none');
        }

        return sprintf($this->Translate($count === 1 ? '%d runtime warning' : '%d runtime warnings'), $count);
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

    private function isPerformanceLogEnabled(): bool
    {
        return (bool)@$this->ReadPropertyBoolean(self::PROP_ENABLE_PERFORMANCE_LOG);
    }

    private function logPerformanceSample(string $scope, float $startedAt, array $context = [], bool $force = false): void
    {
        if (!$this->isPerformanceLogEnabled()) {
            return;
        }

        $context = ['elapsed_ms' => round((microtime(true) - $startedAt) * 1000.0, 3)] + $context;
        $this->SendDebug('Performance', $scope . ' | ' . json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0);
    }

    private function logPerformanceMarker(string $scope, string $phase, array $context = []): void
    {
        if (!$this->isPerformanceLogEnabled()) {
            return;
        }

        $payload = ['phase' => $phase] + $context;
        $this->SendDebug('Performance', $scope . ' | ' . json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0);
    }
}
