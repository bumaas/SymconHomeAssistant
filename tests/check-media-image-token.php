<?php

declare(strict_types=1);

/**
 * Prüft am ECHTEN Modul (Home Assistant Device, Bundle-Modus) über den offiziellen Kernel-Stub, dass die
 * Cover-Adresse eines Mediaplayers ohne den Zugangsschlüssel token= gespeichert und angezeigt wird.
 *
 * Blindtest 04.10.2026: Die Variable „Media URL" (Sonos) enthielt ein HA-Proxy-Token. Gegenprobe am
 * eigenen HA (media_player.wohnzimmer, 04.10.2026): Mit token= liefert /api/media_player_proxy das Bild
 * OHNE jede Anmeldung (HTTP 200), ohne token= nur angemeldet (Long-Lived-Token: HTTP 200, gleiche Größe;
 * ohne: 403). Der Schlüssel in der Adresse ist also selbst eine Zugangsberechtigung. Der Splitter holt
 * das Cover angemeldet, token= wird nicht gebraucht; cache= bleibt (Titelwechsel).
 *
 * Fixture: Konfiguration „Denon Wohnzimmer" aus tests/fixtures/legacy_idents_20261003.json; Adressformat
 * wie aus HA gelesen, Schlüssel durch Platzhalter ersetzt.
 */

require_once __DIR__ . '/device-harness.php';

error_reporting(E_ALL);

const SCHLUESSEL = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
const BILD = '/api/media_player_proxy/media_player.denon_wohnzimmer?token=' . SCHLUESSEL . '&cache=6127587cd1a616a2';

$fixture = json_decode((string)file_get_contents(__DIR__ . '/fixtures/legacy_idents_20261003.json'), true, 512, JSON_THROW_ON_ERROR);
$konfiguration = array_values(array_filter($fixture['instanzen'], static fn(array $i): bool => $i['instanz'] === 'Denon Wohnzimmer'))[0]['konfiguration'];
$konfiguration[0]['attributes']['entity_picture'] = BILD;

$datei = tempnam(sys_get_temp_dir(), 'ha_media_check_');
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

// 1. Konfiguration und Export ohne Schlüssel
pruefe(!str_contains($g->attribut('ResolvedConfig'), SCHLUESSEL), 'Gespeicherte Konfiguration ohne token=');
$export = (string)neueAusfuehrung($g)->ExportConfigBundleDataUrl();
pruefe(!str_contains((string)base64_decode(substr($export, strpos($export, ',') + 1)), SCHLUESSEL), 'Config-Bundle ohne token=');

// 2. Attribut-Topic (statestream): Variable „Media URL" ohne Schlüssel, cache= bleibt
neueAusfuehrung($g)->ReceiveData(mqttMeldung('statestream/media_player/denon_wohnzimmer/entity_picture', json_encode(BILD)));
$ident = neueAusfuehrung($g)->rufe('buildSharedAttributeIdent', 'media_player.denon_wohnzimmer', 'media_image_url');
$wert = $g->wert($ident);
pruefe(is_string($wert) && $wert !== '', 'Variable „Media URL" angelegt und gefüllt', var_export($wert, true));
pruefe(is_string($wert) && !str_contains($wert, 'token='), 'Variable „Media URL" ohne token=', (string)$wert);
pruefe(is_string($wert) && str_contains($wert, 'cache=6127587cd1a616a2'), 'cache= bleibt erhalten', (string)$wert);

// 3. REST-Zustände (UpdateConfiguration) bringen keinen Schlüssel in die Konfiguration
$ohne = $konfiguration;
unset($ohne[0]['attributes']['entity_picture']);
$gemischt = neueAusfuehrung($g)->rufe('mergeStateAttributes', $ohne, [
    'media_player.denon_wohnzimmer' => ['attributes' => ['entity_picture' => BILD, 'friendly_name' => 'Denon Wohnzimmer']],
]);
pruefe(!str_contains(json_encode($gemischt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), SCHLUESSEL), 'REST-Zustände ohne token=');

@unlink($datei);
ergebnis();
