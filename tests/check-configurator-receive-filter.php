<?php

declare(strict_types=1);

/**
 * Prüft am ECHTEN Modul Home Assistant Configurator über den Kernel-Stub, dass der Konfigurator keine Meldungen
 * aus dem Datenfluss der Geräte annimmt.
 *
 * Anlass: Messung am nuc (27.11.2026, Home Assistant Splitter mit 31 Geräten, 6 Entitäten und einem Konfigurator).
 * Der Konfigurator hängt mit derselben Datenfluss-ID am Splitter wie die Geräte, wertet aber keine Meldung aus und
 * setzte keinen Empfangsfilter. Der Kernel stellte ihm deshalb jede Meldung des statestream zu, auch die zwei
 * Drittel, die kein Gerät braucht. Der MQTT Discovery Konfigurator hat den Filter seit build 251.
 *
 * Fixture: tests/fixtures/configurator_statestream_20261127.txt, 40 Meldungen aus dem Mitschnitt am MQTT Client
 * des nuc (Debug-Datei, Zeilen „MQTT:RX:PUBLISH“, unverändert übernommen).
 */

require_once __DIR__ . '/device-harness.php';

error_reporting(E_ALL);

$meldungen = [];
foreach (file(__DIR__ . '/fixtures/configurator_statestream_20261127.txt', FILE_IGNORE_NEW_LINES) as $zeile) {
    if (preg_match('/MQTT:RX:PUBLISH \| Topic: (.*?), Payload: (.*)$/', $zeile, $m) === 1) {
        $meldungen[] = ['topic' => $m[1], 'payload' => $m[2]];
    }
}
pruefe(count($meldungen) === 40, 'Fixture: 40 Meldungen aus dem Mitschnitt', (string)count($meldungen));

$splitter = neueFensterInstanz(SplitterHarness::class);
ConfiguratorHarness::$parentAktiv = true;
$konfigurator = neueFensterInstanz(ConfiguratorHarness::class);
IPS_ConnectInstance($konfigurator->id(), $splitter->id());
neueAusfuehrung($konfigurator)->ApplyChanges();

$filter = $konfigurator->GetReceiveDataFilter();
pruefe($filter === '^$', 'Konfigurator: Empfangsfilter lässt nichts durch', var_export($filter, true));

// So prüft der Kernel: das Muster gegen das Datenpaket, wie der Splitter es an seine Kinder gibt.
$zugestellt = 0;
foreach ($meldungen as $meldung) {
    $paket = json_encode([
        'DataID'  => HAIds::DATA_SPLITTER_TO_DEVICE,
        'Topic'   => $meldung['topic'],
        'Payload' => bin2hex($meldung['payload']),
    ], JSON_THROW_ON_ERROR);
    if (preg_match('/' . $filter . '/', $paket) === 1) {
        $zugestellt++;
    }
}
pruefe($zugestellt === 0, 'Von 40 Meldungen des statestream erreicht keine den Konfigurator', $zugestellt . ' zugestellt');

ConfiguratorHarness::$parentAktiv = false;
ergebnis();
