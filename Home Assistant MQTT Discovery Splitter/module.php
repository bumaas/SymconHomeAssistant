<?php /** @noinspection PhpUnused */

/** @noinspection AutoloadingIssuesInspection */

declare(strict_types=1);

require_once __DIR__ . '/../libs/HACommonIncludes.php';

class HomeAssistantMQTTDiscoverySplitter extends IPSModuleStrict
{
    use HAParentConnectionTrait;
    use ModuleDebugTrait;

    private const string TIMER_DIAGNOSTICS_REFRESH = 'DiagnosticsRefresh';
    private const string ATTRIBUTE_DISCOVERY_CACHE = 'MqttDiscoveryConfigCache';
    private const string ATTRIBUTE_TOPIC_PAYLOAD_CACHE = 'MqttTopicPayloadCache';
    private const string ATTRIBUTE_MQTT_SESSION_STATE = 'MqttSessionState';
    private const string ATTRIBUTE_DIAGNOSTICS_DIRTY = 'DiagnosticsDirty';
    private const string ATTRIBUTE_REFERENCED_TOPIC_LOOKUP = 'MqttReferencedTopicLookup';
    private const string ATTRIBUTE_BUNDLE_STATE = 'BundleState';
    private const string EXPORT_FORMAT = 'ha_mqtt_discovery_bundle';
    private const int EXPORT_VERSION = 2;
    private const int DIAGNOSTIC_PREVIEW_LIMIT = 8;
    private const int OUTPUT_BUFFER_RESERVE_BYTES = 262144;
    private const int DIAGNOSTICS_REFRESH_INTERVAL_MS = 1000;
    private const string SOURCE_MODE_MQTT = 'mqtt';
    private const string SOURCE_MODE_BUNDLE = 'bundle';

    public function Create(): void
    {
        parent::Create();

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);
        $this->SetReceiveDataFilter('^$');

        $this->RegisterPropertyString('SourceMode', self::SOURCE_MODE_MQTT);
        $this->RegisterPropertyString('MQTTDiscoveryPrefix', 'homeassistant');
        $this->RegisterPropertyString('BundlePath', '');
        $this->RegisterPropertyBoolean('BundleCurrentSessionOnly', false);
        $this->RegisterPropertyBoolean('ReplayTopicsOnApply', false);
        $this->RegisterPropertyBoolean('EnableExpertDebug', false);
        $this->RegisterPropertyInteger('OutputBufferSize', 10);

        $this->RegisterTimer(self::TIMER_DIAGNOSTICS_REFRESH, 0, 'HAMD_RefreshDiscoveryDiagnostics($_IPS["TARGET"]);');
        $this->RegisterAttributeString('LastMQTTMessage', '');
        $this->RegisterAttributeString(self::ATTRIBUTE_DISCOVERY_CACHE, '{}');
        $this->RegisterAttributeString(self::ATTRIBUTE_TOPIC_PAYLOAD_CACHE, '{}');
        $this->RegisterAttributeString(self::ATTRIBUTE_MQTT_SESSION_STATE, '{}');
        $this->RegisterAttributeBoolean(self::ATTRIBUTE_DIAGNOSTICS_DIRTY, false);
        $this->RegisterAttributeString(self::ATTRIBUTE_REFERENCED_TOPIC_LOOKUP, '{}');
        $this->RegisterAttributeString(self::ATTRIBUTE_BUNDLE_STATE, '{}');
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if (($Message === IPS_KERNELMESSAGE) && (($Data[0] ?? null) === KR_READY)) {
            $this->ApplyChanges();
            return;
        }

        if ($this->isBundleMode()) {
            return;
        }

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
        $startedAt = microtime(true);
        $this->logPerformanceMarker(__FUNCTION__, 'start', [
            'SourceMode' => $this->getSourceMode()
        ]);
        parent::ApplyChanges();
        $this->SetTimerInterval(self::TIMER_DIAGNOSTICS_REFRESH, 0);
        $this->syncParentStatusMessageRegistration();
        if (!$this->isKernelReady()) {
            $this->debugExpert('ApplyChanges', 'Kernel noch nicht bereit. Initialisierung wird bis KR_READY verschoben.', [], true);
            return;
        }
        $this->SetReceiveDataFilter($this->isBundleMode() ? '^$' : '.*');

        if ($this->isBundleMode()) {
            $this->applyBundleMode();
            $this->logPerformanceSample(__FUNCTION__, $startedAt, [
                'SourceMode' => $this->getSourceMode(),
                'Path' => 'bundle'
            ], true);
            return;
        }

        if (($this->readBundleState()['mode'] ?? self::SOURCE_MODE_MQTT) === self::SOURCE_MODE_BUNDLE) {
            $this->clearDiscoveryRuntimeCaches();
            $this->writeBundleState($this->buildDefaultBundleState());
            $this->WriteAttributeString('LastMQTTMessage', '');
        }

        $this->logPerformanceMarker(__FUNCTION__, 'before_refreshMqttSessionState');
        $this->refreshMqttSessionState();
        $this->logPerformanceMarker(__FUNCTION__, 'after_refreshMqttSessionState');
        $this->logPerformanceMarker(__FUNCTION__, 'before_pruneCaches');
        $this->pruneCaches();
        $this->logPerformanceMarker(__FUNCTION__, 'after_pruneCaches');
        $this->updateDiagnosticsLabels();

        $prefix = $this->getDiscoveryPrefix();
        if ($prefix === '') {
            $this->SetStatus(202);
            $this->logPerformanceSample(__FUNCTION__, $startedAt, [
                'SourceMode' => $this->getSourceMode(),
                'Result' => 'missing_prefix'
            ], true);
            return;
        }

        if (!$this->hasCompatibleParentModule(HAIds::MODULE_MQTT_CLIENT)) {
            $this->SetStatus(201);
            $this->debugExpert('ApplyChanges', 'MQTT Parent ist nicht kompatibel.', $this->buildCurrentParentDebugContext(), true);
            $this->logPerformanceSample(__FUNCTION__, $startedAt, [
                'SourceMode' => $this->getSourceMode(),
                'Result' => 'parent_incompatible'
            ], true);
            return;
        }

        if (!$this->hasCompatibleActiveParentModule(HAIds::MODULE_MQTT_CLIENT)) {
            $this->SetStatus(201);
            $this->debugExpert('ApplyChanges', 'MQTT Parent ist nicht aktiv.', $this->buildCurrentParentDebugContext(), true);
            $this->logPerformanceSample(__FUNCTION__, $startedAt, [
                'SourceMode' => $this->getSourceMode(),
                'Result' => 'parent_inactive'
            ], true);
            return;
        }

        $this->SetStatus(IS_ACTIVE);
        $this->logPerformanceSample(__FUNCTION__, $startedAt, [
            'SourceMode' => $this->getSourceMode(),
            'DiscoveryCount' => count($this->readDiscoveryCache())
        ], true);
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

