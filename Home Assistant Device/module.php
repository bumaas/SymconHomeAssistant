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
    private const string ATTR_RESOLVED_CONFIG = 'ResolvedConfig';

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
    use HARestParentClientTrait;
    use HAEntityConfigLoaderTrait;
    use HAEntityConfigBuilderTrait {
        buildResolvedEntityConfig as private buildResolvedEntities;
    }
    use HADeviceCoreTrait;

    public function Create(): void
    {
        $this->LogMessage('Create | start', KL_MESSAGE);
        parent::Create();
        $this->LogMessage('Create | after_parent', KL_MESSAGE);

        // Nachrichten registrieren, um auf Gateway-Änderungen zu reagieren.
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);
        $this->LogMessage('Create | after_RegisterMessage', KL_MESSAGE);

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
        $this->RegisterPropertyBoolean(self::PROP_SHOW_UNAVAILABLE_ENTITIES_JSON, false);
        $this->RegisterPropertyInteger(self::PROP_OUTPUT_BUFFER_SIZE, 10);
        $this->LogMessage('Create | after_RegisterProperties', KL_MESSAGE);

        $this->RegisterTimer(self::TIMER_MEDIA_PLAYER_PROGRESS, 0, 'HA_UpdateMediaPlayerProgress($_IPS["TARGET"]);');
        $this->LogMessage('Create | after_RegisterTimer', KL_MESSAGE);
    }


    /**
     * Reagiert auf Änderungen am Parent (Gateway).
     */
    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if (($Message === IPS_KERNELMESSAGE) && (($Data[0] ?? null) === KR_READY)) {
            $this->debugExpert('MessageSink', 'Kernel bereit. Aktualisiere...');
            $this->ApplyChanges();
            return;
        }

        if (!$this->isModuleRuntimeReady()) {
            return;
        }

        // Wenn sich die Verbindung ändert, ist die Konfiguration neu zu laden.
        if ($Message === FM_CONNECT || $Message === FM_DISCONNECT || $Message === IM_CHANGESTATUS) {
            $this->debugExpert('MessageSink', 'Verbindungsstatus geändert. Aktualisiere...');
            $this->ApplyChanges();
        }
    }

    public function ApplyChanges(): void
    {
        $this->LogMessage('ApplyChanges | entry_before_parent', KL_MESSAGE);
        parent::ApplyChanges();
        $this->LogMessage('ApplyChanges | entry_after_parent', KL_MESSAGE);
        $this->ensureResolvedConfigAttributeRegistered(__FUNCTION__);
        $this->syncParentStatusMessageRegistration();
        if (!$this->isKernelReady()) {
            $this->debugExpert('ApplyChanges', 'Kernel noch nicht bereit. Initialisierung wird bis KR_READY verschoben.');
            return;
        }
        $this->SetTimerInterval(self::TIMER_MEDIA_PLAYER_PROGRESS, 0);
        $this->maintainUnavailableEntitiesJsonVariable();
        $this->updateUnavailableEntitiesJsonVariable();

        $parentState = $this->determineParentRuntimeState([HAIds::MODULE_SPLITTER]);
        if ($parentState !== 'active') {
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
        $deviceId = trim($this->ReadPropertyString(self::PROP_DEVICE_ID));
        if ($deviceId === '') {
            $this->WriteAttributeString(self::ATTR_RESOLVED_CONFIG, '[]');
            $this->resetResolvedDeviceRuntime();
            $this->SetSummary('');
            $this->SetStatus(self::STATUS_DEVICE_ID_MISSING);
            $this->updateDiagnosticsLabels();
            $this->refreshResolvedFormFields();
            return;
        }

        $configData = $this->resolveDeviceConfigByDeviceId($deviceId);
        if ($configData === []) {
            $this->WriteAttributeString(self::ATTR_RESOLVED_CONFIG, '[]');
            $this->resetResolvedDeviceRuntime();
            $this->SetSummary($deviceId);
            $this->SetStatus(self::STATUS_DEVICE_NOT_FOUND);
            $this->updateDiagnosticsLabels();
            $this->refreshResolvedFormFields();
            $this->debugExpert(__FUNCTION__, 'Gerät nicht in Home Assistant gefunden', ['DeviceID' => $deviceId], true);
            return;
        }

        $this->WriteAttributeString(
            self::ATTR_RESOLVED_CONFIG,
            json_encode($configData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        // 1. MQTT-Basetopic ermitteln.
        $baseTopic = $this->determineBaseTopic();
        $this->debugExpert('ApplyChanges', 'Konfiguration geladen', ['BaseTopic' => $baseTopic]);

        $stateMap = $this->fetchStateMap($configData);
        if ($stateMap !== []) {
            $configData = $this->mergeStateAttributes($configData, $stateMap);
            $this->WriteAttributeString(
                self::ATTR_RESOLVED_CONFIG,
                json_encode($configData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        if ($baseTopic === '') {
            $this->SetStatus(self::STATUS_MQTT_BASE_TOPIC_MISSING);
            $this->debugExpert('ApplyChanges', 'MQTTBaseTopic ist leer. MQTT Statestream Updates kommen dann nicht an.');
        } else {
            $this->SetStatus(IS_ACTIVE);
        }
        $this->SetSummary($this->getResolvedDeviceName($configData) ?: $deviceId);

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
        $this->WriteAttributeString('LastMQTTMessage', date('Y-m-d H:i:s'));
        $this->updateDiagnosticsLabels();

        if ($this->tryHandleStateFromTopic($topic, $payload)) {
            return '';
        }

        if (isset($this->topicMapping[$topic])) {
            $entityId = $this->topicMapping[$topic];
            $this->updateEntityValue($entityId, $payload);
        } else {
            $this->debugRuntimeIssue(__FUNCTION__, 'MQTT-Topic ohne Mapping verworfen', ['Topic' => $topic]);
        }

        return '';
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

        if ($this->handleLockAction($Ident, $Value)) {
            return;
        }
        if ($this->handleCoverAction($Ident, $Value)) {
            return;
        }
        if ($this->handleCoverTiltAction($Ident, $Value)) {
            return;
        }
        if ($this->handleValveAction($Ident, $Value)) {
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

            if ($this->trySendMainEntityValueViaRest($entityId, (string)($domain ?? ''), $mqttPayload, $Ident, $entity['attributes'] ?? [])) {
                return;
            }

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
        $config = $this->readResolvedConfig(__FUNCTION__);
        $this->debugExpert(__FUNCTION__, 'config:', $config);

        $values = [];
        $domainOptions = HADomainCatalog::getDomainSelectOptions();
        $resolvedName = $this->getResolvedDeviceName($config);
        $resolvedArea = $this->getResolvedDeviceArea($config);

        if (is_array($config)) {
            foreach ($config as $row) {
                $row = $this->normalizeEntityStructure($row);
                if ($row === null) {
                    continue;
                }

                // Filter out entities with unsupported domains to satisfy the requirement
                // that they should not appear in the Device instance configuration.
                if (!HADomainCatalog::isDomainSupported($row['domain'] ?? '')) {
                    $this->debugExpert(__FUNCTION__, 'Filtering out unsupported domain: ' . ($row['domain'] ?? 'unknown') . ' for entity: ' . ($row['entity_id'] ?? 'unknown'));
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
            if (isset($element['items']) && is_array($element['items'])) {
                foreach ($element['items'] as &$item) {
                    if (($item['name'] ?? '') === self::PROP_DEVICE_NAME) {
                        $item['caption'] = sprintf(
                            $this->Translate('Device name (HA): %s'),
                            $resolvedName
                        );
                        continue;
                    }
                    if (($item['name'] ?? '') === self::PROP_DEVICE_AREA) {
                        $item['caption'] = sprintf(
                            $this->Translate('Area: %s'),
                            $resolvedArea
                        );
                        continue;
                    }
                    if (($item['name'] ?? '') === 'ResolvedConfig') {
                        $item['values'] = $values;
                        foreach ($item['columns'] as &$column) {
                            if (($column['name'] ?? '') === 'domain') {
                                $column['edit']['options'] = $domainOptions;
                                break;
                            }
                        }
                        unset($column);
                    }
                }
                unset($item);
            }
            if (($element['name'] ?? '') === 'ResolvedConfig') {
                $element['values'] = $values;
                // Update domain options dynamically from catalog
                foreach ($element['columns'] as &$column) {
                    if (($column['name'] ?? '') === 'domain') {
                        $column['edit']['options'] = $domainOptions;
                        break;
                    }
                }
                break;
            }
        }
        unset($element);

        foreach ($form['actions'] as &$action) {
            if (($action['name'] ?? '') === 'CURRENT_FILTER') {
                $action['caption'] = sprintf(
                    $this->Translate('Current filter (regex): %s'),
                    $this->ReadAttributeString('CurrentFilter')
                );
            }
        }
        unset($action);
        $this->applyCurrentDiagnosticsToForm($form, $values);
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

    private function getConfiguredEntities(string $context): array
    {
        $configData = $this->readResolvedConfig($context);

        $configuredEntities = [];
        foreach ($configData as $row) {
            $row = $this->normalizeActiveConfiguredEntity($row);
            if ($row === null) {
                continue;
            }

            $configuredEntities[] = $row;
        }

        return $this->applySharedEntityIdents($configuredEntities);
    }

    private function resolveDeviceConfigByDeviceId(string $deviceId): array
    {
        $rawEntities = $this->fetchEntitiesByDeviceId($deviceId);
        if (!is_array($rawEntities) || $rawEntities === []) {
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

    private function readResolvedConfig(string $context): array
    {
        $this->ensureResolvedConfigAttributeRegistered($context);
        $configData = $this->decodeJsonArray($this->ReadAttributeString(self::ATTR_RESOLVED_CONFIG), $context);
        return $configData ?? [];
    }

    private function ensureResolvedConfigAttributeRegistered(string $context): void
    {
        if (@$this->ReadAttributeString(self::ATTR_RESOLVED_CONFIG) !== false) {
            return;
        }

        $this->RegisterAttributeString(self::ATTR_RESOLVED_CONFIG, '[]');
        $this->WriteAttributeString(self::ATTR_RESOLVED_CONFIG, '[]');
        $this->debugExpert($context, 'ResolvedConfig in Bestandsinstanz initialisiert');
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

    private function getResolvedDeviceId(?array $configData = null): string
    {
        $configData ??= $this->readResolvedConfig(__FUNCTION__);
        $first = $configData[0] ?? null;
        if (is_array($first)) {
            $deviceId = trim((string)($first['device_id'] ?? ''));
            if ($deviceId !== '') {
                return $deviceId;
            }
        }

        return trim($this->ReadPropertyString(self::PROP_DEVICE_ID));
    }

    private function refreshResolvedFormFields(): void
    {
        $configData = $this->readResolvedConfig(__FUNCTION__);
        $resolvedName = $this->getResolvedDeviceName($configData);
        $resolvedArea = $this->getResolvedDeviceArea($configData);
        $this->updateFormFieldSafe('DeviceName', 'caption', sprintf($this->Translate('Device name (HA): %s'), $resolvedName));
        $this->updateFormFieldSafe('DeviceArea', 'caption', sprintf($this->Translate('Area: %s'), $resolvedArea));
        $this->updateFormFieldSafe('ResolvedConfig', 'values', json_encode($this->buildResolvedConfigFormValues($configData), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
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
                ? json_encode($attributes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : '{}';
            $values[] = $row;
        }

        return $values;
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
        $previousEntities = $this->entities;
        $this->entities = [];
        $this->topicMapping = [];
        $this->rebuildSharedEntityIdentIndexes();
        $filterTopics = [];
        $positionIndex = 0;
        $activeEntityIds = [];
        $inactiveEntityIds = [];
        $activeEntities = [];
        $renamedEntityIds = [];
        $this->hasMultipleStatusEntities = $this->countStatusEntities($configData) > 1;

        foreach ($configData as $row) {
            $entity = $this->normalizeEntityStructure($row);
            if ($entity === null) {
                continue;
            }
            $entityId = (string)($entity['entity_id'] ?? '');
            if ($entityId === '') {
                continue;
            }
            if (!($entity['create_var'] ?? true)) {
                $inactiveEntityIds[] = $entityId;
                continue;
            }
            if (($entity['domain'] ?? '') === '') {
                $this->debugExpert('processEntities', 'Entity ohne Domain', $entity);
                continue;
            }

            $activeEntities[] = $entity;
        }

        $activeEntities = $this->applySharedEntityIdents($activeEntities);
        foreach ($activeEntities as $entity) {
            $entityId = (string)($entity['entity_id'] ?? '');
            if ($entityId === '') {
                continue;
            }

            $activeEntityIds[] = $entityId;
            $positionIndex++;
            $basePosition                                = $positionIndex * 10;
            $entity['position_base']                     = $basePosition;
            if (isset($previousEntities[$entityId]['attributes'])
                && is_array($previousEntities[$entityId]['attributes'])) {
                $existingAttributes = $previousEntities[$entityId]['attributes'];
                if (!isset($entity['attributes']) || !is_array($entity['attributes'])) {
                    $entity['attributes'] = $existingAttributes;
                } else {
                    $entity['attributes'] = array_merge($existingAttributes, $entity['attributes']);
                }
            }
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
        $this->cleanupManagedEntityObjects($entityIdsToCleanup, $activeEntityIds, $previousEntities);

        return $filterTopics;
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
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childId) {
            $object = IPS_GetObject($childId);
            $ident = (string)($object['ObjectIdent'] ?? '');
            if ($ident === ''
                || !$this->isManagedEntityIdent($ident, $baseIdents)
                || $this->isManagedEntityIdent($ident, $activeBaseIdents)) {
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
            } elseif ($objectType === 5) {
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
     * Aktualisiert den Wert einer Variable basierend auf dem MQTT Payload.
     * Hält dabei Hauptvariable und Attributcache synchron.
     */
    private function updateEntityValue(string $entityId, string $payload): void
    {
        $this->debugExpert(__FUNCTION__, 'Wert wird gesetzt', ['EntityID' => $entityId, 'Payload' => $payload]);
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
            $this->debugRuntimeIssue(__FUNCTION__, 'Domain nicht ermittelbar', ['EntityID' => $entityId]);
            return;
        }

        $ident = $this->getSharedEntityMainIdent($entityId);
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
                    $this->debugRuntimeIssue('ReceiveData', 'Invalid JSON payload', ['Error' => $e->getMessage()]);
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

    private function decodeJsonArray(string $json, string $context): ?array
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            if ($context === 'ReceiveData') {
                $this->debugRuntimeIssue($context, 'Invalid JSON', ['Error' => $e->getMessage()]);
            } else {
                $this->debugExpert($context, 'Invalid JSON', ['Error' => $e->getMessage()]);
            }
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
    private function initializeStatesFromHa(array $configData): void
    {
        // Ohne aktiven Parent ist keine REST-Abfrage möglich.
        if (!$this->hasActiveParent()) {
            return;
        }

        foreach ($configData as $row) {
            // Konfigurationseintrag normalisieren und validieren.
            $entity = $this->normalizeEntityStructure($row);
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
            $entity = $this->normalizeEntityStructure($row);
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
            $entity = $this->normalizeEntityStructure($row);
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
    private function updateFormFieldSafe(string $name, string $property, mixed $value): void
    {
        @ $this->UpdateFormField($name, $property, $value);
    }

}
