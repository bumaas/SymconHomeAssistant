<?php

declare(strict_types=1);

/**
 * Prüft die Anzeigegenauigkeit (Nachkommastellen) von Zahlensensoren am ECHTEN Modul über den Kernel-Stub.
 *
 * Blindtest 04.10.2026 (Befund 8): Zellspannung 3.392 V erschien als „3 V", 47 Variablen am nuc zeigten
 * abgeschnittene Werte (Batteriespannung 2.98 → „2 V", Strompreis 0.3323 → „0 €/kWh"). Ursache: Ohne
 * Angabe im Zustand setzte das Modul 0 Stellen. Home Assistant führt die Genauigkeit nur in der
 * Entity-Registry (Vorschlag der Integration oder eigene Einstellung), erreichbar allein über die
 * WebSocket-API (config/entity_registry/list_for_display, Feld dp). Sie ist je Sensor verschieden und
 * nicht aus Einheit oder Wert ableitbar — Marstek: AC-Spannung 1, Batterie 2, Zelle 3 Stellen.
 * Abstimmung Burkhard 04.10.2026: WebSocket plus fester Rückfall.
 *
 * Fixture tests/fixtures/display_precision_20261004.json: echte Zustände und Registry-Einträge aus HA
 * (04.10.2026) sowie echte Server-Rahmen der WebSocket-API (Handshake, auth_required, auth_ok, Kopf der
 * list_for_display-Antwort mit 64-Bit-Länge; dessen Nutzlast ersetzt der Test durch die Fixture-Einträge,
 * mit Leerzeichen auf die echte Länge aufgefüllt).
 */

require_once __DIR__ . '/device-harness.php';

error_reporting(E_ALL);

$fixture = json_decode((string)file_get_contents(__DIR__ . '/fixtures/display_precision_20261004.json'), true, 512, JSON_THROW_ON_ERROR);
$rahmen = $fixture['rahmen'];
$registry = $fixture['registry'];
$erwartet = [];
foreach ($registry as $eintrag) {
    if (array_key_exists('dp', $eintrag)) {
        $erwartet[$eintrag['ei']] = $eintrag['dp'];
    }
}

// ---- Teil 1: WebSocket-Rahmen (RFC 6455) an echten Server-Bytes ----
$f = HAWebSocketClient::decodeFrame((string)hex2bin($rahmen['auth_required_hex']));
pruefe($f !== null && $f['fin'] && $f['opcode'] === HAWebSocketClient::OPCODE_TEXT
    && (json_decode($f['payload'], true)['type'] ?? '') === 'auth_required', 'Rahmen auth_required gelesen');
$f = HAWebSocketClient::decodeFrame((string)hex2bin($rahmen['auth_ok_hex']));
pruefe(($f !== null ? json_decode($f['payload'], true)['type'] ?? '' : '') === 'auth_ok', 'Rahmen auth_ok gelesen');

$nutzlast = json_encode(['id' => 1, 'type' => 'result', 'success' => true, 'result' => ['entities' => $registry]], JSON_THROW_ON_ERROR);
$nutzlast = str_pad($nutzlast, $rahmen['result_payload_length'], ' ');
$gross = hex2bin($rahmen['result_header_hex']) . $nutzlast;
$f = HAWebSocketClient::decodeFrame($gross);
pruefe($f !== null && $f['size'] === strlen($gross) && $f['payload'] === $nutzlast, 'Rahmen mit 64-Bit-Länge gelesen (' . strlen($nutzlast) . ' Bytes)');
pruefe(HAWebSocketClient::decodeFrame(substr($gross, 0, 70000)) === null, 'Unvollständiger Rahmen: null (weiterlesen)');

