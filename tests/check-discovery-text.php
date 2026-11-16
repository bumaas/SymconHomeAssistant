<?php

declare(strict_types=1);

/**
 * Prüft am ECHTEN Modul (Home Assistant MQTT Discovery Device) über den Kernel-Stub, dass Entitäten der
 * Komponente `text` als Textvariable ankommen und schaltbar sind.
 *
 * Anlass: Boui, Forum t/142973 #386 und PN 144956 (16.11.2026). EMS-ESP v3.9.1 (Buderus-Kessel + RC35)
 * kündigt Schaltzeiten, Urlaubs- und Feiertage, Wartungsdatum und Uhrzeit des Reglers als `text` an. Der
 * Parser kannte die Komponente nicht, die Discovery-Diagnose führte alle zehn als „nicht unterstützt“.
 *
 * Fixture: tests/fixtures/discovery_ems_esp_20261116.json (Bouis Bundle, von ihm auf EMS-ESP gefiltert,
 * ems-esp/info entfernt).
 * Erwartetes Verhalten nach HA Core (mqtt/text.py): Zustand aus state_topic/value_template als Text,
 * Schreiben sendet den Text (über command_template, falls angegeben) an command_topic. min ist 0 per
 * Vorgabe, ein leerer Text also gültig.
 */

require_once __DIR__ . '/device-harness.php';

error_reporting(E_ALL);

$bundle = json_decode((string)file_get_contents(__DIR__ . '/fixtures/discovery_ems_esp_20261116.json'), true, 512, JSON_THROW_ON_ERROR);

// --- Teil 1: Der Parser liest alle zehn text-Konfigurationen, die Diagnose führt keine als nicht unterstützt.
$parser = new HAMqttDiscoveryParser('homeassistant');
$analyse = $parser->analyzeConfigMessages($bundle['discovery_configs']);
$texte = array_filter($analyse['entities'], static fn(array $e): bool => ($e['component'] ?? '') === 'text');
pruefe(count($texte) === 10, 'Parser: zehn text-Entitäten', (string)count($texte));
$diagnose = json_encode($analyse['diagnostics']['unsupported'] ?? [], JSON_UNESCAPED_UNICODE);
pruefe(!str_contains($diagnose, '"text"') && !str_contains($diagnose, 'text/'), 'Diagnose: text nicht mehr „nicht unterstützt“', $diagnose);

DiscoveryDeviceHarness::$splitterAntwort = [
    'Items'            => $bundle['discovery_configs'],
    'Count'            => count($bundle['discovery_configs']),
    'DiscoveryPrefix'  => 'homeassistant',
    'SessionStartedAt' => time() - 3600,
];
DiscoveryDeviceHarness::$topicPayloadAntwort = ['Items' => $bundle['topic_payloads']];
DiscoveryDeviceHarness::$parentAktiv = true;

$splitter = neueFensterInstanz(DiscoverySplitterHarness::class);

function neuesGeraetFuer(string $deviceId): DiscoveryDeviceHarness
{
    global $splitter;
    $g = neueFensterInstanz(DiscoveryDeviceHarness::class);
    IPS_ConnectInstance($g->id(), $splitter->id());
    $g->SetProperty('DeviceID', $deviceId);
    neueAusfuehrung($g)->ApplyChanges();
    return $g;
}

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

$thermostat = neuesGeraetFuer('ems-esp-thermostat');
$vars = variablenNachName($thermostat->id());
$liste = json_encode(array_keys($vars), JSON_UNESCAPED_UNICODE);

// --- Teil 2: Textvariablen mit den Werten aus ems-esp/thermostat_data.
$erwartet = [
    'HK1 Programmschaltzeit Eigen 1'      => '00 mo 05:30 on',
    'HK1 Programmschaltzeit Eigen 2'      => '00 mo 00:00 off',
    'HK1 Urlaubstage'                     => '01.01.2000-01.01.2000',
    'HK1 Feiertage'                       => '01.01.2000-01.01.2000',
    'WWK Programmschaltzeit'              => '00 mo 08:30 on',
    'WWK Zirculationsprogramm Schaltzeit' => '00 mo 05:30 on',
    'WWK Urlaubstage'                     => '01.01.2000-01.01.2000',
    'WWK Feiertage'                       => '01.01.2000-01.01.2000',
    'Datum/Zeit'                          => '16.11.2026 16:53',
];
foreach ($erwartet as $name => $soll) {
    $id = $vars[$name] ?? null;
    pruefe($id !== null, "Variable „{$name}“ angelegt", $liste);
    if ($id === null) {
        continue;
    }
    pruefe(IPS_GetVariable($id)['VariableType'] === VARIABLETYPE_STRING, "„{$name}“ ist eine Textvariable");
    pruefe(GetValue($id) === $soll, "„{$name}“ = " . var_export($soll, true), var_export(GetValue($id), true));
    pruefe(IPS_GetVariable($id)['VariableAction'] !== 0, "„{$name}“ ist schaltbar");
}

