<?php

declare(strict_types=1);

/**
 * Prüft am ECHTEN Modul (Home Assistant MQTT Discovery Device) über den Kernel-Stub, dass das erneute
 * Einspielen zwischengespeicherter Meldungen beim Übernehmen die „Letzte Aktualisierung" nicht bewegt,
 * solange sich der Wert nicht ändert.
 *
 * Befund 04.10.2026 am nuc: „ID.4 Pro" (CarConnectivity, VW-Dienst stillgelegt) hatte seit 26.06.2026
 * keinen neuen Wert, trug aber eine Aktualisierung von wenigen Minuten. Jedes ApplyChanges — bei einem
 * nicht angekündigten Gerät die Nachprüfung alle 10 Minuten — spielt die Meldungen aus dem Zwischenspeicher
 * des Splitters ein, und jedes SetValue setzt VariableUpdated. Ein totes Gerät wirkt so frisch (Objektbaum,
 * MCP). Eine Meldung aus dem Zwischenspeicher ist keine neue Meldung.
 *
 * Fixture: tests/fixtures/ha_mqtt_discovery_bundle_zigbee2mqtt_light_current_v2.json (echtes, neutralisiertes
 * Zigbee2MQTT-Bundle): Discovery-Configs und zwischengespeicherte Meldungen von „Light Fixture 001".
 */

require_once __DIR__ . '/device-harness.php';

error_reporting(E_ALL);

const GERAET = 'zigbee2mqtt_device_001';
const MARKE = 1;

$bundle = json_decode((string)file_get_contents(__DIR__ . '/fixtures/ha_mqtt_discovery_bundle_zigbee2mqtt_light_current_v2.json'), true, 512, JSON_THROW_ON_ERROR);
$configs = array_values(array_filter($bundle['discovery_configs'], static fn(array $r): bool => str_contains((string)$r['topic'], '/device_001/')));
pruefe($configs !== [], 'Fixture: Discovery-Config von Light Fixture 001');

DiscoveryDeviceHarness::$splitterAntwort = [
    'Items'            => $configs,
    'Count'            => count($configs),
    'DiscoveryPrefix'  => 'homeassistant',
    'SessionStartedAt' => time() - 3600,
];
DiscoveryDeviceHarness::$topicPayloadAntwort = ['Items' => $bundle['topic_payloads']];
DiscoveryDeviceHarness::$parentAktiv = true;

/** Setzt VariableUpdated aller Variablen der Instanz auf eine Marke (Kernel-Zustand des Stubs). */
function markiereAktualisierung(int $instanz): void
{
    $speicher = new ReflectionProperty(IPS\VariableManager::class, 'variables');
    $variablen = $speicher->getValue();
    foreach (IPS_GetChildrenIDs($instanz) as $id) {
        if (isset($variablen[$id])) {
            $variablen[$id]['VariableUpdated'] = MARKE;
        }
    }
    $speicher->setValue(null, $variablen);
}

/** @return array<string, int> Ident => VariableUpdated */
function aktualisierungen(int $instanz): array
{
    $ergebnis = [];
    foreach (IPS_GetChildrenIDs($instanz) as $id) {
        if (IPS_VariableExists($id)) {
            $ergebnis[IPS_GetObject($id)['ObjectIdent']] = IPS_GetVariable($id)['VariableUpdated'];
        }
    }
    return $ergebnis;
}

$splitter = neueFensterInstanz(DiscoverySplitterHarness::class);
$g = neueFensterInstanz(DiscoveryDeviceHarness::class);
IPS_ConnectInstance($g->id(), $splitter->id());
$g->SetProperty('DeviceID', GERAET);
neueAusfuehrung($g)->ApplyChanges();

$vorher = aktualisierungen($g->id());
$mitWert = array_filter($vorher, static fn(int $t): bool => $t > 0);
pruefe(count($mitWert) >= 2, 'Erstes Übernehmen: Variablen aus den zwischengespeicherten Meldungen gefüllt (' . count($mitWert) . ')',
    json_encode($vorher));
// „OFF" gleicht dem Standardwert false der eben angelegten Variable — geschrieben wird trotzdem, sonst
// bliebe sie ohne Zeitstempel (MCP: „01.01.1970").
pruefe(($vorher['light_status'] ?? 0) > 0, 'Erstes Übernehmen: neu angelegte Variable mit Standardwert erhält einen Zeitstempel',
    json_encode($vorher));

// Zweites Übernehmen mit denselben zwischengespeicherten Meldungen (Nachprüfung, Neustart, Übernehmen).
markiereAktualisierung($g->id());
neueAusfuehrung($g)->ApplyChanges();
$bewegt = array_keys(array_filter(aktualisierungen($g->id()), static fn(int $t): bool => $t !== MARKE));
pruefe($bewegt === [], 'Gleiche Meldungen erneut eingespielt: keine Variable gilt als aktualisiert', implode(', ', $bewegt));

// Hat sich eine zwischengespeicherte Meldung geändert, wird geschrieben.
$geaendert = array_map(static function (array $p): array {
    if ($p['topic'] === 'zigbee2mqtt/light_fixture_001') {
        $wert = json_decode($p['payload'], true);
        $wert['state'] = $wert['state'] === 'ON' ? 'OFF' : 'ON';
        $p['payload'] = json_encode($wert, JSON_UNESCAPED_SLASHES);
    }
    return $p;
}, $bundle['topic_payloads']);
DiscoveryDeviceHarness::$topicPayloadAntwort = ['Items' => $geaendert];
markiereAktualisierung($g->id());
neueAusfuehrung($g)->ApplyChanges();
$bewegt = array_keys(array_filter(aktualisierungen($g->id()), static fn(int $t): bool => $t !== MARKE));
pruefe($bewegt !== [], 'Geänderte Meldung im Zwischenspeicher: die betroffene Variable wird geschrieben', implode(', ', $bewegt));

// Eine echte neue Meldung (ReceiveData) aktualisiert auch bei gleichem Wert — das ist eine Meldung des Geräts.
markiereAktualisierung($g->id());
$zustand = array_values(array_filter($geaendert, static fn(array $p): bool => $p['topic'] === 'zigbee2mqtt/light_fixture_001'))[0]['payload'];
neueAusfuehrung($g)->ReceiveData(json_encode([
    'DataID'  => HAIds::DATA_MQTT_DISCOVERY_SPLITTER_TO_DEVICE,
    'Topic'   => 'zigbee2mqtt/light_fixture_001',
    'Payload' => bin2hex($zustand),
], JSON_THROW_ON_ERROR));
$bewegt = array_keys(array_filter(aktualisierungen($g->id()), static fn(int $t): bool => $t !== MARKE));
pruefe($bewegt !== [], 'Neue Meldung des Geräts mit gleichem Wert: Aktualisierung wie bisher', implode(', ', $bewegt));

DiscoveryDeviceHarness::$splitterAntwort = null;
DiscoveryDeviceHarness::$topicPayloadAntwort = null;
DiscoveryDeviceHarness::$parentAktiv = false;
ergebnis();
