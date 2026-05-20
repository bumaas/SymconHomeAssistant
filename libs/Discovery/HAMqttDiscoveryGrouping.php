<?php

declare(strict_types=1);

final class HAMqttDiscoveryGrouping
{
    public function groupEntitiesToDevices(array $entities): array
    {
        $groups = [];

        foreach ($entities as $entity) {
            if (!is_array($entity)) {
                continue;
            }

            $device = $entity['device'] ?? [];
            if (!is_array($device)) {
                $device = [];
            }

            $groupKey = (string)($device['discovery_device_id'] ?? '');
            if ($groupKey === '') {
                $groupKey = 'entity:' . ($entity['unique_id'] ?? '');
            }
            if ($groupKey === 'entity:') {
                continue;
            }

            if (!isset($groups[$groupKey])) {
                $groupType = $this->determineGroupType($device, $groupKey);
                $groups[$groupKey] = [
                    'source'       => 'mqtt_discovery',
                    'device_id'    => $groupKey,
                    'group_type'   => $groupType,
                    'is_bridge_device' => $groupType === 'bridge',
                    'name'         => (string)($device['name'] ?? $entity['name'] ?? $groupKey),
                    'area'         => '',
                    'manufacturer' => (string)($device['manufacturer'] ?? ''),
                    'model'        => (string)($device['model'] ?? ''),
                    'model_id'     => (string)($device['model_id'] ?? ''),
                    'hw_version'   => $device['hw_version'] ?? null,
                    'sw_version'   => (string)($device['sw_version'] ?? ''),
                    'via_device'   => (string)($device['via_device'] ?? ''),
                    'entities'     => []
                ];
            }

            $groups[$groupKey]['entities'][] = $entity;
        }

        foreach ($groups as &$group) {
            usort($group['entities'], static function (array $left, array $right): int {
                $leftName = strtolower((string)($left['name'] ?? ''));
                $rightName = strtolower((string)($right['name'] ?? ''));
                $nameCompare = $leftName <=> $rightName;
                if ($nameCompare !== 0) {
                    return $nameCompare;
                }

                return strtolower((string)($left['component'] ?? '')) <=> strtolower((string)($right['component'] ?? ''));
            });
        }
        unset($group);

        uasort($groups, static function (array $left, array $right): int {
            return strtolower((string)($left['name'] ?? '')) <=> strtolower((string)($right['name'] ?? ''));
        });

        return array_values($groups);
    }