foreach ([5, 300, 70000] as $laenge) {
    $text = str_repeat('x', $laenge);
    $f = HAWebSocketClient::decodeFrame(HAWebSocketClient::encodeClientFrame($text, HAWebSocketClient::OPCODE_TEXT, "\x01\x02\x03\x04"));
    pruefe($f !== null && $f['payload'] === $text, 'Client-Rahmen maskiert und zurückgelesen, ' . $laenge . ' Bytes');
}
// Echter Handshake mit dem Beispielschlüssel aus RFC 6455 (Sec-WebSocket-Key dGhlIHNhbXBsZSBub25jZQ==)
preg_match('#Sec-WebSocket-Accept:\s*(\S+)#i', $rahmen['handshake'], $treffer);
pruefe(HAWebSocketClient::computeAcceptKey('dGhlIHNhbXBsZSBub25jZQ==') === ($treffer[1] ?? ''), 'Handshake-Prüfwert wie von HA berechnet');

pruefe(HAWebSocketClient::buildWebSocketUrl('http://192.168.178.109:8123') === 'ws://192.168.178.109:8123/api/websocket', 'Adresse http → ws');
pruefe(HAWebSocketClient::buildWebSocketUrl('https://ha.example.org/') === 'wss://ha.example.org/api/websocket', 'Adresse https → wss');
pruefe(HAWebSocketClient::buildWebSocketUrl('homeassistant.local:8123') === null, 'Adresse ohne Schema: keine Verbindung');

// ---- Teil 2: Splitter holt die Registry einmal und gibt jedem Kind nur seine Entitäten ----
$splitter = neueFensterInstanz(SplitterHarness::class);
$splitter->SetProperty('HAToken', 'test');
$splitter->ApplyChanges();
SplitterHarness::$registryAntwort = ['ok' => true, 'result' => ['entities' => $registry]];
SplitterHarness::$registryAbrufe = 0;
$anfrage = static fn(array $ids): array => json_decode(neueAusfuehrung($splitter)->ForwardData(json_encode([
    'DataID'           => HAIds::DATA_DEVICE_TO_SPLITTER,
    'DisplayPrecision' => $ids,
], JSON_THROW_ON_ERROR)), true);
$antwort = $anfrage(['sensor.marstek_venus_modbus_batteriepack_1_zelle_1_spannung', 'sensor.alpstuga_air_quality_monitor_luftfeuchtigkeit', 'sensor.gibt_es_nicht']);
pruefe(($antwort['Ok'] ?? null) === true, 'Splitter: Abfrage erfolgreich');
pruefe(($antwort['Map'] ?? null) === ['sensor.marstek_venus_modbus_batteriepack_1_zelle_1_spannung' => 3],
    'Splitter: nur angefragte Entitäten mit Angabe', json_encode($antwort['Map'] ?? null));
$anfrage(['sensor.keller_energie_kuhlschrank_spannung']);
pruefe(SplitterHarness::$registryAbrufe === 1, 'Splitter: zweite Anfrage aus dem Zwischenspeicher', (string)SplitterHarness::$registryAbrufe);

$splitter2 = neueFensterInstanz(SplitterHarness::class);
$splitter2->SetProperty('HAToken', 'test');
$splitter2->ApplyChanges();
SplitterHarness::$registryAntwort = ['ok' => false, 'error' => 'WebSocket connect failed'];
$antwort = json_decode(neueAusfuehrung($splitter2)->ForwardData(json_encode(['DataID' => HAIds::DATA_DEVICE_TO_SPLITTER, 'DisplayPrecision' => ['sensor.x']], JSON_THROW_ON_ERROR)), true);
pruefe(($antwort['Ok'] ?? null) === false && ($antwort['Map'] ?? null) === [], 'Splitter: Fehlschlag meldet Ok=false und leere Map', json_encode($antwort));

