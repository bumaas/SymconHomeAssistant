<?php

declare(strict_types=1);

/**
 * Prüft am ECHTEN Splitter über den Kernel-Stub, dass der Selbsttest nur Meldungen von `mqtt_statestream`
 * als „MQTT-Daten empfangen“ zählt.
 *
 * Anlass: alsk1 (t/142973 #381/#382, 14.11.2026). In HA stand `base_topic: homeassistant_stream`, im Splitter
 * `homeassistant`, abonniert war `homeassistant/#`. Vom statestream kam also nichts an. Der Selbsttest meldete
 * trotzdem „MQTT-Daten empfangen (vor 3s)“ und „Empfangene Domänen: sensor, status, switch“, Fazit „alles in
 * Ordnung“. Gezählt hatte er die Discovery-Konfigurationen anderer MQTT-Geräte (`homeassistant/<domain>/…/config`)
 * und die Startmeldung von HA (`homeassistant/status`), die mit dem Standard-Base-Topic unter demselben Präfix liegen.
 *
 * Fixture tests/fixtures/selftest_fremde_topics_20261114.json: Topics aus echten, schon versionierten Mitschnitten
 * (Discovery-Bundles ebusd/Zigbee2MQTT und mitsubishi2mqtt, statestream-Mitschnitt hue_aurelle vom nuc).
 */

require_once __DIR__ . '/device-harness.php';

error_reporting(E_ALL);

$fixture = json_decode((string)file_get_contents(__DIR__ . '/fixtures/selftest_fremde_topics_20261114.json'), true, 512, JSON_THROW_ON_ERROR);

$splitter = neueFensterInstanz(SplitterHarness::class);
$splitter->SetProperty('HAUrl', 'http://127.0.0.1:9');
$splitter->SetProperty('HAToken', 'test');
$splitter->SetProperty('MQTTBaseTopic', 'homeassistant');
$splitter->ApplyChanges();

$empfange = static function (array $meldung) use ($splitter): void {
    neueAusfuehrung($splitter)->ReceiveData(json_encode([
        'DataID'           => HAIds::DATA_MQTT_RX,
        'PacketType'       => 3,
        'QualityOfService' => 0,
        'Retain'           => true,
        'Topic'            => $meldung['topic'],
        'Payload'          => bin2hex($meldung['payload']),
    ], JSON_THROW_ON_ERROR));
};

// ---- Teil 1: nur fremde Meldungen unter dem Base-Topic (Alex' Lage) ----
foreach ($fixture['fremde_meldungen'] as $meldung) {
    $empfange($meldung);
}
$bericht = neueAusfuehrung($splitter)->RunSelfTest();

pruefe(
    !str_contains($bericht, 'MQTT data received ('),
    'Ohne statestream meldet der Selbsttest keine empfangenen Daten',
    $bericht
);
pruefe(
    str_contains($bericht, 'No MQTT data received yet'),
    'Ohne statestream warnt der Selbsttest mit dem Hinweis auf mqtt_statestream und base_topic',
    $bericht
);
pruefe(
    str_contains($bericht, 'Received domains: none yet'),
    'Discovery-Konfigurationen und homeassistant/status zählen nicht als empfangene Domänen',
    $bericht
);
pruefe(
    preg_match('~Received domains: [^\n]*\bstatus\b~', $bericht) !== 1,
    'Die Startmeldung von HA erscheint nicht als Domäne „status“',
    $bericht
);

// ---- Teil 2: Gegenprobe, echte statestream-Meldungen kommen an ----
foreach ($fixture['statestream_meldungen'] as $meldung) {
    $empfange($meldung);
}
$bericht = neueAusfuehrung($splitter)->RunSelfTest();

pruefe(str_contains($bericht, 'MQTT data received ('), 'Mit statestream meldet der Selbsttest empfangene Daten', $bericht);
pruefe(
    str_contains($bericht, 'Only one domain is delivering data: light'),
    'Als Domäne zählt allein die des statestream (light)',
    $bericht
);

// ---- Teil 3: Neustart ohne Replay (Modul-Update, MQTT Server als Parent) ----
// Die Buffer sind weg, die Verbindung steht noch, bis zur nächsten Zustandsänderung kommt nichts. Bis build 229
// lag der Zeitpunkt nur im Buffer: Eine laufende Anlage meldete direkt nach dem Update „Noch keine MQTT-Daten“.
$splitter->pufferVerwerfen();
$bericht = neueAusfuehrung($splitter)->RunSelfTest();

