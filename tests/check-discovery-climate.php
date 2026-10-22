<?php

declare(strict_types=1);

/**
 * Prüft am ECHTEN Modul (Home Assistant MQTT Discovery Device) über den Kernel-Stub, dass ein Klimagerät
 * aus der MQTT Discovery schalt- und lesbar ist: Solltemperatur, Modus, Lüfter, Lamellen, Aktion und
 * Raumtemperatur.
 *
 * Anlass: MarekG, Forum t/142973 #376 und PN 144746 (22.10.2026). Mitsubishi-Klimaanlage mit
 * mitsubishi2mqtt 2025.10.30. Das Gerät wurde erkannt, die Hauptvariable stand aber auf 0 C, wurde „Nie“
 * aktualisiert und hatte keine Aktion; Modus und Lüfter fehlten ganz. Ursachen:
 * - mitsubishi2mqtt schreibt die Kurzformen der Discovery (temp_cmd_t, mode_stat_tpl, …), der Parser
 *   kannte davon nur die allgemeinen (stat_t, cmd_t, …).
 * - Die Templates sind bedingt: {{ value_json.mode if (…) else 'off' }}, die Solltemperatur ein
 *   {% if %}-Block mit Begrenzung auf 16 bis 31.
 * - Ein nicht verstandenes Template schrieb das ganze JSON in die Variable („Up Time“).
 *
 * Fixture: tests/fixtures/discovery_mitsubishi2mqtt_20261022.json (MarekGs Bundle, MAC/BSSID/IP neutralisiert).
 * Erwartetes Schreibverhalten nach HA Core (mqtt/climate.py): async_set_hvac_mode sendet nur an
 * mode_command_topic, power_command_topic gehört zu turn_on/turn_off.
 */

require_once __DIR__ . '/device-harness.php';

error_reporting(E_ALL);

const GERAET = 'klimaanlage-buero_AABBCC000001';
const BASIS = 'mitsubishi2mqtt/klimaanlage-buero/';

$bundle = json_decode((string)file_get_contents(__DIR__ . '/fixtures/discovery_mitsubishi2mqtt_20261022.json'), true, 512, JSON_THROW_ON_ERROR);

DiscoveryDeviceHarness::$splitterAntwort = [
    'Items'            => $bundle['discovery_configs'],
    'Count'            => count($bundle['discovery_configs']),
    'DiscoveryPrefix'  => 'homeassistant',
    'SessionStartedAt' => time() - 3600,
];
DiscoveryDeviceHarness::$topicPayloadAntwort = ['Items' => $bundle['topic_payloads']];
DiscoveryDeviceHarness::$parentAktiv = true;

$splitter = neueFensterInstanz(DiscoverySplitterHarness::class);
$g = neueFensterInstanz(DiscoveryDeviceHarness::class);
IPS_ConnectInstance($g->id(), $splitter->id());
$g->SetProperty('DeviceID', GERAET);
neueAusfuehrung($g)->ApplyChanges();

/** @return array<string, int> Ident => Variablen-ID */
function variablen(int $instanz): array
{
    $ergebnis = [];
    foreach (IPS_GetChildrenIDs($instanz) as $id) {
        if (IPS_VariableExists($id)) {
            $ergebnis[IPS_GetObject($id)['ObjectIdent']] = $id;
        }
    }
    return $ergebnis;
}

$vars = variablen($g->id());
$liste = json_encode(array_keys($vars));
$hauptIdent = array_find(array_keys($vars), static fn(string $i): bool => str_starts_with($i, 'climate'));
pruefe($hauptIdent !== null, 'Klima-Hauptvariable angelegt', $liste);
$hauptIdent ??= 'climate';

/** Ident der Zusatzvariable: beginnt mit „climate“, endet auf das Attribut (das Ident-Schema kürzt „status“). */
function ident(string $attribut): string
{
    global $vars, $hauptIdent;
    return array_find(array_keys($vars), static fn(string $i): bool => $i !== $hauptIdent && str_starts_with($i, 'climate')
        && str_ends_with($i, '_' . $attribut) && !str_ends_with($i, '_horizontal_' . $attribut)) ?? "climate_$attribut";
}

