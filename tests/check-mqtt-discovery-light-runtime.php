<?php

declare(strict_types=1);

require_once __DIR__ . '/harness.php';
require_once dirname(__DIR__) . '/libs/HACommonIncludes.php';

/** Abbruch der laufenden Fixture nach einer bereits per pruefe() gezählten Fehlprüfung. */
final class FixtureAbbruch extends RuntimeException
{
}

main($argv);
ergebnis();

function main(array $argv): void
{
    $fixturePaths = array_slice($argv, 1);
    if ($fixturePaths === []) {
        $fixturePaths = findDefaultFixtures();
    }

    if (!pruefe($fixturePaths !== [], 'Light-Fixtures gefunden')) {
        fwrite(STDERR, "Aufruf: php tests/check-mqtt-discovery-light-runtime.php <bundle1.json> [bundle2.json ...]\n");
        ergebnis();
    }

    foreach ($fixturePaths as $fixturePath) {
        echo '=== ' . $fixturePath . " ===\n";
        try {
            $report = analyzeFixture($fixturePath);
            printReport($report);
        } catch (FixtureAbbruch) {
            echo "  (Fixture nach Fehlprüfung abgebrochen)\n";
        } catch (Throwable $e) {
            pruefe(false, "Fixture-Fehler [$fixturePath]", $e->getMessage());
        }
    }
}

/** Zählt eine Prüfung; bei Fehlschlag wird die laufende Fixture abgebrochen (wie früher per Exception). */
function bestehe(bool $ok, string $text, string $detail = ''): void
{
    if (!pruefe($ok, $text, $detail, true)) {
        throw new FixtureAbbruch($text);
    }
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
    bestehe(is_array($records), 'discovery_configs vorhanden und ein Array');

    $entities = $parser->parseConfigMessages($records);
    $lightEntities = array_values(array_filter(
        $entities,
        static fn(array $entity): bool => (string)($entity['component'] ?? '') === HALightDefinitions::DOMAIN
    ));
    bestehe($lightEntities !== [], 'geparste light-Entities vorhanden');

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
    echo 'Light runtime: parsed=' . $report['light_count']
        . ', grouped=' . $report['grouped_light_count']
        . ', runtime_samples=' . $report['runtime_samples']
        . ', attribute_samples=' . $report['attribute_samples'] . "\n";
}

function loadBundle(string $fixturePath): array
{
    bestehe(is_file($fixturePath), 'Fixture-Datei vorhanden');

    $raw = file_get_contents($fixturePath);
    bestehe($raw !== false, 'Fixture-Datei lesbar');

    $bundle = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    bestehe(is_array($bundle), 'Fixture-Datei ist ein gültiges JSON-Objekt');

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
        bestehe($uniqueId !== '' && isset($rawLightConfigs[$uniqueId]), 'Raw-Light-Config vorhanden für UniqueID ' . $uniqueId);

        $config = $rawLightConfigs[$uniqueId];
        $state = $entity['state'] ?? [];
        bestehe(is_array($state), 'State-Struktur vorhanden für Light ' . $uniqueId);

        assertSameValue(
            normalizeString($config['schema'] ?? null),
            normalizeString($state['schema'] ?? null),
            'schema stimmt überein für ' . $uniqueId
        );
        assertSameValue(
            (bool)($config['brightness'] ?? false),
            (bool)($state['brightness'] ?? false),
            'brightness stimmt überein für ' . $uniqueId
        );
        assertSameValue(
            is_numeric($config['brightness_scale'] ?? null) ? (int)$config['brightness_scale'] : null,
            is_numeric($state['brightness_scale'] ?? null) ? (int)$state['brightness_scale'] : null,
            'brightness_scale stimmt überein für ' . $uniqueId
        );
        assertSameValue(
            normalizeOptions($config['supported_color_modes'] ?? null),
            normalizeOptions($state['supported_color_modes'] ?? null),
            'supported_color_modes stimmt überein für ' . $uniqueId
        );
        assertSameValue(
            normalizeOptions($config['effect_list'] ?? null),
            normalizeOptions($state['effect_list'] ?? null),
            'effect_list stimmt überein für ' . $uniqueId
        );

        if (normalizeOptions($config['effect_list'] ?? null) !== []) {
            $supportedFeatures = (int)($state['supported_features'] ?? 0);
            bestehe(($supportedFeatures & 4) !== 0, 'supported_features enthält das Effect-Bit für ' . $uniqueId);
        }
    }
}