pruefe(
    str_contains($bericht, 'MQTT data received ('),
    'Nach einem Neustart ohne neue Meldung kennt der Selbsttest den letzten statestream-Empfang noch',
    $bericht
);
pruefe(
    !str_contains($bericht, 'No MQTT data received yet'),
    'Nach einem Neustart warnt der Selbsttest nicht „Noch keine MQTT-Daten empfangen“',
    $bericht
);

// ---- Teil 4: fremde Topics in der Form von statestream ----
// Ein LinkTap-Gateway legt Zustand und Befehle unter homeassistant/linktap/<id>/state bzw. …/set ab: drei
// Segmente, kein „config“. „linktap“ ist keine Domäne von Home Assistant.
$zweiter = neueFensterInstanz(SplitterHarness::class);
$zweiter->SetProperty('HAUrl', 'http://127.0.0.1:9');
$zweiter->SetProperty('HAToken', 'test');
$zweiter->SetProperty('MQTTBaseTopic', 'homeassistant');
$zweiter->ApplyChanges();

$empfangeAn = static function (SplitterHarness $ziel, string $topic, string $payload): void {
    neueAusfuehrung($ziel)->ReceiveData(json_encode([
        'DataID'           => HAIds::DATA_MQTT_RX,
        'PacketType'       => 3,
        'QualityOfService' => 0,
        'Retain'           => false,
        'Topic'            => $topic,
        'Payload'          => bin2hex($payload),
    ], JSON_THROW_ON_ERROR));
};

foreach ($fixture['linktap_meldungen'] as $meldung) {
    $empfangeAn($zweiter, $meldung['topic'], $meldung['payload']);
}
$bericht = neueAusfuehrung($zweiter)->RunSelfTest();

pruefe(
    !str_contains($bericht, 'MQTT data received (') && str_contains($bericht, 'No MQTT data received yet'),
    'Topics eines fremden Geräts in der Form von statestream zählen nicht als empfangene Daten',
    $bericht
);
pruefe(
    str_contains($bericht, 'Received domains: none yet'),
    '„linktap“ erscheint nicht als empfangene Domäne',
    $bericht
);

// Der eigene Befehl: Lehnt HA den REST-Aufruf ab, reicht der Splitter das set-Topic an MQTT weiter. Über das
// Abo <base>/# kommt es zurück und ist ebenfalls keine Meldung von statestream. Das Topic erzeugt der Splitter
// hier selbst (Entität aus rest_set_select_20261015.json).
$befehle = json_decode((string)file_get_contents(__DIR__ . '/fixtures/rest_set_select_20261015.json'), true, 512, JSON_THROW_ON_ERROR);
[$domain, $objekt] = explode('.', (string)$befehle['zustaende'][0]['entity_id'], 2);
SplitterHarness::$mqttWeiterleitungen = [];
SplitterHarness::$haAntwort = static fn(string $url, mixed $daten): array => ['response' => '400: Bad Request', 'httpCode' => 400];
neueAusfuehrung($zweiter)->ForwardData(json_encode([
    'DataID'           => HAIds::DATA_DEVICE_TO_SPLITTER,
    'PacketType'       => 3,
    'QualityOfService' => 0,
    'Retain'           => false,
    'Topic'            => 'homeassistant/' . $domain . '/' . $objekt . '/set',
    'Payload'          => bin2hex('x'),
], JSON_THROW_ON_ERROR));
SplitterHarness::$haAntwort = null;
$setTopic = SplitterHarness::$mqttWeiterleitungen[0] ?? '';
pruefe(str_ends_with($setTopic, '/' . $objekt . '/set'), 'Vorbedingung: Der abgelehnte Befehl geht als set-Topic an MQTT', $setTopic);

$empfangeAn($zweiter, $setTopic, 'x');
$bericht = neueAusfuehrung($zweiter)->RunSelfTest();

pruefe(
    !str_contains($bericht, 'MQTT data received (') && str_contains($bericht, 'Received domains: none yet'),
    'Das zurückkommende eigene set-Topic zählt nicht als Meldung von statestream',
    $bericht
);

ergebnis();
