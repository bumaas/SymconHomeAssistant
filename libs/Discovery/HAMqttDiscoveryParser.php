<?php

declare(strict_types=1);

final class HAMqttDiscoveryParser
{
    /** @var string[] */
    private const array SUPPORTED_COMPONENTS = [
        HABinarySensorDefinitions::DOMAIN,
        HASensorDefinitions::DOMAIN,
        HASwitchDefinitions::DOMAIN,
        HASelectDefinitions::DOMAIN,
        HAButtonDefinitions::DOMAIN,
        HALightDefinitions::DOMAIN
    ];

    private const string DEVICE_AUTOMATION_COMPONENT = 'device_automation';
    private const string DEVICE_DISCOVERY_COMPONENT = 'device';

    public function __construct(
        private readonly string $discoveryPrefix = 'homeassistant'
    ) {
    }

    public function parseConfigMessage(string $topic, string $payload): ?array
    {
        $entities = $this->parseConfigRecord($topic, $payload);
        if (count($entities) !== 1) {
            return null;
        }

        return reset($entities) ?: null;
    }

    public function parseConfigRecord(string $topic, string $payload): array
    {
        $topicMeta = $this->parseTopic($topic);
        if ($topicMeta === null) {
            return [];
        }

        try {
            $config = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (!is_array($config)) {
            return [];
        }

        $config = $this->normalizeConfigAliases($config);

        $component = $topicMeta['component'];
        if ($component === self::DEVICE_DISCOVERY_COMPONENT) {
            return $this->parseDeviceDiscoveryMessage($topicMeta, $config, $topic);
        }

        if ($component === self::DEVICE_AUTOMATION_COMPONENT) {
            $parsed = $this->parseDeviceAutomationMessage($topicMeta, $config, $topic);
            return $parsed === null ? [] : [$parsed['unique_id'] => $parsed];
        }

        if (!in_array($component, self::SUPPORTED_COMPONENTS, true)) {
            return [];
        }

        $parsed = $this->parseSingleComponentMessage($topicMeta, $config, $topic);
        return [$parsed['unique_id'] => $parsed];
    }

    public function parseConfigMessages(array $records): array
    {
        $result = [];
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }

            $topic = $this->normalizeNullableString($record['topic'] ?? null);
            $payload = $record['payload'] ?? null;
            if ($topic === null || !is_string($payload) || trim($payload) === '') {
                continue;
            }

            foreach ($this->parseConfigRecord($topic, $payload) as $uniqueId => $parsed) {
                $result[$uniqueId] = $parsed;
            }
        }

