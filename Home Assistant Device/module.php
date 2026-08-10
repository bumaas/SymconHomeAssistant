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
require_once __DIR__ . '/../libs/Device/HADeviceEntityNormalization.php';
require_once __DIR__ . '/../libs/Device/HAEntityStore.php';
require_once __DIR__ . '/../libs/Device/HAVariableMapping.php';
require_once __DIR__ . '/../libs/Device/HAAttributeActionMapping.php';
require_once __DIR__ . '/../libs/Device/HADeviceCore.php';

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
class HomeAssistantDevice extends IPSModuleStrict implements HADeviceConstants
{
    private const int STATUS_PARENT_UNAVAILABLE = 201;
    private const int STATUS_MQTT_BASE_TOPIC_MISSING = 202;
    private const int STATUS_DEVICE_ID_MISSING = 210;
    private const int STATUS_DEVICE_NOT_FOUND = 211;
    private const int STATUS_BUNDLE_PATH_MISSING = 212;
    private const int STATUS_BUNDLE_INVALID = 213;
    private const string ATTR_RESOLVED_CONFIG = 'ResolvedConfig';

    // Ausreißer-Diagnose (gated über EnablePerformanceLog): Schritt-Samples oberhalb der Schwelle
    // landen gedrosselt im Symcon-Log — die Schritt-Scopes zeigen dann, WO die Zeit steckt.
    // Drossel-Zeitstempel MUSS im Buffer liegen (ReceiveData läuft in getrennten PHP-Ausführungen).
    private const float PERF_SLOW_THRESHOLD_MS = 100.0;
    private const string BUFFER_PERF_SLOW_LOG_TS = 'PerfSlowLogEpoch';
    private const int PERF_SLOW_LOG_THROTTLE_SEC = 10;

    use ModuleDebugTrait;
    use HAIdentNamingTrait;
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
    use HADeviceEntityNormalizationTrait;
    use HAEntityStoreTrait;
    use HAVariableMappingTrait;
    use HAAttributeActionMappingTrait;
    use HASupportedFeaturesTrait;
    use HADiagnosticsTrait;
    use HAOutputBufferTrait;
    use HABundlePathTrait;
    use HARestParentClientTrait;
    use HAEntityConfigLoaderTrait;
    use HAEntityConfigBuilderTrait {
        buildResolvedEntityConfig as private buildResolvedEntities;
    }
    use HADeviceCoreTrait;

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
        $this->RegisterAttributeString(self::ATTR_RESOLVED_CONFIG, '[]');

        // Eigenschaften
        $this->RegisterPropertyString(self::PROP_DEVICE_ID, '');
        $this->RegisterPropertyString(self::PROP_DEVICE_AREA, '');
        $this->RegisterPropertyString(self::PROP_DEVICE_NAME, '');
        $this->RegisterPropertyBoolean(self::PROP_ENABLE_EXPERT_DEBUG, false);
        $this->RegisterPropertyBoolean(self::PROP_ENABLE_PERFORMANCE_LOG, false);
        $this->RegisterPropertyBoolean(self::PROP_SHOW_TECHNICAL_ENTITY_COLUMNS, false);
        $this->RegisterPropertyBoolean(self::PROP_SHOW_UNAVAILABLE_ENTITIES_JSON, false);
        $this->RegisterPropertyBoolean(self::PROP_EMULATE_STATUS, false);
        $this->RegisterPropertyInteger(self::PROP_OUTPUT_BUFFER_SIZE, 10);
        $this->RegisterPropertyString(self::PROP_SOURCE_MODE, 'mqtt');
        $this->RegisterPropertyString(self::PROP_BUNDLE_PATH, '');

