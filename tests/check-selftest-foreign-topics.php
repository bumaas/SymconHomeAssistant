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

ergebnis();
