<?php /** @noinspection PhpUnused */

/** @noinspection AutoloadingIssuesInspection */

declare(strict_types=1);

require_once __DIR__ . '/../libs/HACommonIncludes.php';

class HomeAssistantMQTTDiscoveryConfigurator extends IPSModuleStrict
{
    use ModuleDebugTrait;
    use HAMqttDiscoveryParentClientTrait;

    private HAMqttDiscoveryParser $parser;
    private HAMqttDiscoveryGrouping $grouping;

    public function Create(): void
    {
        $this->LogMessage('Create | start', KL_MESSAGE);
        parent::Create();
        $this->LogMessage('Create | after_parent', KL_MESSAGE);

        $this->RegisterPropertyBoolean('ShowBridgeDevices', true);
        $this->RegisterPropertyBoolean('EnableExpertDebug', false);
        $this->LogMessage('Create | after_RegisterProperties', KL_MESSAGE);
    }

    public function GetCompatibleParents(): string
    {
        $parents = [
            'type' => 'connect',
            'modules' => [
                ['moduleID' => HAIds::MODULE_MQTT_DISCOVERY_SPLITTER]
            ]
        ];

        return json_encode($parents, JSON_THROW_ON_ERROR);
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true, 512, JSON_THROW_ON_ERROR);

        $parentState = $this->getDiscoveryParentRuntimeState();
        if ($parentState !== 'active') {
            $message = match ($parentState) {
                'missing' => 'Kein Parent verbunden',
                'inactive' => 'Home Assistant MQTT Discovery Splitter Parent ist nicht aktiv',
                default => 'Parent ist nicht Home Assistant MQTT Discovery Splitter'
            };
            $this->debugExpert(__FUNCTION__, $message);
            return json_encode($form, JSON_THROW_ON_ERROR);
        }

        $records = $this->loadDiscoveryRecords();
        $analysis = $this->analyzeDiscoveryRecords($records);
        $groups = $analysis['groups'];
        $diagnostics = $analysis['diagnostics'];
        $values = $this->prepareConfiguratorValues($groups);

        if ($records === []) {
            $form['actions'][] = [
                'type' => 'Label',
                'caption' => $this->Translate('No MQTT discovery configs found in splitter cache. MQTT parent must subscribe to the discovery prefix, for example homeassistant/# or #.')
            ];
        }

        $diagnosticsPanel = $this->buildDiagnosticsPanel($diagnostics, count($groups));
        if ($diagnosticsPanel !== null) {
            $form['actions'][] = $diagnosticsPanel;
        }

        $form['actions'][] = [
            'type' => 'Configurator',
            'name' => 'MqttDiscoveryDevices',
            'caption' => $this->Translate('Found MQTT Discovery Devices'),
            'rowCount' => 20,
            'add' => false,
            'delete' => true,
            'columns' => [
                ['caption' => $this->Translate('Type'), 'name' => 'Type', 'width' => '100px'],
                ['caption' => $this->Translate('Device'), 'name' => 'name', 'width' => '260px'],
                ['caption' => $this->Translate('Manufacturer'), 'name' => 'Manufacturer', 'width' => '180px'],
                ['caption' => $this->Translate('Model'), 'name' => 'Model', 'width' => '180px'],
                ['caption' => $this->Translate('Entities'), 'name' => 'EntityCount', 'width' => '80px'],
                ['caption' => $this->Translate('Summary'), 'name' => 'Summary', 'width' => 'auto']
            ],
            'values' => $values
        ];

        return json_encode($form, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function loadDiscoveryRecords(): array
    {
        $response = $this->sendDiscoveryRequestToParent('GetDiscoveryConfigs');
        if ($response === null) {
            return [];
        }

        $items = $response['Items'] ?? [];
        if (!is_array($items)) {
            return [];
        }

        $topics = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $topics[] = (string)($item['topic'] ?? '');
        }

        $this->debugExpert(__FUNCTION__, 'Loaded discovery records', [
            'Count' => count($items),
            'Topics' => array_slice($topics, 0, 20)
        ]);
        return $items;
    }