        return $result;
    }

    public function analyzeConfigMessages(array $records): array
    {
        $entities = [];
        $diagnostics = $this->createDiagnostics();

        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }

            $topic = $this->normalizeNullableString($record['topic'] ?? null);
            if ($topic === null) {
                continue;
            }

            $topicMeta = $this->parseTopic($topic);
            if ($topicMeta === null) {
                continue;
            }

            $diagnostics['total_records']++;

            $payload = $record['payload'] ?? null;
            if (!is_string($payload) || trim($payload) === '') {
                $this->recordSkippedDiagnostic(
                    $diagnostics,
                    'empty_payload',
                    (string)($topicMeta['component'] ?? ''),
                    $this->buildDiagnosticExample((string)($topicMeta['component'] ?? ''), (string)($topicMeta['topic_object_id'] ?? ''), $topic)
                );
                continue;
            }

            try {
                $config = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $this->recordSkippedDiagnostic(
                    $diagnostics,
                    'invalid_json',
                    (string)($topicMeta['component'] ?? ''),
                    $this->buildDiagnosticExample((string)($topicMeta['component'] ?? ''), (string)($topicMeta['topic_object_id'] ?? ''), $topic)
                );
                continue;
            }

            if (!is_array($config)) {
                $this->recordSkippedDiagnostic(
                    $diagnostics,
                    'invalid_json_object',
                    (string)($topicMeta['component'] ?? ''),
                    $this->buildDiagnosticExample((string)($topicMeta['component'] ?? ''), (string)($topicMeta['topic_object_id'] ?? ''), $topic)
                );
                continue;
            }

            $config = $this->normalizeConfigAliases($config);
            foreach ($this->analyzeNormalizedConfigRecord($topicMeta, $config, $topic, $diagnostics) as $uniqueId => $parsed) {
                $entities[$uniqueId] = $parsed;
            }
        }

        $diagnostics['parsed_entities'] = count($entities);
        return [
            'entities' => $entities,
            'diagnostics' => $this->finalizeDiagnostics($diagnostics)
        ];
    }

    private function parseSingleComponentMessage(array $topicMeta, array $config, string $topic): array
    {
        $component = (string)($topicMeta['component'] ?? '');
        $device = $this->parseDevice($config['device'] ?? null);
        $objectId = $this->pickNonEmptyString($config['object_id'] ?? null, $topicMeta['topic_object_id']);
        $name = $this->deriveName($config, $objectId, $device['name']);

        return [
            'source'         => 'mqtt_discovery',
            'discovery_prefix' => $this->discoveryPrefix,
            'component'      => $component,
            'unique_id'      => $this->pickNonEmptyString($config['unique_id'] ?? null, $component . '.' . $objectId),
            'object_id'      => $objectId,
            'topic_object_id' => $topicMeta['topic_object_id'],
            'topic_node_id'  => $topicMeta['topic_node_id'],
            'name'           => $name,
            'entity_id_hint' => $component . '.' . $objectId,
            'device'         => $device,
            'transport'      => [
                'state_topic'           => $this->normalizeNullableString($config['state_topic'] ?? null),
                'command_topic'         => $this->normalizeNullableString($config['command_topic'] ?? null),
                'json_attributes_topic' => $this->normalizeNullableString($config['json_attributes_topic'] ?? null),
                'optimistic'            => (bool)($config['optimistic'] ?? false),
                'retain'                => (bool)($config['retain'] ?? false),
                'qos'                   => $this->normalizeQos($config['qos'] ?? null)
            ],
            'state'          => [
                'value_template'      => HAMqttDiscoveryTemplate::parseValueTemplate($this->normalizeNullableString($config['value_template'] ?? null)),
                'state_on'            => $config['state_on'] ?? null,
                'state_off'           => $config['state_off'] ?? null,
                'payload_on'          => $config['payload_on'] ?? null,
                'payload_off'         => $config['payload_off'] ?? null,
                'payload_press'       => $config['payload_press'] ?? null,
                'event_payload'       => null,
                'event_type'          => null,
                'event_types'         => [],
                'options'             => $this->normalizeStringList($config['options'] ?? null),
                'unit_of_measurement' => $this->normalizeNullableString($config['unit_of_measurement'] ?? null),
                'device_class'        => $this->normalizeNullableString($config['device_class'] ?? null),
                'state_class'         => $this->normalizeNullableString($config['state_class'] ?? null),
                'entity_category'     => $this->normalizeNullableString($config['entity_category'] ?? null),
                'enabled_by_default'  => !array_key_exists('enabled_by_default', $config) || (bool)$config['enabled_by_default'],
                'icon'                => $this->normalizeNullableString($config['icon'] ?? null),
                'brightness'          => (bool)($config['brightness'] ?? false),
                'brightness_scale'    => is_numeric($config['brightness_scale'] ?? null) ? (int)$config['brightness_scale'] : null,
                'effect'              => (bool)($config['effect'] ?? false),
                'effect_list'         => $this->normalizeStringList($config['effect_list'] ?? null),
                'supported_features'  => $component === HALightDefinitions::DOMAIN ? $this->buildLightSupportedFeatures($config) : null,
                'supported_color_modes' => $this->normalizeStringList($config['supported_color_modes'] ?? null),
                'min_mireds'          => is_numeric($config['min_mireds'] ?? null) ? (int)$config['min_mireds'] : null,
                'max_mireds'          => is_numeric($config['max_mireds'] ?? null) ? (int)$config['max_mireds'] : null,
                'min_color_temp_kelvin' => is_numeric($config['min_color_temp_kelvin'] ?? null) ? (int)$config['min_color_temp_kelvin'] : null,
                'max_color_temp_kelvin' => is_numeric($config['max_color_temp_kelvin'] ?? null) ? (int)$config['max_color_temp_kelvin'] : null,
                'schema'              => $this->normalizeNullableString($config['schema'] ?? null)
            ],
            'command'        => [
                'mode'             => $this->determineCommandMode($config),
                'command_template' => HAMqttDiscoveryTemplate::parseCommandTemplate($this->normalizeNullableString($config['command_template'] ?? null))
            ],
            'availability'   => $this->parseAvailability($config),
            'origin'         => $this->parseOrigin($config['origin'] ?? null),
            'raw'            => [
                'topic'  => $topic,
                'config' => $config
            ]
        ];
    }

    private function parseDeviceAutomationMessage(array $topicMeta, array $config, string $topic): ?array
    {
        $triggerTopic = $this->normalizeNullableString($config['topic'] ?? null);
        if ($triggerTopic === null) {
            return null;
        }

        $device = $this->parseDevice($config['device'] ?? null);
        $objectId = $this->pickNonEmptyString($config['object_id'] ?? null, $topicMeta['topic_object_id']);
        $eventType = $this->pickNonEmptyString($config['subtype'] ?? null, $objectId);
        $eventPayload = $this->pickNonEmptyString($config['payload'] ?? null, $eventType);
        $name = $this->deriveDeviceAutomationName($config, $eventType);
        $uniqueFallback = implode('.', array_filter([
            HAEventDefinitions::DOMAIN,
            $device['discovery_device_id'] !== '' ? $device['discovery_device_id'] : $topicMeta['topic_node_id'],
            $objectId
        ], static fn(string $part): bool => $part !== ''));

        return [
            'source'           => 'mqtt_discovery',
            'discovery_prefix' => $this->discoveryPrefix,
            'component'        => HAEventDefinitions::DOMAIN,
            'unique_id'        => $this->pickNonEmptyString($config['unique_id'] ?? null, $uniqueFallback),
            'object_id'        => $objectId,
            'topic_object_id'  => $topicMeta['topic_object_id'],
            'topic_node_id'    => $topicMeta['topic_node_id'],
            'name'             => $name,
            'entity_id_hint'   => HAEventDefinitions::DOMAIN . '.' . $objectId,
            'device'           => $device,
            'transport'        => [
                'state_topic'           => $triggerTopic,
                'command_topic'         => null,
                'json_attributes_topic' => null,
                'optimistic'            => false,
                'retain'                => false,
                'qos'                   => $this->normalizeQos($config['qos'] ?? null)
            ],
            'state'            => [
                'value_template'      => [
                    'kind'      => 'raw_value',
                    'path'      => [],
                    'filters'   => [],
                    'supported' => true,
                    'raw'       => '{{ value }}'
                ],
                'state_on'            => null,
                'state_off'           => null,
                'payload_on'          => null,
                'payload_off'         => null,
                'payload_press'       => null,
                'event_payload'       => $eventPayload,
                'event_type'          => $eventType,
                'event_types'         => [$eventType],
                'options'             => [],
                'unit_of_measurement' => null,
                'device_class'        => $this->normalizeNullableString($config['type'] ?? null),
                'state_class'         => null,
                'entity_category'     => null,
                'enabled_by_default'  => true,
                'icon'                => null
            ],
            'command'          => [
                'mode'             => 'none',
                'command_template' => null
            ],
            'availability'     => $this->parseAvailability($config),
            'origin'           => $this->parseOrigin($config['origin'] ?? null),
            'raw'              => [
                'topic'  => $topic,
                'config' => $config
            ]
        ];
    }

    private function parseDeviceDiscoveryMessage(array $topicMeta, array $config, string $topic): array
    {
        $components = $config['components'] ?? null;
        if (!is_array($components) || $components === []) {
            return [];
        }

        $result = [];
        $sharedConfig = $this->extractDeviceDiscoverySharedConfig($config);
        foreach ($components as $componentId => $componentConfig) {
            if (!is_string($componentId) || $componentId === '' || !is_array($componentConfig)) {
                continue;
            }

            $componentConfig = $this->normalizeConfigAliases($componentConfig);
            $platform = $this->normalizeNullableString($componentConfig['platform'] ?? null);
            if ($platform === null) {
                continue;
            }

            if ($platform !== self::DEVICE_AUTOMATION_COMPONENT && !in_array($platform, self::SUPPORTED_COMPONENTS, true)) {
                continue;
            }

            $mergedConfig = array_replace($sharedConfig, $componentConfig);
            $mergedConfig['device'] = $config['device'] ?? null;
            $mergedConfig['origin'] = $config['origin'] ?? null;
            $mergedConfig['object_id'] ??= $componentId;

            $nestedTopicMeta = [
                'component' => $platform,
                'topic_object_id' => $componentId,
                'topic_node_id' => $this->buildDeviceDiscoveryNodeId($topicMeta)
            ];

            if ($platform === self::DEVICE_AUTOMATION_COMPONENT) {
                $parsed = $this->parseDeviceAutomationMessage($nestedTopicMeta, $mergedConfig, $topic);
            } else {
                $parsed = $this->parseSingleComponentMessage($nestedTopicMeta, $mergedConfig, $topic);
            }

            if ($parsed === null) {
                continue;
            }

            $result[$parsed['unique_id']] = $parsed;
        }

        return $result;
    }

    private function analyzeNormalizedConfigRecord(array $topicMeta, array $config, string $topic, array &$diagnostics): array
    {
        $component = (string)($topicMeta['component'] ?? '');
        if ($component === self::DEVICE_DISCOVERY_COMPONENT) {
            return $this->analyzeDeviceDiscoveryMessage($topicMeta, $config, $topic, $diagnostics);
        }

        if ($component === self::DEVICE_AUTOMATION_COMPONENT) {
            $parsed = $this->parseDeviceAutomationMessage($topicMeta, $config, $topic);
            if ($parsed === null) {
                $this->recordSkippedDiagnostic(
                    $diagnostics,
                    'missing_topic',
                    self::DEVICE_AUTOMATION_COMPONENT,
                    $this->buildDiagnosticExample(self::DEVICE_AUTOMATION_COMPONENT, (string)($topicMeta['topic_object_id'] ?? ''), $topic)
                );
                return [];
            }

            return [$parsed['unique_id'] => $parsed];
        }

        if (!in_array($component, self::SUPPORTED_COMPONENTS, true)) {
            $this->recordUnsupportedDiagnostic(
                $diagnostics,
                $component,
                $this->buildDiagnosticExample($component, (string)($topicMeta['topic_object_id'] ?? ''), $topic)
            );
            return [];
        }

        $parsed = $this->parseSingleComponentMessage($topicMeta, $config, $topic);
        return [$parsed['unique_id'] => $parsed];
    }

    private function analyzeDeviceDiscoveryMessage(array $topicMeta, array $config, string $topic, array &$diagnostics): array
    {
        $components = $config['components'] ?? null;
        if (!is_array($components) || $components === []) {
            $this->recordSkippedDiagnostic(
                $diagnostics,
                'missing_components',
                self::DEVICE_DISCOVERY_COMPONENT,
                $this->buildDiagnosticExample(self::DEVICE_DISCOVERY_COMPONENT, (string)($topicMeta['topic_object_id'] ?? ''), $topic)
            );
            return [];
        }

        $result = [];
        $sharedConfig = $this->extractDeviceDiscoverySharedConfig($config);
        foreach ($components as $componentId => $componentConfig) {
            if (!is_string($componentId) || $componentId === '' || !is_array($componentConfig)) {
                $this->recordSkippedDiagnostic(
                    $diagnostics,
                    'invalid_component_entry',
                    self::DEVICE_DISCOVERY_COMPONENT,
                    $this->buildDiagnosticExample(self::DEVICE_DISCOVERY_COMPONENT, (string)$componentId, $topic)
                );
                continue;
            }

            $componentConfig = $this->normalizeConfigAliases($componentConfig);
            $platform = $this->normalizeNullableString($componentConfig['platform'] ?? null);
            if ($platform === null) {
                $this->recordSkippedDiagnostic(
                    $diagnostics,
                    'missing_platform',
                    self::DEVICE_DISCOVERY_COMPONENT,
                    $this->buildDiagnosticExample(self::DEVICE_DISCOVERY_COMPONENT, $componentId, $topic)
                );
                continue;
            }

            if ($platform !== self::DEVICE_AUTOMATION_COMPONENT && !in_array($platform, self::SUPPORTED_COMPONENTS, true)) {
                $this->recordUnsupportedDiagnostic(
                    $diagnostics,
                    $platform,
                    $this->buildDiagnosticExample($platform, $componentId, $topic)
                );
                continue;
            }

            $mergedConfig = array_replace($sharedConfig, $componentConfig);
            $mergedConfig['device'] = $config['device'] ?? null;
            $mergedConfig['origin'] = $config['origin'] ?? null;
            $mergedConfig['object_id'] ??= $componentId;

            $nestedTopicMeta = [
                'component' => $platform,
                'topic_object_id' => $componentId,
                'topic_node_id' => $this->buildDeviceDiscoveryNodeId($topicMeta)
            ];

            if ($platform === self::DEVICE_AUTOMATION_COMPONENT) {
                $parsed = $this->parseDeviceAutomationMessage($nestedTopicMeta, $mergedConfig, $topic);
                if ($parsed === null) {
                    $this->recordSkippedDiagnostic(
                        $diagnostics,
                        'missing_topic',
                        self::DEVICE_AUTOMATION_COMPONENT,
                        $this->buildDiagnosticExample(self::DEVICE_AUTOMATION_COMPONENT, $componentId, $topic)
                    );
                    continue;
                }
            } else {
                $parsed = $this->parseSingleComponentMessage($nestedTopicMeta, $mergedConfig, $topic);
            }

            $result[$parsed['unique_id']] = $parsed;
        }

        return $result;
    }

    private function parseTopic(string $topic): ?array
    {
        $parts = explode('/', trim($topic, '/'));
        $prefixParts = explode('/', trim($this->discoveryPrefix, '/'));
        if (count($parts) < (count($prefixParts) + 3)) {
            return null;
        }

        foreach ($prefixParts as $index => $prefixPart) {
            if (($parts[$index] ?? '') !== $prefixPart) {
                return null;
            }
        }

        $componentIndex = count($prefixParts);
        $component = $parts[$componentIndex] ?? '';
        if ($component === '') {
            return null;
        }

        if (($parts[count($parts) - 1] ?? '') !== 'config') {
            return null;
        }

        $objectPath = array_slice($parts, $componentIndex + 1, -1);
        if ($objectPath === []) {
            return null;
        }

        $topicObjectId = (string)end($objectPath);
        $topicNodeId = count($objectPath) > 1
            ? implode('/', array_slice($objectPath, 0, -1))
            : '';

        return [
            'component'      => $component,
            'topic_object_id' => $topicObjectId,
            'topic_node_id'  => $topicNodeId
        ];
    }

    private function parseDevice(mixed $device): array
    {
        if (!is_array($device)) {
            $identifiers = [];
            return [
                'discovery_device_id' => '',
                'identifiers'         => $identifiers,
                'name'                => '',
                'manufacturer'        => '',
                'model'               => '',
                'model_id'            => '',
                'hw_version'          => null,
                'sw_version'          => '',
                'via_device'          => ''
            ];
        }

        $device = $this->normalizeDeviceAliases($device);
        $identifiers = $this->normalizeDeviceIdentifiers($device['identifiers'] ?? null);
        return [
            'discovery_device_id' => $identifiers[0] ?? '',
            'identifiers'         => $identifiers,
            'name'                => $this->normalizeNullableString($device['name'] ?? null) ?? '',
            'manufacturer'        => $this->normalizeNullableString($device['manufacturer'] ?? null) ?? '',
            'model'               => $this->normalizeNullableString($device['model'] ?? null) ?? '',
            'model_id'            => $this->normalizeNullableString($device['model_id'] ?? null) ?? '',
            'hw_version'          => $device['hw_version'] ?? null,
            'sw_version'          => $this->normalizeNullableString($device['sw_version'] ?? null) ?? '',
            'via_device'          => $this->normalizeNullableString($device['via_device'] ?? null) ?? ''
        ];
    }

    private function parseAvailability(array $config): array
    {
        $entries = [];
        if (isset($config['availability']) && is_array($config['availability'])) {
            foreach ($config['availability'] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $topic = $this->normalizeNullableString($entry['topic'] ?? null);
                if ($topic === null) {
                    continue;
                }

                $entries[] = [
                    'topic'                 => $topic,
                    'value_template'        => HAMqttDiscoveryTemplate::parseValueTemplate($this->normalizeNullableString($entry['value_template'] ?? null)),
                    'payload_available'     => $entry['payload_available'] ?? 'online',
                    'payload_not_available' => $entry['payload_not_available'] ?? 'offline'
                ];
            }
        } elseif (isset($config['availability_topic'])) {
            $topic = $this->normalizeNullableString($config['availability_topic']);
            if ($topic !== null) {
                $entries[] = [
                    'topic'                 => $topic,
                    'value_template'        => HAMqttDiscoveryTemplate::parseValueTemplate($this->normalizeNullableString($config['availability_template'] ?? null)),
                    'payload_available'     => $config['payload_available'] ?? 'online',
                    'payload_not_available' => $config['payload_not_available'] ?? 'offline'
                ];
            }
        }

        return [
            'mode'    => $this->normalizeAvailabilityMode($config['availability_mode'] ?? null),
            'entries' => $entries
        ];
    }

    private function parseOrigin(mixed $origin): array
    {
        if (!is_array($origin)) {
            return [
                'name' => '',
                'sw'   => '',
                'url'  => ''
            ];
        }

        return [
            'name' => $this->normalizeNullableString($origin['name'] ?? null) ?? '',
            'sw'   => $this->normalizeNullableString($origin['sw'] ?? null) ?? '',
            'url'  => $this->normalizeNullableString($origin['url'] ?? null) ?? ''
        ];
    }

    private function buildLightSupportedFeatures(array $config): int
    {
        $features = 0;

        if (!empty($config['effect']) || $this->normalizeStringList($config['effect_list'] ?? null) !== []) {
            $features |= 4;
        }
        if (!empty($config['flash'])) {
            $features |= 8;
        }
        if (!empty($config['transition'])) {
            $features |= 32;
        }

        return $features;
    }

    private function normalizeConfigAliases(array $config): array
    {
        $config = $this->applyAliases($config, [
            'availability'         => 'avty',
            'availability_mode'    => 'avty_mode',
            'availability_topic'   => 'avty_t',
            'availability_template' => 'avty_tpl',
            'command_template'     => 'cmd_tpl',
            'command_topic'        => 'cmd_t',
            'device'               => 'dev',
            'device_class'         => 'dev_cla',
            'entity_category'      => 'ent_cat',
            'json_attributes_topic' => 'json_attr_t',
            'origin'               => 'o',
            'object_id'            => 'obj_id',
            'payload_available'    => 'pl_avail',
            'payload_not_available' => 'pl_not_avail',
            'payload_off'          => 'pl_off',
            'payload_on'           => 'pl_on',
            'payload_press'        => 'pl_prs',
            'platform'             => 'p',
            'state_class'          => 'stat_cla',
            'state_off'            => 'stat_off',
            'state_on'             => 'stat_on',
            'state_topic'          => 'stat_t',
            'unique_id'            => 'uniq_id',
            'unit_of_measurement'  => 'unit_of_meas',
            'value_template'       => 'val_tpl',
            'icon'                 => 'ic',
            'optimistic'           => 'opt',
            'retain'               => 'ret',
            'components'           => 'cmps'
        ]);

        if (isset($config['device']) && is_array($config['device'])) {
            $config['device'] = $this->normalizeDeviceAliases($config['device']);
        }

        if (isset($config['availability']) && is_array($config['availability'])) {
            $normalizedAvailability = [];
            foreach ($config['availability'] as $entry) {
                if (!is_array($entry)) {
                    $normalizedAvailability[] = $entry;
                    continue;
                }

                $normalizedAvailability[] = $this->applyAliases($entry, [
                    'payload_available'     => 'pl_avail',
                    'payload_not_available' => 'pl_not_avail',
                    'value_template'        => 'val_tpl'
                ]);
            }
            $config['availability'] = $normalizedAvailability;
        }

        $baseTopic = $this->normalizeNullableString($config['~'] ?? null);
        return $this->expandRelativeTopicReferences($config, $baseTopic);
    }

    private function extractDeviceDiscoverySharedConfig(array $config): array
    {
        $shared = [];
        foreach ([
            '~',
            'availability',
            'availability_mode',
            'availability_topic',
            'availability_template',
            'command_topic',
            'command_template',
            'encoding',
            'optimistic',
            'qos',
            'retain',
            'state_topic'
        ] as $key) {
            if (!array_key_exists($key, $config)) {
                continue;
            }

            $shared[$key] = $config[$key];
        }

        return $shared;
    }

    private function buildDeviceDiscoveryNodeId(array $topicMeta): string
    {
        $parts = [];
        $topicNodeId = trim((string)($topicMeta['topic_node_id'] ?? ''), '/');
        if ($topicNodeId !== '') {
            $parts[] = $topicNodeId;
        }

        $topicObjectId = trim((string)($topicMeta['topic_object_id'] ?? ''), '/');
        if ($topicObjectId !== '') {
            $parts[] = $topicObjectId;
        }

        return implode('/', $parts);
    }

    private function normalizeDeviceAliases(array $device): array
    {
        return $this->applyAliases($device, [
            'identifiers' => 'ids',
            'manufacturer' => 'mf',
            'model'       => 'mdl',
            'model_id'    => 'mdl_id',
            'sw_version'  => 'sw'
        ]);
    }

    private function applyAliases(array $data, array $aliases): array
    {
        foreach ($aliases as $canonicalKey => $aliasKey) {
            if (array_key_exists($canonicalKey, $data) || !array_key_exists($aliasKey, $data)) {
                continue;
            }

            $data[$canonicalKey] = $data[$aliasKey];
        }

        return $data;
    }

    private function expandRelativeTopicReferences(mixed $value, ?string $baseTopic, array $path = []): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $nestedValue) {
                $nextPath = $path;
                if (is_string($key)) {
                    $nextPath[] = $key;
                }

                $value[$key] = $this->expandRelativeTopicReferences($nestedValue, $baseTopic, $nextPath);
            }

            return $value;
        }

        if (!is_string($value)) {
            return $value;
        }

        $currentKey = $path[count($path) - 1] ?? null;
        if (!is_string($currentKey) || !$this->isTopicReferenceKey($currentKey, $path)) {
            return $value;
        }

        return $this->resolveRelativeTopicReference($value, $baseTopic) ?? $value;
    }

    private function isTopicReferenceKey(string $currentKey, array $path): bool
    {
        if (str_ends_with($currentKey, '_topic') || preg_match('/_t$/', $currentKey) === 1) {
            return true;
        }

        return $currentKey === 'topic'
            && (count($path) === 1 || in_array('availability', $path, true));
    }

    private function resolveRelativeTopicReference(mixed $value, ?string $baseTopic): ?string
    {
        $topic = $this->normalizeNullableString($value);
        if ($topic === null) {
            return null;
        }

        if (!str_starts_with($topic, '~/')) {
            return $topic;
        }

        $baseTopic = $this->normalizeNullableString($baseTopic);
        if ($baseTopic === null) {
            return $topic;
        }

        return trim($baseTopic, '/') . substr($topic, 1);
    }

    private function determineCommandMode(array $config): string
    {
        $commandTopic = $this->normalizeNullableString($config['command_topic'] ?? null);
        if ($commandTopic === null) {
            return 'none';
        }

        $commandTemplate = HAMqttDiscoveryTemplate::parseCommandTemplate($this->normalizeNullableString($config['command_template'] ?? null));
        if ($commandTemplate !== null) {
            return $commandTemplate['supported'] ? 'template' : 'raw_template';
        }

        return 'payload';
    }

    private function deriveName(array $config, string $objectId, string $deviceName): string
    {
        $explicitName = $this->normalizeNullableString($config['name'] ?? null);
        if ($explicitName !== null) {
            return $explicitName;
        }

        $trimmedObjectId = $objectId;
        $deviceSlug = $this->slugify($deviceName);
        if ($deviceSlug !== '' && str_starts_with($trimmedObjectId, $deviceSlug . '_')) {
            $trimmedObjectId = substr($trimmedObjectId, strlen($deviceSlug) + 1);
        }

        if ($trimmedObjectId === '') {
            $trimmedObjectId = $objectId;
        }

        $words = array_values(array_filter(explode('_', $trimmedObjectId), static fn(string $word): bool => $word !== ''));
        if ($words === []) {
            return $objectId;
        }

        $humanized = implode(' ', $words);
        return ucfirst($humanized);
    }

    private function deriveDeviceAutomationName(array $config, string $eventType): string
    {
        $explicitName = $this->normalizeNullableString($config['name'] ?? null);
        if ($explicitName !== null) {
            return $explicitName;
        }

        $triggerType = $this->normalizeNullableString($config['type'] ?? null);
        $humanizedEventType = $this->humanizeIdentifier($eventType);
        if ($triggerType !== null) {
            return ucfirst($triggerType) . ' ' . $humanizedEventType;
        }

        return $humanizedEventType;
    }

    private function humanizeIdentifier(string $value): string
    {
        $words = array_values(array_filter(explode('_', trim($value)), static fn(string $word): bool => $word !== ''));
        if ($words === []) {
            return $value;
        }

        return ucfirst(implode(' ', $words));
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? $value;
        return trim($value, '_');
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        return $value === '' ? null : $value;
    }

    private function normalizeStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            $item = $this->normalizeNullableString($item);
            if ($item === null) {
                continue;
            }
            $result[] = $item;
        }

        return $result;
    }

    private function normalizeDeviceIdentifiers(mixed $value): array
    {
        if (is_array($value)) {
            return $this->normalizeStringList($value);
        }

        $singleIdentifier = $this->normalizeNullableString($value);
        return $singleIdentifier === null ? [] : [$singleIdentifier];
    }

    private function normalizeQos(mixed $value): int
    {
        if (!is_numeric($value)) {
            return 0;
        }

        $qos = (int)$value;
        return max(0, min(2, $qos));
    }

    private function normalizeAvailabilityMode(mixed $value): string
    {
        $value = $this->normalizeNullableString($value);
        if ($value === null) {
            return 'latest';
        }

        $value = strtolower($value);
        return match ($value) {
            'all', 'any', 'latest' => $value,
            default => 'latest'
        };
    }

    private function pickNonEmptyString(mixed $preferred, string $fallback): string
    {
        $preferred = $this->normalizeNullableString($preferred);
        return $preferred ?? $fallback;
    }

    private function createDiagnostics(): array
    {
        return [
            'total_records' => 0,
            'parsed_entities' => 0,
            'unsupported' => [],
            'skipped' => []
        ];
    }

    private function recordUnsupportedDiagnostic(array &$diagnostics, string $component, string $example): void
    {
        $component = $component !== '' ? $component : 'unknown';
        if (!isset($diagnostics['unsupported'][$component])) {
            $diagnostics['unsupported'][$component] = [
                'component' => $component,
                'count' => 0,
                'examples' => []
            ];
        }

        $diagnostics['unsupported'][$component]['count']++;
        $this->appendDiagnosticExample($diagnostics['unsupported'][$component]['examples'], $example);
    }

    private function recordSkippedDiagnostic(array &$diagnostics, string $reason, string $component, string $example): void
    {
        $component = $component !== '' ? $component : 'unknown';
        $key = $reason . '|' . $component;
        if (!isset($diagnostics['skipped'][$key])) {
            $diagnostics['skipped'][$key] = [
                'reason' => $reason,
                'component' => $component,
                'count' => 0,
                'examples' => []
            ];
        }

        $diagnostics['skipped'][$key]['count']++;
        $this->appendDiagnosticExample($diagnostics['skipped'][$key]['examples'], $example);
    }

    private function appendDiagnosticExample(array &$examples, string $example): void
    {
        if ($example === '' || in_array($example, $examples, true)) {
            return;
        }

        $examples[] = $example;
        if (count($examples) > 3) {
            $examples = array_slice($examples, 0, 3);
        }
    }

    private function buildDiagnosticExample(string $component, string $objectId, string $topic): string
    {
        $component = trim($component);
        $objectId = trim($objectId);
        if ($component !== '' && $objectId !== '') {
            return $component . ':' . $objectId;
        }
        if ($objectId !== '') {
            return $objectId;
        }
        if ($component !== '') {
            return $component;
        }

        return $topic;
    }

    private function finalizeDiagnostics(array $diagnostics): array
    {
        $unsupported = array_values($diagnostics['unsupported']);
        usort($unsupported, static function (array $left, array $right): int {
            $countCompare = (int)($right['count'] ?? 0) <=> (int)($left['count'] ?? 0);
            if ($countCompare !== 0) {
                return $countCompare;
            }

            return strcmp((string)($left['component'] ?? ''), (string)($right['component'] ?? ''));
        });

        $skipped = array_values($diagnostics['skipped']);
        usort($skipped, static function (array $left, array $right): int {
            $countCompare = (int)($right['count'] ?? 0) <=> (int)($left['count'] ?? 0);
            if ($countCompare !== 0) {
                return $countCompare;
            }

            $reasonCompare = strcmp((string)($left['reason'] ?? ''), (string)($right['reason'] ?? ''));
            if ($reasonCompare !== 0) {
                return $reasonCompare;
            }

            return strcmp((string)($left['component'] ?? ''), (string)($right['component'] ?? ''));
        });

        return [
            'total_records' => (int)($diagnostics['total_records'] ?? 0),
            'parsed_entities' => (int)($diagnostics['parsed_entities'] ?? 0),
            'unsupported' => $unsupported,
            'skipped' => $skipped
        ];
    }
}
