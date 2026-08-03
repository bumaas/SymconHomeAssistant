<?php /** @noinspection PhpUnused */

/** @noinspection AutoloadingIssuesInspection */

declare(strict_types=1);

require_once __DIR__ . '/../libs/HACommonIncludes.php';

class HomeAssistantConfigurator extends IPSModuleStrict
{
    use ModuleDebugTrait;
    use HARestParentClientTrait;
    use HASupportedFeaturesTrait;
    use HADiagnosticAggregationTrait;
    use HADiagnosticFormattingTrait;
    use HAEntityConfigLoaderTrait;
    use HAEntityNormalizationTrait;
    use HAEntityConfigBuilderTrait {
        buildResolvedEntityConfig as private buildResolvedEntities;
    }
    use HAEntityGroupingTrait {
        groupEntitiesToDevices as private groupResolvedEntitiesToDevices;
        getCleanedEntities as private getResolvedCleanedEntities;
        generateEntitySummary as private generateResolvedEntitySummary;
    }

    // ... Caches ...
    private array $entities = [];

    private const string TIMER_CACHE_REFRESH = 'CacheRefreshTimer';
    private const string BUFFER_REFRESH_ACTIVE = 'CacheRefreshActive';
    private const string ATTRIBUTE_CACHED_DIAGNOSTICS = 'CachedDiagnostics';
    private const string FORM_CONFIGURATOR_DIAGNOSTICS = 'ConfiguratorDiagnostics';
    private const int PERFORMANCE_LOG_THRESHOLD_MS = 250;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyBoolean('EnableExpertDebug', false);
        $this->RegisterPropertyBoolean('EnableDomainFilter', false);

        // Preload the centralized default domain list for new instances.
        $defaultDomains = HADomainCatalog::getConfiguratorDefaultDomains();
        $domainList = [];
        foreach ($defaultDomains as $domain) {
            $domainList[] = ['Domain' => $domain];
        }

        $this->RegisterPropertyString(
            'IncludeDomains',
            json_encode($domainList, JSON_THROW_ON_ERROR)
        );
        $this->RegisterPropertyInteger('OutputBufferSize', 10);
        $this->RegisterPropertyString('DeviceMapping', '[]');
        $this->RegisterAttributeString('CachedEntities', json_encode([], JSON_THROW_ON_ERROR));
        $this->RegisterAttributeString(self::ATTRIBUTE_CACHED_DIAGNOSTICS, json_encode($this->createEmptyConfiguratorDiagnostics(), JSON_THROW_ON_ERROR));

