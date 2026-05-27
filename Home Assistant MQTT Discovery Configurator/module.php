<?php /** @noinspection PhpUnused */

/** @noinspection AutoloadingIssuesInspection */

declare(strict_types=1);

require_once __DIR__ . '/../libs/HACommonIncludes.php';

class HomeAssistantMQTTDiscoveryConfigurator extends IPSModuleStrict
{
    use ModuleDebugTrait;
    use HAMqttDiscoveryParentClientTrait;
    use HADiagnosticFormattingTrait;

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
                'caption' => $this->Translate("No MQTT discovery configs found in splitter cache yet. Check whether the MQTT parent receives the discovery prefix, for example 'homeassistant/#' or '#', and whether discovery payloads are available.")
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
                return !($group['is_bridge_device'] ?? false);
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
                'Type' => $this->Translate($this->determineGroupType($group)),
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
        return match ((string)($group['group_type'] ?? 'device')) {
            'bridge' => 'Bridge',
            default => 'Device'
        };
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
            $suffix = $this->translateEntityAccessMode($entity);
            $parts[] = $component . ': ' . $name . ' (' . $suffix . ')';
        }

        return implode(' | ', array_slice($parts, 0, 8));
    }

    private function translateEntityAccessMode(array $entity): string
    {
        $hasStateTopic = trim((string)($entity['transport']['state_topic'] ?? '')) !== '';
        $hasCommandTopic = trim((string)($entity['transport']['command_topic'] ?? '')) !== '';

        if ($hasCommandTopic && !$hasStateTopic) {
            return $this->Translate('Write only');
        }

        if ($hasCommandTopic) {
            return $this->Translate('Read and write');
        }

        return $this->Translate('Read only');
    }

    private function buildDiagnosticsPanel(array $diagnostics, int $groupCount): ?array
    {
        $unsupported = is_array($diagnostics['unsupported'] ?? null) ? $diagnostics['unsupported'] : [];
        $parsedEntities = (int)($diagnostics['parsed_entities'] ?? 0);
        $totalRecords = (int)($diagnostics['total_records'] ?? 0);
        $visible = $unsupported !== [] || $totalRecords > 0;

        if (!$visible) {
            return null;
        }

        return $this->buildUnsupportedDiagnosticsPanel(
            $this->Translate('Discovery Diagnostics'),
            sprintf($this->Translate('Parsed discovery records/entities/devices: %d/%d/%d'), $totalRecords, $parsedEntities, $groupCount),
            $this->Translate('Unsupported discovery components: none'),
            $this->Translate('Unsupported discovery components: %s'),
            $unsupported,
            $visible
        );
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