    private function analyzeDiscoveryRecords(array $records): array
    {
        $this->ensureHelpers();

        $analysis = $this->parser->analyzeConfigMessages($records);
        $entities = is_array($analysis['entities'] ?? null) ? $analysis['entities'] : [];
        $diagnostics = is_array($analysis['diagnostics'] ?? null) ? $analysis['diagnostics'] : [];
        $groups = $this->grouping->groupEntitiesToDevices($entities);

        if (!$this->ReadPropertyBoolean('ShowBridgeDevices')) {
            $groups = array_values(array_filter($groups, static function (array $group): bool {
                return !str_starts_with((string)($group['device_id'] ?? ''), 'zigbee2mqtt_bridge_');
            }));
        }

        $entityMap = [];
        foreach ($entities as $uniqueId => $entity) {
            if (!is_array($entity)) {
                continue;
            }
            $entityMap[] = [
                'UniqueID' => (string)$uniqueId,
                'DeviceID' => (string)($entity['device']['discovery_device_id'] ?? ''),
                'Component' => (string)($entity['component'] ?? ''),
                'ObjectID' => (string)($entity['object_id'] ?? '')
            ];
        }

        $groupMap = [];
        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }
            $groupMap[] = [
                'DeviceID' => (string)($group['device_id'] ?? ''),
                'Name' => (string)($group['name'] ?? ''),
                'EntityCount' => count(is_array($group['entities'] ?? null) ? $group['entities'] : [])
            ];
        }

        $this->debugExpert(__FUNCTION__, 'Grouped discovery devices', [
            'Entities' => count($entities),
            'Groups' => count($groups),
            'Unsupported' => $diagnostics['unsupported'] ?? [],
            'Skipped' => $diagnostics['skipped'] ?? [],
            'EntityMap' => array_slice($entityMap, 0, 30),
            'GroupMap' => $groupMap
        ]);

        return [
            'groups' => $groups,
            'diagnostics' => $diagnostics
        ];
    }

    private function prepareConfiguratorValues(array $groups): array
    {
        $instance = IPS_GetInstance($this->InstanceID);
        $configuratorParentId = (int)($instance['ConnectionID'] ?? 0);
        $mappedInstances = $this->buildExistingDeviceInstanceMap($configuratorParentId);

        $values = [];
        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }

            $entities = $group['entities'] ?? [];
            if (!is_array($entities)) {
                $entities = [];
            }

            $deviceConfig = $this->grouping->buildDeviceConfig($group);
            $deviceId = (string)($deviceConfig['device_id'] ?? '');
            $instanceID = $mappedInstances[$deviceId][0] ?? 0;

            $row = [
                'instanceID' => $instanceID,
                'Type' => $this->determineGroupType($group),
                'name' => (string)($group['name'] ?? ''),
                'Manufacturer' => (string)($group['manufacturer'] ?? ''),
                'Model' => (string)($group['model'] ?? ''),
                'DeviceID' => $deviceId,
                'EntityCount' => count($entities),
                'Summary' => $this->buildEntitySummary($entities),
                'group' => (string)($group['manufacturer'] ?? '')
            ];

            if ($deviceId !== '') {
                $row['create'] = [
                    'moduleID' => HAIds::MODULE_MQTT_DISCOVERY_DEVICE,
                    'configuration' => [
                        'DeviceID' => $deviceId
                    ],
                    'name' => (string)($deviceConfig['device_name'] ?? $deviceId)
                ];
            }

            $this->debugExpert(__FUNCTION__, 'Prepared MQTT discovery configurator row', [
                'ConfiguratorParent' => $this->getCurrentParentDebugContext(),
                'DeviceID' => $deviceId,
                'InstanceID' => $instanceID,
                'Create' => $row['create'] ?? null
            ], true);

            $values[] = $row;
        }

        return $values;
    }

    private function determineGroupType(array $group): string
    {
        $deviceId = (string)($group['device_id'] ?? '');
        if (str_starts_with($deviceId, 'zigbee2mqtt_bridge_')) {
            return 'Bridge';
        }

        return 'Device';
    }

    private function buildEntitySummary(array $entities): string
    {
        $parts = [];
        foreach ($entities as $entity) {
            if (!is_array($entity)) {
                continue;
            }

            $component = (string)($entity['component'] ?? 'unknown');
            $name = (string)($entity['name'] ?? $entity['object_id'] ?? '');
            $mode = (string)($entity['command']['mode'] ?? 'none');
            $suffix = $mode === 'none' ? 'ro' : 'rw';
            $parts[] = $component . ': ' . $name . ' (' . $suffix . ')';
        }

        return implode(' | ', array_slice($parts, 0, 8));
    }

    private function buildDiagnosticsPanel(array $diagnostics, int $groupCount): ?array
    {
        $unsupported = is_array($diagnostics['unsupported'] ?? null) ? $diagnostics['unsupported'] : [];
        $skipped = is_array($diagnostics['skipped'] ?? null) ? $diagnostics['skipped'] : [];
        $parsedEntities = (int)($diagnostics['parsed_entities'] ?? 0);
        $totalRecords = (int)($diagnostics['total_records'] ?? 0);

        if ($unsupported === [] && $skipped === [] && $totalRecords === 0) {
            return null;
        }

        $items = [[
            'type' => 'Label',
            'caption' => sprintf($this->Translate('Parsed discovery records/entities/devices: %d/%d/%d'), $totalRecords, $parsedEntities, $groupCount)
        ]];

        $items[] = [
            'type' => 'Label',
            'caption' => $unsupported === []
                ? $this->Translate('Unsupported discovery components: none')
                : sprintf($this->Translate('Unsupported discovery components: %s'), $this->formatUnsupportedSummary($unsupported))
        ];

        if ($unsupported !== []) {
            $items[] = [
                'type' => 'Label',
                'caption' => sprintf($this->Translate('Unsupported examples: %s'), $this->formatUnsupportedExamples($unsupported))
            ];
        }

        $items[] = [
            'type' => 'Label',
            'caption' => $skipped === []
                ? $this->Translate('Skipped discovery entries: none')
                : sprintf($this->Translate('Skipped discovery entries: %s'), $this->formatSkippedSummary($skipped))
        ];

        if ($skipped !== []) {
            $items[] = [
                'type' => 'Label',
                'caption' => sprintf($this->Translate('Skipped examples: %s'), $this->formatSkippedExamples($skipped))
            ];
        }

        return [
            'type' => 'ExpansionPanel',
            'caption' => $this->Translate('Discovery Diagnostics'),
            'expanded' => false,
            'items' => $items
        ];
    }

    private function formatUnsupportedSummary(array $unsupported): string
    {
        $parts = [];
        foreach (array_slice($unsupported, 0, 8) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $parts[] = sprintf(
                '%s (%d)',
                (string)($entry['component'] ?? 'unknown'),
                (int)($entry['count'] ?? 0)
            );
        }

        return $parts === [] ? $this->Translate('none') : implode(', ', $parts);
    }

    private function formatUnsupportedExamples(array $unsupported): string
    {
        $parts = [];
        foreach (array_slice($unsupported, 0, 4) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $examples = array_slice(is_array($entry['examples'] ?? null) ? $entry['examples'] : [], 0, 2);
            if ($examples === []) {
                continue;
            }

            $parts[] = (string)($entry['component'] ?? 'unknown') . ' -> ' . implode(', ', $examples);
        }

        return $parts === [] ? $this->Translate('none') : implode(' | ', $parts);
    }

    private function formatSkippedSummary(array $skipped): string
    {
        $parts = [];
        foreach (array_slice($skipped, 0, 8) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $parts[] = sprintf(
                '%s (%d)',
                $this->formatSkippedLabel($entry),
                (int)($entry['count'] ?? 0)
            );
        }

        return $parts === [] ? $this->Translate('none') : implode(', ', $parts);
    }

    private function formatSkippedExamples(array $skipped): string
    {
        $parts = [];
        foreach (array_slice($skipped, 0, 4) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $examples = array_slice(is_array($entry['examples'] ?? null) ? $entry['examples'] : [], 0, 2);
            if ($examples === []) {
                continue;
            }

            $parts[] = $this->formatSkippedLabel($entry) . ' -> ' . implode(', ', $examples);
        }

        return $parts === [] ? $this->Translate('none') : implode(' | ', $parts);
    }

    private function formatSkippedLabel(array $entry): string
    {
        $component = (string)($entry['component'] ?? 'unknown');
        $reason = (string)($entry['reason'] ?? 'unknown');

        return match ($reason) {
            'empty_payload' => sprintf($this->Translate('%s without payload'), $component),
            'invalid_json' => sprintf($this->Translate('%s invalid JSON'), $component),
            'invalid_json_object' => sprintf($this->Translate('%s invalid JSON object'), $component),
            'missing_topic' => sprintf($this->Translate('%s without topic'), $component),
            'missing_components' => $this->Translate('device discovery without components'),
            'missing_platform' => $this->Translate('device discovery without platform'),
            'invalid_component_entry' => $this->Translate('device discovery invalid component entry'),
            default => sprintf($this->Translate('%s skipped'), $component)
        };
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

    private function buildExistingDeviceInstanceMap(int $configuratorParentId): array
    {
        $instances = [];
        foreach (IPS_GetInstanceListByModuleID(HAIds::MODULE_MQTT_DISCOVERY_DEVICE) as $instanceId) {
            $instance = IPS_GetInstance($instanceId);
            $parentId = (int)($instance['ConnectionID'] ?? 0);
            if ($configuratorParentId > 0 && $parentId !== $configuratorParentId) {
                continue;
            }

            $deviceId = trim((string)@IPS_GetProperty($instanceId, 'DeviceID'));
            if ($deviceId === '') {
                continue;
            }

            $instances[$deviceId][] = $instanceId;
        }

        return $instances;
    }

}
