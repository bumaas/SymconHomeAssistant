<?php

declare(strict_types=1);

/**
 * Prüft am ECHTEN Modul (Home Assistant MQTT Discovery Device) über den Kernel-Stub ein Licht im
 * Standard-Schema von HA (`schema` fehlt oder `basic`): Ein/Aus als payload_on/payload_off an das
 * Command-Topic, Helligkeit über eigene Topics (brightness_state_topic, brightness_command_topic).
 *
 * Anlass: herbertf, Forum t/142973 #397 (25.11.2026). Ein Dimmer aus Z-Wave JS UI kam nur als Ein/Aus an.
 * Z-Wave JS kündigt Dimmer im Standard-Schema mit getrennten Helligkeits-Topics an; der Discovery-Pfad
 * kannte nur das JSON-Schema (`brightness: true`, Helligkeit im JSON des State-Topics) und schickte auch
 * Ein/Aus als {"state":"ON"}.
 *
 * Fixture: tests/fixtures/discovery_awtrix_20260513.json (Anwender-Bundle, neutralisiert): AWTRIX 3,
 * Licht „Matrix“ mit bri_stat_t/bri_cmd_t, ohne payload_on/off und brightness_scale (Vorgaben ON/OFF, 255).
 * Erwartetes Verhalten nach HA Core (mqtt/light/schema_basic.py):
 * - Helligkeit wird unterstützt, sobald brightness_command_topic angekündigt ist.
 * - Zustand: Wert / brightness_scale * 255, gerundet, höchstens 255; leere Meldung und 0 werden ignoriert.
 * - Befehl: round(Wert / 255 * brightness_scale), mindestens 1, an brightness_command_topic.
 * - Ausschalten sendet payload_off an command_topic, Einschalten ohne Helligkeit payload_on.
 */

require_once __DIR__ . '/device-harness.php';

error_reporting(E_ALL);

$bundle = json_decode((string)file_get_contents(__DIR__ . '/fixtures/discovery_awtrix_20260513.json'), true, 512, JSON_THROW_ON_ERROR);

const MATRIX = 'awtrix_test/aabbcc000001/000001_mat/';

DiscoveryDeviceHarness::$splitterAntwort = [
    'Items'            => $bundle['discovery_configs'],
    'Count'            => count($bundle['discovery_configs']),
    'DiscoveryPrefix'  => 'homeassistant',
    'SessionStartedAt' => time() - 3600,
];
DiscoveryDeviceHarness::$topicPayloadAntwort = ['Items' => $bundle['topic_payloads']];
DiscoveryDeviceHarness::$parentAktiv = true;

$splitter = neueFensterInstanz(DiscoverySplitterHarness::class);
$awtrix = neueFensterInstanz(DiscoveryDeviceHarness::class);
IPS_ConnectInstance($awtrix->id(), $splitter->id());
$awtrix->SetProperty('DeviceID', 'aabbcc000001');
neueAusfuehrung($awtrix)->ApplyChanges();

/** @return array<string, int> Ident => Variablen-ID */
function variablenNachIdent(int $instanz): array
{
    $ergebnis = [];
    foreach (IPS_GetChildrenIDs($instanz) as $id) {
        if (IPS_VariableExists($id)) {
            $ergebnis[IPS_GetObject($id)['ObjectIdent']] = $id;
        }
    }
    return $ergebnis;
}

function helligkeitVon(int $instanz, string $lichtIdent): ?int
{
    foreach (variablenNachIdent($instanz) as $ident => $id) {
        if ($ident !== $lichtIdent && str_starts_with($ident, $lichtIdent) && str_ends_with($ident, 'brightness')) {
            return $id;
        }
    }
    return null;
}

$vars = variablenNachIdent($awtrix->id());
$liste = json_encode(array_keys($vars), JSON_UNESCAPED_UNICODE);
$matrixId = array_find($vars, static fn(int $id): bool => IPS_GetName($id) === 'Matrix');
pruefe($matrixId !== null, 'Hauptvariable „Matrix“ angelegt', $liste);
$matrixIdent = $matrixId !== null ? IPS_GetObject($matrixId)['ObjectIdent'] : '';