function assertGroupedLightMetadata(array $lightEntities, array $groupedLightRows): void
{
    foreach ($lightEntities as $entity) {
        $entityKey = (string)($entity['unique_id'] ?? '');
        bestehe($entityKey !== '' && isset($groupedLightRows[$entityKey]), 'Grouped-Light-Row vorhanden für ' . $entityKey);

        $row = $groupedLightRows[$entityKey];
        $metadata = $row['metadata'] ?? [];
        $state = $entity['state'] ?? [];
        bestehe(is_array($metadata) && is_array($state), 'Metadaten vorhanden für ' . $entityKey);

        foreach ([
            'brightness_scale',
            'supported_features',
            'min_mireds',
            'max_mireds',
            'min_color_temp_kelvin',
            'max_color_temp_kelvin',
            'schema'
        ] as $field) {
            assertSameValue($state[$field] ?? null, $metadata[$field] ?? null, 'gruppiert: ' . $field . ' stimmt überein für ' . $entityKey);
        }

        assertSameValue(
            normalizeOptions($state['supported_color_modes'] ?? null),
            normalizeOptions($metadata['supported_color_modes'] ?? null),
            'gruppiert: supported_color_modes stimmt überein für ' . $entityKey
        );
        assertSameValue(
            normalizeOptions($state['effect_list'] ?? null),
            normalizeOptions($metadata['effect_list'] ?? null),
            'gruppiert: effect_list stimmt überein für ' . $entityKey
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
        assertSameValue($decoded['state'], $actual, 'Runtime-State-Extraktion stimmt für Topic ' . $stateTopic);
        $samples++;
    }

    bestehe($samples !== 0, 'verwertbare Light-Runtime-Samples vorhanden');

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
            assertSameValue((int) $decoded['brightness'], $attributes['brightness'] ?? null, 'Attribut brightness stimmt für Topic ' . $stateTopic);
            $brightnessSamples++;
        }

        if (array_key_exists('color_temp', $decoded)) {
            assertSameValue((int) $decoded['color_temp'], $attributes['color_temp'] ?? null, 'Attribut color_temp stimmt für Topic ' . $stateTopic);
            $colorTempSamples++;
        }

        if (is_array($decoded['color'] ?? null) && array_key_exists('x', $decoded['color']) && array_key_exists('y', $decoded['color'])) {
            assertSameValue(
                [(float) $decoded['color']['x'], (float) $decoded['color']['y']],
                $attributes['xy_color'] ?? null,
                'Attribut xy_color stimmt für Topic ' . $stateTopic
            );
            $xySamples++;
        }

        $samples++;
    }

    bestehe(
        !($samples === 0 || $brightnessSamples === 0 || $colorTempSamples === 0 || $xySamples === 0),
        'Light-Attributsamples vollständig (brightness, color_temp, xy_color)',
        "samples=$samples, brightness=$brightnessSamples, color_temp=$colorTempSamples, xy=$xySamples"
    );

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
        assertSameValue($expected, $actual, 'Command-Payload stimmt für Input ' . var_export($input, true));
    }

    bestehe(HAMqttDiscoveryLightRuntime::buildCommandPayload('maybe') === null, 'ungültiger Light-Command wird verworfen');
}

function assertAttributeCommandPayloads(): void
{
    assertSameValue('{"brightness":12}', HAMqttDiscoveryLightRuntime::buildAttributeCommandPayload('brightness', 12), 'brightness-Command-Payload stimmt');
    assertSameValue('{"color_temp":370}', HAMqttDiscoveryLightRuntime::buildAttributeCommandPayload('color_temp', 370), 'color_temp-Command-Payload stimmt');
    assertSameValue('{"color_temp":370}', HAMqttDiscoveryLightRuntime::buildAttributeCommandPayload('color_temp_kelvin', 2703), 'color_temp_kelvin-Command-Payload stimmt');
    assertSameValue('{"effect":"blink"}', HAMqttDiscoveryLightRuntime::buildAttributeCommandPayload('effect', 'blink'), 'effect-Command-Payload stimmt');

    // Seit Build 125/130 sind xy/hs schreibbar: HA-JSON-Schema erwartet ein verschachteltes "color"-Objekt.
    assertSameValue('{"color":{"x":0.1,"y":0.2}}', HAMqttDiscoveryLightRuntime::buildAttributeCommandPayload('xy_color', '[0.1,0.2]'), 'xy_color-Command-Payload stimmt');
    assertSameValue('{"color":{"h":30.0,"s":40.0}}', HAMqttDiscoveryLightRuntime::buildAttributeCommandPayload('hs_color', '[30,40]'), 'hs_color-Command-Payload stimmt');

    bestehe(HAMqttDiscoveryLightRuntime::buildAttributeCommandPayload('xy_color', 'kaputt') === null, 'unparsbarer xy_color-Wert wird verworfen');
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
    bestehe(
        $expected === $actual,
        $message,
        'erwartet=' . formatValue($expected) . ', erhalten=' . formatValue($actual)
    );
}

function formatValue(mixed $value): string
{
    if (is_scalar($value) || $value === null) {
        return var_export($value, true);
    }

    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
