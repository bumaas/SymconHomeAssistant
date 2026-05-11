<?php /** @noinspection PhpUnused */

/** @noinspection AutoloadingIssuesInspection */

declare(strict_types=1);

require_once __DIR__ . '/../libs/HACommonIncludes.php';

class HomeAssistantMQTTDiscoverySplitter extends IPSModuleStrict
{
    use HAParentConnectionTrait;
    use ModuleDebugTrait;

    private const string ATTRIBUTE_DISCOVERY_CACHE = 'MqttDiscoveryConfigCache';
    private const string ATTRIBUTE_TOPIC_PAYLOAD_CACHE = 'MqttTopicPayloadCache';
    private const string ATTRIBUTE_MQTT_SESSION_STATE = 'MqttSessionState';
    private const string EXPORT_FORMAT = 'ha_mqtt_discovery_bundle';
    private const int EXPORT_VERSION = 1;
    private const int DIAGNOSTIC_PREVIEW_LIMIT = 8;
    private const int OUTPUT_BUFFER_RESERVE_BYTES = 262144;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);

        $this->RegisterPropertyString('MQTTDiscoveryPrefix', 'homeassistant');
        $this->RegisterPropertyBoolean('EnableExpertDebug', false);
        $this->RegisterPropertyInteger('OutputBufferSize', 10);

        $this->RegisterAttributeString('LastMQTTMessage', '');
        $this->RegisterAttributeString(self::ATTRIBUTE_DISCOVERY_CACHE, '{}');
        $this->RegisterAttributeString(self::ATTRIBUTE_TOPIC_PAYLOAD_CACHE, '{}');
        $this->RegisterAttributeString(self::ATTRIBUTE_MQTT_SESSION_STATE, '{}');
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === FM_CONNECT) {
            $this->startNewMqttSession();
        } elseif ($Message === FM_DISCONNECT) {
            $this->markMqttSessionInactive();
        }

        if ($Message === FM_CONNECT || $Message === FM_DISCONNECT || $Message === IM_CHANGESTATUS) {
            $this->ApplyChanges();
        }
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->syncParentStatusMessageRegistration();
        $this->SetReceiveDataFilter('.*');
        $this->refreshMqttSessionState();
        $this->pruneCaches();
        $this->updateDiagnosticsLabels();

        $prefix = $this->getDiscoveryPrefix();
        if ($prefix === '') {
            $this->SetStatus(202);
            return;
        }

        if (!$this->hasCompatibleParentModule(HAIds::MODULE_MQTT_CLIENT)) {
            $this->SetStatus(201);
            $this->debugExpert('ApplyChanges', 'MQTT Parent ist nicht kompatibel.', $this->buildCurrentParentDebugContext(), true);
            return;
        }

        if (!$this->hasCompatibleActiveParentModule(HAIds::MODULE_MQTT_CLIENT)) {
            $this->SetStatus(201);
            $this->debugExpert('ApplyChanges', 'MQTT Parent ist nicht aktiv.', $this->buildCurrentParentDebugContext(), true);
            return;
        }

        $this->SetStatus(IS_ACTIVE);
    }

    public function GetCompatibleParents(): string
    {
        $parents = [
            'type' => 'connect',
            'modules' => [
                ['moduleID' => HAIds::MODULE_MQTT_CLIENT]
            ]
        ];

        return json_encode($parents, JSON_THROW_ON_ERROR);
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->applyCurrentDiagnosticsToForm($form);
        return json_encode($form, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function ExportDiscoveryBundle(bool $includeTopicPayloads = true, bool $includeAllCachedTopics = false, bool $currentSessionOnly = false): string
    {
        $bundle = $this->buildDiscoveryExportBundle([
            'include_topic_payloads' => $includeTopicPayloads,
            'include_all_cached_topics' => $includeAllCachedTopics,
            'current_session_only' => $currentSessionOnly
        ]);

        $json = json_encode($bundle, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $this->applyOutputBufferForStringResponse($json, 'ExportDiscoveryBundle');
        return $json;
    }

    public function ExportDiscoveryBundleDataUrl(bool $includeTopicPayloads = true, bool $includeAllCachedTopics = false, bool $currentSessionOnly = false): string
    {
        $json = $this->ExportDiscoveryBundle($includeTopicPayloads, $includeAllCachedTopics, $currentSessionOnly);
        $dataUrl = 'data:text/plain;charset=utf-8;base64,' . base64_encode($json);
        $this->applyOutputBufferForStringResponse($dataUrl, 'ExportDiscoveryBundleDataUrl');
        return $dataUrl;
    }

    public function ReconnectMqttParent(): void
    {
        if (!$this->hasCompatibleParentModule(HAIds::MODULE_MQTT_CLIENT)) {
            $this->debugExpert(__FUNCTION__, 'MQTT Parent ist nicht kompatibel.', $this->buildCurrentParentDebugContext(), true);
            $this->updateDiagnosticsLabels();
            return;
        }

        $mqttClientId = $this->getCurrentParentId();
        if ($mqttClientId <= 0 || !IPS_InstanceExists($mqttClientId)) {
            $this->debugExpert(__FUNCTION__, 'MQTT Parent nicht gefunden.', ['ParentID' => $mqttClientId], true);
            $this->updateDiagnosticsLabels();
            return;
        }

        $mqttClient = IPS_GetInstance($mqttClientId);
        $ioParentId = (int)($mqttClient['ConnectionID'] ?? 0);
        if ($ioParentId <= 0 || !IPS_InstanceExists($ioParentId)) {
            $this->debugExpert(__FUNCTION__, 'MQTT Parent hat keinen gueltigen IO-Parent.', [
                'MQTTParentID' => $mqttClientId,
                'IOParentID' => $ioParentId
            ], true);
            $this->updateDiagnosticsLabels();
            return;
        }

        $disconnected = IPS_DisconnectInstance($mqttClientId);
        $reconnected = IPS_ConnectInstance($mqttClientId, $ioParentId);

        $this->debugExpert(__FUNCTION__, 'MQTT Parent reconnect ausgefuehrt', [
            'MQTTParentID' => $mqttClientId,
            'IOParentID' => $ioParentId,
            'Disconnected' => $disconnected,
            'Reconnected' => $reconnected
        ], !$disconnected || !$reconnected);

        $this->updateDiagnosticsLabels();
    }

    public function ForwardData(string $JSONString): string
    {
        try {
            $data = json_decode($JSONString, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->debugExpert('ForwardData', 'Invalid JSON', ['Error' => $e->getMessage()]);
            return '';
        }

        $dataId = $data['DataID'] ?? '';
        if ($dataId === HAIds::DATA_MQTT_DISCOVERY_DEVICE_TO_SPLITTER) {
            if (array_key_exists('DiscoveryAction', $data)) {
                return $this->handleDiscoveryRequest($data);
            }

            $data['DataID'] = HAIds::DATA_MQTT_TX;
            return $this->SendDataToParent(json_encode($data, JSON_THROW_ON_ERROR));
        }

        return $this->SendDataToParent($JSONString);
    }

    public function ReceiveData(string $JSONString): string
    {
        try {
            $data = json_decode($JSONString, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->debugExpert('ReceiveData', 'Invalid JSON', ['Error' => $e->getMessage()]);
            return '';
        }

        $dataId = $data['DataID'] ?? '';
        if ($dataId === HAIds::DATA_MQTT_RX || $dataId === HAIds::DATA_MQTT_TX) {
            $this->refreshMqttSessionState();
            $this->WriteAttributeString('LastMQTTMessage', date('Y-m-d H:i:s'));

            $topic = (string)($data['Topic'] ?? '');
            $payload = $this->decodePayload((string)($data['Payload'] ?? ''));
            $metadata = [
                'retained' => array_key_exists('Retain', $data) ? (bool)$data['Retain'] : null,
                'qos' => array_key_exists('QualityOfService', $data) ? (int)$data['QualityOfService'] : null,
                'direction' => $dataId === HAIds::DATA_MQTT_TX ? 'tx' : 'rx'
            ];
            $this->updateTopicPayloadCacheFromMessage($topic, $payload, $metadata);
            $this->updateDiscoveryCacheFromMessage($topic, $payload, $metadata);
            $this->updateDiagnosticsLabels();

            $data['DataID'] = HAIds::DATA_MQTT_DISCOVERY_SPLITTER_TO_DEVICE;
            $this->SendDataToChildren(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return '';
        }

        $this->SendDataToChildren($JSONString);
        return '';
    }

    private function handleDiscoveryRequest(array $data): string
    {
        $action = trim((string)($data['DiscoveryAction'] ?? ''));
        if ($action === '') {
            return json_encode(['Error' => 'Missing DiscoveryAction'], JSON_THROW_ON_ERROR);
        }

        return match ($action) {
            'GetDiscoveryConfigs' => json_encode($this->buildDiscoveryResponse(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'GetTopicPayloads' => json_encode($this->buildTopicPayloadResponse($data['Topics'] ?? []), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ExportDiscoveryBundle' => json_encode($this->buildDiscoveryExportBundle($data['Options'] ?? null), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default => json_encode(['Error' => 'Unsupported DiscoveryAction'], JSON_THROW_ON_ERROR)
        };
    }

    private function updateDiagnosticsLabels(): void
    {
        foreach ($this->buildDiagnosticsCaptions() as $field => $caption) {
            $this->updateFormFieldSafe($field, 'caption', $caption);
        }
    }

    private function getInstanceStatusName(int $status): string
    {
        return match ($status) {
            IS_CREATING => 'IS_CREATING',
            IS_ACTIVE => 'IS_ACTIVE',
            IS_DELETING => 'IS_DELETING',
            IS_INACTIVE => 'IS_INACTIVE',
            IS_NOTCREATED => 'IS_NOTCREATED',
            default => ''
        };
    }

    private function updateFormFieldSafe(string $name, string $property, mixed $value): void
    {
        @ $this->UpdateFormField($name, $property, $value);
    }

    private function getDiscoveryPrefix(): string
    {
        $prefix = trim($this->ReadPropertyString('MQTTDiscoveryPrefix'));
        return trim($prefix, '/');
    }

    private function decodePayload(string $payloadHex): string
    {
        $decoded = hex2bin($payloadHex);
        return $decoded === false ? '' : $decoded;
    }

    private function updateDiscoveryCacheFromMessage(string $topic, string $payload, array $metadata = []): void
    {
        $topic = trim($topic, '/');
        if (!$this->isDiscoveryConfigTopic($topic)) {
            return;
        }

        $cache = $this->readDiscoveryCache();
        if (trim($payload) === '') {
            unset($cache[$topic]);
            $this->debugExpert(__FUNCTION__, 'Removed discovery config from cache', [
                'Topic' => $topic,
                'CacheCount' => count($cache)
            ]);
        } else {
            $cache[$topic] = $this->createCacheRecord($topic, $payload, $metadata);
            $this->debugExpert(__FUNCTION__, 'Cached discovery config', [
                'Topic' => $topic,
                'CacheCount' => count($cache)
            ]);
        }

        $this->writeDiscoveryCache($cache);
    }

    private function buildDiscoveryResponse(): array
    {
        $records = $this->annotateCacheRecords(array_values($this->getDiscoveryConfigRecords()));
        usort($records, static function (array $left, array $right): int {
            return strcmp((string)($left['topic'] ?? ''), (string)($right['topic'] ?? ''));
        });

        $topics = array_map(static fn(array $record): string => (string)($record['topic'] ?? ''), $records);
        $this->debugExpert(__FUNCTION__, 'Returning discovery config records', [
            'Count' => count($records),
            'Topics' => array_slice($topics, 0, 20)
        ]);

        return [
            'Items' => $records,
            'Count' => count($records),
            'DiscoveryPrefix' => $this->getDiscoveryPrefix()
        ];
    }

    private function buildTopicPayloadResponse(mixed $topics): array
    {
        $requestedTopics = $this->normalizeRequestedTopics($topics);
        $cache = $this->readTopicPayloadCache();
        $records = [];
        foreach ($requestedTopics as $topic) {
            $record = $cache[$topic] ?? null;
            if (!is_array($record)) {
                continue;
            }
            $records[] = $record;
        }

        $records = $this->annotateCacheRecords($records);

        usort($records, static function (array $left, array $right): int {
            return strcmp((string)($left['topic'] ?? ''), (string)($right['topic'] ?? ''));
        });

        return [
            'Items' => $records,
            'Count' => count($records)
        ];
    }

    private function buildDiscoveryExportBundle(mixed $options = null): array
    {
        $normalizedOptions = $this->normalizeExportOptions($options);
        $discoveryConfigs = array_values($this->getDiscoveryConfigRecords());
        if ($normalizedOptions['current_session_only']) {
            $discoveryConfigs = $this->filterCurrentSessionRecords($discoveryConfigs);
        }
        usort($discoveryConfigs, static function (array $left, array $right): int {
            return strcmp((string)($left['topic'] ?? ''), (string)($right['topic'] ?? ''));
        });
        $annotatedDiscoveryConfigs = $this->annotateCacheRecords($discoveryConfigs);
        $discoveryAnalysis = $this->analyzeDiscoveryConfigRecords($discoveryConfigs);
        $topicAnalysis = $this->analyzeReferencedRuntimeTopics($discoveryConfigs);

        $topicPayloads = [];
        if ($normalizedOptions['include_topic_payloads']) {
            if ($normalizedOptions['include_all_cached_topics']) {
                $topicPayloads = $this->getAllNonDiscoveryTopicPayloadRecords();
            } else {
                $referencedTopics = $this->collectReferencedTopicsFromDiscoveryConfigs($discoveryConfigs);
                $topicPayloads = $this->buildTopicPayloadResponse($referencedTopics)['Items'];
            }

            if ($normalizedOptions['current_session_only']) {
                $topicPayloads = $this->filterCurrentSessionRecords($topicPayloads);
            }
        }

        $topicPayloads = $this->annotateCacheRecords($topicPayloads);

        $bundle = [
            'format' => self::EXPORT_FORMAT,
            'version' => self::EXPORT_VERSION,
            'exported_at' => date(DATE_ATOM),
            'splitter' => [
                'instance_id' => $this->InstanceID,
                'instance_name' => IPS_GetName($this->InstanceID),
                'discovery_prefix' => $this->getDiscoveryPrefix()
            ],
            'session' => $this->buildExportSessionInfo(),
            'options' => [
                'include_topic_payloads' => $normalizedOptions['include_topic_payloads'],
                'include_all_cached_topics' => $normalizedOptions['include_all_cached_topics'],
                'current_session_only' => $normalizedOptions['current_session_only']
            ],
            'source' => [
                'producer_hint' => '',
                'producer_version' => '',
                'notes' => ''
            ],
            'diagnostics' => [
                'discovery_configs' => [
                    'total' => $discoveryAnalysis['total_count'],
                    'current_session' => $discoveryAnalysis['current_count'],
                    'stale' => $discoveryAnalysis['stale_count']
                ],
                'referenced_topic_payloads' => [
                    'referenced' => $topicAnalysis['referenced_count'],
                    'current_session' => $topicAnalysis['current_count'],
                    'stale' => $topicAnalysis['stale_count'],
                    'missing' => $topicAnalysis['missing_count'],
                    'extra_cached' => $topicAnalysis['extra_count']
                ]
            ],
            'referenced_topics' => [
                'all' => $topicAnalysis['referenced_topics'],
                'missing' => $topicAnalysis['missing_topics'],
                'stale' => $topicAnalysis['stale_topics'],
                'extra_cached' => $topicAnalysis['extra_topics']
            ],
            'discovery_configs' => $annotatedDiscoveryConfigs,
            'topic_payloads' => $topicPayloads,
            'stats' => [
                'discovery_config_count' => count($annotatedDiscoveryConfigs),
                'topic_payload_count' => count($topicPayloads)
            ]
        ];

        $this->debugExpert(__FUNCTION__, 'Built discovery export bundle', [
            'DiscoveryConfigCount' => $bundle['stats']['discovery_config_count'],
            'TopicPayloadCount' => $bundle['stats']['topic_payload_count'],
            'Options' => $bundle['options']
        ]);

        return $bundle;
    }

    private function getDiscoveryConfigRecords(): array
    {
        $prefix = $this->getDiscoveryPrefix();
        $result = [];
        foreach ($this->readDiscoveryCache() as $topic => $record) {
            if (!is_array($record) || !$this->isDiscoveryConfigTopic((string)$topic, $prefix)) {
                continue;
            }
            $result[$topic] = $record;
        }

        return $result;
    }

    private function getAllNonDiscoveryTopicPayloadRecords(): array
    {
        $records = array_values($this->readTopicPayloadCache());

        usort($records, static function (array $left, array $right): int {
            return strcmp((string)($left['topic'] ?? ''), (string)($right['topic'] ?? ''));
        });

        return $records;
    }

    private function pruneCaches(): void
    {
        $this->writeDiscoveryCache($this->getDiscoveryConfigRecords());
        $this->writeTopicPayloadCache($this->readTopicPayloadCache());
    }

    private function updateTopicPayloadCacheFromMessage(string $topic, string $payload, array $metadata = []): void
    {
        $topic = trim($topic, '/');
        if ($topic === '') {
            return;
        }

        $cache = $this->readTopicPayloadCache();
        if ($this->isDiscoveryConfigTopic($topic) || trim($payload) === '') {
            unset($cache[$topic]);
        } else {
            $cache[$topic] = $this->createCacheRecord($topic, $payload, $metadata);
        }

        $this->writeTopicPayloadCache($cache);
    }

    private function isDiscoveryConfigTopic(string $topic, ?string $prefix = null): bool
    {
        $topic = trim($topic, '/');
        if ($topic === '') {
            return false;
        }

        $prefix = trim((string)($prefix ?? $this->getDiscoveryPrefix()), '/');
        if ($prefix === '') {
            return false;
        }

        return str_starts_with($topic, $prefix . '/') && str_ends_with($topic, '/config');
    }

    private function readDiscoveryCache(): array
    {
        try {
            $decoded = json_decode($this->ReadAttributeString(self::ATTRIBUTE_DISCOVERY_CACHE), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return $this->normalizeCacheRecords($decoded, true);
    }

    private function readTopicPayloadCache(): array
    {
        try {
            $decoded = json_decode($this->ReadAttributeString(self::ATTRIBUTE_TOPIC_PAYLOAD_CACHE), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return $this->normalizeCacheRecords($decoded, false);
    }

    private function writeDiscoveryCache(array $cache): void
    {
        $this->WriteAttributeString(
            self::ATTRIBUTE_DISCOVERY_CACHE,
            json_encode($cache, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    private function writeTopicPayloadCache(array $cache): void
    {
        $this->WriteAttributeString(
            self::ATTRIBUTE_TOPIC_PAYLOAD_CACHE,
            json_encode($cache, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    private function normalizeRequestedTopics(mixed $topics): array
    {
        if (!is_array($topics)) {
            return [];
        }

        $result = [];
        foreach ($topics as $topic) {
            if (!is_string($topic)) {
                continue;
            }
            $topic = trim($topic, '/');
            if ($topic === '') {
                continue;
            }
            $result[$topic] = true;
        }

        return array_keys($result);
    }

    private function buildDiagnosticsCaptions(): array
    {
        $instance = IPS_GetInstance($this->InstanceID);
        $instanceStatus = (int)($instance['InstanceStatus'] ?? 0);

        $last = $this->ReadAttributeString('LastMQTTMessage');
        if ($last === '') {
            $last = 'nie';
        }

        $parent = $this->buildCurrentParentDebugContext();
        $parentId = (int)($parent['ParentID'] ?? 0);
        $parentStatus = (int)($parent['ParentStatus'] ?? 0);
        $parentName = (string)($parent['ParentName'] ?? '');

        $statusName = $this->getInstanceStatusName($parentStatus);
        $nameSuffix = $parentName !== '' ? ' (' . $parentName . ')' : '';
        $statusSuffix = $statusName !== '' ? ' (' . $statusName . ')' : '';

        $discoveryAnalysis = $this->analyzeDiscoveryConfigRecords();
        $topicAnalysis = $this->analyzeReferencedRuntimeTopics();

        return [
            'DiagInstance' => 'Instance: ' . $this->InstanceID . ' | Status ' . $instanceStatus,
            'DiagParent' => 'MQTT Parent: ' . $parentId . $nameSuffix . ' | Status ' . $parentStatus . $statusSuffix,
            'DiagSession' => 'MQTT Session: ' . $this->buildSessionCaption(),
            'LastMQTTMessage' => 'Letzte MQTT-Message: ' . $last,
            'DiagDiscovery' => sprintf(
                'MQTT Discovery Prefix: %s | Configs gesamt/aktuell/stale: %d/%d/%d',
                $this->getDiscoveryPrefix(),
                $discoveryAnalysis['total_count'],
                $discoveryAnalysis['current_count'],
                $discoveryAnalysis['stale_count']
            ),
            'DiagDiscoveryPreview' => 'Stale Discovery Topics: ' . $this->buildTopicPreview($discoveryAnalysis['stale_topics']),
            'DiagTopicPayloads' => sprintf(
                'Referenzierte Runtime Topics gesamt/aktuell/stale/fehlend: %d/%d/%d/%d',
                $topicAnalysis['referenced_count'],
                $topicAnalysis['current_count'],
                $topicAnalysis['stale_count'],
                $topicAnalysis['missing_count']
            ),
            'DiagTopicPreview' => 'Fehlende/Stale Runtime Topics: ' . $this->buildCombinedTopicIssuePreview(
                $topicAnalysis['missing_topics'],
                $topicAnalysis['stale_topics']
            )
        ];
    }

    private function applyOutputBufferForStringResponse(string $response, string $context): void
    {
        $configuredBufferMb = max(0, $this->ReadPropertyInteger('OutputBufferSize'));
        $configuredBufferBytes = $configuredBufferMb > 0 ? $configuredBufferMb * 1024 * 1024 : 0;
        $responseBytes = strlen($response);
        $recommendedBufferBytes = max($configuredBufferBytes, $responseBytes + self::OUTPUT_BUFFER_RESERVE_BYTES);
        ini_set('ips.output_buffer', (string)$recommendedBufferBytes);

        $this->debugExpert($context, 'output_buffer', [
            'ResponseBytes' => $responseBytes,
            'ConfiguredBufferBytes' => $configuredBufferBytes,
            'RecommendedBufferBytes' => $recommendedBufferBytes,
            'AppliedBufferBytes' => ini_get('ips.output_buffer')
        ]);
    }

    private function applyCurrentDiagnosticsToForm(array &$form): void
    {
        $captions = $this->buildDiagnosticsCaptions();
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

    private function normalizeExportOptions(mixed $options): array
    {
        if (!is_array($options)) {
            $options = [];
        }

        return [
            'include_topic_payloads' => !array_key_exists('include_topic_payloads', $options) || (bool)$options['include_topic_payloads'],
            'include_all_cached_topics' => (bool)($options['include_all_cached_topics'] ?? false),
            'current_session_only' => (bool)($options['current_session_only'] ?? false)
        ];
    }

    private function filterCurrentSessionRecords(array $records): array
    {
        $sessionState = $this->readMqttSessionState();
        $filtered = [];

        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }

            if (!$this->isRecordCurrentSession($record, $sessionState)) {
                continue;
            }

            $filtered[] = $record;
        }

        return $filtered;
    }

    private function collectReferencedTopicsFromDiscoveryConfigs(array $records): array
    {
        $topics = [];
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }

            $payload = $record['payload'] ?? null;
            if (!is_string($payload) || trim($payload) === '') {
                continue;
            }

            try {
                $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            $baseTopic = null;
            if (is_array($decoded)) {
                $baseTopic = $this->normalizeRelativeTopicBase($decoded['~'] ?? null);
                $this->collectDeviceAutomationTopics($decoded, $topics, $baseTopic);
                $this->collectAvailabilityTopics($decoded['availability'] ?? null, $topics, $baseTopic);
            }
            $this->collectTopicsFromDecodedPayload($decoded, $topics, $baseTopic ?? null);
        }

        $normalizedTopics = [];
        foreach (array_keys($topics) as $topic) {
            $topic = trim((string)$topic, '/');
            if ($topic === '' || $this->isDiscoveryConfigTopic($topic)) {
                continue;
            }
            $normalizedTopics[$topic] = true;
        }

        $result = array_keys($normalizedTopics);
        sort($result, SORT_STRING);
        return $result;
    }

    private function collectDeviceAutomationTopics(array $decoded, array &$topics, ?string $baseTopic = null): void
    {
        if (($decoded['automation_type'] ?? null) !== 'trigger') {
            return;
        }

        $topic = $this->resolveRelativeTopicReference($decoded['topic'] ?? null, $baseTopic);
        if ($topic === null) {
            return;
        }

        $topics[$topic] = true;

        if (!str_contains($topic, '/')) {
            return;
        }

        $segments = explode('/', $topic);
        $field = trim((string)end($segments));
        if ($field === '' || !preg_match('/^[A-Za-z0-9_]+$/', $field) || !str_ends_with($topic, '/' . $field)) {
            return;
        }

        $fallbackTopic = substr($topic, 0, -strlen('/' . $field));
        if ($fallbackTopic !== '') {
            $topics[$fallbackTopic] = true;
        }
    }

    private function collectAvailabilityTopics(mixed $availability, array &$topics, ?string $baseTopic = null): void
    {
        if (!is_array($availability)) {
            return;
        }

        foreach ($availability as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $topic = $this->resolveRelativeTopicReference($entry['topic'] ?? null, $baseTopic);
            if ($topic === null) {
                continue;
            }

            $topics[$topic] = true;
        }
    }

    private function normalizeCacheRecords(mixed $cache, bool $discoveryCache): array
    {
        if (!is_array($cache)) {
            return [];
        }

        $result = [];
        foreach ($cache as $topic => $record) {
            $normalizedTopic = is_string($topic) ? $topic : '';
            $normalizedRecord = $this->normalizeCacheRecord($normalizedTopic, $record, $discoveryCache);
            if ($normalizedRecord === null) {
                continue;
            }

            $result[$normalizedRecord['topic']] = $normalizedRecord;
        }

        ksort($result, SORT_STRING);
        return $result;
    }

    private function normalizeCacheRecord(string $fallbackTopic, mixed $record, bool $discoveryCache): ?array
    {
        if (is_string($record)) {
            $record = [
                'topic' => $fallbackTopic,
                'payload' => $record
            ];
        }

        if (!is_array($record)) {
            return null;
        }

        $topic = trim((string)($record['topic'] ?? $fallbackTopic), '/');
        if ($topic === '') {
            return null;
        }

        if ($this->isDiscoveryConfigTopic($topic) !== $discoveryCache) {
            return null;
        }

        $payload = array_key_exists('payload', $record) ? (string)$record['payload'] : '';
        if (trim($payload) === '') {
            return null;
        }

        $normalized = [
            'topic' => $topic,
            'payload' => $payload,
            'received_at' => max(0, (int)($record['received_at'] ?? 0))
        ];

        $sessionId = trim((string)($record['session_id'] ?? ''));
        if ($sessionId !== '') {
            $normalized['session_id'] = $sessionId;
        }

        if (array_key_exists('retained', $record) || array_key_exists('retain', $record)) {
            $normalized['retained'] = (bool)($record['retained'] ?? $record['retain']);
        }

        if (array_key_exists('qos', $record)) {
            $normalized['qos'] = max(0, min(2, (int)$record['qos']));
        }

        $direction = strtolower(trim((string)($record['direction'] ?? '')));
        if ($direction === 'rx' || $direction === 'tx') {
            $normalized['direction'] = $direction;
        }

        return $normalized;
    }

    private function createCacheRecord(string $topic, string $payload, array $metadata = []): array
    {
        $record = [
            'topic' => $topic,
            'payload' => $payload,
            'received_at' => time()
        ];

        $session = $this->readMqttSessionState();
        $sessionId = trim((string)($session['id'] ?? ''));
        if ($sessionId !== '') {
            $record['session_id'] = $sessionId;
        }

        if (array_key_exists('retained', $metadata) && $metadata['retained'] !== null) {
            $record['retained'] = (bool)$metadata['retained'];
        }

        if (array_key_exists('qos', $metadata) && $metadata['qos'] !== null) {
            $record['qos'] = max(0, min(2, (int)$metadata['qos']));
        }

        $direction = strtolower(trim((string)($metadata['direction'] ?? '')));
        if ($direction === 'rx' || $direction === 'tx') {
            $record['direction'] = $direction;
        }

        return $record;
    }

    private function annotateCacheRecords(array $records): array
    {
        $sessionState = $this->readMqttSessionState();
        $annotated = [];
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }

            $record['is_current_session'] = $this->isRecordCurrentSession($record, $sessionState);
            $annotated[] = $record;
        }

        return $annotated;
    }

    private function analyzeDiscoveryConfigRecords(?array $records = null): array
    {
        $records = $records ?? array_values($this->getDiscoveryConfigRecords());
        $sessionState = $this->readMqttSessionState();
        $staleTopics = [];
        $currentCount = 0;

        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }

            if ($this->isRecordCurrentSession($record, $sessionState)) {
                $currentCount++;
                continue;
            }

            $topic = trim((string)($record['topic'] ?? ''), '/');
            if ($topic !== '') {
                $staleTopics[] = $topic;
            }
        }

        sort($staleTopics, SORT_STRING);

        return [
            'total_count' => count($records),
            'current_count' => $currentCount,
            'stale_count' => count($staleTopics),
            'stale_topics' => $staleTopics
        ];
    }

    private function analyzeReferencedRuntimeTopics(?array $discoveryConfigs = null): array
    {
        $discoveryConfigs = $discoveryConfigs ?? array_values($this->getDiscoveryConfigRecords());
        $referencedTopics = $this->collectReferencedTopicsFromDiscoveryConfigs($discoveryConfigs);
        $referencedLookup = array_fill_keys($referencedTopics, true);
        $runtimeCache = $this->readTopicPayloadCache();
        $sessionState = $this->readMqttSessionState();

        $currentTopics = [];
        $staleTopics = [];
        $missingTopics = [];

        foreach ($referencedTopics as $topic) {
            $record = $runtimeCache[$topic] ?? null;
            if (!is_array($record)) {
                $missingTopics[] = $topic;
                continue;
            }

            if ($this->isRecordCurrentSession($record, $sessionState)) {
                $currentTopics[] = $topic;
                continue;
            }

            $staleTopics[] = $topic;
        }

        $extraTopics = [];
        foreach (array_keys($runtimeCache) as $topic) {
            if (!isset($referencedLookup[$topic])) {
                $extraTopics[] = $topic;
            }
        }

        sort($currentTopics, SORT_STRING);
        sort($staleTopics, SORT_STRING);
        sort($missingTopics, SORT_STRING);
        sort($extraTopics, SORT_STRING);

        return [
            'referenced_count' => count($referencedTopics),
            'current_count' => count($currentTopics),
            'stale_count' => count($staleTopics),
            'missing_count' => count($missingTopics),
            'extra_count' => count($extraTopics),
            'referenced_topics' => $referencedTopics,
            'current_topics' => $currentTopics,
            'stale_topics' => $staleTopics,
            'missing_topics' => $missingTopics,
            'extra_topics' => $extraTopics
        ];
    }

    private function buildTopicPreview(array $topics): string
    {
        if ($topics === []) {
            return '-';
        }

        $preview = implode(' | ', array_slice($topics, 0, self::DIAGNOSTIC_PREVIEW_LIMIT));
        if (count($topics) > self::DIAGNOSTIC_PREVIEW_LIMIT) {
            $preview .= ' | ...';
        }

        return $preview;
    }

    private function buildCombinedTopicIssuePreview(array $missingTopics, array $staleTopics): string
    {
        $issues = [];
        foreach ($missingTopics as $topic) {
            $issues[] = 'missing:' . $topic;
        }
        foreach ($staleTopics as $topic) {
            $issues[] = 'stale:' . $topic;
        }

        return $this->buildTopicPreview($issues);
    }

    private function refreshMqttSessionState(): void
    {
        if ($this->hasCompatibleActiveParentModule(HAIds::MODULE_MQTT_CLIENT)) {
            $state = $this->readMqttSessionState();
            $currentParentId = $this->getCurrentParentId();
            if (!(bool)($state['active'] ?? false) || (int)($state['parent_id'] ?? 0) !== $currentParentId || trim((string)($state['id'] ?? '')) === '') {
                $this->startNewMqttSession();
            }
            return;
        }

        $this->markMqttSessionInactive();
    }

    private function startNewMqttSession(): void
    {
        if (!$this->hasCompatibleParentModule(HAIds::MODULE_MQTT_CLIENT)) {
            return;
        }

        $state = $this->readMqttSessionState();
        $sequence = max(0, (int)($state['sequence'] ?? 0)) + 1;
        $startedAt = time();
        $newState = [
            'id' => $this->getCurrentParentId() . '-' . $sequence . '-' . $startedAt,
            'started_at' => $startedAt,
            'parent_id' => $this->getCurrentParentId(),
            'active' => true,
            'sequence' => $sequence
        ];

        $this->writeMqttSessionState($newState);
    }

    private function markMqttSessionInactive(): void
    {
        $state = $this->readMqttSessionState();
        if (!(bool)($state['active'] ?? false) && (int)($state['parent_id'] ?? 0) === $this->getCurrentParentId()) {
            return;
        }

        $state['active'] = false;
        $state['parent_id'] = $this->getCurrentParentId();
        $this->writeMqttSessionState($state);
    }

    private function readMqttSessionState(): array
    {
        try {
            $decoded = json_decode($this->ReadAttributeString(self::ATTRIBUTE_MQTT_SESSION_STATE), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $decoded = [];
        }

        if (!is_array($decoded)) {
            $decoded = [];
        }

        return [
            'id' => trim((string)($decoded['id'] ?? '')),
            'started_at' => max(0, (int)($decoded['started_at'] ?? 0)),
            'parent_id' => max(0, (int)($decoded['parent_id'] ?? 0)),
            'active' => (bool)($decoded['active'] ?? false),
            'sequence' => max(0, (int)($decoded['sequence'] ?? 0))
        ];
    }

    private function writeMqttSessionState(array $state): void
    {
        $this->WriteAttributeString(
            self::ATTRIBUTE_MQTT_SESSION_STATE,
            json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    private function isRecordCurrentSession(array $record, ?array $sessionState = null): bool
    {
        $sessionState ??= $this->readMqttSessionState();
        if (!(bool)($sessionState['active'] ?? false)) {
            return false;
        }

        $sessionId = trim((string)($sessionState['id'] ?? ''));
        if ($sessionId !== '') {
            $recordSessionId = trim((string)($record['session_id'] ?? ''));
            if ($recordSessionId !== '') {
                return $recordSessionId === $sessionId;
            }
        }

        $startedAt = max(0, (int)($sessionState['started_at'] ?? 0));
        $receivedAt = max(0, (int)($record['received_at'] ?? 0));
        return $startedAt > 0 && $receivedAt >= $startedAt;
    }

    private function buildSessionCaption(): string
    {
        $state = $this->readMqttSessionState();
        $startedAt = (int)($state['started_at'] ?? 0);
        if ($startedAt <= 0) {
            return 'keine aktive Session';
        }

        $timeText = date('Y-m-d H:i:s', $startedAt);
        if ((bool)($state['active'] ?? false)) {
            return 'aktiv seit ' . $timeText;
        }

        return 'inaktiv | letzte Session ' . $timeText;
    }

    private function buildExportSessionInfo(): array
    {
        $state = $this->readMqttSessionState();
        return [
            'id' => $state['id'],
            'active' => (bool)$state['active'],
            'started_at' => $state['started_at'] > 0 ? date(DATE_ATOM, (int)$state['started_at']) : null,
            'parent_id' => (int)$state['parent_id']
        ];
    }

    private function collectTopicsFromDecodedPayload(mixed $value, array &$topics, ?string $baseTopic = null, array $path = []): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $nestedValue) {
                $nestedPath = $path;
                if (is_string($key)) {
                    $nestedPath[] = $key;
                }
                $this->collectTopicsFromDecodedPayload($nestedValue, $topics, $baseTopic, $nestedPath);
            }
            return;
        }

        if (!is_string($value)) {
            return;
        }

        $currentKey = $path[count($path) - 1] ?? null;
        if (!is_string($currentKey) || !$this->isReferencedRuntimeTopicKey($currentKey)) {
            return;
        }

        $topic = $this->resolveRelativeTopicReference($value, $baseTopic);
        if ($topic === null) {
            return;
        }

        $topics[$topic] = true;
    }

    private function isReferencedRuntimeTopicKey(string $currentKey): bool
    {
        return str_ends_with($currentKey, '_topic') || preg_match('/_t$/', $currentKey) === 1;
    }

    private function normalizeRelativeTopicBase(mixed $value): ?string
    {
        $baseTopic = trim((string)$value, '/');
        return $baseTopic === '' ? null : $baseTopic;
    }

    private function resolveRelativeTopicReference(mixed $value, ?string $baseTopic): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $topic = trim($value);
        if ($topic === '') {
            return null;
        }

        if (!str_starts_with($topic, '~/')) {
            return trim($topic, '/');
        }

        $baseTopic = $this->normalizeRelativeTopicBase($baseTopic);
        if ($baseTopic === null) {
            return trim($topic, '/');
        }

        return $baseTopic . substr($topic, 1);
    }
}
