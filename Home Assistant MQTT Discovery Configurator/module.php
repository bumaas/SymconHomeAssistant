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
        parent::Create();

        $this->RegisterPropertyBoolean('ShowBridgeDevices', true);
        $this->RegisterPropertyBoolean('EnableExpertDebug', false);
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
        $groups = $this->loadDiscoveryGroups($records);
        $values = $this->prepareConfiguratorValues($groups);

        if ($records === []) {
            $form['actions'][] = [
                'type' => 'Label',
                'caption' => 'Keine MQTT-Discovery-Configs im Splitter-Cache gefunden. MQTT Parent muss ein MQTT Client mit homeassistant/# sein.'
            ];
        }

        $form['actions'][] = [
            'type' => 'Configurator',
            'name' => 'MqttDiscoveryDevices',
            'caption' => 'Found MQTT Discovery Devices',
            'rowCount' => 20,
            'add' => false,
            'delete' => true,
            'columns' => [
                ['caption' => 'Type', 'name' => 'Type', 'width' => '100px'],
                ['caption' => 'Device', 'name' => 'name', 'width' => '260px'],
                ['caption' => 'Manufacturer', 'name' => 'Manufacturer', 'width' => '180px'],
                ['caption' => 'Model', 'name' => 'Model', 'width' => '180px'],
                ['caption' => 'Entities', 'name' => 'EntityCount', 'width' => '80px'],
                ['caption' => 'Summary', 'name' => 'Summary', 'width' => 'auto']
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

    private function loadDiscoveryGroups(array $records): array
    {
        $this->ensureHelpers();

        $entities = $this->parser->parseConfigMessages($records);
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
            'EntityMap' => array_slice($entityMap, 0, 30),
            'GroupMap' => $groupMap
        ]);

        return $groups;
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
