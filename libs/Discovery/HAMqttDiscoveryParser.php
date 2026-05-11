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
        HAButtonDefinitions::DOMAIN
    ];

    private const string DEVICE_AUTOMATION_COMPONENT = 'device_automation';

    public function __construct(
        private readonly string $discoveryPrefix = 'homeassistant'
    ) {
    }

    public function parseConfigMessage(string $topic, string $payload): ?array
    {
        $topicMeta = $this->parseTopic($topic);
        if ($topicMeta === null) {
            return null;
        }

        try {
            $config = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($config)) {
            return null;
        }

        $component = $topicMeta['component'];
        if ($component === self::DEVICE_AUTOMATION_COMPONENT) {
            return $this->parseDeviceAutomationMessage($topicMeta, $config, $topic);
        }

        if (!in_array($component, self::SUPPORTED_COMPONENTS, true)) {
            return null;
        }

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
                'icon'                => $this->normalizeNullableString($config['icon'] ?? null)
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

            $parsed = $this->parseConfigMessage($topic, $payload);
            if ($parsed === null) {
                continue;
            }

            $result[$parsed['unique_id']] = $parsed;
        }

        return $result;
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
}