    /** @noinspection PhpUnused */
    public function RefreshDiscoveryDiagnostics(): void
    {
        $this->SetTimerInterval(self::TIMER_DIAGNOSTICS_REFRESH, 0);
        if ($this->isBundleMode()) {
            $this->WriteAttributeBoolean(self::ATTRIBUTE_DIAGNOSTICS_DIRTY, false);
            return;
        }
        if (!$this->ReadAttributeBoolean(self::ATTRIBUTE_DIAGNOSTICS_DIRTY)) {
            return;
        }

        $this->WriteAttributeBoolean(self::ATTRIBUTE_DIAGNOSTICS_DIRTY, false);
        $this->updateDiagnosticsLabels();
    }

    public function ReconnectMqttParent(): void
    {
        if ($this->isBundleMode()) {
            $this->debugExpert(__FUNCTION__, 'MQTT IO reconnect im Bundle-Modus ignoriert.', [
                'SourceMode' => $this->getSourceMode()
            ]);
            $this->updateDiagnosticsLabels();
            return;
        }

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

        $ioReconnect = $this->reconnectIoParent($ioParentId);
        if (!(bool)($ioReconnect['supported'] ?? false)) {
            $this->debugExpert(__FUNCTION__, 'MQTT IO-Reconnect nicht moeglich.', [
                'MQTTParentID' => $mqttClientId,
                'IOParentID' => $ioParentId,
                'Reason' => (string)($ioReconnect['reason'] ?? 'unsupported')
            ], true);
            $this->updateDiagnosticsLabels();
            return;
        }

        $this->debugExpert(__FUNCTION__, 'MQTT IO reconnect ausgefuehrt', [
            'MQTTParentID' => $mqttClientId,
            'IOParentID' => $ioParentId,
            'CloseApplied' => (bool)($ioReconnect['close_applied'] ?? false),
            'OpenApplied' => (bool)($ioReconnect['open_applied'] ?? false),
            'IOStatusAfter' => (int)($ioReconnect['status_after'] ?? 0)
        ], !(bool)($ioReconnect['close_applied'] ?? false) || !(bool)($ioReconnect['open_applied'] ?? false));

        $this->updateDiagnosticsLabels();
    }

    /** @noinspection PhpUnused */
    public function ReplayBundleTopicsToChildren(): void
    {
        if (!$this->isBundleMode()) {
            $this->debugExpert(__FUNCTION__, 'Replay nur im Bundle-Modus verfuegbar.', [
                'SourceMode' => $this->getSourceMode()
            ], true);
            return;
        }

        $this->replayCachedTopicPayloadsToChildren();
    }

    /** @noinspection PhpUnused */
    public function ActivateBundleMode(string $BundlePath, bool $CurrentSessionOnly = false, bool $ReplayTopicsOnApply = false): void
    {
        IPS_SetProperty($this->InstanceID, 'SourceMode', self::SOURCE_MODE_BUNDLE);
        IPS_SetProperty($this->InstanceID, 'BundlePath', $BundlePath);
        IPS_SetProperty($this->InstanceID, 'BundleCurrentSessionOnly', $CurrentSessionOnly);
        IPS_SetProperty($this->InstanceID, 'ReplayTopicsOnApply', $ReplayTopicsOnApply);
        IPS_ApplyChanges($this->InstanceID);
    }

    /** @noinspection PhpUnused */
    public function ActivateMqttMode(): void
    {
        IPS_SetProperty($this->InstanceID, 'SourceMode', self::SOURCE_MODE_MQTT);
        IPS_ApplyChanges($this->InstanceID);
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

            if ($this->isBundleMode()) {
                $this->debugExpert(__FUNCTION__, 'MQTT Command im Bundle-Modus verworfen.', [
                    'Topic' => (string)($data['Topic'] ?? ''),
                    'QoS' => (int)($data['QualityOfService'] ?? 0),
                    'Retain' => (bool)($data['Retain'] ?? false)
                ], true);
                return '';
            }

            $data['DataID'] = HAIds::DATA_MQTT_TX;
            return $this->SendDataToParent(json_encode($data, JSON_THROW_ON_ERROR));
        }

