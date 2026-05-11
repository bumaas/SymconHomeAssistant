<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/libs/HACommonIncludes.php';

const SUPPORTED_COMPONENTS = [
    HABinarySensorDefinitions::DOMAIN,
    HASensorDefinitions::DOMAIN,
    HASwitchDefinitions::DOMAIN,
    HASelectDefinitions::DOMAIN,
    HAButtonDefinitions::DOMAIN,
    'device_automation'
];

exit(main($argv));

function main(array $argv): int
{
    $fixturePaths = array_slice($argv, 1);
    if ($fixturePaths === []) {
        $fixturePaths = findDefaultFixtures();
    }

    if ($fixturePaths === []) {
        fwrite(STDERR, "Keine Fixture-Dateien gefunden.\n");
        fwrite(STDERR, "Aufruf: php tests/check-mqtt-discovery-fixtures.php <bundle1.json> [bundle2.json ...]\n");
        return 1;
    }

    $failed = false;
    foreach ($fixturePaths as $fixturePath) {
        try {
            $report = analyzeFixture($fixturePath);
            printReport($report);
            if ($report['errors'] !== []) {
                $failed = true;
            }
        } catch (Throwable $e) {
            $failed = true;
            fwrite(STDERR, "Fixture-Fehler [$fixturePath]: {$e->getMessage()}\n");
        }
    }

    return $failed ? 1 : 0;
}

function findDefaultFixtures(): array
{
    $paths = glob(dirname(__DIR__) . '/docs/fixtures/*.json');
    if ($paths === false) {
        return [];
    }

    sort($paths);
    return array_values(array_filter($paths, static fn(string $path): bool => is_file($path)));
}

