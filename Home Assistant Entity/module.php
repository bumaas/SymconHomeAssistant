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
    use HAEntityConfigBuilderTrait;
    use HADeviceCoreTrait;

    private const string PROP_ENTITY_ID = 'EntityID';
    private const string ATTR_RESOLVED_CONFIG = 'ResolvedConfig';
    private const int STATUS_PARENT_UNAVAILABLE = 201;
    private const int STATUS_MQTT_BASE_TOPIC_MISSING = 202;
    private const int STATUS_ENTITY_NOT_FOUND = 210;
    private const int STATUS_ENTITY_INVALID = 211;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);
        $this->registerParentStatusTracking();

        $this->RegisterPropertyString(self::PROP_ENTITY_ID, '');
        $this->RegisterPropertyString(self::PROP_DEVICE_ID, '');
        $this->RegisterPropertyString(self::PROP_DEVICE_AREA, '');
        $this->RegisterPropertyString(self::PROP_DEVICE_NAME, '');
        $this->RegisterPropertyBoolean(self::PROP_ENABLE_EXPERT_DEBUG, false);
        $this->RegisterPropertyBoolean(self::PROP_SHOW_UNAVAILABLE_ENTITIES_JSON, false);
        $this->RegisterPropertyBoolean(self::PROP_EMULATE_STATUS, false);
        $this->RegisterPropertyInteger(self::PROP_OUTPUT_BUFFER_SIZE, 10);

        $this->RegisterAttributeString(self::ATTR_RESOLVED_CONFIG, '[]');
        $this->RegisterAttributeString('MQTTBaseTopic', '');
        $this->RegisterAttributeString('CurrentFilter', '');
        $this->RegisterAttributeString('LastMQTTMessage', '');
        $this->RegisterAttributeString('LastRESTFetch', '');
        $this->RegisterAttributeString('EntityStateCache', '{}');

        $this->RegisterTimer(self::TIMER_MEDIA_PLAYER_PROGRESS, 0, 'HAE_UpdateMediaPlayerProgress($_IPS["TARGET"]);');
        $this->registerDeferredApplyTimer();
        $this->registerMediaRefreshTimer();
        $this->registerStateCacheFlushTimer();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        // ApplyChanges nicht direkt im Nachrichten-Kontext ausführen (Insight-Schleifenschutz,
        // siehe HomeAssistantDevice::MessageSink).
        if (($Message === IPS_KERNELMESSAGE) && (($Data[0] ?? null) === KR_READY)) {
            $this->debugExpert(__FUNCTION__, 'Kernel bereit. Aktualisierung geplant...');
            $this->scheduleDeferredApply();
            return;
        }

        // Statuswechsel des Parents werden VOR dem Runtime-Gate ausgewertet: Sie treffen
        // während des Bootlaufs ein, und dann darf die Meldung nicht verlorengehen
        // (Muster des HomeConnect-Moduls). Die Entprellung fängt flatternde Parents ab.
        if ($Message === IM_CHANGESTATUS) {
            if (!$this->isNewParentStatus((int) ($Data[0] ?? 0))) {
                return;
            }
            $this->scheduleDeferredApply();
            return;
        }

        if (!$this->isModuleRuntimeReady()) {
            return;
        }

        if ($Message === FM_CONNECT || $Message === FM_DISCONNECT || $Message === IM_CHANGESTATUS) {
            $this->debugExpert(__FUNCTION__, 'Verbindungsstatus geändert. Aktualisierung geplant...');
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
            $this->debugExpert(__FUNCTION__, 'Kernel noch nicht bereit. Initialisierung wird bis KR_READY verschoben.');
            return;
        }

        $this->SetTimerInterval(self::TIMER_MEDIA_PLAYER_PROGRESS, 0);
        $this->resetPendingMediaRefresh();
        $this->flushEntityStateCache();
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
            $this->debugExpert(__FUNCTION__, $message, $this->getCurrentParentDebugContext(), true);
            return;
        }

        $this->UpdateConfiguration();
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->applyResolvedConfigToForm($form);
        $this->applyCurrentDiagnosticsToForm($form);

        return json_encode($form, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @noinspection PhpUnused */
    public function UpdateConfiguration(): void
    {
        $entityId = trim($this->ReadPropertyString(self::PROP_ENTITY_ID));
        if ($entityId === '') {
            $this->failResolvedEntity(IS_INACTIVE, '');
            return;
        }

        $raw = $this->resolveRawEntityByEntityId($entityId);
        if ($raw === null) {
            // Abfrage fehlgeschlagen (Parent/REST nicht verfügbar): Befund unbekannt,
            // bestehende Konfiguration und Status nicht verwerfen.
            $this->debugExpert(__FUNCTION__, 'Entity-Abfrage fehlgeschlagen. Bestehende Konfiguration bleibt erhalten.', ['EntityID' => $entityId], true);
            return;
        }
        if ($raw === false) {
            $this->failResolvedEntity(self::STATUS_ENTITY_NOT_FOUND, $entityId, 'Entity nicht in Home Assistant gefunden');
            return;
        }

        $resolved = $this->buildResolvedEntityRow($raw, true, true);
        if ($resolved === null) {
            $this->failResolvedEntity(self::STATUS_ENTITY_INVALID, $entityId, 'Entity konnte nicht aufgelöst werden');
            return;
        }

        $resolvedConfig = [$resolved];
        $this->writeResolvedConfig(
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
            $this->writeResolvedConfig(
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
        $this->SetStatus($baseTopic === '' ? self::STATUS_MQTT_BASE_TOPIC_MISSING : IS_ACTIVE);
    }

    /**
     * Gemeinsamer Fehlerpfad von UpdateConfiguration: Konfiguration verwerfen,
     * Zusammenfassung und Status setzen, optional Debug-Meldung ausgeben.
     */
    private function failResolvedEntity(int $status, string $summary, string $debugMessage = ''): void
    {
        $this->writeResolvedConfig('[]');
        $this->SetSummary($summary);
        $this->SetStatus($status);
        if ($debugMessage !== '') {
            $this->debugExpert('UpdateConfiguration', $debugMessage, ['EntityID' => $summary]);
        }
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
            $row = $this->normalizeEntityStructure($row);
            if ($row === null || (($row['create_var'] ?? true) === false)) {
                continue;
            }

            $configuredEntities[] = $row;
        }

        return $this->applySharedEntityIdents($configuredEntities);
    }

    private function isWriteable(string $domain): bool
    {
        return HADomainCatalog::isMainWritable(HADomainCatalog::normalizeDomainAlias($domain));
    }

    private function refreshResolvedFormFields(): void
    {
        $resolved   = $this->getResolvedEntity();
        $attributes = $this->getResolvedAttributesForDisplay($resolved);

        foreach ($this->buildResolvedCaptionMap($resolved, $attributes) as $name => $caption) {
            $this->updateFormFieldSafe($name, 'caption', $caption);
        }
        $this->updateFormFieldSafe(
            'ResolvedAttributes',
            'values',
            json_encode($this->formatResolvedAttributesForForm($attributes), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Captions der aufgelösten Entity-Stammdaten; von applyResolvedConfigToForm
     * und refreshResolvedFormFields gemeinsam genutzt (Schlüssel = Formularfeldname).
     *
     * @return array<string, string>
     */
    private function buildResolvedCaptionMap(array $resolved, array $attributes): array
    {
        $deviceClass  = $this->getResolvedDeviceClass($resolved, $attributes);
        $resolvedArea = $this->translateResolvedArea((string)($resolved['area'] ?? ''));

        return [
            'ResolvedName'           => sprintf($this->Translate('Resolved name: %s'), $resolved['name'] ?? ''),
            'ResolvedDomain'         => sprintf($this->Translate('Resolved domain: %s'), $resolved['domain'] ?? ''),
            'ResolvedDeviceClass'    => sprintf($this->Translate('Resolved device class: %s'), $deviceClass),
            'ResolvedDeviceID'       => sprintf($this->Translate('Resolved device ID: %s'), $resolved['device_id'] ?? ''),
            'ResolvedArea'           => sprintf($this->Translate('Resolved area: %s'), $resolvedArea),
            'ResolvedAttributeCount' => sprintf($this->Translate('Resolved attribute count: %d'), count($attributes)),
        ];
    }

    private function applyResolvedConfigToForm(array &$form): void
    {
        $resolved   = $this->getResolvedEntity();
        $attributes = $this->getResolvedAttributesForDisplay($resolved);
        $captions   = $this->buildResolvedCaptionMap($resolved, $attributes);

        foreach ($form['elements'] as &$element) {
            if ($this->applyResolvedCaptionToItem($element, $captions, $attributes)) {
                continue;
            }

            if (!isset($element['items']) || !is_array($element['items'])) {
                continue;
            }

            foreach ($element['items'] as &$item) {
                $this->applyResolvedCaptionToItem($item, $captions, $attributes);
            }
            unset($item);
        }
        unset($element);
    }

    /**
     * Setzt Caption bzw. Werte eines Formularelements aus der Caption-Map.
     * Liefert true, wenn das Element behandelt wurde.
     */
    private function applyResolvedCaptionToItem(array &$item, array $captions, array $attributes): bool
    {
        $name = (string)($item['name'] ?? '');
        if (isset($captions[$name])) {
            $item['caption'] = $captions[$name];
            return true;
        }
        if ($name === 'ResolvedAttributes') {
            $item['values'] = $this->formatResolvedAttributesForForm($attributes);
            return true;
        }
        return false;
    }

    private function applyCurrentDiagnosticsToForm(array &$form): void
    {
        $lastMqtt = $this->ReadAttributeString('LastMQTTMessage');
        if ($lastMqtt === '') {
            $lastMqtt = $this->Translate('never');
        }

        $lastRest = $this->ReadAttributeString('LastRESTFetch');
        if ($lastRest === '') {
            $lastRest = $this->Translate('never');
        }

        $entityCount = $this->getResolvedEntity() === [] ? 0 : 1;
        $captions = [
            'DiagLastMQTT' => sprintf($this->Translate('Last MQTT message: %s'), $lastMqtt),
            'DiagLastREST' => sprintf($this->Translate('Last REST fetch: %s'), $lastRest),
            'DiagEntityCount' => sprintf($this->Translate('Entities (active): %d'), $entityCount)
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

    private function getResolvedEntity(): array
    {
        $config = $this->readResolvedConfig(__FUNCTION__);
        if ($config === []) {
            return [];
        }

        $entity = $config[0] ?? null;
        return is_array($entity) ? $entity : [];
    }

    private function getResolvedDeviceClass(array $resolved, ?array $attributes = null): string
    {
        $deviceClass = $resolved['device_class'] ?? '';
        if (is_string($deviceClass) && trim($deviceClass) !== '') {
            return trim($deviceClass);
        }

        $attributes ??= $this->getResolvedAttributesForDisplay($resolved);
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

    private function translateResolvedArea(string $area): string
    {
        return match ($area) {
            '', HAConfigDefaults::NAME_UNKNOWN, 'Unbekannt' => $this->Translate('Unknown'),
            HAConfigDefaults::AREA_NONE, 'Kein Bereich' => $this->Translate('No area'),
            HAConfigDefaults::AREA_OTHER, 'Sonstiges' => $this->Translate('Other'),
            default => $area
        };
    }
}
