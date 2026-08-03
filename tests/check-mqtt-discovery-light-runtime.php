<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/libs/HACommonIncludes.php';

exit(main($argv));

function main(array $argv): int
{
    $fixturePaths = array_slice($argv, 1);
    if ($fixturePaths === []) {
        $fixturePaths = findDefaultFixtures();
    }

    if ($fixturePaths === []) {
        fwrite(STDERR, "Keine Light-Fixtures gefunden.\n");
        fwrite(STDERR, "Aufruf: php tests/check-mqtt-discovery-light-runtime.php <bundle1.json> [bundle2.json ...]\n");
        return 1;
    }

    $failed = false;
    foreach ($fixturePaths as $fixturePath) {
        try {
            $report = analyzeFixture($fixturePath);
            printReport($report);
        } catch (Throwable $e) {
            $failed = true;
            fwrite(STDERR, "Fixture-Fehler [$fixturePath]: {$e->getMessage()}\n");
        }
    }

    return $failed ? 1 : 0;
}

function findDefaultFixtures(): array
{
    $paths = glob(dirname(__DIR__) . '/tests/fixtures/*light*.json');
    if ($paths === false) {
        return [];
    }

    sort($paths);
    return array_values(array_filter($paths, static fn(string $path): bool => is_file($path)));
}

function analyzeFixture(string $fixturePath): array
{
    $bundle = loadBundle($fixturePath);
    $discoveryPrefix = normalizeString($bundle['splitter']['discovery_prefix'] ?? null) ?? 'homeassistant';

    $parser = new HAMqttDiscoveryParser($discoveryPrefix);
    $grouping = new HAMqttDiscoveryGrouping();

    $records = $bundle['discovery_configs'] ?? null;
    if (!is_array($records)) {
        throw new RuntimeException('discovery_configs fehlt oder ist kein Array.');
    }

    $entities = $parser->parseConfigMessages($records);
    $lightEntities = array_values(array_filter(
        $entities,
        static fn(array $entity): bool => (string)($entity['component'] ?? '') === HALightDefinitions::DOMAIN
    ));
    if ($lightEntities === []) {
        throw new RuntimeException('Keine geparsten light-Entities gefunden.');
    }

    $rawLightConfigs = buildRawLightConfigMap($records);
    assertParsedLightMetadata($lightEntities, $rawLightConfigs);

    $groups = $grouping->groupEntitiesToDevices($entities);
    $groupedLightRows = buildGroupedLightRowMap($groups, $grouping);
    assertGroupedLightMetadata($lightEntities, $groupedLightRows);

    $topicPayloads = buildTopicPayloadMap($bundle['topic_payloads'] ?? []);
    $runtimeSamples = assertRuntimeStateSamples($lightEntities, $topicPayloads);
    $attributeSamples = assertRuntimeAttributeSamples($lightEntities, $topicPayloads);
    assertCommandPayloads();
    assertAttributeCommandPayloads();

    return [
        'path' => $fixturePath,
        'light_count' => count($lightEntities),
        'grouped_light_count' => count($groupedLightRows),
        'runtime_samples' => $runtimeSamples,
        'attribute_samples' => $attributeSamples
    ];
}

function printReport(array $report): void
{
    echo '=== OK: ' . $report['path'] . " ===\n";
    echo 'Light runtime: parsed=' . $report['light_count']
        . ', grouped=' . $report['grouped_light_count']
        . ', runtime_samples=' . $report['runtime_samples']
        . ', attribute_samples=' . $report['attribute_samples'] . "\n";
}

function loadBundle(string $fixturePath): array
{
    if (!is_file($fixturePath)) {
        throw new RuntimeException('Fixture-Datei nicht gefunden.');
    }

    $raw = file_get_contents($fixturePath);
    if ($raw === false) {
        throw new RuntimeException('Fixture-Datei konnte nicht gelesen werden.');
    }

    $bundle = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($bundle)) {
        throw new RuntimeException('Fixture-Datei ist kein gueltiges JSON-Objekt.');
    }

    return $bundle;
}

function buildRawLightConfigMap(array $records): array
{
    $map = [];
    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }

        $topic = (string)($record['topic'] ?? '');
        if (!str_starts_with($topic, 'homeassistant/light/')) {
            continue;
        }

        $payload = $record['payload'] ?? null;
        if (!is_string($payload) || trim($payload) === '') {
            continue;
        }

        $config = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($config)) {
            continue;
        }

        $uniqueId = normalizeString($config['unique_id'] ?? null);
        if ($uniqueId === null) {
            continue;
        }

        $map[$uniqueId] = $config;
    }

    return $map;
}

