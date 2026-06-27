<?php

declare(strict_types=1);

// Prueft den geteilten Cluster-Kern (HADomainCatalog::clusterByCommonPrefix / longestCommonStringPrefix)
// und die zwei Anwendungsformen:
//  - Legacy (HADeviceCore): Gruppierung nach <base>/<domain>, Kollaps der Objekt-IDs, Kodierung "\\?\/".
//  - Discovery (MQTT Discovery Device): generelles Praefix-Clustering ueber volle Topics, Kodierung "(?:\\/|/)".
// Verifiziert Kollaps, Trennung mehrerer Namensfamilien, Matching inkl. escaped Slashes, Reject fremder
// Geraete und den Fallback bei heterogenen Namen.

require_once dirname(__DIR__) . '/libs/HADomainCatalog.php';

const MIN_PREFIX = 3;

// --- Legacy-Pfad: Gruppierung nach <base>/<domain>, Kodierung "\\?\/", Subtopic-Suffix ---
function encodeLegacy(string $topic): string
{
    return str_replace('\/', '\\\\?\/', preg_quote($topic, '/'));
}

function legacyParts(array $topics): array
{
    $groups = [];
    foreach ($topics as $t) {
        if ($t === '') {
            continue;
        }
        $pos = strrpos($t, '/');
        $groupKey = $pos === false ? '' : substr($t, 0, $pos);
        $name = $pos === false ? $t : substr($t, $pos + 1);
        $groups[$groupKey][$name] = true;
    }

    $parts = [];
    foreach ($groups as $groupKey => $nameSet) {
        $names = array_keys($nameSet);
        sort($names, SORT_STRING);
        foreach (HADomainCatalog::clusterByCommonPrefix($names, MIN_PREFIX) as $cluster) {
            $members = $cluster['members'];
            $prefix = $cluster['prefix'];
            if (count($members) >= 2 && strlen($prefix) >= MIN_PREFIX) {
                $base = $groupKey === '' ? $prefix : $groupKey . '/' . $prefix;
                $parts[] = encodeLegacy($base) . '[^"]*';
                continue;
            }
            foreach ($members as $name) {
                $full = $groupKey === '' ? $name : $groupKey . '/' . $name;
                $parts[] = encodeLegacy($full) . '(\\\\?\/[^"]*)?';
            }
        }
    }
    return $parts;
}

// --- Discovery-Pfad: generelles Clustering ueber volle Topics, Kodierung "(?:\\/|/)" ---
function encodeDiscovery(string $topic): string
{
    return str_replace('\/', '(?:\\\\/|/)', preg_quote($topic, '/'));
}

function discoveryParts(array $topics): array
{
    $names = array_values(array_unique(array_filter($topics, static fn(string $t): bool => $t !== '')));
    sort($names, SORT_STRING);

    $parts = [];
    foreach (HADomainCatalog::clusterByCommonPrefix($names, MIN_PREFIX) as $cluster) {
        $members = $cluster['members'];
        $prefix = $cluster['prefix'];
        if (count($members) >= 2 && strlen($prefix) >= MIN_PREFIX) {
            $parts[] = encodeDiscovery($prefix) . '[^"]*';
            continue;
        }
        foreach ($members as $name) {
            $parts[] = encodeDiscovery($name);
        }
    }
    return $parts;
}

function rxMatch(array $parts, string $glue, string $topic): bool
{
    $filter = '.*"Topic":"(' . $glue . implode('|', $parts) . ')".*';
    return preg_match('~' . $filter . '~', '{"DataID":"x","Topic":"' . $topic . '"}') === 1;
}

$fail = 0;
$check = static function (bool $ok, string $label) use (&$fail): void {
    echo ($ok ? 'OK   ' : 'FAIL ') . $label . "\n";
    $fail += $ok ? 0 : 1;
};