function wert(string $ident): mixed
{
    global $vars;
    return isset($vars[$ident]) ? GetValue($vars[$ident]) : '(fehlt)';
}

function schaltbar(string $ident): bool
{
    global $vars;
    return isset($vars[$ident]) && IPS_GetVariable($vars[$ident])['VariableAction'] !== 0;
}

// --- Teil 1: Solltemperatur (Hauptvariable) aus temp_stat_t + {% if %}-Template, schaltbar über temp_cmd_t.
pruefe(wert($hauptIdent) == 22, 'Solltemperatur aus dem Zustands-JSON (22)', var_export(wert($hauptIdent), true));
pruefe(schaltbar($hauptIdent), 'Solltemperatur ist schaltbar (temp_cmd_t)');
// temperature_unit „C“ heißt in HA Grad Celsius; MarekGs Hauptvariable zeigte „0 C“.
$haupt = IPS_GetVariable($vars[$hauptIdent] ?? 0)['VariablePresentation'] ?? [];
pruefe(($haupt['SUFFIX'] ?? '') === ' °C', 'Solltemperatur mit Einheit °C', json_encode($haupt, JSON_UNESCAPED_UNICODE));

// --- Teil 2: Zusatzvariablen mit den Werten aus mitsubishi2mqtt/…/state.
$erwartet = [
    'hvac_mode'             => 'off',
    'fan_mode'              => 'medium',
    'swing_mode'            => 'AUTO',
    'swing_horizontal_mode' => '|',
    'hvac_action'           => 'off',
    'current_temperature'   => 21.5,
];
foreach ($erwartet as $attribut => $soll) {
    pruefe(wert(ident($attribut)) === $soll, "Variable $attribut = " . var_export($soll, true),
        var_export(wert(ident($attribut)), true) . ' / ' . $liste);
}
foreach (['hvac_mode', 'fan_mode', 'swing_mode', 'swing_horizontal_mode'] as $attribut) {
    pruefe(schaltbar(ident($attribut)), "$attribut ist schaltbar");
}
foreach (['hvac_action', 'current_temperature'] as $attribut) {
    pruefe(isset($vars[ident($attribut)]) && !schaltbar(ident($attribut)), "$attribut ist nur lesbar");
}

// Darstellung: Modus als Aufzählung mit den angekündigten Modi, die Aktion als Wertanzeige.
$darstellung = isset($vars[ident('hvac_mode')]) ? IPS_GetVariable($vars[ident('hvac_mode')])['VariablePresentation'] : [];
$optionen = array_column(json_decode((string)($darstellung['OPTIONS'] ?? '[]'), true) ?? [], 'Caption', 'Value');
pruefe(($darstellung['PRESENTATION'] ?? '') === VARIABLE_PRESENTATION_ENUMERATION, 'Modus: Aufzählung', json_encode($darstellung));
pruefe(array_keys($optionen) === ['heat_cool', 'cool', 'dry', 'heat', 'fan_only', 'off'], 'Modus: Optionen aus modes', json_encode($optionen));
pruefe(($optionen['cool'] ?? '') === 'Cooling', 'Modus: Beschriftung wie im Bridge-Device', json_encode($optionen));
$aktion = isset($vars[ident('hvac_action')]) ? IPS_GetVariable($vars[ident('hvac_action')])['VariablePresentation'] : [];
pruefe(($aktion['PRESENTATION'] ?? '') === VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'Aktion: Wertanzeige', json_encode($aktion));

// --- Teil 3: Schalten sendet an die angekündigten Topics.
function schalte(string $ident, mixed $wert): array
{
    global $g;
    DiscoveryDeviceHarness::$mqttSendungen = [];
    neueAusfuehrung($g)->RequestAction($ident, $wert);
    return DiscoveryDeviceHarness::$mqttSendungen;
}