function analyzeFixture(string $fixturePath): array
{
    if (!is_file($fixturePath)) {
        throw new RuntimeException('Datei nicht gefunden.');
    }

    $raw = file_get_contents($fixturePath);
    if ($raw === false) {
        throw new RuntimeException('Datei konnte nicht gelesen werden.');
    }

    $bundle = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($bundle)) {
        throw new RuntimeException('Bundle ist kein JSON-Objekt.');
    }

    $errors = [];
    if (($bundle['format'] ?? null) !== 'ha_mqtt_discovery_bundle') {
        $errors[] = 'Unerwartetes Bundle-Format.';
    }
    $bundleVersion = (int)($bundle['version'] ?? 0);
    if (!in_array($bundleVersion, [1, 2], true)) {
        $errors[] = 'Unerwartete Bundle-Version.';
    }

    $discoveryPrefix = normalizeString($bundle['splitter']['discovery_prefix'] ?? null) ?? 'homeassistant';
    $records = $bundle['discovery_configs'] ?? null;
    if (!is_array($records)) {
        throw new RuntimeException('discovery_configs fehlt oder ist kein Array.');
    }

    if ($bundleVersion === 2) {
        $referencedTopics = $bundle['referenced_topics'] ?? null;
        if (!is_array($referencedTopics)) {
            $errors[] = 'referenced_topics fehlt oder ist fuer Version 2 kein Array.';
        } else {
            foreach ($referencedTopics as $index => $topicEntry) {
                if (!is_array($topicEntry)) {
                    $errors[] = 'referenced_topics[' . $index . '] ist kein Objekt.';
                    continue;
                }

                $topic = normalizeString($topicEntry['topic'] ?? null);
                $status = normalizeString($topicEntry['status'] ?? null);
                $primaryKind = normalizeString($topicEntry['primary_kind'] ?? null);
                $kinds = $topicEntry['kinds'] ?? null;

                if ($topic === null) {
                    $errors[] = 'referenced_topics[' . $index . '] ohne topic.';
                }
                if (!in_array($status, ['current', 'stale', 'missing'], true)) {
                    $errors[] = 'referenced_topics[' . $index . '] mit ungueltigem status.';
                }
                if ($primaryKind === null) {
                    $errors[] = 'referenced_topics[' . $index . '] ohne primary_kind.';
                }
                if (!is_array($kinds) || $kinds === []) {
                    $errors[] = 'referenced_topics[' . $index . '] ohne kinds.';
                }
            }
        }
    }

    $parser = new HAMqttDiscoveryParser($discoveryPrefix);
    $grouping = new HAMqttDiscoveryGrouping();

    $entities = [];
    $componentCounts = [];
    $originCounts = [];
    $duplicateUniqueIds = [];
    $supportedParseFailures = [];
    $supportedRecordCount = 0;
    $supportedParsedCount = 0;
    $unsupportedRecordCount = 0;
    $writableEntityCount = 0;
    $entityOnlyGroupCandidates = 0;

    foreach ($records as $index => $record) {
        if (!is_array($record)) {
            $errors[] = 'Discovery-Record ist kein Array bei Index ' . $index . '.';
            continue;
        }

        $topic = normalizeString($record['topic'] ?? null);
        $payload = $record['payload'] ?? null;
        if ($topic === null || !is_string($payload) || trim($payload) === '') {
            $errors[] = 'Discovery-Record ohne gueltiges topic/payload bei Index ' . $index . '.';
            continue;
        }

        $component = extractTopicComponent($topic, $discoveryPrefix);
        $isSupportedComponent = $component !== null && in_array($component, SUPPORTED_COMPONENTS, true);
        if ($isSupportedComponent) {
            $supportedRecordCount++;
        } else {
            $unsupportedRecordCount++;
        }

        $parsed = $parser->parseConfigMessage($topic, $payload);
        if ($parsed === null) {
            if ($isSupportedComponent) {
                $supportedParseFailures[] = $topic;
            }
            continue;
        }

        $uniqueId = (string)($parsed['unique_id'] ?? '');
        if ($uniqueId === '') {
            $errors[] = 'Geparste Entity ohne unique_id fuer Topic ' . $topic . '.';
            continue;
        }

        if (isset($entities[$uniqueId])) {
            $duplicateUniqueIds[] = $uniqueId;
        }

        $entities[$uniqueId] = $parsed;
        $supportedParsedCount++;

        $parsedComponent = (string)($parsed['component'] ?? '');
        incrementCount($componentCounts, $parsedComponent);

        $originName = normalizeString($parsed['origin']['name'] ?? null) ?? '<leer>';
        incrementCount($originCounts, $originName);

        if ((string)($parsed['command']['mode'] ?? 'none') !== 'none') {
            $writableEntityCount++;
        }

        if ((string)($parsed['device']['discovery_device_id'] ?? '') === '') {
            $entityOnlyGroupCandidates++;
        }
    }

    if ($supportedRecordCount === 0) {
        $errors[] = 'Keine Discovery-Configs fuer aktuell unterstuetzte Komponenten gefunden.';
    }
    if ($supportedParseFailures !== []) {
        $errors[] = 'Unterstuetzte Discovery-Configs konnten nicht geparst werden: ' . implode(', ', array_slice($supportedParseFailures, 0, 5));
    }
    if ($entities === []) {
        $errors[] = 'Keine unterstuetzten Discovery-Entities geparst.';
    }

    $groups = $grouping->groupEntitiesToDevices(array_values($entities));
    if ($groups === []) {
        $errors[] = 'Keine Device-Gruppierung erzeugt.';
    }

    $deviceConfigs = [];
    $groupedEntityCount = 0;
    foreach ($groups as $group) {
        $deviceConfig = $grouping->buildDeviceConfig($group);
        $deviceConfigs[] = $deviceConfig;
        $groupedEntityCount += count($deviceConfig['entities'] ?? []);
    }

    if ($groupedEntityCount !== count($entities)) {
        $errors[] = 'Gruppierung enthaelt nicht alle geparsten Entities.';
    }

    return [
        'path' => $fixturePath,
        'bundle' => [
            'format' => (string)($bundle['format'] ?? ''),
            'version' => $bundleVersion,
            'exported_at' => (string)($bundle['exported_at'] ?? '')
        ],
        'diagnostics' => [
            'discovery_total' => (int)($bundle['diagnostics']['discovery_configs']['total'] ?? count($records)),
            'discovery_current_session' => (int)($bundle['diagnostics']['discovery_configs']['current_session'] ?? 0),
            'discovery_stale' => (int)($bundle['diagnostics']['discovery_configs']['stale'] ?? 0),
            'referenced_total' => (int)($bundle['diagnostics']['referenced_topic_payloads']['referenced'] ?? 0),
            'referenced_current_session' => (int)($bundle['diagnostics']['referenced_topic_payloads']['current_session'] ?? 0),
            'referenced_stale' => (int)($bundle['diagnostics']['referenced_topic_payloads']['stale'] ?? 0),
            'referenced_missing' => (int)($bundle['diagnostics']['referenced_topic_payloads']['missing'] ?? 0)
        ],
        'supported' => [
            'record_count' => $supportedRecordCount,
            'parsed_count' => count($entities),
            'parsed_record_count' => $supportedParsedCount,
            'unsupported_record_count' => $unsupportedRecordCount,
            'writable_entity_count' => $writableEntityCount,
            'entity_only_group_candidates' => $entityOnlyGroupCandidates
        ],
        'grouping' => [
            'device_count' => count($groups),
            'grouped_entity_count' => $groupedEntityCount
        ],
        'component_counts' => sortCountsDesc($componentCounts),
        'origin_counts' => sortCountsDesc($originCounts),
        'duplicate_unique_ids' => array_values(array_unique($duplicateUniqueIds)),
        'errors' => $errors
    ];
}

