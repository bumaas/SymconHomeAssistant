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

        $this->RegisterPropertyString('SourceMode', self::SOURCE_MODE_MQTT);
        $this->RegisterPropertyString('MQTTDiscoveryPrefix', 'homeassistant');
        $this->RegisterPropertyString('BundlePath', '');
        $this->RegisterPropertyBoolean('BundleCurrentSessionOnly', false);
        $this->RegisterPropertyBoolean('ReplayTopicsOnApply', false);
        $this->RegisterPropertyBoolean('EnableExpertDebug', false);
        $this->RegisterPropertyInteger('OutputBufferSize', 10);

        $this->RegisterTimer(self::TIMER_DIAGNOSTICS_REFRESH, 0, 'HA_RefreshDiscoveryDiagnostics($_IPS["TARGET"]);');
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
        $this->SetReceiveDataFilter($this->isBundleMode() ? '^$' : '.*');

        if ($this->isBundleMode()) {
            $this->applyBundleMode();
            return;
        }

        if (($this->readBundleState()['mode'] ?? self::SOURCE_MODE_MQTT) === self::SOURCE_MODE_BUNDLE) {
            $this->clearDiscoveryRuntimeCaches();
            $this->writeBundleState($this->buildDefaultBundleState());
            $this->WriteAttributeString('LastMQTTMessage', '');
        }

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

    /** @noinspection PhpUnused */
    public function RefreshDiscoveryDiagnostics(): void
    {
        $this->SetTimerInterval(self::TIMER_DIAGNOSTICS_REFRESH, 0);
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
        $loadResult = $this->loadConfiguredBundle();
        if (!($loadResult['ok'] ?? false)) {
            $state = is_array($loadResult['state'] ?? null) ? $loadResult['state'] : $this->buildDefaultBundleState();
            $this->writeBundleState($state);
            $this->clearDiscoveryRuntimeCaches();
            $this->writeMqttSessionState($this->buildBundleSessionState([], $state));
            $this->WriteAttributeString('LastMQTTMessage', '');
            $this->updateDiagnosticsLabels();

            if (($loadResult['status'] ?? 204) === 202) {
                $this->SetStatus(202);
                return;
            }

            $this->SetStatus((int)($loadResult['status'] ?? 204));
            return;
        }

        $bundle = is_array($loadResult['bundle'] ?? null) ? $loadResult['bundle'] : [];
        $state = is_array($loadResult['state'] ?? null) ? $loadResult['state'] : $this->buildDefaultBundleState();
        $this->hydrateCachesFromBundle($bundle, $state);
        $this->WriteAttributeString('LastMQTTMessage', '');
        $this->updateDiagnosticsLabels();
        $this->SetStatus(IS_ACTIVE);

        if ($this->ReadPropertyBoolean('ReplayTopicsOnApply')) {
            $this->replayCachedTopicPayloadsToChildren();
        }
    }

    private function loadConfiguredBundle(): array
    {
        $path = trim($this->ReadPropertyString('BundlePath'));
        $state = $this->buildDefaultBundleState();
        $state['mode'] = self::SOURCE_MODE_BUNDLE;
        $state['path'] = $path;
        $state['current_session_only'] = $this->ReadPropertyBoolean('BundleCurrentSessionOnly');

        if ($path === '') {
            $state['error'] = 'Bundle-Pfad ist leer.';
            return [
                'ok' => false,
                'status' => 203,
                'state' => $state
            ];
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            $state['error'] = 'Bundle-Datei konnte nicht gelesen werden.';
            return [
                'ok' => false,
                'status' => 204,
                'state' => $state
            ];
        }

        try {
            $bundle = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $state['error'] = 'Bundle ist kein gueltiges JSON: ' . $e->getMessage();
            return [
                'ok' => false,
                'status' => 204,
                'state' => $state
            ];
        }

        if (!is_array($bundle)) {
            $state['error'] = 'Bundle ist kein JSON-Objekt.';
            return [
                'ok' => false,
                'status' => 204,
                'state' => $state
            ];
        }

        $format = trim((string)($bundle['format'] ?? ''));
        if ($format !== self::EXPORT_FORMAT) {
            $state['error'] = 'Unerwartetes Bundle-Format.';
            return [
                'ok' => false,
                'status' => 204,
                'state' => $state
            ];
        }

        $version = (int)($bundle['version'] ?? 0);
        if ($version !== self::EXPORT_VERSION) {
            $state['error'] = 'Unerwartete Bundle-Version. Erwartet wird V' . self::EXPORT_VERSION . '.';
            return [
                'ok' => false,
                'status' => 204,
                'state' => $state
            ];
        }

        if (!is_array($bundle['discovery_configs'] ?? null)) {
            $state['error'] = 'discovery_configs fehlt oder ist kein Array.';
            return [
                'ok' => false,
                'status' => 204,
                'state' => $state
            ];
        }

        if (array_key_exists('topic_payloads', $bundle) && !is_array($bundle['topic_payloads'])) {
            $state['error'] = 'topic_payloads ist kein Array.';
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
            $state['error'] = 'MQTT Discovery Prefix ist leer.';
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
        $bundleSessionState = $this->buildBundleSessionState($bundle, $state);
        $discoveryCache = $this->normalizeCacheRecords($bundle['discovery_configs'] ?? [], true);
        $runtimeCache = $this->normalizeCacheRecords($bundle['topic_payloads'] ?? [], false);

        if ($this->ReadPropertyBoolean('BundleCurrentSessionOnly')) {
            $discoveryCache = $this->filterBundleCacheToCurrentSession($discoveryCache, $bundleSessionState);
            $runtimeCache = $this->filterBundleCacheToCurrentSession($runtimeCache, $bundleSessionState);
        }

        $lookup = $this->buildReferencedTopicLookup($discoveryCache);
        $runtimeCache = $this->filterRuntimeCacheToReferencedTopics($runtimeCache, $lookup);

        $state['loaded'] = true;
        $state['discovery_config_count'] = count($discoveryCache);
        $state['topic_payload_count'] = count($runtimeCache);

        $this->writeBundleState($state);
        $this->writeDiscoveryCache($discoveryCache);
        $this->writeReferencedTopicLookup($lookup);
        $this->writeTopicPayloadCache($runtimeCache);
        $this->writeMqttSessionState($bundleSessionState);
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
        if (trim($payload) === '') {
            unset($cache[$topic]);
            $this->debugExpert(__FUNCTION__, 'Removed discovery config from cache', [
                'Topic' => $topic,
                'CacheCount' => count($cache)
            ]);
        } else {
            $record = $this->createCacheRecord($topic, $payload, $metadata, false);
            if ($record === null) {
                unset($cache[$topic]);
                $this->debugExpert(__FUNCTION__, 'Discovery-Payload nicht cachebar', [
                    'Topic' => $topic,
                    'PayloadBytes' => strlen($payload),
                    'CacheCount' => count($cache)
                ], true);
            } else {
                $cache[$topic] = $record;
                $this->debugExpert(__FUNCTION__, 'Cached discovery config', [
                    'Topic' => $topic,
                    'CacheCount' => count($cache)
                ]);
            }
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
        $discoveryCache = $this->getDiscoveryConfigRecords();
        $this->writeDiscoveryCache($discoveryCache);
        $this->syncReferencedRuntimeTopicState($discoveryCache);
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
                if ($record !== null) {
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

    private function readReferencedTopicLookup(): array
    {
        try {
            $decoded = json_decode($this->ReadAttributeString(self::ATTRIBUTE_REFERENCED_TOPIC_LOOKUP), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

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
        $this->WriteAttributeString(
            self::ATTRIBUTE_REFERENCED_TOPIC_LOOKUP,
            json_encode($lookup, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    private function buildDefaultBundleState(): array
    {
        return [
            'mode' => self::SOURCE_MODE_MQTT,
            'loaded' => false,
            'path' => '',
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
        try {
            $decoded = json_decode($this->ReadAttributeString(self::ATTRIBUTE_BUNDLE_STATE), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $decoded = [];
        }

        if (!is_array($decoded)) {
            $decoded = [];
        }

        return [
            'mode' => trim((string)($decoded['mode'] ?? self::SOURCE_MODE_MQTT)),
            'loaded' => (bool)($decoded['loaded'] ?? false),
            'path' => trim((string)($decoded['path'] ?? '')),
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

        $sourceCaption = $sourceMode === self::SOURCE_MODE_BUNDLE
            ? 'Source: Bundle'
            : 'Source: MQTT';
        if ($sourceMode === self::SOURCE_MODE_BUNDLE && !$bundleState['loaded']) {
            $sourceCaption .= ' | Fehler';
        }

        $bundleCaption = 'Bundle: nicht aktiv';
        if ($sourceMode === self::SOURCE_MODE_BUNDLE) {
            if ($bundleState['loaded']) {
                $bundleName = $bundleState['path'] !== '' ? pathinfo($bundleState['path'], PATHINFO_BASENAME) : '-';
                $bundleExport = $bundleState['exported_at'] !== '' ? $bundleState['exported_at'] : '-';
                $sessionFilter = $bundleState['current_session_only'] ? ' | nur aktuelle Session' : '';
                $bundleCaption = sprintf(
                    'Bundle: geladen | %s | Export %s | Configs %d | Topics %d%s',
                    $bundleName,
                    $bundleExport,
                    (int)$bundleState['discovery_config_count'],
                    (int)$bundleState['topic_payload_count'],
                    $sessionFilter
                );
            } else {
                $bundleCaption = 'Bundle: Fehler | ' . ($bundleState['error'] !== '' ? $bundleState['error'] : 'nicht geladen');
            }
        }

        $parentCaption = 'MQTT Parent: ' . $parentId . $nameSuffix . ' | Status ' . $parentStatus . $statusSuffix;
        if ($sourceMode === self::SOURCE_MODE_BUNDLE) {
            $parentCaption .= ' | im Bundle-Modus nicht verwendet';
        }

        $sessionLabel = $sourceMode === self::SOURCE_MODE_BUNDLE ? 'Bundle Session: ' : 'MQTT Session: ';

        return [
            'DiagInstance' => 'Instance: ' . $this->InstanceID . ' | Status ' . $instanceStatus,
            'DiagSource' => $sourceCaption,
            'DiagParent' => $parentCaption,
            'DiagSession' => $sessionLabel . $this->buildSessionCaption(),
            'DiagBundle' => $bundleCaption,
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
        $isBundleMode = $this->isBundleMode();
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
        foreach ($items as &$item) {
            if (isset($item['items']) && is_array($item['items'])) {
                $this->applyFormItemsState($item['items'], $captions, $isBundleMode);
            }

            $name = (string)($item['name'] ?? '');
            if ($name !== '' && array_key_exists($name, $captions)) {
                $item['caption'] = $captions[$name];
            }

            $caption = (string)($item['caption'] ?? '');
            if ($caption === 'MQTT-IO reconnecten') {
                $item['enabled'] = !$isBundleMode;
            }
            if ($caption === 'Bundle-Topics replayen') {
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
            $issues[] = 'missing:' . $topic;
        }
        foreach ($staleTopics as $topic) {
            $issues[] = 'stale:' . $topic;
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
        if ($this->isBundleMode()) {
            return;
        }

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
        $sessionId = trim((string)($state['id'] ?? ''));
        $sessionSuffix = ' | ID ' . ($sessionId !== '' ? $sessionId : '-');
        if ($startedAt <= 0) {
            return 'keine aktive Session' . $sessionSuffix;
        }

        $timeText = date('Y-m-d H:i:s', $startedAt);
        if ((bool)($state['active'] ?? false)) {
            return 'aktiv seit ' . $timeText . $sessionSuffix;
        }

        return 'inaktiv | letzte Session ' . $timeText . $sessionSuffix;
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
