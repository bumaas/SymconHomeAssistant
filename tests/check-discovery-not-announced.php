<?php

declare(strict_types=1);

/**
 * Prüft am ECHTEN Modul (Home Assistant MQTT Discovery Device) über den offiziellen Kernel-Stub, dass
 * ein Gerät, das nicht mehr per MQTT Discovery angekündigt wird, nicht still auf „aktiv" bleibt
 * (MCP-Regeln 3 und 11; Abstimmung Burkhard 03.10.2026).
 *
 * Kannte der Splitter das Gerät nicht, griff die Instanz auf die zuletzt gespeicherte Definition
 * zurück und meldete 102 — ohne Ende. Ein Gerät, das nicht mehr angekündigt wird, blieb so dauerhaft
 * „aktiv", auch wenn keine seiner Variablen je einen Wert bekam. Der Rückfall selbst bleibt sinnvoll:
 * Nach einem Neustart von Broker oder Splitter treffen die Ankündigungen erst nach und nach ein.
 *
 * Jetzt: Läuft die MQTT-Sitzung des Splitters seit mindestens 10 Minuten und kennt er das Gerät
 * nicht, geht die Instanz auf 202 und schreibt einmal eine Warnung ins Log. Variablen und gespeicherte
 * Definition bleiben. Davor bleibt sie auf 102 und prüft nach Ablauf der Frist erneut. Kündigt sich
 * das Gerät wieder an, geht sie auf 102 zurück und meldet das im Log.
 *
 * Fixture: echtes Discovery-Bundle tests/fixtures/ha_mqtt_discovery_bundle_ebusd.json (Zigbee2MQTT-Bridge).
 */

require_once __DIR__ . '/device-harness.php';

error_reporting(E_ALL);

const GERAET = 'zigbee2mqtt_bridge_0xe8e07efffe7c7715';
const FRIST = 600;
const STATUS_NICHT_ANGEKUENDIGT = 202;

$bundle = json_decode((string)file_get_contents(__DIR__ . '/fixtures/ha_mqtt_discovery_bundle_ebusd.json'), true, 512, JSON_THROW_ON_ERROR);
$alle = $bundle['discovery_configs'];
$mitGeraet = array_values(array_filter($alle, static fn(array $r): bool => str_contains((string)$r['payload'], GERAET)));
$ohneGeraet = array_values(array_filter($alle, static fn(array $r): bool => !str_contains((string)$r['payload'], GERAET)));
pruefe($mitGeraet !== [] && $ohneGeraet !== [], 'Fixture: Ankündigungen mit und ohne das Gerät', count($mitGeraet) . ' / ' . count($ohneGeraet));

function splitterAntwort(array $datensaetze, ?int $sitzungSeit): void
{
    DiscoveryDeviceHarness::$splitterAntwort = [
        'Items'            => $datensaetze,
        'Count'            => count($datensaetze),
        'DiscoveryPrefix'  => 'homeassistant',
        'SessionStartedAt' => $sitzungSeit === null ? 0 : time() - $sitzungSeit,
    ];
}

/** @return list<int> Typen der Logeinträge der Instanz */
function logTypen(IPSModuleStrict $g): array
{
    return array_map(static fn(array $m): int => $m['Type'], IPS\LogServer::getLogMessages((string)$g->id()));
}

function logTexte(IPSModuleStrict $g): string
{
    return json_encode(array_column(IPS\LogServer::getLogMessages((string)$g->id()), 'Message'), JSON_UNESCAPED_UNICODE);
}

function anwenden(DiscoveryDeviceHarness $g): DiscoveryDeviceHarness
{
    $neu = neueAusfuehrung($g);
    $neu->ApplyChanges();
    return $neu;
}

// Splitter als Parent (aktiv); seine Antwort gibt der Testrahmen vor.
$splitter = neueFensterInstanz(DiscoverySplitterHarness::class);
$g = neueFensterInstanz(DiscoveryDeviceHarness::class);
IPS_ConnectInstance($g->id(), $splitter->id());
DiscoveryDeviceHarness::$parentAktiv = true;
$g->SetProperty('DeviceID', GERAET);