        return $this->SendDataToParent($JSONString);
    }

    public function ReceiveData(string $JSONString): string
    {
        if ($this->isBundleMode()) {
            return '';
        }

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
            $this->scheduleDiagnosticsRefresh();

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
            'GetDiscoveryConfigs' => $this->encodeDiscoveryRequestResponse(
                $this->buildDiscoveryResponse(),
                'GetDiscoveryConfigs'
            ),
            'GetTopicPayloads' => $this->encodeDiscoveryRequestResponse(
                $this->buildTopicPayloadResponse($data['Topics'] ?? []),
                'GetTopicPayloads'
            ),
            'ExportDiscoveryBundle' => json_encode($this->buildDiscoveryExportBundle($data['Options'] ?? null), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default => json_encode(['Error' => 'Unsupported DiscoveryAction'], JSON_THROW_ON_ERROR)
        };
    }

    private function encodeDiscoveryRequestResponse(array $payload, string $context): string
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->applyOutputBufferForStringResponse($json, $context);
        return $json;
    }

    private function updateDiagnosticsLabels(): void
    {
        foreach ($this->buildDiagnosticsCaptions() as $field => $caption) {
            $this->updateFormFieldSafe($field, 'caption', $caption);
        }
    }

    private function scheduleDiagnosticsRefresh(): void
    {
        $this->WriteAttributeBoolean(self::ATTRIBUTE_DIAGNOSTICS_DIRTY, true);
        if ($this->GetTimerInterval(self::TIMER_DIAGNOSTICS_REFRESH) <= 0) {
            $this->SetTimerInterval(self::TIMER_DIAGNOSTICS_REFRESH, self::DIAGNOSTICS_REFRESH_INTERVAL_MS);
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

    private function getSourceMode(): string
    {
        $mode = strtolower(trim($this->ReadPropertyString('SourceMode')));
        return $mode === self::SOURCE_MODE_BUNDLE ? self::SOURCE_MODE_BUNDLE : self::SOURCE_MODE_MQTT;
    }

    private function isBundleMode(): bool
    {
        return $this->getSourceMode() === self::SOURCE_MODE_BUNDLE;
    }

    private function getConfiguredDiscoveryPrefix(): string
    {
        $prefix = trim($this->ReadPropertyString('MQTTDiscoveryPrefix'));
        return trim($prefix, '/');
    }

    private function getDefaultBundleFixturesPath(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures';
    }

    private function resolveBundlePath(string $path): string
    {
        if ($path === '') {
            return '';
        }

        if ($this->isAbsoluteFilesystemPath($path)) {
            return $path;
        }

        return $this->getDefaultBundleFixturesPath() . DIRECTORY_SEPARATOR . ltrim($path, '\\/');
    }

    private function isAbsoluteFilesystemPath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1) {
            return true;
        }

        if (str_starts_with($path, '\\\\') || str_starts_with($path, '//')) {
            return true;
        }

        return str_starts_with($path, '/') || str_starts_with($path, '\\');
    }

    private function reconnectIoParent(int $ioParentId): array
    {
        $configuration = $this->readInstanceConfiguration($ioParentId);
        if ($configuration === null) {
            return [
                'supported' => false,
                'reason' => 'configuration_unavailable'
            ];
        }

        if (!array_key_exists('Open', $configuration)) {
            return [
                'supported' => false,
                'reason' => 'open_property_missing'
            ];
        }

        $closeApplied = $this->setInstanceOpenState($ioParentId, false);
        usleep(300000);
        $openApplied = $this->setInstanceOpenState($ioParentId, true);

        return [
            'supported' => true,
            'close_applied' => $closeApplied,
            'open_applied' => $openApplied,
            'status_after' => $this->getInstanceStatus($ioParentId)
        ];
    }

    private function readInstanceConfiguration(int $instanceId): ?array
    {

        try {
            $configuration = IPS_GetConfiguration($instanceId);
        } catch (Throwable) {
            return null;
        }

        if (!is_string($configuration) || trim($configuration) === '') {
            return [];
        }

        try {
            $decoded = json_decode($configuration, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function setInstanceOpenState(int $instanceId, bool $open): bool
    {
        try {
            IPS_SetProperty($instanceId, 'Open', $open);
            IPS_ApplyChanges($instanceId);
        } catch (Throwable $e) {
            $this->debugExpert(__FUNCTION__, 'IO Open-Status konnte nicht gesetzt werden.', [
                'InstanceID' => $instanceId,
                'Open' => $open,
                'Error' => $e->getMessage()
            ], true);
            return false;
        }

        return true;
    }

    private function getInstanceStatus(int $instanceId): int
    {
        if (!IPS_InstanceExists($instanceId)) {
            return 0;
        }

        $instance = IPS_GetInstance($instanceId);
        return (int)($instance['InstanceStatus'] ?? 0);
    }

    private function getDiscoveryPrefix(): string
    {
        if ($this->isBundleMode()) {
            $bundlePrefix = trim((string)($this->readBundleState()['discovery_prefix'] ?? ''), '/');
            if ($bundlePrefix !== '') {
                return $bundlePrefix;
            }
        }

        return $this->getConfiguredDiscoveryPrefix();
    }

    private function decodePayload(string $payloadHex): string
    {
        $decoded = hex2bin($payloadHex);
        return $decoded === false ? '' : $decoded;
    }

    private function applyBundleMode(): void
    {
        $startedAt = microtime(true);
        $this->logPerformanceMarker(__FUNCTION__, 'start', [
            'BundlePath' => $this->ReadPropertyString('BundlePath'),
            'CurrentSessionOnly' => $this->ReadPropertyBoolean('BundleCurrentSessionOnly'),
            'ReplayTopicsOnApply' => $this->ReadPropertyBoolean('ReplayTopicsOnApply')
        ]);

        $loadStartedAt = microtime(true);
        $loadResult = $this->loadConfiguredBundle();
        $this->logPerformanceSample(__FUNCTION__ . '.loadConfiguredBundle', $loadStartedAt, [
            'Ok' => (bool)($loadResult['ok'] ?? false),
            'Status' => (int)($loadResult['status'] ?? 0)
        ], true);

        if (!($loadResult['ok'] ?? false)) {
            $state = is_array($loadResult['state'] ?? null) ? $loadResult['state'] : $this->buildDefaultBundleState();
            $this->writeBundleState($state);
            $this->clearDiscoveryRuntimeCaches();
            $this->writeMqttSessionState($this->buildBundleSessionState([], $state));
            $this->WriteAttributeString('LastMQTTMessage', '');
            $diagnosticsStartedAt = microtime(true);
            $this->updateDiagnosticsLabels();
            $this->logPerformanceSample(__FUNCTION__ . '.updateDiagnosticsLabels', $diagnosticsStartedAt, [
                'Result' => 'load_failed'
            ], true);

            if (($loadResult['status'] ?? 204) === 202) {
                $this->SetStatus(202);
                $this->logPerformanceSample(__FUNCTION__, $startedAt, [
                    'Result' => 'bundle_missing_path',
                    'Status' => 202
                ], true);
                return;
            }

            $this->SetStatus((int)($loadResult['status'] ?? 204));
            $this->logPerformanceSample(__FUNCTION__, $startedAt, [
                'Result' => 'bundle_load_failed',
                'Status' => (int)($loadResult['status'] ?? 204)
            ], true);
            return;
        }

        $bundle = is_array($loadResult['bundle'] ?? null) ? $loadResult['bundle'] : [];
        $state = is_array($loadResult['state'] ?? null) ? $loadResult['state'] : $this->buildDefaultBundleState();

        $hydrateStartedAt = microtime(true);
        $this->hydrateCachesFromBundle($bundle, $state);
        $this->logPerformanceSample(__FUNCTION__ . '.hydrateCachesFromBundle', $hydrateStartedAt, [
            'DiscoveryCount' => count($this->readDiscoveryCache()),
            'TopicPayloadCount' => count($this->readTopicPayloadCache())
        ], true);

        $this->WriteAttributeString('LastMQTTMessage', '');

        $diagnosticsStartedAt = microtime(true);
        $this->updateDiagnosticsLabels();
        $this->logPerformanceSample(__FUNCTION__ . '.updateDiagnosticsLabels', $diagnosticsStartedAt, [
            'Result' => 'bundle_loaded'
        ], true);

        $this->SetStatus(IS_ACTIVE);

        if ($this->ReadPropertyBoolean('ReplayTopicsOnApply')) {
            $replayStartedAt = microtime(true);
            $this->replayCachedTopicPayloadsToChildren();
            $this->logPerformanceSample(__FUNCTION__ . '.replayCachedTopicPayloadsToChildren', $replayStartedAt, [], true);
        }

        $this->logPerformanceSample(__FUNCTION__, $startedAt, [
            'Result' => 'bundle_loaded',
            'DiscoveryCount' => count($this->readDiscoveryCache()),
            'TopicPayloadCount' => count($this->readTopicPayloadCache())
        ], true);
    }

    private function loadConfiguredBundle(): array
    {
        $startedAt = microtime(true);
        $rawPath = trim($this->ReadPropertyString('BundlePath'));
        $path = $this->resolveBundlePath($rawPath);
        $state = $this->buildDefaultBundleState();
        $state['mode'] = self::SOURCE_MODE_BUNDLE;
        $state['path'] = $path;
        $state['configured_path'] = $rawPath;
        $state['base_path'] = $this->getDefaultBundleFixturesPath();
        $state['current_session_only'] = $this->ReadPropertyBoolean('BundleCurrentSessionOnly');

        if ($rawPath === '') {
            $state['error'] = $this->Translate('Bundle path is empty.');
            return [
                'ok' => false,
                'status' => 203,
                'state' => $state
            ];
        }

        $readStartedAt = microtime(true);
        $raw = @file_get_contents($path);
        $this->logPerformanceSample(__FUNCTION__ . '.file_get_contents', $readStartedAt, [
            'Path' => $path,
            'Bytes' => is_string($raw) ? strlen($raw) : 0,
            'Readable' => $raw !== false
        ], true);
        if ($raw === false) {
            $state['error'] = $this->Translate('Bundle file could not be read.');
            return [
                'ok' => false,
                'status' => 204,
                'state' => $state
            ];
        }

        try {
            $decodeStartedAt = microtime(true);
            $bundle = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $this->logPerformanceSample(__FUNCTION__ . '.json_decode', $decodeStartedAt, [
                'TopLevelKeys' => is_array($bundle) ? count($bundle) : 0
            ], true);
        } catch (JsonException $e) {
            $state['error'] = $this->Translate('Bundle is not valid JSON: ') . $e->getMessage();
            return [
                'ok' => false,
                'status' => 204,
                'state' => $state
            ];
        }

        if (!is_array($bundle)) {
            $state['error'] = $this->Translate('Bundle is not a JSON object.');
            return [
                'ok' => false,
                'status' => 204,
                'state' => $state
            ];
        }

        $format = trim((string)($bundle['format'] ?? ''));
        if ($format !== self::EXPORT_FORMAT) {
            $state['error'] = $this->Translate('Unexpected bundle format.');
            return [
                'ok' => false,
                'status' => 204,
                'state' => $state
            ];
        }

        $version = (int)($bundle['version'] ?? 0);
        if ($version !== self::EXPORT_VERSION) {
            $state['error'] = sprintf($this->Translate('Unexpected bundle version. Expected V%d.'), self::EXPORT_VERSION);
            return [
                'ok' => false,
                'status' => 204,
                'state' => $state
            ];
        }

        if (!is_array($bundle['discovery_configs'] ?? null)) {
            $state['error'] = $this->Translate('discovery_configs is missing or is not an array.');
            return [
                'ok' => false,
                'status' => 204,
                'state' => $state
            ];
        }

        if (array_key_exists('topic_payloads', $bundle) && !is_array($bundle['topic_payloads'])) {
            $state['error'] = $this->Translate('topic_payloads is not an array.');
            return [
                'ok' => false,
                'status' => 204,
                'state' => $state
            ];
        }

        $bundlePrefix = trim((string)($bundle['splitter']['discovery_prefix'] ?? ''), '/');
        $configuredPrefix = $this->getConfiguredDiscoveryPrefix();
        $effectivePrefix = $bundlePrefix !== '' ? $bundlePrefix : $configuredPrefix;
        if ($effectivePrefix === '') {
            $state['error'] = $this->Translate('MQTT discovery prefix is empty.');
            return [
                'ok' => false,
                'status' => 202,
                'state' => $state
            ];
        }

        $state['loaded'] = true;
        $state['error'] = '';
        $state['bundle_format'] = $format;
        $state['bundle_version'] = $version;
        $state['discovery_prefix'] = $effectivePrefix;
        $state['exported_at'] = trim((string)($bundle['exported_at'] ?? ''));
        $state['session_id'] = trim((string)($bundle['session']['id'] ?? ''));
        $state['session_started_at'] = $this->parseBundleTimestamp($bundle['session']['started_at'] ?? null);
        $state['session_active'] = (bool)($bundle['session']['active'] ?? true);
        $state['discovery_config_count'] = is_array($bundle['discovery_configs']) ? count($bundle['discovery_configs']) : 0;
        $state['topic_payload_count'] = is_array($bundle['topic_payloads'] ?? null) ? count($bundle['topic_payloads']) : 0;
        $state['source_notes'] = trim((string)($bundle['source']['notes'] ?? ''));

        return [
            'ok' => true,
            'bundle' => $bundle,
            'state' => $state
        ];
    }

    private function hydrateCachesFromBundle(array $bundle, array $state): void
    {
        $startedAt = microtime(true);
        $bundleSessionState = $this->buildBundleSessionState($bundle, $state);

        $normalizeStartedAt = microtime(true);
        $discoveryCache = $this->normalizeCacheRecords($bundle['discovery_configs'] ?? [], true);
        $runtimeCache = $this->normalizeCacheRecords($bundle['topic_payloads'] ?? [], false);
        $this->logPerformanceSample(__FUNCTION__ . '.normalizeCacheRecords', $normalizeStartedAt, [
            'DiscoveryCount' => count($discoveryCache),
            'TopicPayloadCount' => count($runtimeCache)
        ], true);

        if ($this->ReadPropertyBoolean('BundleCurrentSessionOnly')) {
            $filterStartedAt = microtime(true);
            $discoveryCache = $this->filterBundleCacheToCurrentSession($discoveryCache, $bundleSessionState);
            $runtimeCache = $this->filterBundleCacheToCurrentSession($runtimeCache, $bundleSessionState);
            $this->logPerformanceSample(__FUNCTION__ . '.filterBundleCacheToCurrentSession', $filterStartedAt, [
                'DiscoveryCount' => count($discoveryCache),
                'TopicPayloadCount' => count($runtimeCache)
            ], true);
        }

        $lookupStartedAt = microtime(true);
        $lookup = $this->buildReferencedTopicLookup($discoveryCache);
        $runtimeCache = $this->filterRuntimeCacheToReferencedTopics($runtimeCache, $lookup);
        $this->logPerformanceSample(__FUNCTION__ . '.buildLookupAndFilterRuntime', $lookupStartedAt, [
            'ReferencedTopicCount' => count($lookup),
            'TopicPayloadCount' => count($runtimeCache)
        ], true);

        $state['loaded'] = true;
        $state['discovery_config_count'] = count($discoveryCache);
        $state['topic_payload_count'] = count($runtimeCache);

        $writeStartedAt = microtime(true);
        $this->writeBundleState($state);
        $this->writeDiscoveryCache($discoveryCache);
        $this->writeReferencedTopicLookup($lookup);
        $this->writeTopicPayloadCache($runtimeCache);
        $this->writeMqttSessionState($bundleSessionState);
        $this->logPerformanceSample(__FUNCTION__ . '.writeAttributes', $writeStartedAt, [
            'DiscoveryCount' => count($discoveryCache),
            'ReferencedTopicCount' => count($lookup),
            'TopicPayloadCount' => count($runtimeCache)
        ], true);

        $this->logPerformanceSample(__FUNCTION__, $startedAt, [
            'DiscoveryCount' => count($discoveryCache),
            'ReferencedTopicCount' => count($lookup),
            'TopicPayloadCount' => count($runtimeCache)
        ], true);
    }

    private function filterBundleCacheToCurrentSession(array $cache, array $sessionState): array
    {
        if (trim((string)($sessionState['id'] ?? '')) === '' && (int)($sessionState['started_at'] ?? 0) <= 0) {
            return $cache;
        }

        $filtered = [];
        foreach ($cache as $topic => $record) {
            if (!is_array($record) || !$this->isBundleRecordCurrentSession($record, $sessionState)) {
                continue;
            }
            $filtered[$topic] = $record;
        }

        ksort($filtered, SORT_STRING);
        return $filtered;
    }

    private function isBundleRecordCurrentSession(array $record, array $sessionState): bool
    {
        if (!empty($record['is_current_session'])) {
            return true;
        }

        $sessionId = trim((string)($sessionState['id'] ?? ''));
        $recordSessionId = trim((string)($record['session_id'] ?? ''));
        if ($sessionId !== '' && $recordSessionId !== '') {
            return $sessionId === $recordSessionId;
        }

        $startedAt = max(0, (int)($sessionState['started_at'] ?? 0));
        $receivedAt = max(0, (int)($record['received_at'] ?? 0));
        return $startedAt > 0 && $receivedAt >= $startedAt;
    }

    private function replayCachedTopicPayloadsToChildren(): void
    {
        $records = array_values($this->readTopicPayloadCache());
        usort($records, static function (array $left, array $right): int {
            $receivedAtCompare = ((int)($left['received_at'] ?? 0)) <=> ((int)($right['received_at'] ?? 0));
            if ($receivedAtCompare !== 0) {
                return $receivedAtCompare;
            }

            return strcmp((string)($left['topic'] ?? ''), (string)($right['topic'] ?? ''));
        });

        $sent = 0;
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }

            $topic = trim((string)($record['topic'] ?? ''), '/');
            $payload = (string)($record['payload'] ?? '');
            if ($topic === '' || trim($payload) === '') {
                continue;
            }

            $packet = [
                'DataID' => HAIds::DATA_MQTT_DISCOVERY_SPLITTER_TO_DEVICE,
                'Topic' => $topic,
                'Payload' => bin2hex($payload),
                'Retain' => (bool)($record['retained'] ?? false),
                'QualityOfService' => max(0, min(2, (int)($record['qos'] ?? 0)))
            ];
            $this->SendDataToChildren(json_encode($packet, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $sent++;
        }

        $this->debugExpert(__FUNCTION__, 'Bundle-Topics an Kinder replayt.', [
            'Count' => $sent
        ]);
    }

    private function clearDiscoveryRuntimeCaches(): void
    {
        $this->writeDiscoveryCache([]);
        $this->writeTopicPayloadCache([]);
        $this->writeReferencedTopicLookup([]);
    }

    private function updateDiscoveryCacheFromMessage(string $topic, string $payload, array $metadata = []): void
    {
        $topic = trim($topic, '/');
        if (!$this->isDiscoveryConfigTopic($topic)) {
            return;
        }

        $cache = $this->readDiscoveryCache();
        $cacheChanged = false;
        if (trim($payload) === '') {
            if (isset($cache[$topic])) {
                unset($cache[$topic]);
                $cacheChanged = true;
                $this->debugExpert(__FUNCTION__, 'Removed discovery config from cache', [
                    'Topic' => $topic,
                    'CacheCount' => count($cache)
                ]);
            }
        } else {
            $record = $this->createCacheRecord($topic, $payload, $metadata, false);
            if ($record === null) {
                if (isset($cache[$topic])) {
                    unset($cache[$topic]);
                    $cacheChanged = true;
                }
                $this->debugExpert(__FUNCTION__, 'Discovery-Payload nicht cachebar', [
                    'Topic' => $topic,
                    'PayloadBytes' => strlen($payload),
                    'CacheCount' => count($cache)
                ], true);
            } else {
                if (!$this->isEquivalentCacheRecord($cache[$topic] ?? null, $record)) {
                    $cache[$topic] = $record;
                    $cacheChanged = true;
                    $this->debugExpert(__FUNCTION__, 'Cached discovery config', [
                        'Topic' => $topic,
                        'CacheCount' => count($cache)
                    ]);
                }
            }
        }

        if (!$cacheChanged) {
            return;
        }

        $this->writeDiscoveryCache($cache);
        $this->syncReferencedRuntimeTopicState($cache);
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
                    'extra_cached' => $topicAnalysis['extra_count'],
                    'by_kind' => $topicAnalysis['by_kind_diagnostics']
                ]
            ],
            'referenced_topics' => $topicAnalysis['topic_entries'],
            'extra_cached_topics' => $topicAnalysis['extra_topics'],
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
        $startedAt = microtime(true);
        $this->logPerformanceMarker(__FUNCTION__, 'start');
        $discoveryCache = $this->getDiscoveryConfigRecords();
        $this->writeDiscoveryCache($discoveryCache);
        $this->logPerformanceMarker(__FUNCTION__, 'before_syncReferencedRuntimeTopicState', [
            'DiscoveryCount' => count($discoveryCache)
        ]);
        $this->syncReferencedRuntimeTopicState($discoveryCache);
        $this->logPerformanceMarker(__FUNCTION__, 'after_syncReferencedRuntimeTopicState', [
            'DiscoveryCount' => count($discoveryCache)
        ]);
        $this->logPerformanceSample(__FUNCTION__, $startedAt, [
            'DiscoveryCount' => count($discoveryCache)
        ]);
    }

    private function updateTopicPayloadCacheFromMessage(string $topic, string $payload, array $metadata = []): void
    {
        $topic = trim($topic, '/');
        if ($topic === '') {
            return;
        }

        $cache = $this->readTopicPayloadCache();
        $cacheChanged = false;
        if ($this->isDiscoveryConfigTopic($topic) || trim($payload) === '') {
            if (isset($cache[$topic])) {
                unset($cache[$topic]);
                $cacheChanged = true;
            }
        } else {
            $referencedTopics = $this->readReferencedTopicLookup();
            if (!isset($referencedTopics[$topic])) {
                if (isset($cache[$topic])) {
                    unset($cache[$topic]);
                    $cacheChanged = true;
                }
            } else {
                $record = $this->createCacheRecord($topic, $payload, $metadata, true);
                if ($record !== null && !$this->isEquivalentCacheRecord($cache[$topic] ?? null, $record)) {
                    $cache[$topic] = $record;
                    $cacheChanged = true;
                }
            }
        }

        if ($cacheChanged) {
            $this->writeTopicPayloadCache($cache);
        }
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
        $decoded = $this->readJsonAttributeArraySafe(self::ATTRIBUTE_DISCOVERY_CACHE);

        return $this->normalizeCacheRecords($decoded, true);
    }

    private function readTopicPayloadCache(): array
    {
        $decoded = $this->readJsonAttributeArraySafe(self::ATTRIBUTE_TOPIC_PAYLOAD_CACHE);

        return $this->normalizeCacheRecords($decoded, false);
    }

    private function writeDiscoveryCache(array $cache): void
    {
        $this->writeJsonAttributeIfChanged(self::ATTRIBUTE_DISCOVERY_CACHE, $cache);
    }

    private function writeTopicPayloadCache(array $cache): void
    {
        $this->writeJsonAttributeIfChanged(self::ATTRIBUTE_TOPIC_PAYLOAD_CACHE, $cache);
    }

    private function readReferencedTopicLookup(): array
    {
        $decoded = $this->readJsonAttributeArraySafe(self::ATTRIBUTE_REFERENCED_TOPIC_LOOKUP);

        if (!is_array($decoded)) {
            return [];
        }

        $result = [];
        foreach ($decoded as $topic => $enabled) {
            if (!is_string($topic) || trim($topic, '/') === '' || !$enabled) {
                continue;
            }
            $result[trim($topic, '/')] = true;
        }

        ksort($result, SORT_STRING);
        return $result;
    }

    private function writeReferencedTopicLookup(array $lookup): void
    {
        ksort($lookup, SORT_STRING);
        $this->writeJsonAttributeIfChanged(self::ATTRIBUTE_REFERENCED_TOPIC_LOOKUP, $lookup);
    }

    private function buildDefaultBundleState(): array
    {
        return [
            'mode' => self::SOURCE_MODE_MQTT,
            'loaded' => false,
            'path' => '',
            'configured_path' => '',
            'base_path' => $this->getDefaultBundleFixturesPath(),
            'error' => '',
            'bundle_format' => '',
            'bundle_version' => 0,
            'discovery_prefix' => '',
            'exported_at' => '',
            'session_id' => '',
            'session_started_at' => 0,
            'session_active' => false,
            'discovery_config_count' => 0,
            'topic_payload_count' => 0,
            'current_session_only' => false,
            'source_notes' => ''
        ];
    }

    private function readBundleState(): array
    {
        $decoded = $this->readJsonAttributeArraySafe(self::ATTRIBUTE_BUNDLE_STATE);

        if (!is_array($decoded)) {
            $decoded = [];
        }

        return [
            'mode' => trim((string)($decoded['mode'] ?? self::SOURCE_MODE_MQTT)),
            'loaded' => (bool)($decoded['loaded'] ?? false),
            'path' => trim((string)($decoded['path'] ?? '')),
            'configured_path' => trim((string)($decoded['configured_path'] ?? '')),
            'base_path' => trim((string)($decoded['base_path'] ?? $this->getDefaultBundleFixturesPath())),
            'error' => trim((string)($decoded['error'] ?? '')),
            'bundle_format' => trim((string)($decoded['bundle_format'] ?? '')),
            'bundle_version' => max(0, (int)($decoded['bundle_version'] ?? 0)),
            'discovery_prefix' => trim((string)($decoded['discovery_prefix'] ?? '')),
            'exported_at' => trim((string)($decoded['exported_at'] ?? '')),
            'session_id' => trim((string)($decoded['session_id'] ?? '')),
            'session_started_at' => max(0, (int)($decoded['session_started_at'] ?? 0)),
            'session_active' => (bool)($decoded['session_active'] ?? false),
            'discovery_config_count' => max(0, (int)($decoded['discovery_config_count'] ?? 0)),
            'topic_payload_count' => max(0, (int)($decoded['topic_payload_count'] ?? 0)),
            'current_session_only' => (bool)($decoded['current_session_only'] ?? false),
            'source_notes' => trim((string)($decoded['source_notes'] ?? ''))
        ];
    }

    private function writeBundleState(array $state): void
    {
        $normalized = $this->buildDefaultBundleState();
        foreach ($normalized as $key => $_value) {
            if (array_key_exists($key, $state)) {
                $normalized[$key] = $state[$key];
            }
        }

        $this->WriteAttributeString(
            self::ATTRIBUTE_BUNDLE_STATE,
            json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    private function syncReferencedRuntimeTopicState(array $discoveryCache): void
    {
        $lookup = $this->buildReferencedTopicLookup($discoveryCache);
        $this->writeReferencedTopicLookup($lookup);
        $this->writeTopicPayloadCache($this->filterRuntimeCacheToReferencedTopics($this->readTopicPayloadCache(), $lookup));
    }

    private function buildReferencedTopicLookup(array $discoveryCache): array
    {
        $lookup = [];
        foreach ($this->collectReferencedTopicsFromDiscoveryConfigs(array_values($discoveryCache)) as $topic) {
            $topic = trim($topic, '/');
            if ($topic === '') {
                continue;
            }
            $lookup[$topic] = true;
        }

        ksort($lookup, SORT_STRING);
        return $lookup;
    }

    private function filterRuntimeCacheToReferencedTopics(array $runtimeCache, array $referencedTopicLookup): array
    {
        $filtered = [];
        foreach ($runtimeCache as $topic => $record) {
            $topic = trim((string)$topic, '/');
            if ($topic === '' || !isset($referencedTopicLookup[$topic])) {
                continue;
            }
            $filtered[$topic] = $record;
        }

        ksort($filtered, SORT_STRING);
        return $filtered;
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
        $bundleState = $this->readBundleState();
        $sourceMode = $this->getSourceMode();

        $last = $this->ReadAttributeString('LastMQTTMessage');
        if ($last === '') {
            $last = $this->Translate('never');
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

        $sourceCaption = $sourceMode === self::SOURCE_MODE_BUNDLE
            ? $this->Translate('Source: Bundle')
            : $this->Translate('Source: MQTT');
        if ($sourceMode === self::SOURCE_MODE_BUNDLE && !$bundleState['loaded']) {
            $sourceCaption .= ' | ' . $this->Translate('Error');
        }

        $bundleCaption = $this->Translate('Bundle: inactive');
        if ($sourceMode === self::SOURCE_MODE_BUNDLE) {
            if ($bundleState['loaded']) {
                $bundleName = $bundleState['path'] !== '' ? pathinfo($bundleState['path'], PATHINFO_BASENAME) : '-';
                $bundleExport = $bundleState['exported_at'] !== '' ? $bundleState['exported_at'] : '-';
                $sessionFilter = $bundleState['current_session_only'] ? ' | ' . $this->Translate('current session only') : '';
                $bundleCaption = sprintf(
                    $this->Translate('Bundle: loaded | %s | Export %s | Discovery configs %d | Topics %d%s'),
                    $bundleName,
                    $bundleExport,
                    (int)$bundleState['discovery_config_count'],
                    (int)$bundleState['topic_payload_count'],
                    $sessionFilter
                );
            } else {
                $bundleCaption = $this->Translate('Bundle: error | ') . ($bundleState['error'] !== '' ? $bundleState['error'] : $this->Translate('not loaded'));
            }
        }

        $bundleBasePathCaption = $this->Translate('Default bundle path: ') . ($bundleState['base_path'] !== '' ? $bundleState['base_path'] : $this->getDefaultBundleFixturesPath());

        $parentCaption = $this->Translate('MQTT Parent: ') . $parentId . $nameSuffix . ' | ' . $this->Translate('Status') . ' ' . $parentStatus . $statusSuffix;
        if ($sourceMode === self::SOURCE_MODE_BUNDLE) {
            $parentCaption .= ' | ' . $this->Translate('not used in bundle mode');
        }

        $sessionLabel = $sourceMode === self::SOURCE_MODE_BUNDLE
            ? $this->Translate('Bundle Session: ')
            : $this->Translate('MQTT Session: ');

        return [
            'DiagInstance' => $this->Translate('Instance: ') . $this->InstanceID . ' | ' . $this->Translate('Status') . ' ' . $instanceStatus,
            'BundleBasePathInfo' => $bundleBasePathCaption,
            'DiagSource' => $sourceCaption,
            'DiagParent' => $parentCaption,
            'DiagSession' => $sessionLabel . $this->buildSessionCaption(),
            'DiagBundle' => $bundleCaption,
            'LastMQTTMessage' => $this->Translate('Last MQTT message: ') . $last,
            'DiagDiscovery' => sprintf(
                $this->Translate('MQTT Discovery Prefix: %s | Discovery configs total/current/stale: %d/%d/%d'),
                $this->getDiscoveryPrefix(),
                $discoveryAnalysis['total_count'],
                $discoveryAnalysis['current_count'],
                $discoveryAnalysis['stale_count']
            ),
            'DiagDiscoveryPreview' => $this->Translate('Stale discovery topics: ') . $this->buildTopicPreview($discoveryAnalysis['stale_topics']),
            'DiagTopicPayloads' => sprintf(
                $this->Translate('Referenced runtime topics total/current/stale/missing: %d/%d/%d/%d'),
                $topicAnalysis['referenced_count'],
                $topicAnalysis['current_count'],
                $topicAnalysis['stale_count'],
                $topicAnalysis['missing_count']
            ),
            'DiagTopicPreview' => $this->Translate('Missing/stale runtime topics: ') . $this->buildCombinedTopicIssuePreview(
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

    private function logPerformanceSample(string $scope, float $startedAt, array $context = [], bool $force = false): void
    {
    }

    private function logPerformanceMarker(string $scope, string $phase, array $context = []): void
    {
    }

    private function applyCurrentDiagnosticsToForm(array &$form): void
    {
        $captions = $this->buildDiagnosticsCaptions();
        $isBundleMode = $this->isBundleMode();
        if (isset($form['elements']) && is_array($form['elements'])) {
            $this->applyFormItemsState($form['elements'], $captions, $isBundleMode);
        }
        foreach ($form['actions'] as &$action) {
            if (!isset($action['items']) || !is_array($action['items'])) {
                continue;
            }

            $this->applyFormItemsState($action['items'], $captions, $isBundleMode);
        }
        unset($action);
    }

    private function applyFormItemsState(array &$items, array $captions, bool $isBundleMode): void
    {
        $bundleOnlyFields = [
            'BundleBasePathInfo',
            'BundlePath',
            'BundleCurrentSessionOnly',
            'ReplayTopicsOnApply',
            'ButtonReloadBundle',
            'ButtonReplayBundleTopics'
        ];

        foreach ($items as &$item) {
            if (isset($item['items']) && is_array($item['items'])) {
                $this->applyFormItemsState($item['items'], $captions, $isBundleMode);
            }

            $name = (string)($item['name'] ?? '');
            if ($name !== '' && array_key_exists($name, $captions)) {
                $item['caption'] = $captions[$name];
            }

            if ($name !== '' && in_array($name, $bundleOnlyFields, true)) {
                $item['visible'] = $isBundleMode;
            }

            if ($name === 'ButtonReconnectMqttIo') {
                $item['visible'] = !$isBundleMode;
            }
            if ($name === 'ButtonReplayBundleTopics') {
                $item['enabled'] = $isBundleMode;
            }
        }
        unset($item);
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
        $descriptors = $this->collectReferencedTopicDescriptorsFromDiscoveryConfigs($records);
        return array_column($descriptors, 'topic');
    }

    private function collectReferencedTopicDescriptorsFromDiscoveryConfigs(array $records): array
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
            $normalizedTopics[$topic] = array_keys($topics[$topic]);
            sort($normalizedTopics[$topic], SORT_STRING);
        }

        ksort($normalizedTopics, SORT_STRING);

        $result = [];
        foreach ($normalizedTopics as $topic => $kinds) {
            $result[] = [
                'topic' => $topic,
                'kinds' => $kinds,
                'primary_kind' => $kinds[0] ?? 'state'
            ];
        }

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

        $this->addReferencedTopic($topics, $topic, 'event');

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
            $this->addReferencedTopic($topics, $fallbackTopic, 'event');
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

            $this->addReferencedTopic($topics, $topic, 'availability');
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

        if (!empty($record['payload_is_binary'])) {
            $normalized['payload_is_binary'] = true;
            $normalized['payload_bytes'] = max(0, (int)($record['payload_bytes'] ?? 0));
        }

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

        if (array_key_exists('is_current_session', $record)) {
            $normalized['is_current_session'] = (bool)$record['is_current_session'];
        }

        return $normalized;
    }

    private function createCacheRecord(string $topic, string $payload, array $metadata = [], bool $allowBinarySummary = false): ?array
    {
        $storedPayload = $this->normalizePayloadForCache($payload, $allowBinarySummary);
        if ($storedPayload === null) {
            return null;
        }

        $record = [
            'topic' => $topic,
            'payload' => $storedPayload['payload'],
            'received_at' => time()
        ];

        if (!empty($storedPayload['payload_is_binary'])) {
            $record['payload_is_binary'] = true;
            $record['payload_bytes'] = (int)$storedPayload['payload_bytes'];
        }

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

    private function isEquivalentCacheRecord(mixed $left, mixed $right): bool
    {
        if (!is_array($left) || !is_array($right)) {
            return false;
        }

        // Identical MQTT messages should not keep churning the attribute cache. Session-bound metadata
        // still counts as a real change, but volatile timestamps alone do not.
        return $this->buildCacheRecordSignature($left) === $this->buildCacheRecordSignature($right);
    }

    private function buildCacheRecordSignature(array $record): array
    {
        $direction = strtolower(trim((string)($record['direction'] ?? '')));
        if ($direction !== 'rx' && $direction !== 'tx') {
            $direction = '';
        }

        return [
            'topic' => trim((string)($record['topic'] ?? ''), '/'),
            'payload' => (string)($record['payload'] ?? ''),
            'payload_is_binary' => (bool)($record['payload_is_binary'] ?? false),
            'payload_bytes' => max(0, (int)($record['payload_bytes'] ?? 0)),
            'session_id' => trim((string)($record['session_id'] ?? '')),
            'retained' => array_key_exists('retained', $record) ? (bool)$record['retained'] : null,
            'qos' => array_key_exists('qos', $record) ? max(0, min(2, (int)$record['qos'])) : null,
            'direction' => $direction
        ];
    }

    private function normalizePayloadForCache(string $payload, bool $allowBinarySummary): ?array
    {
        if (trim($payload) === '') {
            return null;
        }

        if ($this->isValidUtf8String($payload)) {
            return ['payload' => $payload];
        }

        if (!$allowBinarySummary) {
            return null;
        }

        return [
            'payload' => '[binary payload omitted]',
            'payload_is_binary' => true,
            'payload_bytes' => strlen($payload)
        ];
    }

    private function isValidUtf8String(string $value): bool
    {
        return preg_match('//u', $value) === 1;
    }

    private function writeJsonAttributeIfChanged(string $attribute, array $value): void
    {
        $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($this->ReadAttributeString($attribute) === $encoded) {
            return;
        }

        $this->WriteAttributeString($attribute, $encoded);
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
        $topicDescriptors = $this->collectReferencedTopicDescriptorsFromDiscoveryConfigs($discoveryConfigs);
        $referencedTopics = array_column($topicDescriptors, 'topic');
        $referencedLookup = array_fill_keys($referencedTopics, true);
        $runtimeCache = $this->readTopicPayloadCache();
        $sessionState = $this->readMqttSessionState();

        ['current' => $currentTopics, 'stale' => $staleTopics, 'missing' => $missingTopics] = $this->classifyReferencedTopicsByCacheStatus($referencedTopics, $runtimeCache, $sessionState);

        $extraTopics = [];
        foreach (array_keys($runtimeCache) as $topic) {
            if (!isset($referencedLookup[$topic])) {
                $extraTopics[] = $topic;
            }
        }

        sort($extraTopics, SORT_STRING);

        $topicEntries = [];
        foreach ($topicDescriptors as $descriptor) {
            $topic = (string)($descriptor['topic'] ?? '');
            if ($topic === '') {
                continue;
            }

            $record = $runtimeCache[$topic] ?? null;
            $hasPayload = is_array($record);
            $isCurrentSession = $hasPayload && $this->isRecordCurrentSession($record, $sessionState);
            $status = $hasPayload
                ? ($isCurrentSession ? 'current' : 'stale')
                : 'missing';

            $topicEntries[] = [
                'topic' => $topic,
                'kinds' => is_array($descriptor['kinds'] ?? null) ? array_values($descriptor['kinds']) : [],
                'primary_kind' => (string)($descriptor['primary_kind'] ?? 'state'),
                'status' => $status,
                'is_current_session' => $isCurrentSession,
                'has_payload' => $hasPayload
            ];
        }

        $topicsByKind = [];
        foreach ($topicDescriptors as $descriptor) {
            foreach (($descriptor['kinds'] ?? []) as $kind) {
                if (!is_string($kind) || $kind === '') {
                    continue;
                }

                $topicsByKind[$kind][(string)$descriptor['topic']] = true;
            }
        }
        ksort($topicsByKind, SORT_STRING);

        $byKindDiagnostics = [];
        foreach ($topicsByKind as $kind => $topicLookup) {
            $kindTopics = array_keys($topicLookup);
            sort($kindTopics, SORT_STRING);
            $classified = $this->classifyReferencedTopicsByCacheStatus($kindTopics, $runtimeCache, $sessionState);
            $byKindDiagnostics[$kind] = [
                'referenced' => count($kindTopics),
                'current_session' => count($classified['current']),
                'stale' => count($classified['stale']),
                'missing' => count($classified['missing'])
            ];
        }

        return [
            'referenced_count' => count($referencedTopics),
            'current_count' => count($currentTopics),
            'stale_count' => count($staleTopics),
            'missing_count' => count($missingTopics),
            'extra_count' => count($extraTopics),
            'topic_entries' => $topicEntries,
            'referenced_topics' => $referencedTopics,
            'current_topics' => $currentTopics,
            'stale_topics' => $staleTopics,
            'missing_topics' => $missingTopics,
            'extra_topics' => $extraTopics,
            'by_kind_diagnostics' => $byKindDiagnostics
        ];
    }

    private function classifyReferencedTopicsByCacheStatus(array $topics, array $runtimeCache, array $sessionState): array
    {
        $currentTopics = [];
        $staleTopics = [];
        $missingTopics = [];

        foreach ($topics as $topic) {
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

        sort($currentTopics, SORT_STRING);
        sort($staleTopics, SORT_STRING);
        sort($missingTopics, SORT_STRING);

        return [
            'current' => $currentTopics,
            'stale' => $staleTopics,
            'missing' => $missingTopics
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
            $issues[] = $this->Translate('missing:') . $topic;
        }
        foreach ($staleTopics as $topic) {
            $issues[] = $this->Translate('stale:') . $topic;
        }

        return $this->buildTopicPreview($issues);
    }

    private function parseBundleTimestamp(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return 0;
            }

            if (ctype_digit($trimmed)) {
                return max(0, (int)$trimmed);
            }

            $timestamp = strtotime($trimmed);
            if ($timestamp !== false) {
                return $timestamp;
            }
        }

        return 0;
    }

    private function buildBundleSessionState(array $bundle, array $state): array
    {
        $bundleSession = is_array($bundle['session'] ?? null) ? $bundle['session'] : [];
        $sessionId = trim((string)($bundleSession['id'] ?? $state['session_id'] ?? ''));
        $startedAt = $this->parseBundleTimestamp($bundleSession['started_at'] ?? ($state['session_started_at'] ?? null));

        return [
            'id' => $sessionId,
            'started_at' => $startedAt,
            'parent_id' => 0,
            'active' => $sessionId !== '' || $startedAt > 0,
            'sequence' => 1
        ];
    }

    private function refreshMqttSessionState(): void
    {
        $this->logPerformanceMarker(__FUNCTION__, 'start');
        if ($this->isBundleMode()) {
            $this->logPerformanceMarker(__FUNCTION__, 'bundle_mode_skip');
            return;
        }

        if ($this->hasCompatibleActiveParentModule(HAIds::MODULE_MQTT_CLIENT)) {
            $state = $this->readMqttSessionState();
            $currentParentId = $this->getCurrentParentId();
            if (!(bool)($state['active'] ?? false) || (int)($state['parent_id'] ?? 0) !== $currentParentId || trim((string)($state['id'] ?? '')) === '') {
                $this->startNewMqttSession();
            }
            $this->logPerformanceMarker(__FUNCTION__, 'active_parent');
            return;
        }

        $this->markMqttSessionInactive();
        $this->logPerformanceMarker(__FUNCTION__, 'inactive_parent');
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
        $decoded = $this->readJsonAttributeArraySafe(self::ATTRIBUTE_MQTT_SESSION_STATE);

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

    private function readJsonAttributeArraySafe(string $attribute): array
    {
        try {
            $raw = $this->ReadAttributeString($attribute);
        } catch (Throwable $e) {
            $this->debugExpert(__FUNCTION__, 'Attribute konnte nicht gelesen werden.', [
                'Attribute' => $attribute,
                'Error' => $e->getMessage()
            ], true);
            return [];
        }

        if (!is_string($raw)) {
            $this->debugExpert(__FUNCTION__, 'Attribute lieferte keinen String.', [
                'Attribute' => $attribute,
                'Type' => gettype($raw)
            ], true);
            return [];
        }

        if (trim($raw) === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->debugExpert(__FUNCTION__, 'Attribute enthaelt kein gueltiges JSON.', [
                'Attribute' => $attribute,
                'Error' => $e->getMessage()
            ], true);
            return [];
        }

        return is_array($decoded) ? $decoded : [];
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
        $sessionId = trim((string)($state['id'] ?? ''));
        $sessionSuffix = ' | ID ' . ($sessionId !== '' ? $sessionId : '-');
        if ($startedAt <= 0) {
            return $this->Translate('no active session') . $sessionSuffix;
        }

        $timeText = date('Y-m-d H:i:s', $startedAt);
        if ((bool)($state['active'] ?? false)) {
            return sprintf($this->Translate('active since %s'), $timeText) . $sessionSuffix;
        }

        return sprintf($this->Translate('inactive | last session %s'), $timeText) . $sessionSuffix;
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

        $this->addReferencedTopic($topics, $topic, $this->determineReferencedRuntimeTopicKind($currentKey));
    }

    private function isReferencedRuntimeTopicKey(string $currentKey): bool
    {
        return str_ends_with($currentKey, '_topic') || preg_match('/_t$/', $currentKey) === 1;
    }

    private function determineReferencedRuntimeTopicKind(string $currentKey): string
    {
        return match ($currentKey) {
            'availability_topic', 'avty_t' => 'availability',
            'command_topic', 'cmd_t', 'bri_cmd_t', 'rgb_cmd_t' => 'command',
            'json_attributes_topic', 'json_attr_t' => 'attributes',
            default => 'state'
        };
    }

    private function addReferencedTopic(array &$topics, string $topic, string $kind): void
    {
        $topic = trim($topic, '/');
        if ($topic === '') {
            return;
        }

        if (!isset($topics[$topic]) || !is_array($topics[$topic])) {
            $topics[$topic] = [];
        }

        $topics[$topic][$kind] = true;
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
