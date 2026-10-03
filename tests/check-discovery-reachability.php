<?php

declare(strict_types=1);

/**
 * Prüft am ECHTEN Modul (Home Assistant MQTT Discovery Device) über den offiziellen Kernel-Stub die
 * Variable „Erreichbar" für Discovery-Geräte (MCP-Regeln 3 und 11, Fall B; Abstimmung Burkhard 03.10.2026).
 *
 * Viele MQTT-Geräte melden ihre Erreichbarkeit selbst (availability-Topic, Zigbee2MQTT: {"state":"offline"}).
 * Das Modul wertete das aus, zeigte es aber nur in der Zusammenfassung der Instanz („online 0 | offline 21").
 * Ein komplett offline gemeldetes Gerät stand auf Status 102, ohne Variable und ohne Log; seine Werte
 * sahen aktuell aus.
 *
 * Jetzt nach dem Muster von Device/Entity: Variable „reachable", wenn mindestens eine Entität ein
 * availability-Topic hat; nicht erreichbar, wenn alle Entitäten mit bekanntem Stand offline melden
 * („unbekannt" ist kein Ausfall); Wechsel als Warnung bzw. Meldung im Log. Keine eigene Entprellung —
 * die Bridge urteilt mit ihrer eigenen Frist. Instanzstatus bleibt 102.
 *
 * Fixture: tests/fixtures/discovery_availability_20261003.json (Heizkörperthermostat mit zwei
 * availability-Topics im Modus „all", echtes Payload-Format; aus einem Anwender-Bundle, neutralisiert).
 */

require_once __DIR__ . '/device-harness.php';

error_reporting(E_ALL);

const GERAET = 'zigbee2mqtt_0x00000000000000a1';
const BRIDGE = 'zigbee2mqtt/bridge/state';
const VERFUEGBAR = 'zigbee2mqtt/Raum/Heizkoerper/availability';
const REACHABLE = 'reachable';

$fixture = json_decode((string)file_get_contents(__DIR__ . '/fixtures/discovery_availability_20261003.json'), true, 512, JSON_THROW_ON_ERROR);
$offline = $fixture['payloads'][VERFUEGBAR];
$online = $fixture['payloads']['zigbee2mqtt/Raum/Nachbar/availability'];
pruefe($offline === '{"state":"offline"}' && $online === '{"state":"online"}', 'Fixture: echte Online- und Offline-Meldung');

DiscoveryDeviceHarness::$splitterAntwort = [
    'Items'            => $fixture['discovery_configs'],
    'Count'            => count($fixture['discovery_configs']),
    'DiscoveryPrefix'  => 'homeassistant',
    'SessionStartedAt' => time() - 3600,
];
DiscoveryDeviceHarness::$parentAktiv = true;

/** @return list<int> */
function logTypen(IPSModuleStrict $g): array
{
    return array_map(static fn(array $m): int => $m['Type'], IPS\LogServer::getLogMessages((string)$g->id()));
}

function logTexte(IPSModuleStrict $g): string
{
    return json_encode(array_column(IPS\LogServer::getLogMessages((string)$g->id()), 'Message'), JSON_UNESCAPED_UNICODE);
}

function wert(DiscoveryDeviceHarness $g): mixed
{
    $id = @IPS_GetObjectIDByIdent(REACHABLE, $g->id());
    return $id === false ? null : GetValue($id);
}

function melde(DiscoveryDeviceHarness $g, string $topic, string $payload): void
{
    neueAusfuehrung($g)->ReceiveData(mqttMeldung($topic, $payload));
}

$splitter = neueFensterInstanz(DiscoverySplitterHarness::class);
$g = neueFensterInstanz(DiscoveryDeviceHarness::class);
IPS_ConnectInstance($g->id(), $splitter->id());
$g->SetProperty('DeviceID', GERAET);
neueAusfuehrung($g)->ApplyChanges();

// 1. Variable angelegt, startet als erreichbar (Stand noch unbekannt), kein Logeintrag.
pruefe(wert($g) === true, 'Variable „Erreichbar" angelegt, startet erreichbar', var_export(wert($g), true));
pruefe(logTypen($g) === [], 'Anlegen schreibt nichts ins Log', logTexte($g));

// 2. Bridge online, Gerät online → erreichbar, kein Log.
melde($g, BRIDGE, $online);
melde($g, VERFUEGBAR, $online);
pruefe(wert($g) === true && logTypen($g) === [], 'Online: erreichbar, kein Log', logTexte($g));

// 3. Gerät meldet offline (Modus all: eine Quelle offline genügt) → nicht erreichbar, eine Warnung.
melde($g, VERFUEGBAR, $offline);
pruefe(wert($g) === false, 'Offline: nicht erreichbar', var_export(wert($g), true));
pruefe(logTypen($g) === [KL_WARNING], 'Offline: eine Warnung im Log', logTexte($g));

// 4. Wiederholte Offline-Meldung und ApplyChanges (Kernel-Neustart) → keine zweite Warnung, bleibt aus.
melde($g, VERFUEGBAR, $offline);
neueAusfuehrung($g)->ApplyChanges();
pruefe(wert($g) === false && logTypen($g) === [KL_WARNING], 'Wiederholung und Neustart: bleibt aus, keine zweite Warnung', logTexte($g));
$name = IPS_GetName((int)IPS_GetObjectIDByIdent(REACHABLE, $g->id()));
pruefe(!str_contains($name, '(') && $name !== '', 'Neustart: Variable gilt nicht als veraltet', $name);

// 5. Gerät wieder online → erreichbar, Meldung im Log.
melde($g, VERFUEGBAR, $online);
pruefe(wert($g) === true, 'Wieder online: erreichbar', var_export(wert($g), true));
pruefe(logTypen($g) === [KL_WARNING, KL_MESSAGE], 'Wieder online: Meldung im Log', logTexte($g));

// 6. Instanzstatus bleibt 102 (Abstimmung 30.09.2026, wie Device/Entity).
pruefe($g->status() === IS_ACTIVE, 'Instanzstatus bleibt 102', (string)$g->status());

// 7. Ein Gerät ohne availability-Topic bekommt keine Variable.
$ohne = array_map(static function (array $r): array {
    $p = json_decode($r['payload'], true);
    unset($p['availability'], $p['availability_mode'], $p['avty'], $p['avty_mode']);
    $r['payload'] = json_encode($p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $r;
}, $fixture['discovery_configs']);
DiscoveryDeviceHarness::$splitterAntwort['Items'] = $ohne;
$h = neueFensterInstanz(DiscoveryDeviceHarness::class);
IPS_ConnectInstance($h->id(), $splitter->id());
$h->SetProperty('DeviceID', GERAET);
neueAusfuehrung($h)->ApplyChanges();
pruefe(@IPS_GetObjectIDByIdent(REACHABLE, $h->id()) === false, 'Ohne availability-Topic: keine Variable');

DiscoveryDeviceHarness::$splitterAntwort = null;
DiscoveryDeviceHarness::$parentAktiv = false;
ergebnis();