function printReport(array $report): void
{
    $path = $report['path'];
    $status = $report['errors'] === [] ? 'OK' : 'FEHLER';

    echo "=== $status: $path ===\n";
    echo 'Bundle: format=' . $report['bundle']['format'] . ', version=' . $report['bundle']['version'] . ', exported_at=' . $report['bundle']['exported_at'] . "\n";
    echo 'Diagnostics: discovery(total/current/stale)='
        . $report['diagnostics']['discovery_total'] . '/'
        . $report['diagnostics']['discovery_current_session'] . '/'
        . $report['diagnostics']['discovery_stale']
        . ', referenced(total/current/stale/missing)='
        . $report['diagnostics']['referenced_total'] . '/'
        . $report['diagnostics']['referenced_current_session'] . '/'
        . $report['diagnostics']['referenced_stale'] . '/'
        . $report['diagnostics']['referenced_missing'] . "\n";
    echo 'Supported: records='
        . $report['supported']['record_count']
        . ', parsed=' . $report['supported']['parsed_count']
        . ', unsupported_records=' . $report['supported']['unsupported_record_count']
        . ', writable=' . $report['supported']['writable_entity_count']
        . ', entity_only=' . $report['supported']['entity_only_group_candidates'] . "\n";
    echo 'Grouping: devices='
        . $report['grouping']['device_count']
        . ', grouped_entities=' . $report['grouping']['grouped_entity_count'] . "\n";
    echo 'Components: ' . formatCounts($report['component_counts']) . "\n";
    echo 'Origins: ' . formatCounts($report['origin_counts']) . "\n";

    if ($report['duplicate_unique_ids'] !== []) {
        echo 'Hinweis: doppelte unique_id(s): ' . implode(', ', array_slice($report['duplicate_unique_ids'], 0, 5)) . "\n";
    }

    foreach ($report['errors'] as $error) {
        echo 'ERROR: ' . $error . "\n";
    }

    echo "\n";
}

function extractTopicComponent(string $topic, string $discoveryPrefix): ?string
{
    $topicParts = explode('/', trim($topic, '/'));
    $prefixParts = explode('/', trim($discoveryPrefix, '/'));
    if (count($topicParts) < count($prefixParts) + 3) {
        return null;
    }

    foreach ($prefixParts as $index => $prefixPart) {
        if (($topicParts[$index] ?? '') !== $prefixPart) {
            return null;
        }
    }

    $component = $topicParts[count($prefixParts)] ?? '';
    return $component !== '' ? $component : null;
}

function incrementCount(array &$counts, string $key): void
{
    $counts[$key] = ($counts[$key] ?? 0) + 1;
}

function normalizeString(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $value = trim($value);
    return $value === '' ? null : $value;
}

function sortCountsDesc(array $counts): array
{
    arsort($counts);
    return $counts;
}

function formatCounts(array $counts): string
{
    if ($counts === []) {
        return '-';
    }

    $parts = [];
    foreach ($counts as $name => $count) {
        $parts[] = $name . '=' . $count;
    }

    return implode(', ', $parts);
}