    public function buildDeviceConfig(array $group): array
    {
        $deviceConfig = [
            'device_id'    => (string)($group['device_id'] ?? ''),
            'device_name'  => (string)($group['name'] ?? ''),
            'device_area'  => '',
            'manufacturer' => (string)($group['manufacturer'] ?? ''),
            'model'        => (string)($group['model'] ?? ''),
            'model_id'     => (string)($group['model_id'] ?? ''),
            'hw_version'   => $group['hw_version'] ?? null,
            'sw_version'   => (string)($group['sw_version'] ?? ''),
            'via_device'   => (string)($group['via_device'] ?? ''),
            'entities'     => []
        ];

        $entities = $group['entities'] ?? [];
        if (!is_array($entities)) {
            return $deviceConfig;
        }

        foreach ($entities as $entity) {
            if (!is_array($entity)) {
                continue;
            }

            $deviceConfig['entities'][] = [
                'entity_key'        => (string)($entity['unique_id'] ?? ''),
                'component'         => (string)($entity['component'] ?? ''),
                'device_name'       => (string)($group['name'] ?? ''),
                'object_id'         => (string)($entity['object_id'] ?? ''),
                'name'              => (string)($entity['name'] ?? ''),
                'create_var'        => (bool)($entity['state']['enabled_by_default'] ?? true),
                'state_topic'       => (string)(($entity['transport']['state_topic'] ?? '') ?: ''),
                'command_topic'     => (string)(($entity['transport']['command_topic'] ?? '') ?: ''),
                'json_attributes_topic' => (string)(($entity['transport']['json_attributes_topic'] ?? '') ?: ''),
                'qos'               => (int)($entity['transport']['qos'] ?? 0),
                'retain'            => (bool)($entity['transport']['retain'] ?? false),
                'optimistic'        => (bool)($entity['transport']['optimistic'] ?? false),
                'value_template'    => $entity['state']['value_template'] ?? null,
                'command_template'  => $entity['command']['command_template'] ?? null,
                'command_mode'      => (string)($entity['command']['mode'] ?? 'none'),
                'availability'      => $entity['availability'] ?? ['mode' => 'latest', 'entries' => []],
                'payload_on'        => $entity['state']['payload_on'] ?? null,
                'payload_off'       => $entity['state']['payload_off'] ?? null,
                'payload_press'     => $entity['state']['payload_press'] ?? null,
                'event_payload'     => $entity['state']['event_payload'] ?? null,
                'state_on'          => $entity['state']['state_on'] ?? null,
                'state_off'         => $entity['state']['state_off'] ?? null,
                'options'           => $entity['state']['options'] ?? [],
                'metadata'          => [
                    'device_class'       => $entity['state']['device_class'] ?? null,
                    'state_class'        => $entity['state']['state_class'] ?? null,
                    'unit'               => $entity['state']['unit_of_measurement'] ?? null,
                    'min'                => $entity['state']['min'] ?? null,
                    'max'                => $entity['state']['max'] ?? null,
                    'step'               => $entity['state']['step'] ?? null,
                    'native_min_value'   => $entity['state']['native_min_value'] ?? null,
                    'native_max_value'   => $entity['state']['native_max_value'] ?? null,
                    'native_step'        => $entity['state']['native_step'] ?? null,
                    'mode'               => $entity['state']['mode'] ?? null,
                    'entity_category'    => $entity['state']['entity_category'] ?? null,
                    'enabled_by_default' => $entity['state']['enabled_by_default'] ?? true,
                    'icon'               => $entity['state']['icon'] ?? null,
                    'brightness'         => $entity['state']['brightness'] ?? false,
                    'brightness_scale'   => $entity['state']['brightness_scale'] ?? null,
                    'effect'             => $entity['state']['effect'] ?? false,
                    'effect_list'        => $entity['state']['effect_list'] ?? [],
                    'supported_features' => $entity['state']['supported_features'] ?? null,
                    'supported_color_modes' => $entity['state']['supported_color_modes'] ?? [],
                    'min_mireds'         => $entity['state']['min_mireds'] ?? null,
                    'max_mireds'         => $entity['state']['max_mireds'] ?? null,
                    'min_color_temp_kelvin' => $entity['state']['min_color_temp_kelvin'] ?? null,
                    'max_color_temp_kelvin' => $entity['state']['max_color_temp_kelvin'] ?? null,
                    'schema'             => $entity['state']['schema'] ?? null,
                    'reports_position'   => $entity['state']['reports_position'] ?? false,
                    'state_open'         => $entity['state']['state_open'] ?? null,
                    'state_closed'       => $entity['state']['state_closed'] ?? null,
                    'state_opening'      => $entity['state']['state_opening'] ?? null,
                    'state_closing'      => $entity['state']['state_closing'] ?? null,
                    'state_stopped'      => $entity['state']['state_stopped'] ?? null,
                    'action_topic'       => $entity['state']['action_topic'] ?? null,
                    'action_command_template' => $entity['state']['action_command_template'] ?? null,
                    'tilt_action_topic'  => $entity['state']['tilt_action_topic'] ?? null,
                    'tilt_action_command_template' => $entity['state']['tilt_action_command_template'] ?? null,
                    'event_type'         => $entity['state']['event_type'] ?? null,
                    'event_types'        => $entity['state']['event_types'] ?? [],
                    'origin'             => $entity['origin'] ?? ['name' => '', 'sw' => '', 'url' => '']
                ]
            ];
        }

        return $deviceConfig;
    }

    private function determineGroupType(array $device, string $groupKey): string
    {
        $manufacturer = strtolower(trim((string)($device['manufacturer'] ?? '')));
        $model = strtolower(trim((string)($device['model'] ?? '')));
        $name = strtolower(trim((string)($device['name'] ?? '')));

        if (str_starts_with($groupKey, 'zigbee2mqtt_bridge_')) {
            return 'bridge';
        }

        if ($manufacturer === 'zigbee2mqtt' && (str_contains($model, 'bridge') || str_contains($name, 'bridge'))) {
            return 'bridge';
        }

        return 'device';
    }
}
