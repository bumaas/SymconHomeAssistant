<?php

declare(strict_types=1);

/**
 * Prüft am ECHTEN Modul (Home Assistant Device, Bundle-Modus) über den offiziellen Kernel-Stub, dass
 * keine zwei Variablen einer Instanz gleich heißen (MCP-Regel 14: Eine KI findet Variablen über den
 * Namen; bei zwei gleichen rät sie).
 *
 * Drei Ursachen, je an einem echten Gerät vom nuc (Abstimmung Burkhard 03.10.2026):
 * 1. Mehrere Entitäten derselben Art: Die Zusatzvariablen trugen keinen Entitätsnamen. Tesla „Free
 *    Willy" mit zwei Klima-Entitäten (dreimal „Isttemperatur", zweimal „Modus", „Ein/Aus") und zwei
 *    Ortungen (zweimal „Source Type"); dazu „Titel" von Mediaplayer und Update.
 *    → Entitätsname voran: „Klima Isttemperatur", „Route Source Type".
 * 2. Home Assistant vergibt denselben Namen an Entitäten verschiedener Art: „Ladekabel" am Tesla
 *    (Ja/Nein-Sensor und Textsensor).
 *    → Art-Hinweis: „Ladekabel (Ja/Nein)", „Ladekabel (Wert)".
 *
 * Der Testrahmen übersetzt nicht; erwartet werden deshalb die englischen Schlüssel der locale.json.
 * 3. Gerät aus zwei Hauptteilen: Luftentfeuchter und Lüfter fielen auf „Status (HUMIDIFIER)" /
 *    „Status (FAN)" zurück. → eigener Name statt Domäne in Großbuchstaben.
 *
 * Fixture: tests/fixtures/naming_collisions_20261003.json (siehe dort „quelle").
 */

require_once __DIR__ . '/device-harness.php';

error_reporting(E_ALL);

/** @var list<string> $bundleDateien */
$bundleDateien = [];

