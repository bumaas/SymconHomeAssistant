<?php /** @noinspection PhpUnused */

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
require_once __DIR__ . '/../libs/Device/HADeviceConstants.php';
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
 */
class HomeAssistantEntity extends IPSModuleStrict implements HADeviceConstants
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
    use HADeviceEntityNormalizationTrait;
    use HAEntityStoreTrait;
    use HAVariableMappingTrait;
    use HAAttributeActionMappingTrait;
    use HASupportedFeaturesTrait;
    use HADiagnosticsTrait;
    use HARestParentClientTrait;
    use HAEntityConfigLoaderTrait;
    use HAEntityConfigBuilderTrait;
    use HADeviceCoreTrait;

    private const string PROP_ENTITY_ID = 'EntityID';
    private const string ATTR_RESOLVED_CONFIG = 'ResolvedConfig';
    private const int STATUS_ENTITY_NOT_FOUND = 210;
    private const int STATUS_ENTITY_INVALID = 211;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);

        $this->RegisterPropertyString(self::PROP_ENTITY_ID, '');
        $this->RegisterPropertyString(self::PROP_DEVICE_ID, '');
        $this->RegisterPropertyString(self::PROP_DEVICE_AREA, '');
        $this->RegisterPropertyString(self::PROP_DEVICE_NAME, '');
        $this->RegisterPropertyBoolean(self::PROP_ENABLE_EXPERT_DEBUG, false);
        $this->RegisterPropertyBoolean(self::PROP_SHOW_UNAVAILABLE_ENTITIES_JSON, false);
        $this->RegisterPropertyInteger(self::PROP_OUTPUT_BUFFER_SIZE, 10);

        $this->RegisterAttributeString(self::ATTR_RESOLVED_CONFIG, '[]');
        $this->RegisterAttributeString('MQTTBaseTopic', '');
        $this->RegisterAttributeString('CurrentFilter', '');
        $this->RegisterAttributeString('LastMQTTMessage', '');
        $this->RegisterAttributeString('LastRESTFetch', '');
        $this->RegisterAttributeString('EntityStateCache', '{}');

        $this->RegisterTimer(self::TIMER_MEDIA_PLAYER_PROGRESS, 0, 'HAE_UpdateMediaPlayerProgress($_IPS["TARGET"]);');
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === FM_CONNECT || $Message === FM_DISCONNECT) {
            $this->debugExpert(__FUNCTION__, 'Verbindungsstatus ge�ndert. Aktualisiere...');
            $this->ApplyChanges();
        }
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetTimerInterval(self::TIMER_MEDIA_PLAYER_PROGRESS, 0);
        $this->maintainUnavailableEntitiesJsonVariable();
        $this->updateUnavailableEntitiesJsonVariable();

        if (!$this->hasCompatibleSplitterParent()) {
            $this->SetStatus(201);
            $this->debugExpert(__FUNCTION__, 'Parent ist nicht Home Assistant Splitter');
            return;
        }

        $this->UpdateConfiguration();
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->applyResolvedConfigToForm($form);

        return json_encode($form, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @noinspection PhpUnused */
    public function UpdateConfiguration(): void
    {
        $entityId = trim($this->ReadPropertyString(self::PROP_ENTITY_ID));
        if ($entityId === '') {
            $this->WriteAttributeString(self::ATTR_RESOLVED_CONFIG, '[]');
            $this->SetSummary('');
            $this->SetStatus(IS_INACTIVE);
            return;
        }

        $raw = $this->resolveRawEntityByEntityId($entityId);
        if ($raw === null) {
            $this->WriteAttributeString(self::ATTR_RESOLVED_CONFIG, '[]');
            $this->SetSummary($entityId);
            $this->SetStatus(self::STATUS_ENTITY_NOT_FOUND);
            $this->debugExpert(__FUNCTION__, 'Entity nicht in Home Assistant gefunden', ['EntityID' => $entityId]);
            return;
        }

        $resolved = $this->buildResolvedEntityRow($raw, true);
        if ($resolved === null) {
            $this->WriteAttributeString(self::ATTR_RESOLVED_CONFIG, '[]');
            $this->SetSummary($entityId);
            $this->SetStatus(self::STATUS_ENTITY_INVALID);
            $this->debugExpert(__FUNCTION__, 'Entity konnte nicht aufgel�st werden', ['EntityID' => $entityId]);
            return;
        }

        $resolvedConfig = [$resolved];
        $this->WriteAttributeString(
            self::ATTR_RESOLVED_CONFIG,
            json_encode($resolvedConfig, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $summary = trim((string)($resolved['name'] ?? ''));
        if ($summary === '') {
            $summary = $entityId;
        }
        $this->SetSummary($summary);

        $baseTopic = $this->determineBaseTopic();
        $this->WriteAttributeString('MQTTBaseTopic', $baseTopic);

        $stateMap = $this->fetchStateMap($resolvedConfig);
        if ($stateMap !== []) {
            $resolvedConfig = $this->mergeStateAttributes($resolvedConfig, $stateMap);
            $this->WriteAttributeString(
                self::ATTR_RESOLVED_CONFIG,
                json_encode($resolvedConfig, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        $filterTopics = $this->processEntities($resolvedConfig, $baseTopic);
        $this->maintainUnavailableEntitiesJsonVariable();
        $this->updateUnavailableEntitiesJsonVariable();
        $this->updateDiagnosticsLabels();
        $this->updateReceiveFilter($filterTopics);

        if ($stateMap === []) {
            $this->initializeStatesFromHa($resolvedConfig);
        } else {
            $this->applyInitialStatesFromMap($resolvedConfig, $stateMap);
        }

        if ($baseTopic !== '' && $this->hasMediaPlayerEntities()) {
            $this->SetTimerInterval(self::TIMER_MEDIA_PLAYER_PROGRESS, 1000);
        }

        $this->refreshResolvedFormFields();
        $this->SetStatus($baseTopic === '' ? 202 : IS_ACTIVE);
    }

    private function getConfiguredEntities(string $context): array
    {
        $configData = $this->decodeJsonArray($this->ReadAttributeString(self::ATTR_RESOLVED_CONFIG), $context);
        if ($configData === null) {
            return [];
        }

        $configuredEntities = [];
        foreach ($configData as $row) {
            $row = $this->normalizeEntity($row, $context);
            if ($row === null || (($row['create_var'] ?? true) === false)) {
                continue;
            }

            $configuredEntities[] = $row;
        }

        return $configuredEntities;
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
            if (!isset($stateMap[$entityId]) || !is_array($stateMap[$entityId])) {
                continue;
            }

            $attrs = $stateMap[$entityId][self::KEY_ATTRIBUTES] ?? [];
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

    private function applyInitialState(string $entityId, array $state): void
    {
        $rawState = (string)($state[self::KEY_STATE] ?? '');
        $this->updateEntityRawStateCache($entityId, $rawState);
        $this->updateAvailabilityValue($entityId, $rawState);

        $parsed = [
            self::KEY_STATE => $rawState
        ];

        $attributes = $state[self::KEY_ATTRIBUTES] ?? null;
        if (is_array($attributes)) {
            $parsed[self::KEY_ATTRIBUTES] = $attributes;
        }

        $this->applyParsedEntityState($entityId, $parsed, 'Initialisierung (HA REST)');
    }

    private function sanitizeIdent(string $id): string
    {
        return str_replace(['.', ' ', '-'], '_', $id);
    }

    private function isWriteable(string $domain): bool
    {
        return HADomainCatalog::isMainWritable(HADomainCatalog::normalizeDomainAlias($domain));
    }

    private function updateFormFieldSafe(string $name, string $property, mixed $value): void
    {
        @$this->UpdateFormField($name, $property, $value);
    }

    private function refreshResolvedFormFields(): void
    {
        $resolved = $this->getResolvedEntity();
        $attributes = $this->getResolvedAttributesForDisplay($resolved);

        $this->updateFormFieldSafe('ResolvedName', 'value', (string)($resolved['name'] ?? ''));
        $this->updateFormFieldSafe('ResolvedDomain', 'value', (string)($resolved['domain'] ?? ''));
        $this->updateFormFieldSafe('ResolvedDeviceClass', 'value', $this->getResolvedDeviceClass($resolved));
        $this->updateFormFieldSafe('ResolvedDeviceID', 'value', (string)($resolved['device_id'] ?? ''));
        $this->updateFormFieldSafe('ResolvedArea', 'value', (string)($resolved['area'] ?? ''));
        $this->updateFormFieldSafe('ResolvedAttributeCount', 'value', (string)count($attributes));
        $this->updateFormFieldSafe(
            'ResolvedAttributes',
            'values',
            json_encode($this->formatResolvedAttributesForForm($attributes), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    private function applyResolvedConfigToForm(array &$form): void
    {
        $resolved = $this->getResolvedEntity();
        $attributes = $this->getResolvedAttributesForDisplay($resolved);

        foreach ($form['elements'] as &$element) {
            if (($element['name'] ?? '') === 'ResolvedName') {
                $element['value'] = (string)($resolved['name'] ?? '');
                continue;
            }
            if (($element['name'] ?? '') === 'ResolvedDomain') {
                $element['value'] = (string)($resolved['domain'] ?? '');
                continue;
            }
            if (($element['name'] ?? '') === 'ResolvedDeviceClass') {
                $element['value'] = $this->getResolvedDeviceClass($resolved);
                continue;
            }
            if (($element['name'] ?? '') === 'ResolvedDeviceID') {
                $element['value'] = (string)($resolved['device_id'] ?? '');
                continue;
            }
            if (($element['name'] ?? '') === 'ResolvedArea') {
                $element['value'] = (string)($resolved['area'] ?? '');
                continue;
            }
            if (($element['name'] ?? '') === 'ResolvedAttributeCount') {
                $element['value'] = (string)count($attributes);
                continue;
            }

            if (!isset($element['items']) || !is_array($element['items'])) {
                continue;
            }

            foreach ($element['items'] as &$item) {
                if (($item['name'] ?? '') === 'ResolvedName') {
                    $item['value'] = (string)($resolved['name'] ?? '');
                    continue;
                }
                if (($item['name'] ?? '') === 'ResolvedDomain') {
                    $item['value'] = (string)($resolved['domain'] ?? '');
                    continue;
                }
                if (($item['name'] ?? '') === 'ResolvedDeviceClass') {
                    $item['value'] = $this->getResolvedDeviceClass($resolved);
                    continue;
                }
                if (($item['name'] ?? '') === 'ResolvedDeviceID') {
                    $item['value'] = (string)($resolved['device_id'] ?? '');
                    continue;
                }
                if (($item['name'] ?? '') === 'ResolvedArea') {
                    $item['value'] = (string)($resolved['area'] ?? '');
                    continue;
                }
                if (($item['name'] ?? '') === 'ResolvedAttributeCount') {
                    $item['value'] = (string)count($attributes);
                    continue;
                }
                if (($item['name'] ?? '') === 'ResolvedAttributes') {
                    $item['values'] = $this->formatResolvedAttributesForForm($attributes);
                }
            }
            unset($item);
        }
        unset($element);
    }

    private function getResolvedEntity(): array
    {
        $config = $this->decodeJsonArray($this->ReadAttributeString(self::ATTR_RESOLVED_CONFIG), __FUNCTION__);
        if (!is_array($config) || $config === []) {
            return [];
        }

        $entity = $config[0] ?? null;
        return is_array($entity) ? $entity : [];
    }

    private function getResolvedDeviceClass(array $resolved): string
    {
        $deviceClass = $resolved['device_class'] ?? '';
        if (is_string($deviceClass) && trim($deviceClass) !== '') {
            return trim($deviceClass);
        }

        $attributes = $this->getResolvedAttributesForDisplay($resolved);
        $attributeDeviceClass = $attributes['device_class'] ?? '';
        return is_string($attributeDeviceClass) ? trim($attributeDeviceClass) : '';
    }

    private function getResolvedAttributesForDisplay(array $resolved): array
    {
        $attributes = $resolved['attributes'] ?? [];
        return is_array($attributes) ? $attributes : [];
    }

    private function formatResolvedAttributesForForm(array $attributes): array
    {
        ksort($attributes);
        $values = [];
        foreach ($attributes as $key => $value) {
            $values[] = [
                'Key' => (string)$key,
                'Value' => $this->stringifyResolvedAttributeValue($value)
            ];
        }

        return $values;
    }

    private function stringifyResolvedAttributeValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return (string)$value;
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}


