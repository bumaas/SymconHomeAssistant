<?php

declare(strict_types=1);

/**
 * Prüft am ECHTEN Modul (Home Assistant MQTT Discovery Device) über den Kernel-Stub die Erreichbarkeit von
 * Geräten aus Z-Wave JS UI.
 *
 * Anlass: herbertf, PN t/145051 (26.11.2026): Alle Geräte standen auf „Nicht erreichbar“, obwohl sie liefen.
 * Z-Wave JS UI kündigt je Entität drei availability-Topics an (Knoten, MQTT-Client des Gateways, Treiber). Zwei
 * davon tragen ein Template, das nur prüft, ob ein Feld gesetzt ist:
 *   {{'true' if value_json.value else 'false'}}
 *   {{'online' if value_json.value else 'offline'}}
 * Der Parser kannte als Bedingung nur `is defined`, `|length` und Vergleiche. Das Template galt als nicht
 * unterstützt, verglichen wurde der rohe JSON-Text mit „true“ bzw. „online“, also nie verfügbar.
 *
 * Erwartet wie in Jinja: Die Bedingung gilt, wenn das Feld da ist und nicht false, null, 0, leerer Text oder
 * leere Liste ist.
 *
 * Fixture: tests/fixtures/discovery_zwavejs_20261125.json (herbertfs Bundle, neutralisiert, Gerät „Raum-Bild“)
 * mit den echten Status-Meldungen. Einen Mitschnitt eines ausgefallenen Knotens gibt es nicht; für diese
 * Richtung dient die echte Meldung mit `value` false.
 */

require_once __DIR__ . '/device-harness.php';

error_reporting(E_ALL);

const KNOTEN = 'Z-Wave-JS/Raum/Bild/status';
const GATEWAY = 'Z-Wave-JS/_CLIENTS/ZWAVE_GATEWAY-zwave-js-ui/status';
const TREIBER = 'Z-Wave-JS/driver/status';