function buildGroupedLightRowMap(array $groups, HAMqttDiscoveryGrouping $grouping): array
{
    $rows = [];
    foreach ($groups as $group) {
        if (!is_array($group)) {
            continue;
        }

        $deviceConfig = $grouping->buildDeviceConfig($group);
        foreach (($deviceConfig['entities'] ?? []) as $entity) {
            if (!is_array($entity) || (string)($entity['component'] ?? '') !== HALightDefinitions::DOMAIN) {
                continue;
            }

            $entityKey = (string)($entity['entity_key'] ?? '');
            if ($entityKey === '') {
                continue;
            }

            $rows[$entityKey] = $entity;
        }
    }

    return $rows;
}

function buildTopicPayloadMap(array $topicPayloads): array
{
    $map = [];
    foreach ($topicPayloads as $row) {
        if (!is_array($row)) {
            continue;
        }

        $topic = normalizeString($row['topic'] ?? null);
        $payload = $row['payload'] ?? null;
        if ($topic === null || !is_string($payload)) {
            continue;
        }

        $map[$topic] = $payload;
    }

    return $map;
}

function assertParsedLightMetadata(array $lightEntities, array $rawLightConfigs): void
{
    foreach ($lightEntities as $entity) {
        $uniqueId = (string)($entity['unique_id'] ?? '');
        if ($uniqueId === '' || !isset($rawLightConfigs[$uniqueId])) {
            throw new RuntimeException('Raw-Light-Config fuer UniqueID fehlt: ' . $uniqueId);
        }

        $config = $rawLightConfigs[$uniqueId];
        $state = $entity['state'] ?? [];
        if (!is_array($state)) {
            throw new RuntimeException('State-Struktur fehlt fuer Light ' . $uniqueId);
        }

        assertSameValue(
            normalizeString($config['schema'] ?? null),
            normalizeString($state['schema'] ?? null),
            'schema mismatch fuer ' . $uniqueId
        );
        assertSameValue(
            (bool)($config['brightness'] ?? false),
            (bool)($state['brightness'] ?? false),
            'brightness mismatch fuer ' . $uniqueId
        );
        assertSameValue(
            is_numeric($config['brightness_scale'] ?? null) ? (int)$config['brightness_scale'] : null,
            is_numeric($state['brightness_scale'] ?? null) ? (int)$state['brightness_scale'] : null,
            'brightness_scale mismatch fuer ' . $uniqueId
        );
        assertSameValue(
            normalizeOptions($config['supported_color_modes'] ?? null),
            normalizeOptions($state['supported_color_modes'] ?? null),
            'supported_color_modes mismatch fuer ' . $uniqueId
        );
        assertSameValue(
            normalizeOptions($config['effect_list'] ?? null),
            normalizeOptions($state['effect_list'] ?? null),
            'effect_list mismatch fuer ' . $uniqueId
        );

        if (normalizeOptions($config['effect_list'] ?? null) !== []) {
            $supportedFeatures = (int)($state['supported_features'] ?? 0);
            if (($supportedFeatures & 4) === 0) {
                throw new RuntimeException('supported_features enthaelt kein Effect-Bit fuer ' . $uniqueId);
            }
        }
    }
}

function assertGroupedLightMetadata(array $lightEntities, array $groupedLightRows): void
{
    foreach ($lightEntities as $entity) {
        $entityKey = (string)($entity['unique_id'] ?? '');
        if ($entityKey === '' || !isset($groupedLightRows[$entityKey])) {
            throw new RuntimeException('Grouped-Light-Row fehlt fuer ' . $entityKey);
        }

        $row = $groupedLightRows[$entityKey];
        $metadata = $row['metadata'] ?? [];
        $state = $entity['state'] ?? [];
        if (!is_array($metadata) || !is_array($state)) {
            throw new RuntimeException('Metadaten fehlen fuer ' . $entityKey);
        }

        foreach ([
            'brightness_scale',
            'supported_features',
            'min_mireds',
            'max_mireds',
            'min_color_temp_kelvin',
            'max_color_temp_kelvin',
            'schema'
        ] as $field) {
            assertSameValue($state[$field] ?? null, $metadata[$field] ?? null, $field . ' mismatch fuer ' . $entityKey);
        }

        assertSameValue(
            normalizeOptions($state['supported_color_modes'] ?? null),
            normalizeOptions($metadata['supported_color_modes'] ?? null),
            'grouped supported_color_modes mismatch fuer ' . $entityKey
        );
        assertSameValue(
            normalizeOptions($state['effect_list'] ?? null),
            normalizeOptions($metadata['effect_list'] ?? null),
            'grouped effect_list mismatch fuer ' . $entityKey
        );
    }
}

function assertRuntimeStateSamples(array $lightEntities, array $topicPayloads): int
{
    $samples = 0;
    foreach ($lightEntities as $entity) {
        $stateTopic = normalizeString($entity['transport']['state_topic'] ?? null);
        if ($stateTopic === null || !isset($topicPayloads[$stateTopic])) {
            continue;
        }

        $decoded = json_decode($topicPayloads[$stateTopic], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !array_key_exists('state', $decoded)) {
            continue;
        }

        $actual = HAMqttDiscoveryLightRuntime::extractStateValue($decoded);
        assertSameValue($decoded['state'], $actual, 'Runtime-State-Extraktion mismatch fuer Topic ' . $stateTopic);
        $samples++;
    }

    if ($samples === 0) {
        throw new RuntimeException('Keine verwertbaren Light-Runtime-Samples gefunden.');
    }

    return $samples;
}