// ===== Legacy: zwei Namensfamilien in sensor + update-Single + event-Cluster =====
$legacyTopics = [
    'homeassistant/sensor/gast_gast_licht_fernbedienung_batterie',
    'homeassistant/sensor/gast_gast_licht_fernbedienung_batteriespannung',
    'homeassistant/sensor/gast_gast_licht_fernbedienung_batterietyp',
    'homeassistant/sensor/bilresa_scroll_wheel_aktuelle_schalterstellung_1',
    'homeassistant/sensor/bilresa_scroll_wheel_aktuelle_schalterstellung_2',
    'homeassistant/sensor/bilresa_scroll_wheel_aktuelle_schalterstellung_9',
    'homeassistant/update/gast_gast_licht_fernbedienung_firmware',
    'homeassistant/event/gast_gast_licht_fernbedienung_taste_1',
    'homeassistant/event/gast_gast_licht_fernbedienung_taste_2',
];
$lp = legacyParts($legacyTopics);
echo 'Legacy parts: ' . count($lp) . ' (von ' . count($legacyTopics) . " Topics)\n";
foreach ($lp as $p) {
    echo '  | ' . $p . "\n";
}
// sensor: 2 Cluster, update: 1 Single, event: 1 Cluster => 4
$check(count($lp) === 4, 'Legacy: 4 Parts (2 sensor-Cluster + update + event)');
$check(rxMatch($lp, '', 'homeassistant/sensor/gast_gast_licht_fernbedienung_batteriespannung/last_updated'), 'Legacy match gast');
$check(rxMatch($lp, '', 'homeassistant/sensor/bilresa_scroll_wheel_aktuelle_schalterstellung_9/state_class'), 'Legacy match bilresa');
$check(rxMatch($lp, '', 'homeassistant\/event\/gast_gast_licht_fernbedienung_taste_2\/state'), 'Legacy match escaped slashes');
$check(!rxMatch($lp, '', 'homeassistant/sensor/evcc_home_power/state'), 'Legacy reject fremdes Geraet');
$check(!rxMatch($lp, '', 'homeassistant/switch/other_relay/state'), 'Legacy reject fremde Domain');

// ===== Discovery: volle Leaf-Topics eines Producers (carconnectivity) =====
$discoveryTopics = [
    'carconnectivity/0/garage/WVG/charging/state',
    'carconnectivity/0/garage/WVG/charging/commands/start-stop',
    'carconnectivity/0/garage/WVG/climatization/state',
    'carconnectivity/0/garage/WVG/drive/state',
    'homeassistant/sensor/foo/state',
];
$dp = discoveryParts($discoveryTopics);
echo "\nDiscovery parts: " . count($dp) . ' (von ' . count($discoveryTopics) . " Topics)\n";
foreach ($dp as $p) {
    echo '  | ' . $p . "\n";
}
// 4 carconnectivity-Topics teilen langen Praefix => 1 Cluster; homeassistant/foo => 1 Single => 2
$check(count($dp) === 2, 'Discovery: 2 Parts (carconnectivity-Cluster + foo-Single)');
$check(rxMatch($dp, '?:', 'carconnectivity/0/garage/WVG/charging/state'), 'Discovery match state');
$check(rxMatch($dp, '?:', 'carconnectivity/0/garage/WVG/climatization/state'), 'Discovery match climatization');
$check(rxMatch($dp, '?:', 'carconnectivity\/0\/garage\/WVG\/drive\/state'), 'Discovery match escaped slashes');
$check(rxMatch($dp, '?:', 'homeassistant/sensor/foo/state'), 'Discovery match Single');
$check(!rxMatch($dp, '?:', 'carconnectivity/1/other/XYZ/state'), 'Discovery reject anderes Fahrzeug');

// ===== Heterogen (kein gemeinsamer Praefix) -> Fallback Einzelauflistung =====
$het = ['homeassistant/sensor/foo', 'homeassistant/sensor/bar'];
$check(count(legacyParts($het)) === 2, 'Heterogen Legacy: 2 Einzel-Parts');
$check(count(discoveryParts(['a/foo', 'a/bar'])) === 2, 'Heterogen Discovery: 2 Einzel-Parts');

echo "\n" . ($fail === 0 ? 'ALL OK' : "FAILURES: $fail") . "\n";
exit($fail === 0 ? 0 : 1);
