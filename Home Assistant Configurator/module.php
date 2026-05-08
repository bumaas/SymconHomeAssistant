<?php /** @noinspection PhpUnused */

/** @noinspection AutoloadingIssuesInspection */

declare(strict_types=1);

require_once __DIR__ . '/../libs/HACommonIncludes.php';

class HomeAssistantConfigurator extends IPSModuleStrict
{
    use ModuleDebugTrait;
    use HARestParentClientTrait;
    use HASupportedFeaturesTrait;
    use HAEntityConfigLoaderTrait;
    use HAEntityNormalizationTrait;
    use HAEntityConfigBuilderTrait {
        buildResolvedEntityConfig as private buildResolvedEntities;
        buildStableCreateConfig as private buildStableResolvedCreateConfig;
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

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyBoolean('EnableExpertDebug', false);
        $this->RegisterPropertyBoolean('AutoCreateVariables', true);
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

        $this->SetBuffer(self::BUFFER_REFRESH_ACTIVE, json_encode(false, JSON_THROW_ON_ERROR));

    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true, 512, JSON_THROW_ON_ERROR);

        if (!$this->hasCompatibleSplitterParent()) {
            $this->debugExpert(__FUNCTION__, 'Parent ist nicht Home Assistant Splitter');
            return json_encode($form, JSON_THROW_ON_ERROR);
        }

        // Do not start a new search if a search is currently active
        if (!json_decode($this->GetBuffer(self::BUFFER_REFRESH_ACTIVE), false, 512, JSON_THROW_ON_ERROR)) {
            $this->SetBuffer(self::BUFFER_REFRESH_ACTIVE, json_encode(true, JSON_THROW_ON_ERROR));

            // Start device search in a timer, not prolonging the execution of GetConfigurationForm

            $this->debugExpert(__FUNCTION__, 'RegisterOnceTimer');
            $this->RegisterOnceTimer(self::TIMER_CACHE_REFRESH, 'IPS_RequestAction($_IPS["TARGET"], "refresh_cache", "");');
        }

        $bufferSizeMb = max(0, $this->ReadPropertyInteger('OutputBufferSize'));
        if ($bufferSizeMb > 0) {
            $bufferSizeBytes = $bufferSizeMb * 1024 * 1024;
            ini_set('ips.output_buffer', (string) $bufferSizeBytes);
        }

        if (empty($this->entities)) {
            try {
                $this->entities = json_decode($this->ReadAttributeString('CachedEntities'), true, 512, JSON_THROW_ON_ERROR) ?? [];
            } catch (JsonException) {
                $this->entities = [];
            }
        }

        $domainsList = $this->getConfiguredDomainRows();
        $domainFilterEnabled = $this->ReadPropertyBoolean('EnableDomainFilter');
        $domainsSimple = $this->getConfiguredDomainNames();
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

        $entitiesForDisplay = $this->getFilteredEntitiesForDisplay($this->entities, $domainFilterEnabled, $domainsSimple);
        $devices = $this->groupResolvedEntitiesToDevices($entitiesForDisplay);
        $values = $this->prepareConfiguratorValues($devices);

        $form['actions'][] = [
            'type'     => 'Configurator',
            'name'     => 'HomeAssistantDevices',
            'caption'  => 'Found Devices',
            'rowCount' => 20,
            'add'      => false,
            'delete'   => true,
            'columns'  => [
                ['caption' => 'Type', 'name' => 'Type', 'width' => '90px'],
                ['caption' => 'Area', 'name' => 'Area', 'width' => '150px'],
                ['caption' => 'Device', 'name' => 'name', 'width' => '250px'],
                ['caption' => 'Manufacturer', 'name' => 'Manufacturer', 'width' => '200px'],
                ['caption' => 'Model', 'name' => 'Model', 'width' => '200px'],
                ['caption' => 'Entities', 'name' => 'Summary', 'width' => 'auto']
            ],
            'values'   => $values
        ];