function assertRuntimeAttributeSamples(array $lightEntities, array $topicPayloads): int
{
    $samples = 0;
    $brightnessSamples = 0;
    $colorTempSamples = 0;
    $xySamples = 0;

    foreach ($lightEntities as $entity) {
        $stateTopic = normalizeString($entity['transport']['state_topic'] ?? null);
        if ($stateTopic === null || !isset($topicPayloads[$stateTopic])) {
            continue;
        }

        $decoded = json_decode($topicPayloads[$stateTopic], true, 512, JSON_THROW_ON_ERROR);
        $attributes = HAMqttDiscoveryLightRuntime::extractAttributes($decoded, $entity['state'] ?? []);
        if ($attributes === []) {
            continue;
        }

        if (array_key_exists('brightness', $decoded)) {
            assertSameValue((int) $decoded['brightness'], $attributes['brightness'] ?? null, 'brightness mismatch fuer Topic ' . $stateTopic);
            $brightnessSamples++;
        }

        if (array_key_exists('color_temp', $decoded)) {
            assertSameValue((int) $decoded['color_temp'], $attributes['color_temp'] ?? null, 'color_temp mismatch fuer Topic ' . $stateTopic);
            $colorTempSamples++;
        }

        if (is_array($decoded['color'] ?? null) && array_key_exists('x', $decoded['color']) && array_key_exists('y', $decoded['color'])) {
            assertSameValue(
                [(float) $decoded['color']['x'], (float) $decoded['color']['y']],
                $attributes['xy_color'] ?? null,
                'xy_color mismatch fuer Topic ' . $stateTopic
            );
            $xySamples++;
        }

        $samples++;
    }

    if ($samples === 0 || $brightnessSamples === 0 || $colorTempSamples === 0 || $xySamples === 0) {
        throw new RuntimeException('Light-Attributsamples sind unvollstaendig.');
    }

    return $samples;
}

function assertCommandPayloads(): void
{
    foreach ([
        [true, '{"state":"ON"}'],
        [false, '{"state":"OFF"}'],
        ['on', '{"state":"ON"}'],
        ['OFF', '{"state":"OFF"}'],
        [1, '{"state":"ON"}'],
        [0, '{"state":"OFF"}']
    ] as [$input, $expected]) {
        $actual = HAMqttDiscoveryLightRuntime::buildCommandPayload($input);
        assertSameValue($expected, $actual, 'Command-Payload mismatch fuer Input ' . var_export($input, true));
    }

    if (HAMqttDiscoveryLightRuntime::buildCommandPayload('maybe') !== null) {
        throw new RuntimeException('Ungueltiger Light-Command wurde nicht verworfen.');
    }
}

function assertAttributeCommandPayloads(): void
{
    assertSameValue('{"brightness":12}', HAMqttDiscoveryLightRuntime::buildAttributeCommandPayload('brightness', 12), 'brightness command mismatch');
    assertSameValue('{"color_temp":370}', HAMqttDiscoveryLightRuntime::buildAttributeCommandPayload('color_temp', 370), 'color_temp command mismatch');
    assertSameValue('{"color_temp":370}', HAMqttDiscoveryLightRuntime::buildAttributeCommandPayload('color_temp_kelvin', 2703), 'color_temp_kelvin command mismatch');
    assertSameValue('{"effect":"blink"}', HAMqttDiscoveryLightRuntime::buildAttributeCommandPayload('effect', 'blink'), 'effect command mismatch');

    // Seit Build 125/130 sind xy/hs schreibbar: HA-JSON-Schema erwartet ein verschachteltes "color"-Objekt.
    assertSameValue('{"color":{"x":0.1,"y":0.2}}', HAMqttDiscoveryLightRuntime::buildAttributeCommandPayload('xy_color', '[0.1,0.2]'), 'xy_color command mismatch');
    assertSameValue('{"color":{"h":30.0,"s":40.0}}', HAMqttDiscoveryLightRuntime::buildAttributeCommandPayload('hs_color', '[30,40]'), 'hs_color command mismatch');

    if (HAMqttDiscoveryLightRuntime::buildAttributeCommandPayload('xy_color', 'kaputt') !== null) {
        throw new RuntimeException('Unparsbarer xy_color-Wert wurde nicht verworfen.');
    }
}

function normalizeOptions(mixed $options): array
{
    return HASelectDefinitions::normalizeOptions($options);
}

function normalizeString(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $value = trim($value);
    return $value === '' ? null : $value;
}

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' (expected=' . formatValue($expected) . ', actual=' . formatValue($actual) . ')');
    }
}

function formatValue(mixed $value): string
{
    if (is_scalar($value) || $value === null) {
        return var_export($value, true);
    }

    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
