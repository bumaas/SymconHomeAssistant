<?php

declare(strict_types=1);

/**
 * Prüft die Diagnosetexte von Splitter und Device am ECHTEN Modul über den offiziellen Kernel-Stub
 * (MCP-Punkt H5, Abstimmung Burkhard 03.10.2026):
 * - „Letzter REST-Timeout" nennt den Zeitpunkt. Bisher stand dort nur Entität, Dienst und Frist
 *   („climate.x | set_temperature | 10s") — ob vor fünf Minuten oder vor drei Wochen, sagte nichts.
 * - Die Diagnosetexte des Device laufen über Translate(). HADiagnosticsTrait schrieb „Letzte
 *   MQTT-Message:" und „Letzter REST-Abruf:" fest auf Deutsch, auch in einer englischen Konsole.
 *   Der Testrahmen übersetzt nicht; erwartet sind deshalb die englischen Schlüssel der locale.json.
 */

require_once __DIR__ . '/device-harness.php';

error_reporting(E_ALL);

// ---- Splitter: REST-Timeout mit Zeitpunkt ----

$splitter = neueFensterInstanz(SplitterHarness::class);
$splitter->SetProperty('RestAckTimeoutSec', 10);
$splitter->ApplyChanges();
$splitter->rufe('addPendingRestAck', 'climate.testauto_klima', 'set_temperature');
$offen = json_decode($splitter->attribut('PendingRestAcks'), true, 512, JSON_THROW_ON_ERROR);
$offen['climate.testauto_klima']['ts'] = time() - 60;
$splitter->attributSetzen('PendingRestAcks', json_encode($offen, JSON_THROW_ON_ERROR));

$vorher = time();
neueAusfuehrung($splitter)->CheckRestAcks();
$timeout = $splitter->attribut('LastRestTimeout');
pruefe(str_starts_with($timeout, 'climate.testauto_klima | set_temperature | 10s'), 'Timeout nennt Entität, Dienst und Frist', $timeout);
pruefe(preg_match('/\| (\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})$/', $timeout, $treffer) === 1, 'Timeout nennt den Zeitpunkt', $timeout);
$zeitpunkt = isset($treffer[1]) ? strtotime($treffer[1]) : false;
pruefe($zeitpunkt !== false && $zeitpunkt >= $vorher && $zeitpunkt <= time(), 'Zeitpunkt ist der des Timeouts', $timeout);

$captions = neueAusfuehrung($splitter)->rufe('buildDiagnosticsCaptions');
pruefe(($captions['DiagRestTimeout'] ?? '') === 'Last REST timeout: ' . $timeout, 'Diagnose-Label zeigt den Timeout samt Zeitpunkt', (string)($captions['DiagRestTimeout'] ?? ''));

// ---- Device: Diagnosetexte über Translate() ----

$datei = tempnam(sys_get_temp_dir(), 'ha_diag_check_');
if ($datei === false) {
    throw new RuntimeException('Temp-Datei für das Bundle nicht angelegt');
}
file_put_contents($datei, json_encode([[
    'entity_id'   => 'sensor.diagnose',
    'name'        => 'Diagnose',
    'domain'      => 'sensor',
    'device_id'   => 'devid_diagnose',
    'device_name' => 'Testgerät',
    'create_var'  => true,
    'attributes'  => [],
]], JSON_THROW_ON_ERROR));
$geraet = neuesGeraet(['SourceMode' => 'bundle', 'BundlePath' => $datei, 'DeviceName' => 'Testgerät', 'DeviceID' => 'devid_diagnose']);
DeviceHarness::$formularFelder = [];
neueAusfuehrung($geraet)->rufe('updateDiagnosticsLabels');
$mqtt = (string)(DeviceHarness::$formularFelder['DiagLastMQTT.caption'] ?? '');
$rest = (string)(DeviceHarness::$formularFelder['DiagLastREST.caption'] ?? '');
pruefe(str_starts_with($mqtt, 'Last MQTT message: '), 'Device: „Letzte MQTT-Message" über Translate()', $mqtt);
pruefe(str_starts_with($rest, 'Last REST fetch: '), 'Device: „Letzter REST-Abruf" über Translate()', $rest);

@unlink($datei);
ergebnis();
