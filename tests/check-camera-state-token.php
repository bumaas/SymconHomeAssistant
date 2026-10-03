<?php

declare(strict_types=1);

/**
 * Prüft am ECHTEN Modul (Home Assistant Device, Bundle-Modus) über den offiziellen Kernel-Stub zwei
 * Befunde an Kamera-Entitäten (Durchsicht am nuc 03.10.2026, HIKVISION #58706; Abstimmung Burkhard):
 *
 * 1. Der Kamera-Zustand stand als Rohtext in der Variable („Status = idle"). Eine KI sah weder die
 *    möglichen Werte noch ihre Bedeutung (MCP-Regel 14). Jetzt bleibt der Wert der Rohtext (Skripte und
 *    Ereignisse bleiben gültig), die Darstellung eine Wertanzeige mit den drei Zuständen aus Home
 *    Assistant als Optionen: idle, recording, streaming (wie der Mediaplayer-Status; eine Aufzählung
 *    verlangt in Symcon eine Variablenaktion, siehe check-readonly-enum-presentation.php).
 * 2. Der rotierende Kamera-Schlüssel (access_token, dazu token= in entity_picture) stand in der
 *    gespeicherten Konfiguration und damit im herunterladbaren Config-Bundle. Das Modul braucht ihn
 *    nicht (Vorschau über camera_proxy mit dem Long-Lived-Token des Splitters).
 *
 * Der Testrahmen übersetzt nicht; erwartet sind die englischen Schlüssel der locale.json.
 * Fixture: tests/fixtures/camera_hikvision_20261003.json (Schlüssel durch Platzhalter ersetzt).
 */

require_once __DIR__ . '/device-harness.php';

error_reporting(E_ALL);

const KAMERA = 'camera.hikvision_ds_2cd2686g2_izs_mainstream';
const SCHLUESSEL = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

function enthaeltSchluessel(string $text): bool
{
    return str_contains($text, SCHLUESSEL) || str_contains($text, 'access_token');
}

$fixture = json_decode((string)file_get_contents(__DIR__ . '/fixtures/camera_hikvision_20261003.json'), true, 512, JSON_THROW_ON_ERROR);
$konfiguration = $fixture['konfiguration'];
pruefe(enthaeltSchluessel(json_encode($konfiguration, JSON_THROW_ON_ERROR)), 'Fixture enthält den Kamera-Schlüssel (Ausgangslage)');

$datei = tempnam(sys_get_temp_dir(), 'ha_camera_check_');
if ($datei === false) {
    throw new RuntimeException('Temp-Datei für das Bundle nicht angelegt');
}
file_put_contents($datei, json_encode($konfiguration, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$g = neuesGeraet([
    'SourceMode' => 'bundle',
    'BundlePath' => $datei,
    'DeviceName' => $konfiguration[0]['device_name'],
    'DeviceID'   => $konfiguration[0]['device_id'],
]);

// ---- 1. Kamera-Zustand: Rohwert bleibt, Darstellung ist eine Auswahl ----
$ident = $g->rufe('getSharedEntityMainIdent', KAMERA);
neueAusfuehrung($g)->ReceiveData(mqttMeldung('statestream/camera/hikvision_ds_2cd2686g2_izs_mainstream/state', 'idle'));
pruefe($g->wert($ident) === 'idle', 'Wert bleibt der Rohtext aus HA', var_export($g->wert($ident), true));
$p = IPS_GetVariable((int)$g->variablenId($ident))['VariablePresentation'];
pruefe(($p['PRESENTATION'] ?? '') === VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'Darstellung ist eine Wertanzeige mit Optionen (keine Aufzählung: die verlangt eine Aktion)', (string)($p['PRESENTATION'] ?? ''));
$optionen = [];
foreach (json_decode((string)($p['OPTIONS'] ?? '[]'), true) as $o) {
    $optionen[$o['Value']] = $o['Caption'];
}
pruefe($optionen === ['idle' => 'idle', 'recording' => 'recording', 'streaming' => 'streaming'],
    'Optionen: die drei Kamera-Zustände aus Home Assistant', json_encode($optionen));

// ---- 2. Kein Kamera-Schlüssel in Konfiguration und Export ----
pruefe(!enthaeltSchluessel($g->attribut('ResolvedConfig')), 'Gespeicherte Konfiguration ohne Kamera-Schlüssel (Bundle)');
$export = (string)neueAusfuehrung($g)->ExportConfigBundleDataUrl();
$exportJson = base64_decode(substr($export, strpos($export, ',') + 1));
pruefe(!enthaeltSchluessel($exportJson), 'Config-Bundle ohne Kamera-Schlüssel');

// MQTT-Betrieb: UpdateConfiguration mischt die REST-Zustände in die Konfiguration (mergeStateAttributes).
$zeileOhne = $konfiguration;
foreach ($zeileOhne as &$z) {
    unset($z['attributes']['access_token'], $z['attributes']['entity_picture']);
}
unset($z);
$zustaende = [KAMERA => ['attributes' => [
    'access_token'   => SCHLUESSEL,
    'entity_picture' => '/api/camera_proxy/' . KAMERA . '?token=' . SCHLUESSEL,
    'friendly_name'  => 'ms',
]]];
$gemischt = neueAusfuehrung($g)->rufe('mergeStateAttributes', $zeileOhne, $zustaende);
pruefe(!enthaeltSchluessel(json_encode($gemischt, JSON_THROW_ON_ERROR)), 'REST-Zustände bringen keinen Kamera-Schlüssel in die Konfiguration');

@unlink($datei);
ergebnis();