// 1. Gerät angekündigt → aktiv, Variablen angelegt, Definition gespeichert.
splitterAntwort($mitGeraet, 3600);
$g = anwenden($g);
pruefe($g->status() === IS_ACTIVE, 'Angekündigt: Status 102', (string)$g->status());
$variablen = count(array_filter(IPS_GetChildrenIDs($g->id()), static fn(int $id): bool => IPS_GetObject($id)['ObjectType'] === OBJECTTYPE_VARIABLE));
pruefe($variablen > 0, 'Angekündigt: Variablen angelegt (' . $variablen . ')');

// 2. Splitter frisch neu gestartet (Sitzung seit 60 s), Gerät noch nicht wieder angekündigt:
//    Rückfall auf die gespeicherte Definition, Status bleibt 102, Nachprüfung nach Ablauf der Frist.
splitterAntwort($ohneGeraet, 60);
$g = anwenden($g);
pruefe($g->status() === IS_ACTIVE, 'Innerhalb der Frist: Status bleibt 102', (string)$g->status());
$timer = $g->timer('DeferredApply');
pruefe($timer >= (FRIST - 60 - 5) * 1000 && $timer <= (FRIST - 60 + 5) * 1000, 'Innerhalb der Frist: Nachprüfung nach Ablauf geplant', (string)$timer);
pruefe(logTypen($g) === [], 'Innerhalb der Frist: kein Logeintrag', logTexte($g));

// 3. Sitzung läuft seit 11 Minuten, Gerät weiter unbekannt → 202 und genau eine Warnung.
splitterAntwort($ohneGeraet, FRIST + 60);
$g = anwenden($g);
pruefe($g->status() === STATUS_NICHT_ANGEKUENDIGT, 'Nach der Frist: Status 202', (string)$g->status());
pruefe(logTypen($g) === [KL_WARNING], 'Nach der Frist: eine Warnung im Log', logTexte($g));
$nachher = count(array_filter(IPS_GetChildrenIDs($g->id()), static fn(int $id): bool => IPS_GetObject($id)['ObjectType'] === OBJECTTYPE_VARIABLE));
pruefe($nachher === $variablen, 'Nach der Frist: Variablen bleiben erhalten', $nachher . ' statt ' . $variablen);
pruefe($g->timer('DeferredApply') === FRIST * 1000, 'Nach der Frist: regelmäßige Nachprüfung', (string)$g->timer('DeferredApply'));

// 4. Nachprüfung, Gerät weiter unbekannt → keine zweite Warnung.
$g = anwenden($g);
pruefe($g->status() === STATUS_NICHT_ANGEKUENDIGT && logTypen($g) === [KL_WARNING], 'Weiter unbekannt: keine zweite Warnung', logTexte($g));

// 5. Gerät kündigt sich wieder an → 102 und eine Meldung im Log, Nachprüfung aus.
splitterAntwort($mitGeraet, FRIST + 120);
$g = anwenden($g);
pruefe($g->status() === IS_ACTIVE, 'Wieder angekündigt: Status 102', (string)$g->status());
pruefe(logTypen($g) === [KL_WARNING, KL_MESSAGE], 'Wieder angekündigt: Meldung im Log', logTexte($g));
pruefe($g->timer('DeferredApply') === 0, 'Wieder angekündigt: keine Nachprüfung mehr', (string)$g->timer('DeferredApply'));

// 6. Ohne bekannten Sitzungsbeginn (älterer Splitter) wird nicht geurteilt.
splitterAntwort($ohneGeraet, null);
$g = anwenden($g);
pruefe($g->status() === IS_ACTIVE, 'Sitzungsbeginn unbekannt: Status bleibt 102', (string)$g->status());

DiscoveryDeviceHarness::$splitterAntwort = null;
DiscoveryDeviceHarness::$parentAktiv = false;
ergebnis();