$bundle = json_decode((string)file_get_contents(__DIR__ . '/fixtures/discovery_zwavejs_20261125.json'), true, 512, JSON_THROW_ON_ERROR);
$text = static fn(mixed $p): string => is_string($p) ? $p : json_encode($p, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
$payloads = [];
foreach ($bundle['topic_payloads'] as $satz) {
    $payloads[$satz['topic']] = $text($satz['payload']);
}

// --- Teil 1: Der Parser versteht die Bedingung ohne Vergleich.
$config = is_string($bundle['discovery_configs'][0]['payload']) ? json_decode($bundle['discovery_configs'][0]['payload'], true) : $bundle['discovery_configs'][0]['payload'];
$knotenTemplate = $config['availability'][0]['value_template'];
$gatewayTemplate = $config['availability'][1]['value_template'];
pruefe($knotenTemplate === "{{'true' if value_json.value else 'false'}}", 'Fixture: Template des Knotens wie von Z-Wave JS UI', $knotenTemplate);

$parsed = HAMqttDiscoveryTemplate::parseValueTemplate($knotenTemplate);
pruefe(($parsed['supported'] ?? false) === true, 'Template des Knotens wird unterstützt', json_encode($parsed));
$wert = static fn(mixed $json): mixed => HAMqttDiscoveryTemplate::resolveJsonPathValue($parsed, $json);
foreach ([
    'true'            => [['value' => true], 'true'],
    'false'           => [['value' => false], 'false'],
    'Zahl 1'          => [['value' => 1], 'true'],
    'Zahl 0'          => [['value' => 0], 'false'],
    'Zahl 0.0'        => [['value' => 0.0], 'false'],
    'Text'            => [['value' => 'x'], 'true'],
    'Text „0“'        => [['value' => '0'], 'true'],
    'leerer Text'     => [['value' => ''], 'false'],
    'null'            => [['value' => null], 'false'],
    'leere Liste'     => [['value' => []], 'false'],
    'Liste'           => [['value' => [0]], 'true'],
    'Feld fehlt'      => [['status' => 'Alive'], 'false'],
] as $fall => [$json, $soll]) {
    pruefe($wert($json) === $soll, "Bedingung ohne Vergleich, $fall: $soll", var_export($wert($json), true));
}
$parsedGateway = HAMqttDiscoveryTemplate::parseValueTemplate($gatewayTemplate);
pruefe(HAMqttDiscoveryTemplate::resolveJsonPathValue($parsedGateway, json_decode($payloads[GATEWAY], true)) === 'online',
    'Echte Meldung des Gateways ergibt „online“', var_export(HAMqttDiscoveryTemplate::resolveJsonPathValue($parsedGateway, json_decode($payloads[GATEWAY], true)), true));

// Weiter nicht unterstützt: or und not.
pruefe((HAMqttDiscoveryTemplate::parseValueTemplate("{{'a' if value_json.x or value_json.y else 'b'}}")['supported'] ?? true) === false, 'Bedingung mit or bleibt nicht unterstützt');
pruefe((HAMqttDiscoveryTemplate::parseValueTemplate("{{'a' if not value_json.x else 'b'}}")['supported'] ?? true) === false, 'Bedingung mit not bleibt nicht unterstützt');

// --- Teil 2: Das Gerät ist mit den echten Status-Meldungen erreichbar.
DiscoveryDeviceHarness::$splitterAntwort = [
    'Items'            => $bundle['discovery_configs'],
    'Count'            => count($bundle['discovery_configs']),
    'DiscoveryPrefix'  => 'homeassistant',
    'SessionStartedAt' => time() - 3600,
];
DiscoveryDeviceHarness::$topicPayloadAntwort = ['Items' => $bundle['topic_payloads']];
DiscoveryDeviceHarness::$parentAktiv = true;

function erreichbar(DiscoveryDeviceHarness $g): mixed
{
    $id = @IPS_GetObjectIDByIdent('reachable', $g->id());
    return $id === false ? null : GetValue($id);
}

function melde(DiscoveryDeviceHarness $g, string $topic, string $payload): void
{
    neueAusfuehrung($g)->ReceiveData(mqttMeldung($topic, $payload));
}

$splitter = neueFensterInstanz(DiscoverySplitterHarness::class);
$g = neueFensterInstanz(DiscoveryDeviceHarness::class);
IPS_ConnectInstance($g->id(), $splitter->id());
$g->SetProperty('DeviceID', 'zwavejs2mqtt_0x00000001_node23');
neueAusfuehrung($g)->ApplyChanges();
pruefe(erreichbar($g) === true, 'Nach dem Übernehmen erreichbar (Knoten Alive, Gateway und Treiber online)', var_export(erreichbar($g), true));

// Neues Gerät ohne zwischengespeicherte Meldungen, die Status-Meldungen kommen live.
DiscoveryDeviceHarness::$topicPayloadAntwort = ['Items' => []];
$g2 = neueFensterInstanz(DiscoveryDeviceHarness::class);
IPS_ConnectInstance($g2->id(), $splitter->id());
$g2->SetProperty('DeviceID', 'zwavejs2mqtt_0x00000001_node23');
neueAusfuehrung($g2)->ApplyChanges();
melde($g2, TREIBER, $payloads[TREIBER]);
melde($g2, GATEWAY, $payloads[GATEWAY]);
melde($g2, KNOTEN, $payloads[KNOTEN]);
pruefe(erreichbar($g2) === true, 'Live: nach den drei echten Status-Meldungen erreichbar', var_export(erreichbar($g2), true));

// --- Teil 3: Fällt der Knoten aus, gilt das Gerät als nicht erreichbar, und es erholt sich wieder.
$aus = json_decode($payloads[KNOTEN], true, 512, JSON_THROW_ON_ERROR);
$aus['value'] = false;
$aus['status'] = 'Dead';
melde($g2, KNOTEN, json_encode($aus, JSON_THROW_ON_ERROR));
pruefe(erreichbar($g2) === false, 'Knoten meldet value false: nicht erreichbar', var_export(erreichbar($g2), true));
melde($g2, KNOTEN, $payloads[KNOTEN]);
pruefe(erreichbar($g2) === true, 'Knoten meldet wieder Alive: erreichbar', var_export(erreichbar($g2), true));
// Ein schlafender Knoten (Batteriegerät) meldet value true und bleibt erreichbar.
$schlaeft = json_decode($payloads[KNOTEN], true, 512, JSON_THROW_ON_ERROR);
$schlaeft['status'] = 'Asleep';
melde($g2, KNOTEN, json_encode($schlaeft, JSON_THROW_ON_ERROR));
pruefe(erreichbar($g2) === true, 'Schlafender Knoten bleibt erreichbar', var_export(erreichbar($g2), true));
// Das Gateway verliert die Verbindung (Last Will): value false.
melde($g2, GATEWAY, '{"value":false,"time":1795626206701}');
pruefe(erreichbar($g2) === false, 'Gateway meldet value false: nicht erreichbar', var_export(erreichbar($g2), true));

DiscoveryDeviceHarness::$splitterAntwort = null;
DiscoveryDeviceHarness::$topicPayloadAntwort = null;
DiscoveryDeviceHarness::$parentAktiv = false;
ergebnis();