function geraetAus(array $konfiguration): DeviceHarness
{
    global $bundleDateien;
    $datei = tempnam(sys_get_temp_dir(), 'ha_naming_check_');
    if ($datei === false) {
        throw new RuntimeException('Temp-Datei für das Bundle nicht angelegt');
    }
    $bundleDateien[] = $datei;
    file_put_contents($datei, json_encode($konfiguration, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return neuesGeraet([
        'SourceMode' => 'bundle',
        'BundlePath' => $datei,
        'DeviceName' => $konfiguration[0]['device_name'],
        'DeviceID'   => $konfiguration[0]['device_id'],
    ]);
}

/** @return array<string, string> Ident => Name */
function namen(DeviceHarness $g): array
{
    $namen = [];
    foreach (IPS_GetChildrenIDs($g->id()) as $id) {
        $o = IPS_GetObject($id);
        if ($o['ObjectType'] === OBJECTTYPE_VARIABLE) {
            $namen[$o['ObjectIdent']] = $o['ObjectName'];
        }
    }
    ksort($namen);
    return $namen;
}

/** @return list<string> Namen, die mehr als einmal vorkommen */
function doppelte(array $namen): array
{
    return array_keys(array_filter(array_count_values($namen), static fn(int $n): bool => $n > 1));
}

function pruefeName(array $namen, string $ident, string $erwartet, string $geraet): void
{
    pruefe(($namen[$ident] ?? null) === $erwartet, sprintf('%s: %s heißt „%s"', $geraet, $ident, $erwartet), var_export($namen[$ident] ?? null, true));
}

$fixture = json_decode((string)file_get_contents(__DIR__ . '/fixtures/naming_collisions_20261003.json'), true, 512, JSON_THROW_ON_ERROR);
$alle = [];

// ---- Tesla „Testauto": Spielart 1 und 2 ----
$tesla = geraetAus($fixture['geraete']['Home Assistant Gerät']);
$alle['Tesla'] = $namen = namen($tesla);
pruefeName($namen, 'climate_klima', 'Klima Target Temperature', 'Tesla');
pruefeName($namen, 'climate_uberhitzungsschutz_der_kabine', 'Überhitzungsschutz der Kabine', 'Tesla');
pruefeName($namen, 'climate_klima_current_temperature', 'Klima Current Temperature', 'Tesla');
pruefeName($namen, 'climate_uberhitzungsschutz_der_kabine_current_temperature', 'Überhitzungsschutz der Kabine Current Temperature', 'Tesla');
pruefeName($namen, 'climate_klima_hvac_mode', 'Klima HVAC Mode', 'Tesla');
pruefeName($namen, 'climate_uberhitzungsschutz_der_kabine_hvac_mode', 'Überhitzungsschutz der Kabine HVAC Mode', 'Tesla');
pruefeName($namen, 'device_tracker_standort_source_type', 'Standort Source Type', 'Tesla');
pruefeName($namen, 'device_tracker_route_source_type', 'Route Source Type', 'Tesla');
pruefeName($namen, 'binary_sensor_ladekabel', 'Ladekabel (Yes/No)', 'Tesla');
pruefeName($namen, 'sensor_ladekabel', 'Ladekabel (Value)', 'Tesla');
// Einzelstücke bleiben ohne Vorsatz: nur ein Fenster-Cover-Typ je Name, ein Mediaplayer.
pruefeName($namen, 'sensor_batteriestand', 'Batteriestand', 'Tesla');

// ---- Luftentfeuchter: Spielart 3 ----
$entfeuchter = geraetAus($fixture['geraete']['Luftentfeuchter']);
$alle['Luftentfeuchter'] = $namen = namen($entfeuchter);
pruefeName($namen, 'humidifier_status', 'Luftentfeuchter', 'Luftentfeuchter');
pruefeName($namen, 'fan_fan', 'Lüfter', 'Luftentfeuchter');
pruefe(!array_any($namen, static fn(string $n): bool => preg_match('/\([A-Z_]+\)$/', $n) === 1),
    'Luftentfeuchter: kein Name mit Domäne in Großbuchstaben', implode(', ', $namen));

// ---- Aqara FP300: gleichartige Entitäten mit HA-Nummerierung bleiben wie bisher ----
// (Die zweite „Empfindlichkeit" am nuc gehört zu einer in HA entfallenen Entität, siehe
// check-removed-entities.php; die Konfiguration kennt sie nicht mehr.)
$fp300 = geraetAus($fixture['geraete']['Küche.Licht.Bewegungsmelder']);
$alle['FP300'] = $namen = namen($fp300);
pruefeName($namen, 'select_empfindlichkeit', 'Empfindlichkeit', 'FP300');
pruefeName($namen, 'button_identifizieren_1', 'Identifizieren (1)', 'FP300');
pruefeName($namen, 'update_firmware_title', 'Title', 'FP300');

// ---- Keine Instanz hat zwei gleichnamige Variablen ----
foreach ($alle as $geraet => $namen) {
    pruefe(doppelte($namen) === [], $geraet . ': kein Name doppelt', implode(', ', doppelte($namen)));
}

// ---- Heißer Pfad: Eine Zusatzvariable, die erst mit einer MQTT-Attributmeldung entsteht ----
// Jede Meldung beginnt in einer frischen Ausführung; die Instanz kennt dann nur die gemeldete Entität.
IPS_DeleteVariable((int)$tesla->variablenId('device_tracker_route_source_type'));
IPS_DeleteVariable((int)$tesla->variablenId('update_update_title'));
neueAusfuehrung($tesla)->ReceiveData(mqttMeldung('statestream/device_tracker/testauto_route/source_type', '"gps"'));
neueAusfuehrung($tesla)->ReceiveData(mqttMeldung('statestream/update/testauto_update/title', '"Neue Version"'));
$namen = namen($tesla);
pruefeName($namen, 'device_tracker_route_source_type', 'Route Source Type', 'Tesla (MQTT)');
pruefeName($namen, 'update_update_title', $alle['Tesla']['update_update_title'] ?? '?', 'Tesla (MQTT)');
pruefe(doppelte($namen) === [], 'Tesla (MQTT): kein Name doppelt', implode(', ', doppelte($namen)));
$alle['Tesla'] = $namen;

// ---- Ein weiteres ApplyChanges ändert keinen Namen ----
neueAusfuehrung($tesla)->ApplyChanges();
pruefe(namen($tesla) === $alle['Tesla'], 'Tesla: zweites ApplyChanges ändert keinen Namen');

foreach ($bundleDateien as $datei) {
    @unlink($datei);
}

ergebnis();