$s = schalte(ident('hvac_mode'), 'cool');
pruefe($s === [['Topic' => BASIS . 'mode/set', 'Payload' => 'cool']], 'Modus „cool“ geht nur an mode/set', json_encode($s));
$s = schalte(ident('hvac_mode'), 'turbo');
pruefe($s === [], 'Modus außerhalb von modes wird nicht gesendet', json_encode($s));
$s = schalte(ident('fan_mode'), 'high');
pruefe($s === [['Topic' => BASIS . 'fan/set', 'Payload' => 'high']], 'Lüfter „high“ an fan/set', json_encode($s));
$s = schalte(ident('swing_mode'), '3');
pruefe($s === [['Topic' => BASIS . 'vane/set', 'Payload' => '3']], 'Lamelle vertikal an vane/set', json_encode($s));
$s = schalte(ident('swing_horizontal_mode'), '<<');
pruefe($s === [['Topic' => BASIS . 'wide-vane/set', 'Payload' => '<<']], 'Lamelle horizontal an wide-vane/set', json_encode($s));
$s = schalte($hauptIdent, 23);
pruefe($s === [['Topic' => BASIS . 'temp/set', 'Payload' => '23']], 'Solltemperatur 23 an temp/set', json_encode($s));

// --- Teil 4: Eine neue Zustandsmeldung (Fernbedienung) aktualisiert alle Variablen.
$meldung = '{"room_temperature":22.0,"temperature":24,"fan":"high","vane":"3","wideVane":"<<","mode":"cool","action":"cooling","compressor_freq":42}';
neueAusfuehrung($g)->ReceiveData(json_encode(['Topic' => BASIS . 'state', 'Payload' => bin2hex($meldung)], JSON_THROW_ON_ERROR));
$vars = variablen($g->id());
pruefe(wert($hauptIdent) == 24, 'Meldung: Solltemperatur 24', var_export(wert($hauptIdent), true));
foreach (['hvac_mode' => 'cool', 'fan_mode' => 'high', 'swing_mode' => '3', 'swing_horizontal_mode' => '<<', 'hvac_action' => 'cooling', 'current_temperature' => 22.0] as $attribut => $soll) {
    pruefe(wert(ident($attribut)) === $soll, "Meldung: $attribut = " . var_export($soll, true), var_export(wert(ident($attribut)), true));
}

// Fehlt ein Feld, greift der else-Zweig des Templates (… else 'off').
$meldung = '{"room_temperature":22.0,"temperature":24,"fan":"high","vane":"3","wideVane":"<<","mode":"","action":"idle"}';
neueAusfuehrung($g)->ReceiveData(json_encode(['Topic' => BASIS . 'state', 'Payload' => bin2hex($meldung)], JSON_THROW_ON_ERROR));
pruefe(wert(ident('hvac_mode')) === 'off', 'Leerer Modus: Rückfallwert des Templates (off)', var_export(wert(ident('hvac_mode')), true));

// --- Teil 5: Nicht verstandenes Template schreibt kein JSON in die Variable.
$upTime = array_find(array_keys($vars), static fn(string $i): bool => str_contains($i, 'up_time'));
$upWert = $upTime !== null ? (string)GetValue($vars[$upTime]) : '';
pruefe(!str_starts_with(ltrim($upWert), '{'), 'Up Time enthält kein Roh-JSON', $upWert);

// --- Teil 6: Die Bundle-Diagnose des Splitters führt die Befehls-Topics (…_cmd_t) als command, nicht als
// state. Sonst zählen sie als „fehlende Zustandsmeldung“ (MarekGs Bundle: 6 von 8 state „missing“).
$sammle = new ReflectionMethod(HomeAssistantMQTTDiscoverySplitter::class, 'collectReferencedTopicDescriptorsFromDiscoveryConfigs');
$arten = array_column($sammle->invoke(neueAusfuehrung($splitter), $bundle['discovery_configs']), 'kinds', 'topic');
foreach (['mode/set', 'temp/set', 'power/set', 'fan/set', 'vane/set', 'wide-vane/set', 'system/set'] as $befehl) {
    pruefe(($arten[BASIS . $befehl] ?? null) === ['command'], "Splitter: $befehl ist command", json_encode($arten[BASIS . $befehl] ?? null));
}
pruefe(($arten[BASIS . 'state'] ?? null) === ['state'], 'Splitter: state bleibt state', json_encode($arten[BASIS . 'state'] ?? null));

ergebnis();