// ---- Teil 3: Device übernimmt die Stellen in die Darstellung ----
$zeilen = [];
foreach ($fixture['zustaende'] as $zustand) {
    $zeilen[] = $zustand + [
        'name'        => $zustand['attributes']['friendly_name'],
        'device_name' => 'Testgerät',
        'device_id'   => 'none',
        'area'        => 'No area',
        'create_var'  => true,
    ];
}
$geraet = neuesGeraet(['DeviceName' => 'Testgerät', 'DeviceID' => 'none']);
$konfiguration = array_map(static fn(array $z): array => $geraet->rufe('buildResolvedEntityRow', $z, true, true), $zeilen);
HaSendungen::$anzeigeGenauigkeit = $erwartet;
$konfiguration = $geraet->rufe('applyDisplayPrecisions', $konfiguration, []);
$geraet->rufe('writeResolvedConfig', json_encode($konfiguration, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$neu = neueAusfuehrung($geraet);
$neu->rufe('processEntities', $neu->rufe('getConfiguredEntities', 'check'), 'statestream');

$stellen = static function (DeviceHarness $g, string $entityId): ?int {
    $id = $g->variablenId((string)$g->rufe('getSharedEntityMainIdent', $entityId));
    return $id === null ? null : (IPS_GetVariable($id)['VariablePresentation']['DIGITS'] ?? null);
};
foreach ($erwartet as $entityId => $dp) {
    pruefe($stellen($geraet, $entityId) === $dp, $entityId . ': ' . $dp . ' Stellen wie in HA', var_export($stellen($geraet, $entityId), true));
}
// Ohne Angabe in HA (dort: ungerundeter Wert): Stellen aus dem Wert, die nur zunehmen, höchstens 3.
// Abstimmung Burkhard 04.10.2026 — feste 2 Stellen hätten am nuc 75 von 90 solchen Sensoren mit glatten
// Werten ein „.00" angehängt (Batterie „80.00 %").
$feuchte = 'sensor.alpstuga_air_quality_monitor_luftfeuchtigkeit';
$batterie = 'sensor.bewegungsmelder_gast_batterie';
$abgleich = static function (DeviceHarness $g): void {
    $n = neueAusfuehrung($g);
    $n->rufe('processEntities', $n->rufe('getConfiguredEntities', 'check'), 'statestream');
};
neueAusfuehrung($geraet)->ReceiveData(mqttMeldung('statestream/sensor/alpstuga_air_quality_monitor_luftfeuchtigkeit/state', '60.05'));
neueAusfuehrung($geraet)->ReceiveData(mqttMeldung('statestream/sensor/bewegungsmelder_gast_batterie/state', '80'));
$abgleich($geraet);
pruefe($stellen($geraet, $feuchte) === 2, 'Ohne Angabe, Wert 60.05: 2 Stellen', var_export($stellen($geraet, $feuchte), true));
pruefe($stellen($geraet, $batterie) === 0, 'Ohne Angabe, glatter Wert 80: 0 Stellen (kein „80.00 %")', var_export($stellen($geraet, $batterie), true));
neueAusfuehrung($geraet)->ReceiveData(mqttMeldung('statestream/sensor/alpstuga_air_quality_monitor_luftfeuchtigkeit/state', '52'));
$abgleich($geraet);
pruefe($stellen($geraet, $feuchte) === 2, 'Danach glatter Wert 52: Stellen bleiben (nur zunehmend)', var_export($stellen($geraet, $feuchte), true));
neueAusfuehrung($geraet)->ReceiveData(mqttMeldung('statestream/sensor/bewegungsmelder_gast_batterie/state', '79.123456'));
$abgleich($geraet);
pruefe($stellen($geraet, $batterie) === 3, 'Höchstens 3 Stellen', var_export($stellen($geraet, $batterie), true));
pruefe(abs(GetValueFloat((int)$geraet->variablenId((string)$geraet->rufe('getSharedEntityMainIdent', $feuchte))) - 52.0) < 1e-9,
    'Wert der Variable unverändert durch den Abgleich');

// Splitter nicht erreichbar: Die Stellen der letzten Konfiguration bleiben, statt auf den Rückfall zu springen.
HaSendungen::$anzeigeGenauigkeit = null;
$ohneSplitter = $geraet->rufe('applyDisplayPrecisions', array_map(static fn(array $z): array => $geraet->rufe('buildResolvedEntityRow', $z, true, true), $zeilen), $konfiguration);
$zelle = array_values(array_filter($ohneSplitter, static fn(array $r): bool => $r['entity_id'] === 'sensor.marstek_venus_modbus_batteriepack_1_zelle_1_spannung'))[0];
pruefe(($zelle['attributes']['display_precision'] ?? null) === 3, 'Ohne Splitter-Antwort: Stellen aus der letzten Konfiguration', json_encode($zelle['attributes']['display_precision'] ?? null));

ergebnis();