        $this->SetBuffer(self::BUFFER_REFRESH_ACTIVE, json_encode(false, JSON_THROW_ON_ERROR));
    }

    public function GetConfigurationForm(): string
    {
        $startedAt = microtime(true);
        $this->logPerformanceMarker(__FUNCTION__, 'start');
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true, 512, JSON_THROW_ON_ERROR);

        if (!$this->hasCompatibleSplitterParent()) {
            $this->debugExpert(__FUNCTION__, 'Parent ist nicht Home Assistant Splitter');
            $this->logPerformanceSample(__FUNCTION__, $startedAt, [
                'Result' => 'parent_invalid'
            ], true);
            return json_encode($form, JSON_THROW_ON_ERROR);
        }

        $this->scheduleCacheRefreshIfIdle();
        $this->applyOutputBufferSetting();
        $this->loadCachedEntities();
        $this->applyDomainFilterToForm($form);

        $view = $this->buildCurrentConfiguratorView();

        $diagnosticsPanel = $this->buildDiagnosticsPanel($this->getCachedConfiguratorDiagnostics(), count($view['devices']));
        if ($diagnosticsPanel !== null) {
            $form['actions'][] = $diagnosticsPanel;
        }

        $form['actions'][] = $this->buildConfiguratorAction($view['values']);

        $this->logPerformanceSample(__FUNCTION__, $startedAt, [
            'Result' => 'ok',
            'EntityCount' => $view['entityCount'],
            'DeviceCount' => count($view['devices'])
        ], true);
        return json_encode($form, JSON_THROW_ON_ERROR);
    }

    private function scheduleCacheRefreshIfIdle(): void
    {
        // Do not start a new search if a search is currently active
        if (json_decode($this->GetBuffer(self::BUFFER_REFRESH_ACTIVE), false, 512, JSON_THROW_ON_ERROR)) {
            return;
        }
        $this->SetBuffer(self::BUFFER_REFRESH_ACTIVE, json_encode(true, JSON_THROW_ON_ERROR));

        // Start device search in a timer, not prolonging the execution of GetConfigurationForm
        $this->debugExpert('GetConfigurationForm', 'RegisterOnceTimer');
        $this->RegisterOnceTimer(self::TIMER_CACHE_REFRESH, 'IPS_RequestAction($_IPS["TARGET"], "refresh_cache", "");');
        $this->logPerformanceMarker('GetConfigurationForm', 'cache_refresh_scheduled');
    }

    private function applyOutputBufferSetting(): void
    {
        $bufferSizeMb = max(0, $this->ReadPropertyInteger('OutputBufferSize'));
        if ($bufferSizeMb > 0) {
            ini_set('ips.output_buffer', (string)($bufferSizeMb * 1024 * 1024));
        }
    }

    private function loadCachedEntities(): void
    {
        if (!empty($this->entities)) {
            return;
        }
        try {
            $this->entities = json_decode($this->ReadAttributeString('CachedEntities'), true, 512, JSON_THROW_ON_ERROR) ?? [];
        } catch (JsonException) {
            $this->entities = [];
        }
    }

    private function applyDomainFilterToForm(array &$form): void
    {
        $domainsList = $this->getConfiguredDomainRows();
        $domainFilterEnabled = $this->ReadPropertyBoolean('EnableDomainFilter');
        foreach ($form['elements'] as &$element) {
            if (!isset($element['items']) || !is_array($element['items'])) {
                continue;
            }
            foreach ($element['items'] as &$item) {
                if (($item['name'] ?? '') === 'IncludeDomains') {
                    $item['values'] = $domainsList;
                    $item['visible'] = $domainFilterEnabled;
                    continue;
                }
                if (($item['name'] ?? '') === 'EnableDomainFilter') {
                    $item['value'] = $domainFilterEnabled;
                }
            }
            unset($item);
        }
        unset($element);
    }

    /**
     * Aufbereitete Configurator-Ansicht (gefilterte Entitäten → Geräte → Zeilen);
     * von GetConfigurationForm und updateConfiguratorList gemeinsam genutzt.
     *
     * @return array{devices: array, values: array, entityCount: int}
     */
    private function buildCurrentConfiguratorView(): array
    {
        $domainFilterEnabled = $this->ReadPropertyBoolean('EnableDomainFilter');
        $entitiesForDisplay  = $this->getFilteredEntitiesForDisplay($this->entities, $domainFilterEnabled, $this->getConfiguredDomainNames());
        $devices             = $this->groupResolvedEntitiesToDevices($entitiesForDisplay);

        return [
            'devices'     => $devices,
            'values'      => $this->prepareConfiguratorValues($devices),
            'entityCount' => count($entitiesForDisplay)
        ];
    }

    private function buildConfiguratorAction(array $values): array
    {
        return [
            'type'     => 'Configurator',
            'name'     => 'HomeAssistantDevices',
            'caption'  => $this->Translate('Found Devices'),
            'rowCount' => 20,
            'add'      => false,
            'delete'   => true,
            'columns'  => [
                ['caption' => $this->Translate('Type'), 'name' => 'Type', 'width' => '90px'],
                ['caption' => $this->Translate('Area'), 'name' => 'Area', 'width' => '150px'],
                ['caption' => $this->Translate('Device'), 'name' => 'name', 'width' => '250px'],
                ['caption' => $this->Translate('Manufacturer'), 'name' => 'Manufacturer', 'width' => '200px'],
                ['caption' => $this->Translate('Model'), 'name' => 'Model', 'width' => '200px'],
                ['caption' => $this->Translate('Entities'), 'name' => 'Summary', 'width' => 'auto']
            ],
            'values'   => $values
        ];
    }

    /** @noinspection PhpUnused */
    public function RequestAction($Ident, $Value): void
    {
        if ($Ident === 'refresh_cache') {
            $startedAt = microtime(true);
            $this->logPerformanceMarker(__FUNCTION__, 'refresh_cache_start');
            if (!$this->hasCompatibleSplitterParent()) {
                $this->SetBuffer(self::BUFFER_REFRESH_ACTIVE, json_encode(false, JSON_THROW_ON_ERROR));
                $this->debugExpert(__FUNCTION__, 'Parent ist nicht Home Assistant Splitter');
                $this->logPerformanceSample(__FUNCTION__, $startedAt, [
                    'Result' => 'parent_invalid'
                ], true);
                return;
            }
            $this->UpdateCacheFromHA();

            $this->SetBuffer(self::BUFFER_REFRESH_ACTIVE, json_encode(false, JSON_THROW_ON_ERROR));
            $this->updateConfiguratorList();
            $this->logPerformanceSample(__FUNCTION__, $startedAt, [
                'Result' => 'refresh_cache_done',
                'EntityCount' => count($this->entities)
            ], true);

            return;
        }
        parent::RequestAction($Ident, $Value);
    }

    private function updateConfiguratorList(): void
    {
        $view = $this->buildCurrentConfiguratorView();
        $this->UpdateFormField(
            'HomeAssistantDevices',
            'values',
            json_encode($view['values'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        $this->updateDiagnosticsPanel($this->getCachedConfiguratorDiagnostics(), count($view['devices']));
    }

    private function getFilteredEntitiesForDisplay(array $entities, bool $domainFilterEnabled, array $domains): array
    {
        if (!$domainFilterEnabled) {
            return $entities;
        }

        if ($domains === []) {
            return [];
        }

        $filtered = [];
        foreach ($entities as $entity) {
            if (!is_array($entity)) {
                continue;
            }
            $domain = (string)($entity['domain'] ?? '');
            if ($domain === '' && isset($entity['entity_id']) && is_string($entity['entity_id']) && str_contains($entity['entity_id'], '.')) {
                [$domain] = explode('.', $entity['entity_id'], 2);
            }
            if ($domain === '' || !in_array($domain, $domains, true)) {
                continue;
            }
            $filtered[] = $entity;
        }

        return $filtered;
    }

    private function prepareConfiguratorValues(array $devices): array
    {
        $instance = IPS_GetInstance($this->InstanceID);
        $configuratorParentId = (int)($instance['ConnectionID'] ?? 0);
        [$mappedDeviceInstances, $blockedDeviceIds] = $this->buildDeviceInstanceMaps($configuratorParentId);
        [$mappedEntityInstances, $blockedEntityIds] = $this->buildEntityInstanceMaps($configuratorParentId);

        $values = [];
        $haDeviceIds = [];
        $haEntityIds = [];
        foreach ($devices as $dev) {
            $this->debugExpert(__FUNCTION__, json_encode($dev, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));

            $cleanedEntities = $this->getResolvedCleanedEntities($dev);

            if ($this->isEntityCandidate($dev)) {
                $entityId = (string)($dev['device_id'] ?? '');
                if ($entityId === '') {
                    continue;
                }

                $haEntityIds[$entityId] = true;
                $haDeviceIds[$entityId] = true;

                if (!isset($blockedEntityIds[$entityId])) {
                    $entityInstanceID = $mappedEntityInstances[$entityId][0] ?? 0;
                    $values[] = $this->buildEntityRow($dev, $entityInstanceID, $cleanedEntities);
                }
                continue;
            }

            $deviceId = (string)($dev['device_id'] ?? '');
            $instanceID = $mappedDeviceInstances[$deviceId][0] ?? 0;

            if ($deviceId !== '' && isset($blockedDeviceIds[$deviceId])) {
                continue;
            }
            if ($deviceId !== '') {
                $haDeviceIds[$deviceId] = true;
            }

            $values[] = $this->buildDeviceRow($dev, $instanceID, $cleanedEntities, false);
        }

        $deviceStatusRow = $this->buildStatusRow(...);
        $entityStatusRow = $this->buildEntityStatusRow(...);
        $this->appendMissingRows($values, $mappedDeviceInstances, $haDeviceIds, $deviceStatusRow, $this->Translate('Not found in Home Assistant'));
        $this->appendDuplicateRows($values, $mappedDeviceInstances, $deviceStatusRow, $this->Translate('Duplicate device ID'));
        $this->appendMissingRows($values, $mappedEntityInstances, $haEntityIds, $entityStatusRow, $this->Translate('Not found in Home Assistant'));
        $this->appendDuplicateRows($values, $mappedEntityInstances, $entityStatusRow, $this->Translate('Duplicate entity ID'));
        return $values;
    }

    private function buildDeviceInstanceMaps(int $configuratorParentId): array
    {
        return $this->buildInstanceMaps(HAIds::MODULE_DEVICE, 'DeviceID', $configuratorParentId);
    }

    private function buildEntityInstanceMaps(int $configuratorParentId): array
    {
        return $this->buildInstanceMaps(HAIds::MODULE_ENTITY, 'EntityID', $configuratorParentId);
    }

    /**
     * Mappt vorhandene Instanzen eines Moduls auf ihre HA-ID. Instanzen an einem
     * anderen Gateway (Splitter) landen in der Blockliste und werden in diesem
     * Configurator ausgeblendet.
     *
     * @return array{0: array<string, int[]>, 1: array<string, true>}
     */
    private function buildInstanceMaps(string $moduleId, string $idProperty, int $configuratorParentId): array
    {
        $mappedInstances = [];
        $blockedIds      = [];

        foreach (IPS_GetInstanceListByModuleID($moduleId) as $id) {
            $inst     = IPS_GetInstance($id);
            $parentId = (int)($inst['ConnectionID'] ?? 0);
            $haId     = trim((string)@IPS_GetProperty($id, $idProperty));
            if ($haId === '') {
                continue;
            }
            if ($configuratorParentId > 0 && $parentId !== $configuratorParentId) {
                $blockedIds[$haId] = true;
                continue;
            }
            $mappedInstances[$haId][] = $id;
        }

        return [$mappedInstances, $blockedIds];
    }

    /**
     * Gemeinsames Zeilenformat aller Configurator-Einträge
     * (Device-, Entity- und Status-Zeilen nutzen denselben Spaltensatz).
     */
    private function buildConfiguratorRowBase(int $instanceID, string $typeCaption, string $name, string $area, string $manufacturer, string $model, string $deviceId, string $summary): array
    {
        return [
            'instanceID'   => $instanceID,
            'Type'         => $typeCaption,
            'name'         => $name,
            'Area'         => $area,
            'Manufacturer' => $manufacturer,
            'Model'        => $model,
            'DeviceID'     => $deviceId,
            'Summary'      => $summary,
            'group'        => $area
        ];
    }

    private function buildDeviceRow(array $dev, int $instanceID, array $cleanedEntities, bool $isBlocked, string $type = 'Device'): array
    {
        $area = $this->translateConfiguratorArea((string)($dev['area'] ?? HAConfigDefaults::AREA_NONE));
        $summary = $this->translateEntitySummaryForDisplay($this->generateResolvedEntitySummary($cleanedEntities));

        $row = $this->buildConfiguratorRowBase(
            $instanceID,
            $this->Translate($type),
            (string)$dev['name'],
            $area,
            (string)($dev['manufacturer'] ?? ''),
            (string)($dev['model'] ?? ''),
            (string)$dev['device_id'],
            $summary
        );
        if (!$isBlocked) {
            $row['create'] = [
                'moduleID'      => HAIds::MODULE_DEVICE,
                'configuration' => [
                    'DeviceID' => $dev['device_id']
                ],
                'name'          => $dev['name']
            ];
        }
        return $row;
    }

    private function buildEntityRow(array $dev, int $instanceID, array $cleanedEntities): array
    {
        $entityId = (string)($dev['device_id'] ?? '');
        $entityName = (string)($dev['name'] ?? $entityId);
        $area = $this->translateConfiguratorArea((string)($dev['area'] ?? HAConfigDefaults::AREA_OTHER));

        $row = $this->buildConfiguratorRowBase(
            $instanceID,
            $this->Translate('Entity'),
            $entityName,
            $area,
            '',
            '',
            $entityId,
            $this->translateEntitySummaryForDisplay($this->generateResolvedEntitySummary($cleanedEntities))
        );

        $row['create'] = [
            'moduleID' => HAIds::MODULE_ENTITY,
            'configuration' => [
                'EntityID' => $entityId,
                'DeviceID' => $entityId,
                'DeviceArea' => $area,
                'DeviceName' => $entityName
            ],
            'name' => $entityName
        ];

        return $row;
    }

    private function isEntityCandidate(array $dev): bool
    {
        if (!isset($dev['device_id']) || !is_string($dev['device_id'])) {
            return false;
        }

        return str_contains($dev['device_id'], '.');
    }

    /**
     * Zeilen für Instanzen, deren HA-ID nicht (mehr) in Home Assistant existiert.
     *
     * @param callable(int, string, string): array $rowBuilder
     */
    private function appendMissingRows(array &$values, array $mappedInstances, array $knownIds, callable $rowBuilder, string $summary): void
    {
        foreach ($mappedInstances as $haId => $instanceIds) {
            if (isset($knownIds[$haId])) {
                continue;
            }
            foreach ($instanceIds as $instanceId) {
                $values[] = $rowBuilder($instanceId, (string)$haId, $summary);
            }
        }
    }

    /**
     * Zeilen für zusätzliche Instanzen mit derselben HA-ID (Fehlkonfiguration).
     *
     * @param callable(int, string, string): array $rowBuilder
     */
    private function appendDuplicateRows(array &$values, array $mappedInstances, callable $rowBuilder, string $summary): void
    {
        foreach ($mappedInstances as $haId => $instanceIds) {
            if (count($instanceIds) <= 1) {
                continue;
            }
            foreach (array_slice($instanceIds, 1) as $instanceId) {
                $values[] = $rowBuilder($instanceId, (string)$haId, $summary);
            }
        }
    }

    private function buildStatusRow(int $instanceId, string $deviceId, string $summary): array
    {
        return $this->buildInstanceStatusRow($instanceId, $deviceId, $summary, $this->Translate('Device'), HAConfigDefaults::NAME_UNKNOWN);
    }

    private function buildEntityStatusRow(int $instanceId, string $entityId, string $summary): array
    {
        return $this->buildInstanceStatusRow($instanceId, $entityId, $summary, $this->Translate('Entity'), HAConfigDefaults::AREA_OTHER);
    }

    private function buildInstanceStatusRow(int $instanceId, string $haId, string $summary, string $typeCaption, string $areaFallback): array
    {
        $name       = (string)@IPS_GetProperty($instanceId, 'DeviceName');
        $storedArea = (string)@IPS_GetProperty($instanceId, 'DeviceArea');
        $area       = $this->translateConfiguratorArea($storedArea !== '' ? $storedArea : $areaFallback);

        return $this->buildConfiguratorRowBase(
            $instanceId,
            $typeCaption,
            $name !== '' ? $name : IPS_GetName($instanceId),
            $area,
            '',
            '',
            $haId,
            $summary
        );
    }

    private function enrichSupportedFeaturesList(array &$entity): void
    {
        $list = $this->buildSupportedFeaturesList($entity, false);
        if ($list !== []) {
            $entity['attributes']['supported_features_list'] = $list;
        }
    }

    private function UpdateCacheFromHA(): void
    {
        $startedAt = microtime(true);
        $this->logPerformanceMarker(__FUNCTION__, 'start');
        if (!$this->hasCompatibleSplitterParent()) {
            $this->logPerformanceSample(__FUNCTION__, $startedAt, [
                'Result' => 'parent_invalid'
            ], true);
            return;
        }

        $newEntities = [];
        $domainFilterEnabled = $this->ReadPropertyBoolean('EnableDomainFilter');
        $domainsSimple = $domainFilterEnabled ? $this->getConfiguredDomainNames() : null;

        if ($domainFilterEnabled && ($domainsSimple === [])) {
            $this->debugExpert(__FUNCTION__, 'Domain-Filter aktiv, aber keine Domains gesetzt. Ergebnis bleibt leer.');
            $rawEntities = [];
        } else {
            $rawEntities = $this->fetchAllRawEntities($domainsSimple);
        }

        if ($rawEntities === []) {
            $this->debugExpert(__FUNCTION__, 'No entities loaded. Cache cleared.');
            $this->entities = [];
            $this->WriteAttributeString('CachedEntities', json_encode([], JSON_THROW_ON_ERROR));
            $this->WriteAttributeString(self::ATTRIBUTE_CACHED_DIAGNOSTICS, json_encode($this->createEmptyConfiguratorDiagnostics(), JSON_THROW_ON_ERROR));
            $this->logPerformanceSample(__FUNCTION__, $startedAt, [
                'Result' => 'no_entities'
            ], true);
            return;
        }

        foreach ($this->buildResolvedEntities(array_values($rawEntities)) as $resolved) {
            if ($resolved !== null) {
                $newEntities[$resolved['entity_id']] = $resolved;
            }
        }

        $this->entities = $newEntities;
        $this->WriteAttributeString('CachedEntities', json_encode($this->entities, JSON_THROW_ON_ERROR));
        $this->WriteAttributeString(
            self::ATTRIBUTE_CACHED_DIAGNOSTICS,
            json_encode($this->buildConfiguratorDiagnostics(array_values($rawEntities), $newEntities), JSON_THROW_ON_ERROR)
        );
        $this->LogUpdatedEntities();
        $this->logPerformanceSample(__FUNCTION__, $startedAt, [
            'Result' => 'ok',
            'RawEntityCount' => count($rawEntities),
            'ResolvedEntityCount' => count($newEntities)
        ], true);
    }

    private function getConfiguredDomainRows(): array
    {
        try {
            $rows = json_decode($this->ReadPropertyString('IncludeDomains'), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }
        return is_array($rows) ? $rows : [];
    }

    private function getConfiguredDomainNames(): array
    {
        $rows = $this->getConfiguredDomainRows();
        $domains = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $domain = trim((string)($row['Domain'] ?? ''));
            if ($domain === '') {
                continue;
            }
            $domains[$domain] = true;
        }
        return array_keys($domains);
    }

    /**
     * Sortiert die aktuell geladenen Entitäten nach Bereich, Gerät und Namen
     * und gibt diese zur Kontrolle im Debug-Log aus.
     *
     * @return void
     * @throws \JsonException
     * @throws \JsonException
     */
    private function LogUpdatedEntities(): void
    {
        $debugList = $this->entities;
        uasort($debugList, static function ($a, $b) {
            $areaA = ((($a['area'] ?? '') === HAConfigDefaults::AREA_NONE) || (($a['area'] ?? '') === 'Kein Bereich') || empty($a['area'])) ? 'zzz' : $a['area'];
            $areaB = ((($b['area'] ?? '') === HAConfigDefaults::AREA_NONE) || (($b['area'] ?? '') === 'Kein Bereich') || empty($b['area'])) ? 'zzz' : $b['area'];
            $res   = strcasecmp($areaA, $areaB);
            if (strcasecmp($a['device'], $b['device'])) {
                return ($res !== 0) ? $res : (strcasecmp($a['device'], $b['device']));
            }

            return ($res !== 0) ? $res : (strcasecmp($a['name'], $b['name']));
        });

        foreach ($debugList as $entity) {
            // Attribute für die Debug-Ausgabe als String formatieren
            $attributes = json_encode($entity['attributes'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->debugExpert(
                __FUNCTION__,
                sprintf("Found: %s | %s | %s | Attr: %s", $entity['area'], $entity['device'], $entity['name'], $attributes)
            );
            $this->debugExpert(
                __FUNCTION__,
                sprintf(
                    "EntityID: %s | device_id: %s | device_name: %s",
                    $entity['entity_id'],
                    $entity['device_id'],
                    $entity['device_name']
                )
            );
        }
    }

    private function translateConfiguratorArea(string $area): string
    {
        return match ($area) {
            '', HAConfigDefaults::NAME_UNKNOWN, 'Unbekannt' => $this->Translate('Unknown'),
            HAConfigDefaults::AREA_NONE, 'Kein Bereich' => $this->Translate('No area'),
            HAConfigDefaults::AREA_OTHER, 'Sonstiges' => $this->Translate('Other'),
            default => $area
        };
    }

    private function translateEntitySummaryForDisplay(string $summary): string
    {
        if (preg_match('/^(\d+) entities$/', $summary, $matches) === 1) {
            return sprintf($this->Translate('%d entities'), (int)$matches[1]);
        }

        return $summary;
    }

    private function createEmptyConfiguratorDiagnostics(): array
    {
        return [
            'loaded_entities' => 0,
            'resolved_entities' => 0,
            'unsupported' => []
        ];
    }

    private function getCachedConfiguratorDiagnostics(): array
    {
        try {
            $diagnostics = json_decode($this->ReadAttributeString(self::ATTRIBUTE_CACHED_DIAGNOSTICS), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->createEmptyConfiguratorDiagnostics();
        }

        return is_array($diagnostics) ? $diagnostics : $this->createEmptyConfiguratorDiagnostics();
    }

    private function buildDiagnosticsPanel(array $diagnostics, int $deviceCount): ?array
    {
        $unsupported = is_array($diagnostics['unsupported'] ?? null) ? $diagnostics['unsupported'] : [];
        $loadedEntities = (int)($diagnostics['loaded_entities'] ?? 0);
        $resolvedEntities = (int)($diagnostics['resolved_entities'] ?? 0);
        $visible = $unsupported !== [] || $loadedEntities > 0;
        if (!$visible) {
            return null;
        }

        return $this->buildUnsupportedDiagnosticsPanel(
            $this->Translate('Configurator Diagnostics'),
            sprintf($this->Translate('Loaded Home Assistant entities/resolved/devices: %d/%d/%d'), $loadedEntities, $resolvedEntities, $deviceCount),
            $this->Translate('Unsupported Home Assistant domains: none'),
            $this->Translate('Unsupported Home Assistant domains: %s'),
            $unsupported,
            $visible,
            self::FORM_CONFIGURATOR_DIAGNOSTICS
        );
    }

    private function updateDiagnosticsPanel(array $diagnostics, int $deviceCount): void
    {
        $panel = $this->buildDiagnosticsPanel($diagnostics, $deviceCount);
        if ($panel === null) {
            @$this->UpdateFormField(self::FORM_CONFIGURATOR_DIAGNOSTICS, 'visible', false);
            return;
        }

        @$this->UpdateFormField(self::FORM_CONFIGURATOR_DIAGNOSTICS, 'caption', (string)$panel['caption']);
        @$this->UpdateFormField(self::FORM_CONFIGURATOR_DIAGNOSTICS, 'visible', (bool)$panel['visible']);
        @$this->UpdateFormField(
            self::FORM_CONFIGURATOR_DIAGNOSTICS,
            'items',
            json_encode($panel['items'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    private function buildConfiguratorDiagnostics(array $rawEntities, array $resolvedEntities): array
    {
        $diagnostics = $this->createEmptyConfiguratorDiagnostics();
        $diagnostics['loaded_entities'] = count($rawEntities);
        $diagnostics['resolved_entities'] = count($resolvedEntities);

        foreach ($rawEntities as $rawEntity) {
            if (!is_array($rawEntity)) {
                continue;
            }

            $entityId = trim((string)($rawEntity['entity_id'] ?? ''));
            $domain = trim((string)($rawEntity['domain'] ?? ''));
            if ($domain === '' && $entityId !== '' && str_contains($entityId, '.')) {
                [$domain] = explode('.', $entityId, 2);
            }

            if ($domain === '' || HADomainCatalog::isDomainSupported($domain)) {
                continue;
            }

            $this->recordUnsupportedDiagnostic($diagnostics, $domain, $entityId);
        }

        $diagnostics['unsupported'] = $this->finalizeUnsupportedDiagnostics(is_array($diagnostics['unsupported'] ?? null) ? $diagnostics['unsupported'] : []);
        return $diagnostics;
    }

    private function logPerformanceSample(string $scope, float $startedAt, array $context = [], bool $force = false): void
    {
        $durationMs = (int)round((microtime(true) - $startedAt) * 1000);
        if (!$force && $durationMs < self::PERFORMANCE_LOG_THRESHOLD_MS) {
            return;
        }

        $this->sendPerformanceDebug($scope . ' | ' . $durationMs . ' ms', $context);
    }

    private function logPerformanceMarker(string $scope, string $phase, array $context = []): void
    {
        $this->sendPerformanceDebug($scope . ' | ' . $phase, $context);
    }

    private function sendPerformanceDebug(string $message, array $context): void
    {
        if ($context !== []) {
            $encodedContext = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($encodedContext) && $encodedContext !== '') {
                $message .= ' | ' . $encodedContext;
            }
        }

        $this->SendDebug('Performance', $message, 0);
    }

}