// --- Teil 1: Ein/Aus und Helligkeit aus den eingespielten Meldungen.
pruefe($matrixId !== null && GetValue($matrixId) === false, 'Matrix: aus (stat_t OFF)', var_export($matrixId !== null ? GetValue($matrixId) : null, true));
$helligkeitId = helligkeitVon($awtrix->id(), $matrixIdent);
pruefe($helligkeitId !== null, 'Matrix: Helligkeitsvariable angelegt', $liste);
pruefe($helligkeitId !== null && GetValue($helligkeitId) === 14, 'Matrix: Helligkeit 14 aus bri_stat_t (Skala 255)',
    var_export($helligkeitId !== null ? GetValue($helligkeitId) : null, true));
pruefe($helligkeitId !== null && IPS_GetVariable($helligkeitId)['VariableAction'] !== 0, 'Matrix: Helligkeit schaltbar');

// --- Teil 2: Die Indikatoren kündigen keine Helligkeit an und bekommen keine Variable dafür.
foreach (['Indicator 1', 'Indicator 2', 'Indicator 3'] as $name) {
    $id = array_find($vars, static fn(int $v): bool => IPS_GetName($v) === $name);
    pruefe($id !== null && helligkeitVon($awtrix->id(), IPS_GetObject($id)['ObjectIdent']) === null, "„{$name}“ ohne Helligkeit", $liste);
}

// --- Teil 3: Ein/Aus geht als payload_on/payload_off (Vorgabe ON/OFF) an das Command-Topic, nicht als JSON.
function schalte(DiscoveryDeviceHarness $g, string $ident, mixed $wert): array
{
    DiscoveryDeviceHarness::$mqttSendungen = [];
    if ($ident !== '') {
        neueAusfuehrung($g)->RequestAction($ident, $wert);
    }
    return DiscoveryDeviceHarness::$mqttSendungen;
}

$s = schalte($awtrix, $matrixIdent, true);
pruefe($s === [['Topic' => MATRIX . 'cmd_t', 'Payload' => 'ON']], 'Einschalten: ON an cmd_t', json_encode($s));
$s = schalte($awtrix, $matrixIdent, false);
pruefe($s === [['Topic' => MATRIX . 'cmd_t', 'Payload' => 'OFF']], 'Ausschalten: OFF an cmd_t', json_encode($s));

// --- Teil 4: Helligkeit geht an bri_cmd_t (Skala 255, also unverändert), 0 wird wie in HA zu 1.
$helligkeitIdent = $helligkeitId !== null ? IPS_GetObject($helligkeitId)['ObjectIdent'] : '';
$s = schalte($awtrix, $helligkeitIdent, 128);
pruefe($s === [['Topic' => MATRIX . 'bri_cmd_t', 'Payload' => '128']], 'Helligkeit 128 an bri_cmd_t', json_encode($s));
$s = schalte($awtrix, $helligkeitIdent, 0);
pruefe($s === [['Topic' => MATRIX . 'bri_cmd_t', 'Payload' => '1']], 'Helligkeit 0 geht als 1 hinaus', json_encode($s));

// --- Teil 5: Neue Helligkeitsmeldungen; 0 und leer ignoriert HA, der letzte Wert bleibt stehen.
function melde(DiscoveryDeviceHarness $g, string $topic, string $payload): void
{
    neueAusfuehrung($g)->ReceiveData(json_encode(['Topic' => $topic, 'Payload' => bin2hex($payload)], JSON_THROW_ON_ERROR));
}

melde($awtrix, MATRIX . 'bri_stat_t', '200');
pruefe($helligkeitId !== null && GetValue($helligkeitId) === 200, 'Meldung 200 übernommen', var_export($helligkeitId !== null ? GetValue($helligkeitId) : null, true));
melde($awtrix, MATRIX . 'bri_stat_t', '0');
pruefe($helligkeitId !== null && GetValue($helligkeitId) === 200, 'Meldung 0 ignoriert', var_export($helligkeitId !== null ? GetValue($helligkeitId) : null, true));
melde($awtrix, MATRIX . 'bri_stat_t', '');
pruefe($helligkeitId !== null && GetValue($helligkeitId) === 200, 'Leere Meldung ignoriert', var_export($helligkeitId !== null ? GetValue($helligkeitId) : null, true));
melde($awtrix, MATRIX . 'stat_t', 'ON');
pruefe($matrixId !== null && GetValue($matrixId) === true, 'stat_t ON schaltet die Hauptvariable', var_export($matrixId !== null ? GetValue($matrixId) : null, true));

ergebnis();
