<?php

declare(strict_types=1);

// Prüft die Aggregationslogik der Topic-Statistik beider Splitter: Schlüssel = Topic ohne letztes
// Segment (alle Sub-Topics einer Entität zählen zusammen), danach Geräte-Gruppierung über den
// gemeinsamen Präfix (geteilter Kern HADomainCatalog::clusterByCommonPrefix) mit korrekten Summen.

require_once dirname(__DIR__) . '/libs/HADomainCatalog.php';

function statisticsKeyForTopic(string $topic): string
{
    $topic = trim($topic, '/');
    if ($topic === '') {
        return '';
    }
    $pos = strrpos($topic, '/');
    return $pos === false ? $topic : substr($topic, 0, $pos);
}

/** @return array<string, int> deviceKey => sum */
function aggregateDevices(array $counts): array
{
    // Pro Gerät gruppieren: Objekt-ID (letztes Segment des Entity-Keys) über gemeinsamen Präfix
    // clustern, damit z. B. alle marstek_*-Entitäten domainübergreifend in einer Zeile zusammenlaufen
    // (Clustering der vollen Keys würde am gemeinsamen "<base>/<domain>/" alles zusammenwerfen).
    $byObjectId = [];
    foreach ($counts as $entityKey => $n) {
        $pos = strrpos((string)$entityKey, '/');
        $objectId = $pos === false ? (string)$entityKey : substr((string)$entityKey, $pos + 1);
        $byObjectId[$objectId] = ($byObjectId[$objectId] ?? 0) + $n;
    }

    $objectIds = array_keys($byObjectId);
    sort($objectIds, SORT_STRING);
    $devices = [];
    foreach (HADomainCatalog::clusterByCommonPrefix($objectIds, 3) as $cluster) {
        $members = $cluster['members'];
        $deviceKey = count($members) >= 2 ? $cluster['prefix'] . '*' : ($members[0] ?? '');
        $sum = 0;
        foreach ($members as $m) {
            $sum += $byObjectId[$m] ?? 0;
        }
        $devices[$deviceKey] = ($devices[$deviceKey] ?? 0) + $sum;
    }
    arsort($devices);
    return $devices;
}

$fail = 0;
$check = static function (bool $ok, string $label) use (&$fail): void {
    echo ($ok ? 'OK   ' : 'FAIL ') . $label . "\n";
    $fail += $ok ? 0 : 1;
};

// Schlüssel-Extraktion: alle Sub-Topics einer Entität -> derselbe Schlüssel
$check(statisticsKeyForTopic('homeassistant/sensor/marstek_x/state') === 'homeassistant/sensor/marstek_x', 'Key state');
$check(statisticsKeyForTopic('homeassistant/sensor/marstek_x/unit_of_measurement') === 'homeassistant/sensor/marstek_x', 'Key attribute');
$check(statisticsKeyForTopic('/homeassistant/sensor/marstek_x/state') === 'homeassistant/sensor/marstek_x', 'Key führender Slash');
$check(statisticsKeyForTopic('') === '', 'Key leer');

// Simuliere ein Zähl-Fenster: jede Entität hat mehrere Sub-Topics gezählt.
$counts = [
    'homeassistant/sensor/marstek_venus_modbus_battery_soc'   => 40,
    'homeassistant/sensor/marstek_venus_modbus_battery_volt'  => 35,
    'homeassistant/sensor/marstek_venus_modbus_ac_power'      => 25,
    'homeassistant/sensor/evcc_pv_power'                      => 120,
    'homeassistant/sensor/evcc_home_power'                    => 100,
    'homeassistant/sensor/einzelgeraet_temperatur'           => 7,
];
$devices = aggregateDevices($counts);
echo "\nGeräte-Aggregation:\n";
foreach ($devices as $k => $v) {
    echo sprintf("  %-55s %d\n", $k, $v);
}

// marstek: 40+35+25=100, evcc: 120+100=220, einzelgeraet: Single 7
$check(($devices['marstek_venus_modbus_*'] ?? null) === 100, 'marstek Summe = 100');
$check(($devices['evcc_*'] ?? null) === 220, 'evcc Summe = 220');
$check(($devices['einzelgeraet_temperatur'] ?? null) === 7, 'Einzelgerät bleibt einzeln = 7');
$check(array_sum($devices) === array_sum($counts), 'Summe bleibt erhalten (keine Doppel-/Fehlzählung)');
// Höchstlast zuerst (arsort)
$check(array_key_first($devices) === 'evcc_*', 'Top-Verursacher = evcc');

echo "\n" . ($fail === 0 ? 'ALL OK' : "FAILURES: $fail") . "\n";
exit($fail === 0 ? 0 : 1);
