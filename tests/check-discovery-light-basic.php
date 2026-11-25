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

// === Z-Wave JS UI (herbertf, PN 145043, 25.11.2026): Qubino Flush Dimmer Plus. Fixture
// tests/fixtures/discovery_zwavejs_20261125.json. Der Dimmer meldet Zustand und Helligkeit auf demselben Topic
// (…/switch_multilevel/endpoint_0/currentValue, JSON mit value 0…99): state_value_template
// {{ "OFF" if value_json.value == 0 else "ON" }}, brightness_value_template {{ value_json.value }},
// brightness_scale 99, on_command_type brightness (Einschalten sendet nur die Helligkeit, HA: die zuletzt
// bekannte oder 255), Ausschalten payload_off (Vorgabe OFF) an command_topic.
$zwave = json_decode((string)file_get_contents(__DIR__ . '/fixtures/discovery_zwavejs_20261125.json'), true, 512, JSON_THROW_ON_ERROR);
const DIMMER = 'Z-Wave-JS/Raum/Bild/switch_multilevel/endpoint_0/';

DiscoveryDeviceHarness::$splitterAntwort = [
    'Items'            => $zwave['discovery_configs'],
    'Count'            => count($zwave['discovery_configs']),
    'DiscoveryPrefix'  => 'homeassistant',
    'SessionStartedAt' => time() - 3600,
];
DiscoveryDeviceHarness::$topicPayloadAntwort = ['Items' => $zwave['topic_payloads']];
$splitter2 = neueFensterInstanz(DiscoverySplitterHarness::class);
$bild = neueFensterInstanz(DiscoveryDeviceHarness::class);
IPS_ConnectInstance($bild->id(), $splitter2->id());
$bild->SetProperty('DeviceID', 'zwavejs2mqtt_0x00000001_node23');
neueAusfuehrung($bild)->ApplyChanges();

$bildVars = variablenNachIdent($bild->id());
$bildListe = json_encode(array_map(static fn(int $id): string => IPS_GetName($id), $bildVars), JSON_UNESCAPED_UNICODE);
$dimmerIdent = array_find(array_keys($bildVars), static fn(string $i): bool => str_starts_with($i, 'light_') && !str_ends_with($i, 'brightness')) ?? '';
$dimmerId = $bildVars[$dimmerIdent] ?? null;
$dimmerHelligkeitId = $dimmerId !== null ? helligkeitVon($bild->id(), $dimmerIdent) : null;
pruefe($dimmerId !== null, 'Z-Wave: Hauptvariable des Dimmers angelegt', $bildListe);
pruefe($dimmerHelligkeitId !== null, 'Z-Wave: Helligkeitsvariable angelegt', $bildListe);

/** Die echte currentValue-Meldung aus dem Bundle mit anderem Wert. */
function dimmerMeldung(array $zwave, int $wert): string
{
    $roh = array_find($zwave['topic_payloads'], static fn(array $p): bool => $p['topic'] === DIMMER . 'currentValue')['payload'];
    $json = json_decode($roh, true, 512, JSON_THROW_ON_ERROR);
    $json['value'] = $wert;
    return json_encode($json, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

$wert = static fn(?int $id): mixed => $id !== null ? GetValue($id) : null;

// --- Teil 6: Zustand über state_value_template, Helligkeit mit Skala 99.
melde($bild, DIMMER . 'currentValue', dimmerMeldung($zwave, 50));
pruefe($wert($dimmerId) === true, 'Z-Wave: Wert 50 heißt an', var_export($wert($dimmerId), true));
pruefe($wert($dimmerHelligkeitId) === 129, 'Z-Wave: Helligkeit 50/99 = 129 von 255', var_export($wert($dimmerHelligkeitId), true));
melde($bild, DIMMER . 'currentValue', dimmerMeldung($zwave, 0));
pruefe($wert($dimmerId) === false, 'Z-Wave: Wert 0 heißt aus', var_export($wert($dimmerId), true));
pruefe($wert($dimmerHelligkeitId) === 129, 'Z-Wave: Helligkeit 0 ignoriert, 129 bleibt', var_export($wert($dimmerHelligkeitId), true));
melde($bild, DIMMER . 'currentValue', dimmerMeldung($zwave, 99));
pruefe($wert($dimmerHelligkeitId) === 255, 'Z-Wave: Helligkeit 99/99 = 255', var_export($wert($dimmerHelligkeitId), true));

// --- Teil 7: Schalten wie HA mit on_command_type brightness.
$dimmerHelligkeitIdent = $dimmerHelligkeitId !== null ? IPS_GetObject($dimmerHelligkeitId)['ObjectIdent'] : '';
$s = schalte($bild, $dimmerHelligkeitIdent, 128);
pruefe($s === [['Topic' => DIMMER . 'targetValue/set', 'Payload' => '50']], 'Z-Wave: Helligkeit 128 geht als 50 hinaus', json_encode($s));
$s = schalte($bild, $dimmerIdent, false);
pruefe($s === [['Topic' => DIMMER . 'targetValue/set', 'Payload' => 'OFF']], 'Z-Wave: Ausschalten sendet OFF', json_encode($s));
melde($bild, DIMMER . 'currentValue', dimmerMeldung($zwave, 30));
$s = schalte($bild, $dimmerIdent, true);
pruefe($s === [['Topic' => DIMMER . 'targetValue/set', 'Payload' => '30']], 'Z-Wave: Einschalten sendet die letzte Helligkeit (30)', json_encode($s));

// --- Teil 8: Die Spalte „Modus“ zeigt die Fähigkeit, auch bei abgewählten Einträgen (config_switch_67 ist in
// Z-Wave JS von Haus aus abgewählt, hat aber ein Command-Topic; bis build 240 stand dort „Nur lesen“).
/** @return array<string, string> entity_key => mode */
function modusSpalte(DiscoveryDeviceHarness $g): array
{
    $ergebnis = [];
    $suche = static function (mixed $knoten) use (&$suche, &$ergebnis): void {
        if (!is_array($knoten)) {
            return;
        }
        if (isset($knoten['entity_key'], $knoten['mode']) && is_string($knoten['entity_key'])) {
            $ergebnis[$knoten['entity_key']] = (string)$knoten['mode'];
        }
        foreach ($knoten as $kind) {
            $suche($kind);
        }
    };
    $suche(json_decode(neueAusfuehrung($g)->GetConfigurationForm(), true));
    return $ergebnis;
}

$modus = modusSpalte($bild);
pruefe(($modus['zwavejs2mqtt_0x00000001_23-112-0-67'] ?? null) === 'Read and write', 'Modus: abgewählter config_switch_67 „Read and write“',
    var_export($modus['zwavejs2mqtt_0x00000001_23-112-0-67'] ?? null, true));
pruefe(($modus['zwavejs2mqtt_0x00000001_23-37-0-currentValue'] ?? null) === 'Read and write', 'Modus: angehakter Switch „Read and write“',
    var_export($modus['zwavejs2mqtt_0x00000001_23-37-0-currentValue'] ?? null, true));
pruefe(($modus['zwavejs2mqtt_0x00000001_23-50-0-value-66049'] ?? null) === 'Read only', 'Modus: Leistungssensor „Read only“',
    var_export($modus['zwavejs2mqtt_0x00000001_23-50-0-value-66049'] ?? null, true));

ergebnis();