// --- Teil 3: Schalten sendet den Text unverändert an das angekündigte Command-Topic.
function schalteText(DiscoveryDeviceHarness $g, string $name, mixed $wert): array
{
    $id = variablenNachName($g->id())[$name] ?? 0;
    DiscoveryDeviceHarness::$mqttSendungen = [];
    if ($id !== 0) {
        neueAusfuehrung($g)->RequestAction(IPS_GetObject($id)['ObjectIdent'], $wert);
    }
    return DiscoveryDeviceHarness::$mqttSendungen;
}

$s = schalteText($thermostat, 'HK1 Programmschaltzeit Eigen 1', '00 mo 06:00 on');
pruefe($s === [['Topic' => 'ems-esp/thermostat/hc1/switchtime1', 'Payload' => '00 mo 06:00 on']],
    'Schaltzeit an ems-esp/thermostat/hc1/switchtime1', json_encode($s));
$s = schalteText($thermostat, 'HK1 Urlaubstage', '24.12.2026-02.01.2027');
pruefe($s === [['Topic' => 'ems-esp/thermostat/hc1/vacations', 'Payload' => '24.12.2026-02.01.2027']],
    'Urlaubstage an ems-esp/thermostat/hc1/vacations', json_encode($s));
// HA: min = 0 als Vorgabe, ein leerer Text ist ein gültiger Wert.
$s = schalteText($thermostat, 'WWK Feiertage', '');
pruefe($s === [['Topic' => 'ems-esp/thermostat/dhw/holidays', 'Payload' => '']], 'Leerer Text wird gesendet', json_encode($s));

// --- Teil 4: Eine neue Zustandsmeldung aktualisiert die Textvariable.
$meldung = json_decode(array_find($bundle['topic_payloads'], static fn(array $r): bool => $r['topic'] === 'ems-esp/thermostat_data')['payload'], true);
$meldung['hc1']['switchtime1'] = '00 mo 06:00 on';
$meldung['datetime'] = '16.11.2026 17:05';
neueAusfuehrung($thermostat)->ReceiveData(json_encode([
    'Topic'   => 'ems-esp/thermostat_data',
    'Payload' => bin2hex(json_encode($meldung, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))
], JSON_THROW_ON_ERROR));
$vars = variablenNachName($thermostat->id());
pruefe(isset($vars['HK1 Programmschaltzeit Eigen 1']) && GetValue($vars['HK1 Programmschaltzeit Eigen 1']) === '00 mo 06:00 on',
    'Meldung: Schaltzeit aktualisiert', var_export(isset($vars['HK1 Programmschaltzeit Eigen 1']) ? GetValue($vars['HK1 Programmschaltzeit Eigen 1']) : null, true));
pruefe(isset($vars['Datum/Zeit']) && GetValue($vars['Datum/Zeit']) === '16.11.2026 17:05',
    'Meldung: Datum/Zeit aktualisiert', var_export(isset($vars['Datum/Zeit']) ? GetValue($vars['Datum/Zeit']) : null, true));

// --- Teil 5: Das Wartungsdatum am Kessel (anderes Gerät, anderes Topic).
$kessel = neuesGeraetFuer('ems-esp-boiler');
$kesselVars = variablenNachName($kessel->id());
pruefe(isset($kesselVars['Wartungsdatum']) && GetValue($kesselVars['Wartungsdatum']) === '01.01.2000',
    'Kessel: Wartungsdatum aus ems-esp/boiler_data', json_encode(array_keys($kesselVars), JSON_UNESCAPED_UNICODE));
$s = schalteText($kessel, 'Wartungsdatum', '01.06.2027');
pruefe($s === [['Topic' => 'ems-esp/boiler/maintenancedate', 'Payload' => '01.06.2027']],
    'Wartungsdatum an ems-esp/boiler/maintenancedate', json_encode($s));

ergebnis();
