<?php

declare(strict_types=1);

// Prüft den geteilten Cluster-Kern (HADomainCatalog::clusterByCommonPrefix / longestCommonStringPrefix)
// in der Anwendung der klassischen Bridge (HADeviceCore): Gruppierung nach <base>/<domain>, Kollaps der
// Objekt-IDs, Kodierung "\\?\/".
// Verifiziert Kollaps, Trennung mehrerer Namensfamilien, Matching inkl. escaped Slashes, Reject fremder
// Geräte und den Fallback bei heterogenen Namen.
// Das MQTT Discovery Device fasst seit build 252 nichts mehr über den gemeinsamen Anfang zusammen; seinen Filter
// prüft check-discovery-receive-filter.php am echten Modul.

require_once __DIR__ . '/harness.php';
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

function rxMatch(array $parts, string $glue, string $topic): bool
{
    $filter = '.*"Topic":"(' . $glue . implode('|', $parts) . ')".*';
    return preg_match('~' . $filter . '~', '{"DataID":"x","Topic":"' . $topic . '"}') === 1;
}

$check = static function (bool $ok, string $label): void {
    pruefe($ok, $label);
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
$check(!rxMatch($lp, '', 'homeassistant/sensor/evcc_home_power/state'), 'Legacy reject fremdes Gerät');
$check(!rxMatch($lp, '', 'homeassistant/switch/other_relay/state'), 'Legacy reject fremde Domain');

// ===== Heterogen (kein gemeinsamer Präfix) -> Fallback Einzelauflistung =====
$het = ['homeassistant/sensor/foo', 'homeassistant/sensor/bar'];
$check(count(legacyParts($het)) === 2, 'Heterogen Legacy: 2 Einzel-Parts');

ergebnis();
