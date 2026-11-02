<?php

declare(strict_types=1);

/**
 * Prüft am ECHTEN Modul (Home Assistant Device, Bundle-Modus) über den offiziellen Kernel-Stub, dass ein
 * Mediaplayer-Cover, das erst im heißen Pfad (statestream-Topic entity_picture) entsteht, neben seinem
 * Player liegt und nicht ganz oben in der Instanz.
 *
 * Code-Review 02.11.2026: updateMediaPlayerCoverMedia legte das Cover mit Basisposition 0 an. Bis
 * build 220 rückte das nächste ApplyChanges es zurecht, seit build 221 setzt das Modul die Position nur
 * noch beim Anlegen (Store-Review Punkt 11). Bei einem Gerät, dessen Player nicht die erste Entität
 * ist, blieb das Cover so dauerhaft vor den Variablen des Players.
 *
 * Fixture: echte Konfiguration „Wintergarten (Musik Assistant)" vom nuc (02.11.2026), ein Taster und
 * dann der Player; active_queue neutralisiert. Die Bildadresse folgt dem Format aus HA, ohne Schlüssel.
 */

require_once __DIR__ . '/device-harness.php';

error_reporting(E_ALL);

const PLAYER = 'media_player.wintergarten_2';
const BILD = '/api/media_player_proxy/media_player.wintergarten_2?cache=6127587cd1a616a2';

$konfiguration = json_decode((string)file_get_contents(__DIR__ . '/fixtures/media_cover_wintergarten_20261102.json'), true, 512, JSON_THROW_ON_ERROR);
pruefe(array_search(PLAYER, array_column($konfiguration, 'entity_id'), true) === 1, 'Fixture: Player ist die zweite Entität');
// Mit Bild in der Konfiguration legt schon der volle Pfad das Cover an (Bezugsposition).
foreach ($konfiguration as $i => $zeile) {
    if ($zeile['entity_id'] === PLAYER) {
        $konfiguration[$i]['attributes']['entity_picture'] = BILD;
    }
}

$datei = tempnam(sys_get_temp_dir(), 'ha_cover_check_');
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

$coverIdent = (string)$g->rufe('buildSharedSuffixIdent', PLAYER, '_media_cover');

// 1. Voller Pfad: Das Cover liegt in der Reihe des Players (am nuc auf 170).
$cover = @IPS_GetObjectIDByIdent($coverIdent, $g->id());
pruefe(is_int($cover) && $cover > 0, 'ApplyChanges legt das Cover an (' . $coverIdent . ')');
if (!is_int($cover) || $cover <= 0) {
    @unlink($datei);
    ergebnis();
}
$position = IPS_GetObject($cover)['ObjectPosition'];
$vonNull = (int)$g->rufe('getMediaPlayerCoverPosition', 0);
pruefe($position !== $vonNull, 'Voller Pfad: Position aus der Basis des Players, nicht ab 0', $position . ' / ' . $vonNull);

// 2. Der Anwender löscht das Cover, dann kommt ein neues Bild über statestream.
IPS_DeleteMedia($cover, true);
neueAusfuehrung($g)->ReceiveData(mqttMeldung('statestream/media_player/wintergarten_2/entity_picture', json_encode(BILD)));
$neu = @IPS_GetObjectIDByIdent($coverIdent, $g->id());
pruefe(is_int($neu) && $neu > 0, 'Heißer Pfad legt das Cover wieder an');
$neuPosition = is_int($neu) && $neu > 0 ? IPS_GetObject($neu)['ObjectPosition'] : null;
pruefe($neuPosition === $position, 'Heißer Pfad: dieselbe Position wie der volle Pfad', var_export($neuPosition, true) . ' / ' . $position);

// 3. Das nächste ApplyChanges lässt die Position stehen (Store-Review Punkt 11).
neueAusfuehrung($g)->ApplyChanges();
pruefe(is_int($neu) && IPS_GetObject($neu)['ObjectPosition'] === $neuPosition, 'ApplyChanges lässt die Position stehen');

// 4. Dieselbe Ursache bei Attributvariablen: Auch sie entstehen im heißen Pfad mit der Basisposition
// der Entität, die dort bis build 225 fehlte (Vorgabe 10 statt 30).
$titelIdent = (string)$g->rufe('buildSharedAttributeIdent', PLAYER, 'media_title');
$titel = @IPS_GetObjectIDByIdent($titelIdent, $g->id());
pruefe(is_int($titel) && $titel > 0, 'ApplyChanges legt „Titel" an (' . $titelIdent . ')');
if (is_int($titel) && $titel > 0) {
    $titelPosition = IPS_GetObject($titel)['ObjectPosition'];
    IPS_DeleteVariable($titel);
    neueAusfuehrung($g)->ReceiveData(mqttMeldung('statestream/media_player/wintergarten_2/media_title', json_encode('Ulili È (Hawaii Set)')));
    $titelNeu = @IPS_GetObjectIDByIdent($titelIdent, $g->id());
    $titelNeuPosition = is_int($titelNeu) && $titelNeu > 0 ? IPS_GetObject($titelNeu)['ObjectPosition'] : null;
    pruefe($titelNeuPosition === $titelPosition, 'Heißer Pfad: „Titel" auf derselben Position wie im vollen Pfad',
        var_export($titelNeuPosition, true) . ' / ' . $titelPosition);
}

@unlink($datei);
ergebnis();