        return json_encode($form, JSON_THROW_ON_ERROR);
    }

    /** @noinspection PhpUnused */
    public function RequestAction($Ident, $Value): void
    {
        if ($Ident === 'refresh_cache') {
            if (!$this->hasCompatibleSplitterParent()) {
                $this->SetBuffer(self::BUFFER_REFRESH_ACTIVE, json_encode(false, JSON_THROW_ON_ERROR));
                $this->debugExpert(__FUNCTION__, 'Parent ist nicht Home Assistant Splitter');
                return;
            }
            $this->UpdateCacheFromHA();

            $this->SetBuffer(self::BUFFER_REFRESH_ACTIVE, json_encode(false, JSON_THROW_ON_ERROR));
            $this->updateConfiguratorList();

            return;
        }
        parent::RequestAction($Ident, $Value);
    }

    private function updateConfiguratorList(): void
    {
        $domainFilterEnabled = $this->ReadPropertyBoolean('EnableDomainFilter');
        $domainsSimple = $this->getConfiguredDomainNames();
        $entitiesForDisplay = $this->getFilteredEntitiesForDisplay($this->entities, $domainFilterEnabled, $domainsSimple);
        $devices = $this->groupResolvedEntitiesToDevices($entitiesForDisplay);
        $values = $this->prepareConfiguratorValues($devices);
        $this->UpdateFormField(
            'HomeAssistantDevices',
            'values',
            json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
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
        $autoCreateVariables = $this->ReadPropertyBoolean('AutoCreateVariables');

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
            $cleanedNameById = array_column($cleanedEntities, 'name', 'entity_id');

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

                if (!isset($blockedDeviceIds[$entityId])) {
                    $deviceInstanceID = $mappedDeviceInstances[$entityId][0] ?? 0;
                    $entitiesForConfig = $this->buildEntitiesForConfig($dev, $cleanedNameById, $autoCreateVariables);
                    $createEntitiesForConfig = $this->buildStableResolvedCreateConfig($entitiesForConfig);
                    $values[] = $this->buildDeviceRow($dev, $deviceInstanceID, $cleanedEntities, $createEntitiesForConfig, false, 'Device (Legacy)');
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

            $entitiesForConfig = $this->buildEntitiesForConfig($dev, $cleanedNameById, $autoCreateVariables);
            $createEntitiesForConfig = $this->buildStableResolvedCreateConfig($entitiesForConfig);

            $values[] = $this->buildDeviceRow($dev, $instanceID, $cleanedEntities, $createEntitiesForConfig, false);
        }

        $this->appendMissingDeviceRows($values, $mappedDeviceInstances, $haDeviceIds);
        $this->appendDuplicateDeviceRows($values, $mappedDeviceInstances);
        $this->appendMissingEntityRows($values, $mappedEntityInstances, $haEntityIds);
        $this->appendDuplicateEntityRows($values, $mappedEntityInstances);
        return $values;
    }

    private function buildDeviceInstanceMaps(int $configuratorParentId): array
    {
        $existingInstances = IPS_GetInstanceListByModuleID(HAIds::MODULE_DEVICE);
        $mappedInstances = [];
        $blockedDeviceIds = [];

        foreach ($existingInstances as $id) {
            $inst = IPS_GetInstance($id);
            $parentId = (int)($inst['ConnectionID'] ?? 0);
            $devID = (string)@IPS_GetProperty($id, 'DeviceID');
            if ($devID === '') {
                continue;
            }
            // Only map devices that are attached to the same gateway (Splitter) as this Configurator.
            if ($configuratorParentId > 0 && $parentId !== $configuratorParentId) {
                // Device exists on another gateway: hide it in this Configurator.
                $blockedDeviceIds[$devID] = true;
                continue;
            }
            $mappedInstances[$devID][] = $id;
        }

        return [$mappedInstances, $blockedDeviceIds];
    }

    private function buildEntityInstanceMaps(int $configuratorParentId): array
    {
        $existingInstances = IPS_GetInstanceListByModuleID(HAIds::MODULE_ENTITY);
        $mappedInstances = [];
        $blockedEntityIds = [];

        foreach ($existingInstances as $id) {
            $inst = IPS_GetInstance($id);
            $parentId = (int)($inst['ConnectionID'] ?? 0);
            $entityId = trim((string)@IPS_GetProperty($id, 'EntityID'));
            if ($entityId === '') {
                continue;
            }

            if ($configuratorParentId > 0 && $parentId !== $configuratorParentId) {
                $blockedEntityIds[$entityId] = true;
                continue;
            }

            $mappedInstances[$entityId][] = $id;
        }

        return [$mappedInstances, $blockedEntityIds];
    }

    private function buildEntitiesForConfig(array $dev, array $cleanedNameById, bool $autoCreateVariables): array
    {
        $entitiesForConfig = [];
        foreach ($dev['entities'] as $entity) {
            $entityId = (string)($entity['entity_id'] ?? '');
            $cached = $this->entities[$entityId] ?? null;

            if ($cached !== null) {
                $finalEntity = $cached;
            } else {
                $finalEntity = $entity;
            }

            if (isset($cleanedNameById[$entityId])) {
                $finalEntity['name'] = $cleanedNameById[$entityId];
            }

            $finalEntity['create_var'] = $autoCreateVariables;

            // Ensure attributes are properly encoded for the DeviceConfig property
            if (isset($finalEntity['attributes']) && is_array($finalEntity['attributes'])) {
                $finalEntity['attributes'] = json_encode(
                    $finalEntity['attributes'],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
                );
            } elseif (!isset($finalEntity['attributes']) || !is_string($finalEntity['attributes'])) {
                $finalEntity['attributes'] = '{}';
            }

            // Cleanup: These are redundant as they are handled globally or via properties
            unset($finalEntity['area'], $finalEntity['device']);

            $entitiesForConfig[] = $finalEntity;
        }

        return $entitiesForConfig;
    }

    private function buildDeviceRow(array $dev, int $instanceID, array $cleanedEntities, array $entitiesForConfig, bool $isBlocked, string $type = 'Device'): array
    {
        $entitiesForDeviceProperty = [];
        foreach ($entitiesForConfig as $entity) {
            if (!is_array($entity)) {
                continue;
            }

            $attributes = $entity['attributes'] ?? [];
            if (is_array($attributes)) {
                $entity['attributes'] = json_encode(
                    $attributes,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
                );
            } elseif (!is_string($attributes)) {
                $entity['attributes'] = '{}';
            }

            $entitiesForDeviceProperty[] = $entity;
        }

        $row = [
            'instanceID' => $instanceID,
            'Type'       => $type,
            'name'       => $dev['name'],
            'Area'       => $dev['area'],
            'Manufacturer' => $dev['manufacturer'] ?? '',
            'Model'      => $dev['model'] ?? '',
            'DeviceID'   => $dev['device_id'],
            'Summary'    => $this->generateResolvedEntitySummary($cleanedEntities),
            'group'      => $dev['area']
        ];
        if (!$isBlocked) {
            $row['create'] = [
                'moduleID'      => HAIds::MODULE_DEVICE,
                'configuration' => [
                    // Zentrale Properties setzen
                    'DeviceID'     => $dev['device_id'],
                    'DeviceArea'   => $dev['area'],
                    'DeviceName'   => $dev['name'],
                    // Bereinigte Liste übergeben
                    'DeviceConfig' => json_encode($entitiesForDeviceProperty, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
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
        $area = (string)($dev['area'] ?? 'Sonstiges');

        $row = [
            'instanceID' => $instanceID,
            'Type' => 'Entity',
            'name' => $entityName,
            'Area' => $area,
            'Manufacturer' => '',
            'Model' => '',
            'DeviceID' => $entityId,
            'Summary' => $this->generateResolvedEntitySummary($cleanedEntities),
            'group' => $area
        ];

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

    private function appendMissingDeviceRows(array &$values, array $mappedInstances, array $haDeviceIds): void
    {
        // Add devices that exist in Symcon but no longer exist in Home Assistant.
        foreach ($mappedInstances as $devId => $instanceIds) {
            if (isset($haDeviceIds[$devId])) {
                continue;
            }
            foreach ($instanceIds as $instanceId) {
                $values[] = $this->buildStatusRow($instanceId, $devId, $this->Translate('In Home Assistant nicht gefunden'));
            }
        }
    }

    private function appendDuplicateDeviceRows(array &$values, array $mappedInstances): void
    {
        foreach ($mappedInstances as $devId => $instanceIds) {
            if (count($instanceIds) <= 1) {
                continue;
            }
            // Show additional instances with the same DeviceID (misconfiguration).
            foreach (array_slice($instanceIds, 1) as $instanceId) {
                $values[] = $this->buildStatusRow($instanceId, $devId, $this->Translate('Doppelte Geräte-ID'));
            }
        }
    }

    private function appendMissingEntityRows(array &$values, array $mappedInstances, array $haEntityIds): void
    {
        foreach ($mappedInstances as $entityId => $instanceIds) {
            if (isset($haEntityIds[$entityId])) {
                continue;
            }
            foreach ($instanceIds as $instanceId) {
                $values[] = $this->buildEntityStatusRow($instanceId, $entityId, $this->Translate('In Home Assistant nicht gefunden'));
            }
        }
    }

    private function appendDuplicateEntityRows(array &$values, array $mappedInstances): void
    {
        foreach ($mappedInstances as $entityId => $instanceIds) {
            if (count($instanceIds) <= 1) {
                continue;
            }
            foreach (array_slice($instanceIds, 1) as $instanceId) {
                $values[] = $this->buildEntityStatusRow($instanceId, $entityId, $this->Translate('Doppelte Entity-ID'));
            }
        }
    }

    private function buildStatusRow(int $instanceId, string $deviceId, string $summary): array
    {
        $deviceName = (string)@IPS_GetProperty($instanceId, 'DeviceName');
        $deviceArea = (string)@IPS_GetProperty($instanceId, 'DeviceArea');
        $area = $deviceArea !== '' ? $deviceArea : 'Unbekannt';

        return [
            'instanceID'   => $instanceId,
            'Type'         => 'Device',
            'name'         => $deviceName !== '' ? $deviceName : IPS_GetName($instanceId),
            'Area'         => $area,
            'Manufacturer' => '',
            'Model'        => '',
            'DeviceID'     => $deviceId,
            'Summary'      => $summary,
            'group'        => $area
        ];
    }

    private function buildEntityStatusRow(int $instanceId, string $entityId, string $summary): array
    {
        $entityName = (string)@IPS_GetProperty($instanceId, 'DeviceName');
        $entityArea = (string)@IPS_GetProperty($instanceId, 'DeviceArea');
        $area = $entityArea !== '' ? $entityArea : 'Sonstiges';

        return [
            'instanceID' => $instanceId,
            'Type' => 'Entity',
            'name' => $entityName !== '' ? $entityName : IPS_GetName($instanceId),
            'Area' => $area,
            'Manufacturer' => '',
            'Model' => '',
            'DeviceID' => $entityId,
            'Summary' => $summary,
            'group' => $area
        ];
    }

    private function enrichSupportedFeaturesList(array &$entity): void
    {
        if (!isset($entity['attributes']) || !is_array($entity['attributes'])) {
            return;
        }
        if (isset($entity['attributes']['supported_features_list'])) {
            return;
        }
        if (!isset($entity['attributes']['supported_features']) || !is_numeric($entity['attributes']['supported_features'])) {
            return;
        }

        $domain = (string)($entity['domain'] ?? '');
        if ($domain === '' && isset($entity['entity_id']) && str_contains($entity['entity_id'], '.')) {
            [$domain] = explode('.', (string)$entity['entity_id'], 2);
        }

        $list = $this->mapSupportedFeaturesByDomain($domain, (int)$entity['attributes']['supported_features']);
        if ($list !== []) {
            $entity['attributes']['supported_features_list'] = $list;
        }
    }

    private function UpdateCacheFromHA(): void
    {
        if (!$this->hasCompatibleSplitterParent()) {
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
            return;
        }

        foreach ($this->buildResolvedEntities(array_values($rawEntities), true) as $resolved) {
            if ($resolved !== null) {
                $newEntities[$resolved['entity_id']] = $resolved;
            }
        }

        $this->entities = $newEntities;
        $this->WriteAttributeString('CachedEntities', json_encode($this->entities, JSON_THROW_ON_ERROR));
        $this->LogUpdatedEntities();
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
            $areaA = ($a['area'] === 'Kein Bereich' || empty($a['area'])) ? 'zzz' : $a['area'];
            $areaB = ($b['area'] === 'Kein Bereich' || empty($b['area'])) ? 'zzz' : $b['area'];
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

}
