<?php

declare(strict_types=1);

// Prüft den gemeinsamen Kern der Topic-Statistik beider Splitter (libs/HATopicStatistics.php):
// Schlüssel = Topic ohne letztes Segment (alle Sub-Topics einer Entität zählen zusammen), Zählung von
// Messages, Payload-Bytes und größter Einzel-Payload, Geräte-Gruppierung über den gemeinsamen Präfix
// (HADomainCatalog::clusterByCommonPrefix) mit korrekten Summen sowie Upgrade alter Integer-Buffer.

require_once __DIR__ . '/harness.php';
require_once dirname(__DIR__) . '/libs/HADomainCatalog.php';
require_once dirname(__DIR__) . '/libs/HATopicStatistics.php';

$check = static function (bool $ok, string $label): void {
    pruefe($ok, $label);
};

// Schlüssel-Extraktion: alle Sub-Topics einer Entität -> derselbe Schlüssel
$check(HATopicStatistics::keyForTopic('homeassistant/sensor/marstek_x/state') === 'homeassistant/sensor/marstek_x', 'Key state');
$check(HATopicStatistics::keyForTopic('homeassistant/sensor/marstek_x/unit_of_measurement') === 'homeassistant/sensor/marstek_x', 'Key attribute');
$check(HATopicStatistics::keyForTopic('/homeassistant/sensor/marstek_x/state') === 'homeassistant/sensor/marstek_x', 'Key führender Slash');
$check(HATopicStatistics::keyForTopic('') === '', 'Key leer');

// Zählung im Hot-Path: Messages, Bytes, max je Entität; leerer Key wird ignoriert.
$counts = [];
$counts = HATopicStatistics::record($counts, 'homeassistant/sensor/jackery_soc/state', 2);
$counts = HATopicStatistics::record($counts, 'homeassistant/sensor/jackery_soc/attributes', 4000);
$counts = HATopicStatistics::record($counts, 'homeassistant/sensor/jackery_soc/state', 3);
$counts = HATopicStatistics::record($counts, '', 999);
$soc = $counts['homeassistant/sensor/jackery_soc'] ?? null;
$check($soc !== null && $soc['n'] === 3, 'record: 3 Messages je Entität');
$check($soc !== null && $soc['b'] === 4005, 'record: Bytes summiert (4005)');
$check($soc !== null && $soc['m'] === 4000, 'record: max Payload (4000)');
$check(count($counts) === 1, 'record: leerer Key wird ignoriert');

// Buffer-Roundtrip inkl. Upgrade alter Integer-Zähler (Modul-Reload mitten im Fenster).
$roundtrip = HATopicStatistics::decodeCounts(json_encode($counts, JSON_THROW_ON_ERROR));
$check($roundtrip === $counts, 'decodeCounts: JSON-Roundtrip identisch');
$legacy = HATopicStatistics::decodeCounts('{"homeassistant/sensor/alt":7}');
$check(($legacy['homeassistant/sensor/alt'] ?? null) === ['n' => 7, 'b' => 0, 'm' => 0], 'decodeCounts: alter Integer-Buffer wird hochgestuft');
$check(HATopicStatistics::decodeCounts('') === [], 'decodeCounts: leer');
$check(HATopicStatistics::decodeCounts('{kaputt') === [], 'decodeCounts: ungültiges JSON -> leer');

// Simuliere ein Zähl-Fenster: jede Entität hat mehrere Sub-Topics gezählt.
$e = static fn(int $n, int $b, int $m): array => ['n' => $n, 'b' => $b, 'm' => $m];
$counts = [
    'homeassistant/sensor/marstek_venus_modbus_battery_soc'   => $e(40, 400, 12),
    'homeassistant/sensor/marstek_venus_modbus_battery_volt'  => $e(35, 350, 15),
    'homeassistant/sensor/marstek_venus_modbus_ac_power'      => $e(25, 250, 11),
    'homeassistant/sensor/evcc_pv_power'                      => $e(120, 1200, 20),
    'homeassistant/sensor/evcc_home_power'                    => $e(100, 60000, 5000),
    'homeassistant/sensor/einzelgeraet_temperatur'           => $e(7, 70, 10),
];
$agg = HATopicStatistics::aggregate($counts);
$devices = $agg['devices'];
echo "\nGeräte-Aggregation:\n";
foreach ($devices as $k => $v) {
    echo HATopicStatistics::formatDeviceRow((string)$k, $v, 300) . "\n";
}

// marstek: 40+35+25=100, evcc: 120+100=220, einzelgeraet: Single 7
$check(($devices['marstek_venus_modbus_*']['n'] ?? null) === 100, 'marstek Summe = 100');
$check(($devices['marstek_venus_modbus_*']['b'] ?? null) === 1000, 'marstek Bytes = 1000');
$check(($devices['marstek_venus_modbus_*']['m'] ?? null) === 15, 'marstek max = 15');
$check(($devices['evcc_*']['n'] ?? null) === 220, 'evcc Summe = 220');
$check(($devices['evcc_*']['b'] ?? null) === 61200, 'evcc Bytes = 61200');
$check(($devices['evcc_*']['m'] ?? null) === 5000, 'evcc max = 5000');
$check(($devices['einzelgeraet_temperatur']['n'] ?? null) === 7, 'Einzelgerät bleibt einzeln = 7');
$check(array_sum(array_column($devices, 'n')) === $agg['total'], 'Summe bleibt erhalten (keine Doppel-/Fehlzählung)');
$check($agg['total'] === 327 && $agg['bytes'] === 62270 && $agg['max'] === 5000 && $agg['entities'] === 6, 'Gesamtwerte total/bytes/max/entities');
// Höchstlast zuerst (nach Messages)
$check(array_key_first($devices) === 'evcc_*', 'Top-Verursacher = evcc');
// Bytes-Spitzenreiter je Entität
$check(array_key_first($agg['topEntitiesByBytes']) === 'homeassistant/sensor/evcc_home_power', 'Bytes-Top-Entität = evcc_home_power');
$check(count($agg['topEntitiesByBytes']) === HATopicStatistics::TOP_ENTITIES_BYTES, 'Bytes-Top auf ' . HATopicStatistics::TOP_ENTITIES_BYTES . ' begrenzt');

// Formatierung
$check(HATopicStatistics::formatBytes(512) === '512 B', 'formatBytes B');
$check(HATopicStatistics::formatBytes(4915) === '4.8 KB', 'formatBytes KB');
$check(HATopicStatistics::formatBytes(1258291) === '1.2 MB', 'formatBytes MB');
$header = HATopicStatistics::formatHeader($agg, 300);
echo "\n$header\n";
$check(str_starts_with($header, 'Fenster 300s | total=327 (65.4/min) | 60.8 KB (208 B/s, max 4.9 KB) | Entitäten=6 | Geräte=3'), 'Kopfzeile');
$log = HATopicStatistics::formatLogLine($agg, 300);
echo "$log\n";
$check(str_starts_with($log, 'Topic-Statistik Fenster 300s'), 'Log-Zeile beginnt mit Präfix');
$check(str_contains($log, 'evcc_* 220 (44.0/min, 59.8 KB, max 4.9 KB)'), 'Log-Zeile enthält Geräte mit Bytes/max');
$check(str_contains($log, 'Bytes-Top: evcc_home_power 58.6 KB (max 4.9 KB)'), 'Log-Zeile enthält Bytes-Spitzenreiter');

// Leeres Fenster
$empty = HATopicStatistics::aggregate([]);
$check($empty['total'] === 0 && $empty['devices'] === [] && $empty['topEntitiesByBytes'] === [], 'leeres Fenster');

ergebnis();
