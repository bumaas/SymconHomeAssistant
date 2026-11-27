<?php

declare(strict_types=1);

/**
 * Prüft am ECHTEN Modul (Home Assistant MQTT Discovery Device) über den Kernel-Stub, dass keine Variable einen
 * Schieberegler ohne Bereich (MIN >= MAX) bekommt.
 *
 * Anlass: ein Anwender, Forum t/142973 #386 (16.11.2026). EMS-ESP kündigt „Maximale Brennerleistung“
 * als number mit min = max = 100 an (HA lässt das zu). Am nuc nachgestellt (16.11.2026, Bundle-Splitter
 * #54459, Device #35496): Der Kernel verwirft die Darstellung mit „Maximum Value must be higher than Minimum
 * Value“ (HASharedPresentationTrait.php:30), die Variable steht mit Aktion, aber ohne Darstellung da. Jedes
 * „Änderungen übernehmen“ im Formular endet mit „Fehler beim Übernehmen der Änderungen … (Code: -32603)“,
 * danach scheitert der Testbereich der Konsole mit „Uncaught Unexpected presentation when trying to
 * determine minimum“. Der Kernel-Stub prüft MIN < MAX nicht; dieser Test prüft es selbst.
 *
 * Erwartet: Ein Schieberegler ohne Bereich wird zum Eingabefeld (VALUE_INPUT erlaubt nur MULTILINE, PREFIX,
 * SUFFIX, siehe check_presentations.php); die Einheit bleibt.
 *
 * Fixture: tests/fixtures/discovery_ems_esp_20261116.json.
 */

require_once __DIR__ . '/device-harness.php';

error_reporting(E_ALL);

$bundle = json_decode((string)file_get_contents(__DIR__ . '/fixtures/discovery_ems_esp_20261116.json'), true, 512, JSON_THROW_ON_ERROR);

DiscoveryDeviceHarness::$splitterAntwort = [
    'Items'            => $bundle['discovery_configs'],
    'Count'            => count($bundle['discovery_configs']),
    'DiscoveryPrefix'  => 'homeassistant',
    'SessionStartedAt' => time() - 3600,
];
DiscoveryDeviceHarness::$topicPayloadAntwort = ['Items' => $bundle['topic_payloads']];
DiscoveryDeviceHarness::$parentAktiv = true;

$splitter = neueFensterInstanz(DiscoverySplitterHarness::class);
$kessel = neueFensterInstanz(DiscoveryDeviceHarness::class);
IPS_ConnectInstance($kessel->id(), $splitter->id());
$kessel->SetProperty('DeviceID', 'ems-esp-boiler');
neueAusfuehrung($kessel)->ApplyChanges();

/** @return array<string, int> Variablenname => Variablen-ID */
function variablenNachName(int $instanz): array
{
    $ergebnis = [];
    foreach (IPS_GetChildrenIDs($instanz) as $id) {
        if (IPS_VariableExists($id)) {
            $ergebnis[IPS_GetName($id)] = $id;
        }
    }
    return $ergebnis;
}

$vars = variablenNachName($kessel->id());

// --- Teil 1: Keine Variable des Kessels trägt einen Schieberegler mit MIN >= MAX.
foreach ($vars as $name => $id) {
    $p = IPS_GetVariable($id)['VariablePresentation'];
    if (($p['PRESENTATION'] ?? '') !== VARIABLE_PRESENTATION_SLIDER || !isset($p['MIN'], $p['MAX'])) {
        continue;
    }
    pruefe($p['MIN'] < $p['MAX'], "„{$name}“: Schieberegler mit MIN < MAX", json_encode($p));
}

// --- Teil 2: „Maximale Brennerleistung“ (min = max = 100) wird ein Eingabefeld mit Einheit und bleibt schaltbar.
$id = $vars['Maximale Brennerleistung'] ?? 0;
pruefe($id !== 0, '„Maximale Brennerleistung“ angelegt', json_encode(array_keys($vars), JSON_UNESCAPED_UNICODE));
$p = $id !== 0 ? IPS_GetVariable($id)['VariablePresentation'] : [];
pruefe(($p['PRESENTATION'] ?? '') === VARIABLE_PRESENTATION_VALUE_INPUT, 'Maximale Brennerleistung: Eingabefeld', json_encode($p));
pruefe(($p['SUFFIX'] ?? '') === ' %', 'Maximale Brennerleistung: Einheit %', json_encode($p, JSON_UNESCAPED_UNICODE));
$fremd = array_diff(array_keys($p), ['PRESENTATION', 'MULTILINE', 'PREFIX', 'SUFFIX']);
pruefe($fremd === [], 'Maximale Brennerleistung: nur Parameter, die das Eingabefeld kennt', json_encode(array_values($fremd)));
pruefe($id !== 0 && IPS_GetVariable($id)['VariableAction'] !== 0, 'Maximale Brennerleistung: schaltbar');

// Gegenprobe: Eine number mit echtem Bereich bleibt ein Schieberegler.
$p = isset($vars['Eingestellte maximale Brennerleistung']) ? IPS_GetVariable($vars['Eingestellte maximale Brennerleistung'])['VariablePresentation'] : [];
pruefe(($p['PRESENTATION'] ?? '') === VARIABLE_PRESENTATION_SLIDER && ($p['MIN'] ?? null) === 0 && ($p['MAX'] ?? null) === 100,
    'Eingestellte maximale Brennerleistung: Schieberegler 0 bis 100', json_encode($p));

// --- Teil 3: Dieselbe Regel gilt für die Bridge (gemeinsamer Trait). Ein Schieberegler mit MIN > MAX wird
// ebenfalls ein Eingabefeld; Werte aus dem EMS-ESP-Fall, als Float wie von HA geliefert.
$angleichen = new ReflectionMethod(HomeAssistantDevice::class, 'fitPresentationToVariableType');
foreach ([[100.0, 100.0], [101.0, -1.0]] as [$min, $max]) {
    $ergebnis = $angleichen->invoke(null, [
        'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER, 'MIN' => $min, 'MAX' => $max, 'STEP_SIZE' => 1, 'DIGITS' => 0, 'SUFFIX' => ' %'
    ], VARIABLETYPE_FLOAT);
    pruefe($ergebnis === ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_INPUT, 'SUFFIX' => ' %'],
        "Trait: Schieberegler $min..$max wird Eingabefeld", json_encode($ergebnis));
}

ergebnis();