        $this->RegisterTimer(self::TIMER_MEDIA_PLAYER_PROGRESS, 0, 'HA_UpdateMediaPlayerProgress($_IPS["TARGET"]);');
        $this->registerDeferredApplyTimer();
        $this->registerMediaRefreshTimer();
        $this->registerStateCacheFlushTimer();
    }


    /**
     * Reagiert auf Änderungen am Parent (Gateway).
     */
    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        // ApplyChanges nicht direkt im Nachrichten-Kontext ausführen: Beim KR_READY-Broadcast
        // laufen sonst alle Instanzen im selben Insight-Trace über den Splitter, dessen
        // Schleifenschutz die Weiterleitung nach ~120 Einträgen abbricht.
        if (($Message === IPS_KERNELMESSAGE) && (($Data[0] ?? null) === KR_READY)) {
            $this->debugExpert('MessageSink', 'Kernel bereit. Aktualisierung geplant...');
            $this->scheduleDeferredApply();
            return;
        }

        if (!$this->isModuleRuntimeReady()) {
            return;
        }

        // Wenn sich die Verbindung ändert, ist die Konfiguration neu zu laden.
        if ($Message === FM_CONNECT || $Message === FM_DISCONNECT || $Message === IM_CHANGESTATUS) {
            $this->debugExpert('MessageSink', 'Verbindungsstatus geändert. Aktualisierung geplant...');
            $this->scheduleDeferredApply();
        }
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->ensureResolvedConfigAttributeRegistered(__FUNCTION__);
        // Property-Änderungen (z. B. DeviceName) fließen ins Naming ein — Cache verwerfen.
        $this->invalidateConfiguredEntitiesCache();
        $this->syncParentStatusMessageRegistration();
        if (!$this->isKernelReady()) {
            $this->debugExpert('ApplyChanges', 'Kernel noch nicht bereit. Initialisierung wird bis KR_READY verschoben.');
            return;
        }
        $this->SetTimerInterval(self::TIMER_MEDIA_PLAYER_PROGRESS, 0);
        $this->resetPendingMediaRefresh();
        $this->flushEntityStateCache();
        $this->maintainUnavailableEntitiesJsonVariable();
        $this->updateUnavailableEntitiesJsonVariable();

        $isBundleMode = $this->isBundleMode();

        $parentState = $this->determineParentRuntimeState([HAIds::MODULE_SPLITTER]);
        if (!$isBundleMode && $parentState !== 'active') {
            $this->SetStatus(self::STATUS_PARENT_UNAVAILABLE);
            $message = match ($parentState) {
                'missing' => 'Kein Parent verbunden',
                'inactive' => 'Parent ist nicht aktiv',
                default => 'Parent ist nicht Home Assistant Splitter'
            };
            $this->debugExpert('ApplyChanges', $message, $this->getCurrentParentDebugContext(), true);
            return;
        }

        $this->UpdateConfiguration();
    }

    public function UpdateConfiguration(): void
    {
        $isBundleMode = $this->isBundleMode();
        $deviceId     = trim($this->ReadPropertyString(self::PROP_DEVICE_ID));

        if (!$isBundleMode && $deviceId === '') {
            $this->SetSummary('');
            $this->failResolvedConfig(self::STATUS_DEVICE_ID_MISSING, __FUNCTION__);
            return;
        }

        $existingCreateVarMap = $this->buildExistingCreateVarMap();

        if ($isBundleMode) {
            $configData = $this->loadConfigFromBundleFile();
            if ($configData === null) {
                return;
            }
        } else {
            $configData = $this->resolveDeviceConfigByDeviceId($deviceId);
            if ($configData === null) {
                // Abfrage fehlgeschlagen (Parent/REST nicht verfügbar): Befund unbekannt,
                // bestehende Konfiguration und Status nicht verwerfen.
                $this->debugExpert(__FUNCTION__, 'Entitäten-Abfrage fehlgeschlagen. Bestehende Konfiguration bleibt erhalten.', ['DeviceID' => $deviceId], true);
                return;
            }
            if ($configData === []) {
                $this->SetSummary($deviceId);
                $this->failResolvedConfig(self::STATUS_DEVICE_NOT_FOUND, __FUNCTION__, 'Gerät nicht in Home Assistant gefunden', ['DeviceID' => $deviceId]);
                return;
            }
        }

        $configData = $this->mergeCreateVarSettings($configData, $existingCreateVarMap);

        $this->writeResolvedConfig(
            json_encode($configData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        // 1. MQTT-Basetopic ermitteln.
        $baseTopic = $this->determineBaseTopic();
        $this->debugExpert('ApplyChanges', 'Konfiguration geladen', ['BaseTopic' => $baseTopic]);

        $stateMap = $this->fetchStateMap($configData);
        if ($stateMap !== []) {
            $configData = $this->mergeStateAttributes($configData, $stateMap);
            $this->writeResolvedConfig(
                json_encode($configData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        if (!$isBundleMode && $baseTopic === '') {
            $this->SetStatus(self::STATUS_MQTT_BASE_TOPIC_MISSING);
            $this->debugExpert('ApplyChanges', 'MQTTBaseTopic ist leer. MQTT Statestream Updates kommen dann nicht an.');
        } else {
            $this->SetStatus(IS_ACTIVE);
        }
        $this->SetSummary($this->buildDeviceSummary($configData) ?: $deviceId);

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

        $this->refreshResolvedFormFields();
    }

    /**
     * Verarbeitet eingehende MQTT-Nachrichten
     */
    public function ReceiveData(string $JSONString): string
    {
        if (!$this->isModuleRuntimeReady()) {
            return '';
        }
        $data = $this->decodeJsonArray($JSONString, 'ReceiveData');
        if ($data === null) {
            return '';
        }
        $topic   = $data['Topic'] ?? '';
        if (!is_string($topic) || trim($topic) === '') {
            $this->debugRuntimeIssue(__FUNCTION__, 'Topic fehlt in MQTT-Nachricht');
            return '';
        }
        $payload = hex2bin($data['Payload']);
        if ($payload === false) {
            $payload = '';
        }

        $this->debugExpert(__FUNCTION__, 'Eingangsdaten', ['Topic' => $topic, 'Payload' => $payload]);

        $messageStartedAt = microtime(true);

        $stepStartedAt = microtime(true);
        $this->touchLastMqttMessage();
        $this->logPerformanceSample('ReceiveData.touchLastMqttMessage', $stepStartedAt);

        $stepStartedAt = microtime(true);
        $handledAsState = $this->tryHandleStateFromTopic($topic, $payload);
        $this->logPerformanceSample('ReceiveData.tryHandleStateFromTopic', $stepStartedAt, [
            'topic' => $topic,
            'handled' => $handledAsState
        ]);
        if ($handledAsState) {
            $this->logPerformanceSample('ReceiveData.total', $messageStartedAt, ['topic' => $topic, 'path' => 'state']);
            return '';
        }

        if (isset($this->topicMapping[$topic])) {
            $entityId = $this->topicMapping[$topic];
            $stepStartedAt = microtime(true);
            $this->updateEntityValue($entityId, $payload);
            $this->logPerformanceSample('ReceiveData.updateEntityValue', $stepStartedAt, ['topic' => $topic]);
        } else {
            $this->debugRuntimeIssue(__FUNCTION__, 'MQTT-Topic ohne Mapping verworfen', ['Topic' => $topic]);
        }

        $this->logPerformanceSample('ReceiveData.total', $messageStartedAt, ['topic' => $topic, 'path' => 'mapping']);
        return '';
    }

    // P5: Property-Read pro Ausführung memoisieren (Aufruf mehrfach je Message).
    private ?bool $performanceLogEnabled = null;

    private function isPerformanceLogEnabled(): bool
    {
        return $this->performanceLogEnabled ??= (bool)@$this->ReadPropertyBoolean(self::PROP_ENABLE_PERFORMANCE_LOG);
    }

    private function logPerformanceSample(string $scope, float $startedAt, array $context = []): void
    {
        if (!$this->isPerformanceLogEnabled()) {
            return;
        }

        $elapsedMs = round((microtime(true) - $startedAt) * 1000.0, 3);
        $context = ['elapsed_ms' => $elapsedMs] + $context;
        $this->SendDebug('Performance', $scope . ' | ' . json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0);

        // Ausreißer persistent ins Symcon-Log (gedrosselt); nur die Schritt-Scopes, nicht das
        // total-Sample — sonst erzeugt jede langsame Message zwei Zeilen ohne Mehrwert.
        if ($elapsedMs < self::PERF_SLOW_THRESHOLD_MS || $scope === 'ReceiveData.total') {
            return;
        }
        $now = time();
        $last = (int)$this->GetBuffer(self::BUFFER_PERF_SLOW_LOG_TS);
        if ($last > 0 && ($now - $last) < self::PERF_SLOW_LOG_THROTTLE_SEC) {
            return;
        }
        $this->SetBuffer(self::BUFFER_PERF_SLOW_LOG_TS, (string)$now);
        $this->LogMessage(
            sprintf('Performance-Ausreißer %s | %s', $scope, json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            KL_WARNING
        );
    }

    /**
     * Verarbeitet Schaltvorgänge
     */
    public function RequestAction(string $Ident, $Value): void
    {
        if (!$this->isModuleRuntimeReady()) {
            return;
        }
        $this->debugExpert(__FUNCTION__, 'Input', ['Ident' => $Ident, 'Value' => $Value], true);

        if ($this->handleDeferredApplyAction($Ident)) {
            return;
        }

        if ($this->handleMediaRefreshAction($Ident)) {
            return;
        }

        if ($this->handleStateCacheFlushAction($Ident)) {
            return;
        }

        if ($Ident === 'UpdateEntityActive') {
            $this->applyEntityActiveChange($Value);
            return;
        }

        foreach ([
            'handleLockAction',
            'handleCoverAction',
            'handleCoverTiltAction',
            'handleValveAction',
            'handleVacuumAction',
            'handleVacuumFanSpeedAction',
            'handleLawnMowerAction',
            'handleUpdateInstallAction',
            'handleCameraPowerAction',
            'handleMediaPlayerPowerAction',
            'handleMediaPlayerAction',
            'handleClimatePowerAction',
        ] as $domainActionHandler) {
            if ($this->$domainActionHandler($Ident, $Value)) {
                return;
            }
        }

        $entity = $this->findEntityByIdent($Ident);
        if ($entity !== null && !empty($entity['entity_id'])) {
            $this->executeMainEntityAction($Ident, $Value, $entity);
            return;
        }

        $this->executeAttributeAction($Ident, $Value);
    }

    /**
     * Sendet einen Schaltwert für die Hauptvariable einer Entität
     * (REST bevorzugt, sonst MQTT-Set-Topic).
     */
    private function executeMainEntityAction(string $Ident, mixed $Value, array $entity): void
    {
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

        if ($this->trySendMainEntityValueViaRest($entityId, (string)($domain ?? ''), $mqttPayload, $Ident, $entity['attributes'] ?? [])) {
            return;
        }

        $topic = $this->getSetTopicForEntity($entityId);
        if ($topic === '') {
            return;
        }
        $this->debugExpert('RequestAction', 'MQTT publish | Topic=' . $topic . ' | Payload=' . $mqttPayload, [], true);

        $this->sendMqttMessage($topic, $mqttPayload);
        $this->resetVariableByDescriptor($Ident, $this->describeVariableByIdent($Ident, $domain));
    }

    /**
     * Sendet einen Schaltwert für eine Attribut-Variable per MQTT-Set-Topic.
     */
    private function executeAttributeAction(string $Ident, mixed $Value): void
    {
        $attributeInfo = $this->resolveAttributeByIdent($Ident);
        if ($attributeInfo === null) {
            $this->debugExpert('RequestAction', 'Entity/Attribut nicht gefunden', ['Ident' => $Ident], true);
            return;
        }

        $entityId  = $attributeInfo['entity_id'];
        $attribute = $attributeInfo['attribute'];

        $payload = $this->buildDomainAttributePayload($attributeInfo['domain'], $attribute, $Value);
        if ($payload === '' && !HADomainCatalog::supportsAttributePayload($attributeInfo['domain'])) {
            $this->debugExpert('RequestAction', 'Attribut-Domain nicht unterstützt', ['Attribute' => $attribute, 'Domain' => $attributeInfo['domain']], true);
            return;
        }
        if ($payload === '') {
            $this->debugExpert('RequestAction', 'Attribut Payload leer', ['Attribute' => $attribute], true);
            return;
        }
        $topic = $this->getSetTopicForEntity($entityId);
        if ($topic === '') {
            $this->debugExpert('Action', 'Kein Set-Topic für Entity | EntityID=' . $entityId, [], true);
            return;
        }
        $this->debugExpert('RequestAction', 'MQTT publish | Topic=' . $topic . ' | Payload=' . $payload, [], true);
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
        $form   = json_decode(file_get_contents(__DIR__ . '/form.json'), true, 512, JSON_THROW_ON_ERROR);
        $config = $this->readResolvedConfig(__FUNCTION__);
        $this->debugExpert(__FUNCTION__, 'config:', $config);

        $values        = $this->buildResolvedConfigFormValues($config);
        $labelCaptions = $this->buildDeviceLabelCaptions($config);

        $applyItem = function (array &$item) use ($labelCaptions, $values): void {
            $name = (string)($item['name'] ?? '');
            if (isset($labelCaptions[$name])) {
                $item['caption'] = $labelCaptions[$name];
            }
            if ($name === 'ResolvedConfig') {
                $item['values'] = $values;
                $this->applyResolvedConfigColumnSettings($item);
            }
            // descend into RowLayout / nested items
            if (isset($item['items']) && is_array($item['items'])) {
                foreach ($item['items'] as &$child) {
                    $childName = (string)($child['name'] ?? '');
                    if (isset($labelCaptions[$childName])) {
                        $child['caption'] = $labelCaptions[$childName];
                    }
                }
                unset($child);
            }
        };

        foreach ($form['actions'] as &$action) {
            if (($action['name'] ?? '') === 'CURRENT_FILTER') {
                $action['caption'] = sprintf(
                    $this->Translate('Current filter (regex): %s'),
                    $this->ReadAttributeString('CurrentFilter')
                );
            }
            if (isset($action['items']) && is_array($action['items'])) {
                foreach ($action['items'] as &$item) {
                    $applyItem($item);
                }
                unset($item);
            }
            if (($action['name'] ?? '') === 'ResolvedConfig') {
                $action['values'] = $values;
                $this->applyResolvedConfigColumnSettings($action);
            }
        }
        unset($action);
        $this->applyCurrentDiagnosticsToForm($form, $values);
        $this->applyBundleVisibilityToForm($form);
        $this->applyBundleDownloadFilenameToForm($form);
        $this->debugExpert(__FUNCTION__, 'Form:', $form);

        return json_encode($form, JSON_THROW_ON_ERROR);
    }

    private function applyCurrentDiagnosticsToForm(array &$form, array $values): void
    {
        $lastMqtt = $this->ReadAttributeString('LastMQTTMessage');
        if ($lastMqtt === '') {
            $lastMqtt = $this->Translate('never');
        }

        $lastRest = $this->ReadAttributeString('LastRESTFetch');
        if ($lastRest === '') {
            $lastRest = $this->Translate('never');
        }

        $activeEntityCount = count(array_filter($values, static function (array $row): bool {
            return !array_key_exists('create_var', $row) || $row['create_var'];
        }));

        $captions = [
            'DiagLastMQTT' => sprintf($this->Translate('Last MQTT message: %s'), $lastMqtt),
            'DiagLastREST' => sprintf($this->Translate('Last REST fetch: %s'), $lastRest),
            'DiagEntityCount' => sprintf($this->Translate('Entities (active): %d'), $activeEntityCount)
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

    private function applyBundleVisibilityToForm(array &$form): void
    {
        $isBundleMode = $this->isBundleMode();
        foreach ($form['elements'] as &$element) {
            if (($element['name'] ?? '') === 'BundlePanel') {
                $element['visible'] = $isBundleMode;
                break;
            }
        }
        unset($element);
    }

    private function applyBundleDownloadFilenameToForm(array &$form): void
    {
        $deviceLabel = $this->buildDeviceSummary() ?: trim($this->ReadPropertyString(self::PROP_DEVICE_ID));
        $slug        = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $deviceLabel), '_'));
        if ($slug === '') {
            return;
        }
        $filename = "ha_device_config_bundle_{$slug}.json";
        foreach ($form['actions'] as &$panel) {
            if (!isset($panel['items'])) {
                continue;
            }
            foreach ($panel['items'] as &$row) {
                if (!isset($row['items'])) {
                    continue;
                }
                foreach ($row['items'] as &$item) {
                    if (($item['name'] ?? '') === 'ButtonDownloadBundle') {
                        $item['download'] = $filename;
                        return;
                    }
                }
                unset($item);
            }
            unset($row);
        }
        unset($panel);
    }


    // Ungecachter Neuaufbau der aktiven Entitäten; Aufruf ausschließlich über den
    // Cache-Wrapper getConfiguredEntities (HADeviceCore).
    private function buildConfiguredEntitiesUncached(array $configData): array
    {
        $configuredEntities = [];
        foreach ($configData as $row) {
            if (!is_array($row)) {
                continue;
            }
            $row = $this->normalizeActiveConfiguredEntity($row);
            if ($row === null) {
                continue;
            }

            $configuredEntities[] = $row;
        }

        return $this->applySharedEntityIdents($configuredEntities);
    }

    public function ExportConfigBundleDataUrl(): string
    {
        $json = $this->ReadAttributeString(self::ATTR_RESOLVED_CONFIG);
        if ($json === '' || $json === '[]') {
            $json = '[]';
        } else {
            // Re-encode with pretty print for readability.
            try {
                $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                $json    = json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            } catch (JsonException $e) {
                $this->debugExpert(__FUNCTION__, 'Failed to re-encode config: ' . $e->getMessage());
            }
        }

        $dataUrl = 'data:text/plain;charset=utf-8;base64,' . base64_encode($json);
        $this->applyOutputBufferForStringResponse($dataUrl, __FUNCTION__);
        return $dataUrl;
    }

    /** @noinspection PhpUnused */
    public function ActivateBundleMode(string $BundlePath): void
    {
        IPS_SetProperty($this->InstanceID, self::PROP_SOURCE_MODE, 'bundle');
        IPS_SetProperty($this->InstanceID, self::PROP_BUNDLE_PATH, $BundlePath);
        IPS_ApplyChanges($this->InstanceID);
    }

    /** @noinspection PhpUnused */
    public function ActivateMqttMode(): void
    {
        IPS_SetProperty($this->InstanceID, self::PROP_SOURCE_MODE, 'mqtt');
        IPS_ApplyChanges($this->InstanceID);
    }

    private function isBundleMode(): bool
    {
        return strtolower(trim($this->ReadPropertyString(self::PROP_SOURCE_MODE))) === 'bundle';
    }

    private function loadConfigFromBundleFile(): ?array
    {
        $rawPath    = trim($this->ReadPropertyString(self::PROP_BUNDLE_PATH));
        $bundlePath = $this->resolveBundlePath($rawPath);
        if ($bundlePath === '') {
            $this->failResolvedConfig(self::STATUS_BUNDLE_PATH_MISSING, __FUNCTION__);
            return null;
        }

        $contents = @file_get_contents($bundlePath);
        if ($contents === false) {
            $this->failResolvedConfig(self::STATUS_BUNDLE_INVALID, __FUNCTION__, 'Bundle-Datei konnte nicht gelesen werden', ['ConfiguredPath' => $rawPath, 'ResolvedPath' => $bundlePath]);
            return null;
        }

        try {
            $configData = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->failResolvedConfig(self::STATUS_BUNDLE_INVALID, __FUNCTION__, 'Bundle-Datei ist kein gültiges JSON: ' . $e->getMessage(), ['Path' => $bundlePath]);
            return null;
        }

        if (!is_array($configData) || $configData === []) {
            $this->failResolvedConfig(self::STATUS_BUNDLE_INVALID, __FUNCTION__, 'Bundle-Datei enthält keine gültige Konfiguration', ['Path' => $bundlePath]);
            return null;
        }

        $this->debugExpert(__FUNCTION__, 'Bundle geladen', ['ConfiguredPath' => $rawPath, 'ResolvedPath' => $bundlePath, 'Entities' => count($configData)]);
        return $configData;
    }

    /**
     * Gemeinsamer Fehlerpfad: aufgelöste Konfiguration verwerfen, Laufzeit
     * zurücksetzen und den Fehlerstatus samt Formular-/Diagnose-Update melden.
     */
    private function failResolvedConfig(int $status, string $context, string $debugMessage = '', array $debugContext = []): void
    {
        $this->writeResolvedConfig('[]');
        $this->resetResolvedDeviceRuntime();
        $this->SetStatus($status);
        $this->updateDiagnosticsLabels();
        $this->refreshResolvedFormFields();
        if ($debugMessage !== '') {
            $this->debugExpert($context, $debugMessage, $debugContext, true);
        }
    }

    /**
     * @return array|null aufgelöste Konfiguration; [] wenn das Gerät keine Entitäten hat,
     *                    null wenn die Abfrage fehlschlug (Parent/REST nicht verfügbar).
     */
    private function resolveDeviceConfigByDeviceId(string $deviceId): ?array
    {
        $rawEntities = $this->fetchEntitiesByDeviceId($deviceId);
        if ($rawEntities === null) {
            return null;
        }
        if ($rawEntities === []) {
            return [];
        }

        $deduplicated = [];
        foreach ($rawEntities as $rawEntity) {
            if (!is_array($rawEntity)) {
                continue;
            }

            $entityId = trim((string)($rawEntity['entity_id'] ?? ''));
            if ($entityId === '') {
                continue;
            }

            $deduplicated[$entityId] = $rawEntity;
        }

        return $this->buildResolvedEntities(array_values($deduplicated));
    }

    private function getResolvedDeviceName(?array $configData = null): string
    {
        $configData ??= $this->readResolvedConfig(__FUNCTION__);
        $first = $configData[0] ?? null;
        if (!is_array($first)) {
            return trim($this->ReadPropertyString(self::PROP_DEVICE_NAME));
        }

        $name = trim((string)($first['device_name'] ?? ''));
        if ($name !== '' && strtolower($name) !== 'unknown') {
            return $name;
        }

        return trim($this->ReadPropertyString(self::PROP_DEVICE_NAME));
    }

    private function buildDeviceSummary(?array $configData = null): string
    {
        $configData ??= $this->readResolvedConfig(__FUNCTION__);
        $first = $configData[0] ?? null;
        if (!is_array($first)) {
            return trim($this->ReadPropertyString(self::PROP_DEVICE_NAME));
        }

        $manufacturer = trim((string)($first['device_manufacturer'] ?? ''));
        $model        = trim((string)($first['device_model'] ?? ''));
        $name         = trim((string)($first['device_name'] ?? ''));
        if ($name === '' || strtolower($name) === 'unknown') {
            $name = trim($this->ReadPropertyString(self::PROP_DEVICE_NAME));
        }

        $parts = array_filter([$manufacturer, $model, $name], static fn(string $s) => $s !== '');
        return implode(' | ', $parts);
    }

    private function getResolvedDeviceArea(?array $configData = null): string
    {
        $configData ??= $this->readResolvedConfig(__FUNCTION__);
        $first = $configData[0] ?? null;
        if (!is_array($first)) {
            return trim($this->ReadPropertyString(self::PROP_DEVICE_AREA));
        }

        $area = trim((string)($first['area'] ?? ''));
        if (strtolower($area) === 'no area') {
            $area = '';
        }

        return $area !== '' ? $area : trim($this->ReadPropertyString(self::PROP_DEVICE_AREA));
    }

    private function refreshResolvedFormFields(): void
    {
        $configData = $this->readResolvedConfig(__FUNCTION__);
        foreach ($this->buildDeviceLabelCaptions($configData) as $name => $caption) {
            $this->updateFormFieldSafe($name, 'caption', $caption);
        }

        $resolvedConfigForm = json_decode(file_get_contents(__DIR__ . '/form.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->updateFormFieldSafe(
            'ResolvedConfig',
            'columns',
            json_encode(
                $this->buildResolvedConfigColumns($resolvedConfigForm),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )
        );
        $this->updateFormFieldSafe('ResolvedConfig', 'values', json_encode($this->buildResolvedConfigFormValues($configData), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Captions der Geräte-Stammdaten-Labels; von GetConfigurationForm und
     * refreshResolvedFormFields gemeinsam genutzt (Schlüssel = Formularfeldname).
     *
     * @return array<string, string>
     */
    private function buildDeviceLabelCaptions(array $configData): array
    {
        $first        = $configData[0] ?? null;
        $manufacturer = is_array($first) ? trim((string)($first['device_manufacturer'] ?? '')) : '';
        $model        = is_array($first) ? trim((string)($first['device_model'] ?? '')) : '';

        return [
            'DeviceManufacturer'   => sprintf($this->Translate('Manufacturer: %s'), $manufacturer),
            'DeviceModel'          => sprintf($this->Translate('Model: %s'), $model),
            self::PROP_DEVICE_NAME => sprintf($this->Translate('Device name (HA): %s'), $this->getResolvedDeviceName($configData)),
            self::PROP_DEVICE_AREA => sprintf($this->Translate('Area: %s'), $this->getResolvedDeviceArea($configData)),
        ];
    }

    private function resetResolvedDeviceRuntime(): void
    {
        $this->processEntities([], '');
        $this->updateReceiveFilter([]);
        $this->maintainUnavailableEntitiesJsonVariable();
        $this->updateUnavailableEntitiesJsonVariable();
    }

    private function buildResolvedConfigFormValues(array $config): array
    {
        $values = [];
        foreach ($config as $row) {
            $row = $this->normalizeEntityStructure($row);
            if ($row === null || !HADomainCatalog::isDomainSupported($row['domain'] ?? '')) {
                continue;
            }

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

            $row['attributes'] = is_array($attributes)
                ? json_encode($attributes, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : '{}';
            $values[] = $row;
        }

        return $values;
    }

    private function applyResolvedConfigColumnSettings(array &$list): void
    {
        if (!isset($list['columns']) || !is_array($list['columns'])) {
            return;
        }

        $showTechnicalColumns = $this->ReadPropertyBoolean(self::PROP_SHOW_TECHNICAL_ENTITY_COLUMNS);
        foreach ($list['columns'] as &$column) {
            $columnName = (string)($column['name'] ?? '');
            if ($columnName === 'entity_id') {
                $column['visible'] = $showTechnicalColumns;
            }
        }
        unset($column);
    }

    private function buildResolvedConfigColumns(array $form): array
    {
        foreach ($form['actions'] ?? [] as $element) {
            if (!isset($element['items']) || !is_array($element['items'])) {
                continue;
            }

            foreach ($element['items'] as $item) {
                if ((string)($item['name'] ?? '') !== 'ResolvedConfig') {
                    continue;
                }

                $this->applyResolvedConfigColumnSettings($item);
                return is_array($item['columns'] ?? null) ? $item['columns'] : [];
            }
        }

        return [];
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

        // Fremde Instanz-Property: ein defektes JSON darf ApplyChanges nicht abbrechen.
        try {
            $json = json_decode($subscriptions, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->debugExpert('Config', 'Subscriptions des MQTT-IO sind kein gültiges JSON: ' . $e->getMessage(), ['InstanceID' => $instanceId]);
            return $currentBase;
        }
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
     */
    private function processEntities(array $configData, string $baseTopic): array
    {
        $previousEntities = $this->entities;
        $this->entities = [];
        $this->topicMapping = [];
        $this->rebuildSharedEntityIdentIndexes();
        $filterTopics = [];
        $runningPosition = 0;
        $activeEntityIds = [];
        $inactiveEntityIds = [];
        $activeEntities = [];
        $renamedEntityIds = [];
        $this->hasMultipleStatusEntities = $this->countStatusEntities($configData) > 1;

        // Idents für ALLE Entitäten berechnen (inkl. inaktiver), um historische Idents zu ermitteln
        $allWithIdents = $this->applySharedEntityIdents($this->normalizeConfiguredEntityRows($configData));
        $inactiveEntities = [];
        foreach ($allWithIdents as $entity) {
            $entityId = (string)($entity['entity_id'] ?? '');
            if (!($entity['create_var'] ?? true)) {
                $inactiveEntityIds[] = $entityId;
                $inactiveEntities[$entityId] = $entity;
            } else {
                $activeEntities[] = $entity;
            }
        }

        // Idents für aktive Entitäten neu berechnen (korrekte aktuelle Zuweisung ohne inaktive)
        $activeEntities = $this->applySharedEntityIdents($activeEntities);
        foreach ($activeEntities as $entity) {
            $entityId = (string)($entity['entity_id'] ?? '');
            if ($entityId === '') {
                continue;
            }

            $activeEntityIds[] = $entityId;
            $basePosition            = $runningPosition;
            $runningPosition         += HADomainCatalog::getPositionBlockSize($entity['domain'] ?? '');
            $entity['position_base'] = $basePosition;
            $entity                  = $this->mergePreviousEntityAttributes($entity, $previousEntities, $entityId);
            $this->entities[$entityId] = $entity;
            if ($this->hasSharedManagedIdentChanged($previousEntities[$entityId] ?? null, $entity)) {
                $renamedEntityIds[] = $entityId;
            }
            $this->debugExpert('processEntities', 'Entity registriert', ['EntityID' => $entityId, 'Domain' => $entity['domain'] ?? null]);

            $this->maintainEntityVariable($entity);

            if ($baseTopic !== '') {
                $stateTopic = $this->deriveStateTopic($baseTopic, $entityId);
                $this->topicMapping[$stateTopic] = $entityId;
                $entityPrefix = $this->deriveEntityTopicPrefix($baseTopic, $entityId);
                $filterTopics[]                  = $entityPrefix;
                $this->debugExpert('processEntities', 'Topic Mapping', ['StateTopic' => $stateTopic, 'Prefix' => $entityPrefix]);
            }
        }
        $this->rebuildSharedEntityIdentIndexes();

        $entityIdsToCleanup = $previousEntities
                              |> array_keys(...)
                              |> (static fn($x) => array_diff($x, $activeEntityIds))
                              |> (static fn($x) => array_merge($x, $inactiveEntityIds, $renamedEntityIds))
                              |> array_unique(...)
                              |> array_values(...);
        $this->cleanupManagedEntityObjects($entityIdsToCleanup, $activeEntityIds, array_merge($previousEntities, $inactiveEntities));

        return $filterTopics;
    }

    /**
     * Normalisiert alle Konfigurationszeilen zu gültigen Entitäten (inkl. inaktiver),
     * damit auch für abgewählte Entitäten korrekte Idents für den Cleanup bekannt sind.
     */
    private function normalizeConfiguredEntityRows(array $configData): array
    {
        $normalized = [];
        foreach ($configData as $row) {
            $entity = $this->normalizeEntityStructure($row);
            if ($entity === null) {
                continue;
            }
            if ((string)($entity['entity_id'] ?? '') === '') {
                continue;
            }
            if (($entity['domain'] ?? '') === '') {
                $this->debugExpert('processEntities', 'Entity ohne Domain', $entity);
                continue;
            }
            $normalized[] = $entity;
        }
        return $normalized;
    }

    /**
     * Übernimmt bereits gesammelte Attribute aus dem vorherigen Lauf,
     * damit sie beim Neuaufbau der Entity-Liste nicht verloren gehen.
     */
    private function mergePreviousEntityAttributes(array $entity, array $previousEntities, string $entityId): array
    {
        if (!isset($previousEntities[$entityId]['attributes']) || !is_array($previousEntities[$entityId]['attributes'])) {
            return $entity;
        }

        $existingAttributes = $previousEntities[$entityId]['attributes'];
        if (!isset($entity['attributes']) || !is_array($entity['attributes'])) {
            $entity['attributes'] = $existingAttributes;
        } else {
            $entity['attributes'] = array_merge($existingAttributes, $entity['attributes']);
        }
        return $entity;
    }

    private function cleanupManagedEntityObjects(array $entityIds, array $activeEntityIds, array $previousEntities): void
    {
        $entityIds = array_filter(
                         $entityIds,
                         static fn(mixed $entityId): bool => is_string($entityId) && trim($entityId) !== ''
                     )
                     |> array_unique(...)
                     |> array_values(...);
        if ($entityIds === [] && $activeEntityIds === []) {
            return;
        }

        $baseIdents = [];
        foreach ($entityIds as $entityId) {
            $baseIdents[] = $this->sanitizeIdent($entityId);
            $previousIdent = trim((string)($previousEntities[$entityId]['ident'] ?? ''));
            if ($previousIdent !== '') {
                $baseIdents[] = $previousIdent;
            }
            $previousPrefix = trim((string)($previousEntities[$entityId]['ident_prefix'] ?? ''));
            if ($previousPrefix !== '') {
                $baseIdents[] = $previousPrefix;
            }
        }

        $activeBaseIdents = [];
        foreach ($activeEntityIds as $entityId) {
            $activeBaseIdents[] = $this->getSharedEntityIdentPrefix($entityId);
        }
        foreach ($activeEntityIds as $entityId) {
            $legacyBaseIdent = $this->sanitizeIdent($entityId);
            if ($legacyBaseIdent !== '' && !$this->isManagedEntityIdent($legacyBaseIdent, $activeBaseIdents)) {
                $baseIdents[] = $legacyBaseIdent;
            }
        }
        $baseIdents = array_filter($baseIdents, static fn(string $ident): bool => $ident !== '')
                      |> array_unique(...)
                      |> array_values(...);
        $activeBaseIdents = array_filter($activeBaseIdents, static fn(string $ident): bool => $ident !== '')
                            |> array_unique(...)
                            |> array_values(...);
        $allKnownPrefixes = array_values(array_unique(array_merge($activeBaseIdents, $baseIdents)));
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childId) {
            $object = IPS_GetObject($childId);
            $ident = (string)($object['ObjectIdent'] ?? '');
            if ($ident === ''
                || !$this->isManagedEntityIdent($ident, $baseIdents)
                || $this->isMostSpecificallyManagedByPrefixes($ident, $activeBaseIdents, $allKnownPrefixes)) {
                continue;
            }

            $objectType = (int)($object['ObjectType'] ?? -1);
            if ($objectType === OBJECTTYPE_VARIABLE) {
                if ($this->markVariableAsLegacy($childId)) {
                    $this->debugExpert(__FUNCTION__, 'Variable als veraltet markiert', [
                        'ObjectID' => $childId,
                        'ObjectType' => $objectType,
                        'Ident' => $ident
                    ]);
                }
            } elseif ($objectType === OBJECTTYPE_MEDIA) {
                IPS_DeleteMedia($childId, true);
                $this->debugExpert(__FUNCTION__, 'Medienobjekt entfernt', [
                    'ObjectID' => $childId,
                    'ObjectType' => $objectType,
                    'Ident' => $ident
                ]);
            }
        }

        $cache = $this->readEntityStateCache();
        $changed = false;
        foreach ($entityIds as $entityId) {
            if (!isset($cache[$entityId])) {
                continue;
            }

            unset($cache[$entityId]);
            $changed = true;
        }

        if ($changed) {
            $this->writeEntityStateCache($cache);
        }
    }

    private function isManagedEntityIdent(string $ident, array $baseIdents): bool
    {
        return array_any($baseIdents, static fn($baseIdent) => $ident === $baseIdent || str_starts_with($ident, $baseIdent . '_'));
    }

    /**
     * Prüft via Longest-Prefix-Match, ob $ident am spezifischsten zu einem Prefix
     * aus $targetPrefixes gehört.
     * Verhindert, dass z.B. „sensor_battery_percentage_2" als Sub-Variable von
     * „sensor_battery_percentage" gilt, obwohl es ein eigener Entity-Ident ist.
     */
    private function isMostSpecificallyManagedByPrefixes(string $ident, array $targetPrefixes, array $allPrefixes): bool
    {
        $bestPrefix = null;
        $bestLength = -1;
        foreach ($allPrefixes as $prefix) {
            if ($prefix === '') {
                continue;
            }
            if ($ident === $prefix || str_starts_with($ident, $prefix . '_')) {
                $len = strlen($prefix);
                if ($len > $bestLength) {
                    $bestPrefix = $prefix;
                    $bestLength = $len;
                }
            }
        }
        return $bestPrefix !== null && in_array($bestPrefix, $targetPrefixes, true);
    }
    /**
     * Aktualisiert den Wert einer Variable basierend auf dem MQTT Payload.
     * Hält dabei Hauptvariable und Attributcache synchron.
     */
    private function updateEntityValue(string $entityId, string $payload): void
    {
        $this->debugExpert('MQTT', 'Wert empfangen', ['EntityID' => $entityId, 'Payload' => $payload]);
        $parsed = $this->parseEntityPayload($payload);
        $rawState = (string)($parsed[self::KEY_STATE] ?? '');
        $this->updateEntityRawStateCache($entityId, $rawState);
        $this->updateAvailabilityValue($rawState);
        $this->applyParsedEntityState($entityId, $parsed);
    }

    // REST initialization, statestream state and fallback updates share the same domain logic.
    private function applyParsedEntityState(string $entityId, array $parsed): void
    {
        $domain = $this->getEntityDomain($entityId);
        if ($domain === '') {
            $this->debugRuntimeIssue(__FUNCTION__, 'Domain nicht ermittelbar', ['EntityID' => $entityId]);
            return;
        }

        $ident = $this->getSharedEntityMainIdent($entityId);
        $stepStartedAt = microtime(true);
        $descriptor = $this->describeVariableByIdent($ident, $domain);
        $this->logPerformanceSample('applyState.describeVariableByIdent', $stepStartedAt, ['entity' => $entityId]);
        if ($this->isTriggerVariableDescriptor($descriptor)) {
            $this->applyTriggerEntityStateUpdate($entityId, $parsed);
            return;
        }

        $stepStartedAt = microtime(true);
        $handledByDomain = $this->handleDomainUpdateEntityValue($domain, $entityId, $ident, $parsed);
        $this->logPerformanceSample('applyState.handleDomainUpdateEntityValue', $stepStartedAt, [
            'entity' => $entityId,
            'handled' => $handledByDomain
        ]);
        if ($handledByDomain) {
            return;
        }

        $stepStartedAt = microtime(true);
        $attributes = $this->resolveEntityStateAttributes($entityId, $parsed[self::KEY_ATTRIBUTES] ?? null);
        $finalValue = $this->convertValueByDomain($domain, (string)($parsed[self::KEY_STATE] ?? ''), $attributes);
        $this->setEntityMainValue($entityId, $ident, $finalValue, $parsed[self::KEY_STATE] ?? null);
        $this->updateEntityCache($entityId, $parsed[self::KEY_STATE] ?? null, $parsed[self::KEY_ATTRIBUTES] ?? null);
        $this->logPerformanceSample('applyState.setMainValueAndCache', $stepStartedAt, ['entity' => $entityId]);

        if (!empty($parsed[self::KEY_ATTRIBUTES]) && is_array($parsed[self::KEY_ATTRIBUTES])) {
            $stepStartedAt = microtime(true);
            $storedAttributes = $this->storeEntityAttributes($entityId, $parsed[self::KEY_ATTRIBUTES]);
            $this->updateEntityPresentation($entityId, $this->entities[$entityId][self::KEY_ATTRIBUTES] ?? []);
            if ($domain === HALightDefinitions::DOMAIN) {
                $this->updateLightAttributeValues($entityId, $storedAttributes);
            }
            $this->logPerformanceSample('applyState.attributesAndPresentation', $stepStartedAt, [
                'entity' => $entityId,
                'attrCount' => count($parsed[self::KEY_ATTRIBUTES])
            ]);
        }
    }

    // Trigger domains keep metadata but do not persist a main state value.
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

    // --- Technische Helper ---

    private function isWriteable(string $domain): bool
    {
        return HADomainCatalog::isMainWritable($this->normalizeDomainAlias($domain));
    }
    private function applyInitialStatesFromMap(array $configData, array $stateMap): void
    {
        foreach ($configData as $row) {
            $entity = $this->normalizeEntityStructure($row);
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

    private function applyEntityActiveChange(string $configJson): void
    {
        $decoded = $this->decodeJsonArray($configJson, __FUNCTION__);
        if ($decoded === null) {
            return;
        }

        // onEdit liefert die einzelne bearbeitete Zeile (assoziatives Array mit entity_id),
        // nicht die komplette Liste.
        if (array_key_exists('entity_id', $decoded)) {
            $entityId = trim((string)($decoded['entity_id'] ?? ''));
            if ($entityId === '') {
                return;
            }
            $createVarMap = [$entityId => $decoded['create_var'] ?? true];
        } else {
            $createVarMap = [];
            foreach ($decoded as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $entityId = trim((string)($row['entity_id'] ?? ''));
                if ($entityId !== '') {
                    $createVarMap[$entityId] = $row['create_var'] ?? true;
                }
            }
        }

        if ($createVarMap === []) {
            return;
        }

        $updated = $this->mergeCreateVarSettings($this->readResolvedConfig(__FUNCTION__), $createVarMap);
        $this->writeResolvedConfig(
            json_encode($updated, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        if ($this->determineParentRuntimeState([HAIds::MODULE_SPLITTER]) === 'active') {
            $this->UpdateConfiguration();
        }
    }

    private function buildExistingCreateVarMap(): array
    {
        $map = [];
        foreach ($this->readResolvedConfig(__FUNCTION__) as $row) {
            $entityId = trim((string)($row['entity_id'] ?? ''));
            if ($entityId !== '') {
                $map[$entityId] = $row['create_var'] ?? true;
            }
        }
        return $map;
    }

    private function mergeCreateVarSettings(array $configData, array $createVarMap): array
    {
        if ($createVarMap === []) {
            return $configData;
        }
        foreach ($configData as &$entity) {
            $entityId = trim((string)($entity['entity_id'] ?? ''));
            if ($entityId !== '' && array_key_exists($entityId, $createVarMap)) {
                $entity['create_var'] = $createVarMap[$entityId];
            }
        }
        unset($entity);
        return $configData;
    }

}
