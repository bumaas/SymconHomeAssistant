<?php

declare(strict_types=1);

/**
 * Prüft die Attributvariablen eines Lichts im MQTT-Betrieb mit mqtt_statestream am ECHTEN Modul
 * (Home Assistant Device, Bundle-Modus) über den offiziellen Kernel-Stub.
 *
 * statestream sendet bei jeder Zustandsänderung alle Attribute als einzelne Topics, darunter die
 * Fähigkeiten supported_color_modes und supported_features. Jedes dieser Topics glich bis build 207 die
 * Lichtvariablen mit den Attributen aus `$this->entities` ab — unter der Rust-Edition in jeder Ausführung
 * die Konfiguration des letzten ApplyChanges. War das Licht dabei aus, stehen dort nur null-Werte: Die
 * gerade empfangene Helligkeit sprang auf 0, die Farbe wurde leer (doctee, t/142973 #373; am nuc
 * 19.10.2026 nachgestellt: Licht an mit 255 / 2702 K, Symcon zeigte 0 / 0 K).
 *
 * Jede Meldung läuft als MQTT-Nachricht durch ReceiveData(), jede in einer neuen Ausführung.
 *
 * Fixtures (echte Mitschnitte vom nuc, 19.10.2026):
 * - hue_aurelle_config_20261019.json: Konfiguration der Entität (HA_ExportConfigBundleDataUrl)
 * - hue_aurelle_statestream_20261019.txt: statestream-Topics beim Ein- und Ausschalten
 */

require_once __DIR__ . '/device-harness.php';

error_reporting(E_ALL);

const ENTITY = 'light.hue_aurelle';
const BEOBACHTET = ['brightness', 'color_temp_kelvin', 'color_mode', 'hs_color', 'rgb_color', 'xy_color'];

/** @return list<array{zeit:string, attribut:string, payload:string}> */
function ladeMitschnitt(string $name): array
{
    $meldungen = [];
    foreach (file(__DIR__ . '/fixtures/' . $name, FILE_IGNORE_NEW_LINES) as $zeile) {
        if ($zeile === '' || $zeile[0] === '#') {
            continue;
        }
        if (!preg_match('/^(\S+) (\S+)(?: \[retain])? = (.*)$/', $zeile, $treffer)) {
            throw new RuntimeException('Zeile nicht lesbar: ' . $zeile);
        }
        $meldungen[] = ['zeit' => $treffer[1], 'attribut' => $treffer[2], 'payload' => $treffer[3]];
    }
    return $meldungen;
}

$datei = tempnam(sys_get_temp_dir(), 'ha_light_check_');
if ($datei === false) {
    throw new RuntimeException('Temp-Datei für das Bundle nicht angelegt');
}
copy(__DIR__ . '/fixtures/hue_aurelle_config_20261019.json', $datei);
$zeile = json_decode((string)file_get_contents($datei), true, 512, JSON_THROW_ON_ERROR)[0];
$geraet = neuesGeraet([
    'SourceMode' => 'bundle',
    'BundlePath' => $datei,
    'DeviceName' => $zeile['device_name'],
    'DeviceID'   => $zeile['device_id'],
]);

$idents = [];
foreach (BEOBACHTET as $attribut) {
    $idents[$attribut] = neueAusfuehrung($geraet)->rufe('buildSharedAttributeIdent', ENTITY, $attribut);
}

$meldungen = ladeMitschnitt('hue_aurelle_statestream_20261019.txt');
pruefe(count($meldungen) > 60, 'Mitschnitt gelesen (' . count($meldungen) . ' Meldungen)');

// Spur: nach jeder Meldung die Werte aller beobachteten Variablen, die es zu dem Zeitpunkt gibt.
[$domain, $name] = explode('.', ENTITY, 2);
$spur = [];
foreach ($meldungen as $m) {
    neueAusfuehrung($geraet)->ReceiveData(mqttMeldung('statestream/' . $domain . '/' . $name . '/' . $m['attribut'], $m['payload']));
    $werte = [];
    foreach ($idents as $attribut => $ident) {
        $werte[$attribut] = $geraet->variablenId($ident) === null ? null : $geraet->wert($ident);
    }
    $spur[] = [$m, $werte];
}

foreach (['brightness', 'color_temp_kelvin', 'hs_color'] as $attribut) {
    pruefe($geraet->variablenId($idents[$attribut]) !== null, 'Variable ' . $idents[$attribut] . ' angelegt');
}

// 1. Was eine Meldung eines Attributs in seine Variable schreibt, bleibt bis zur nächsten Meldung desselben
//    Attributs stehen. Keine andere Meldung (Zustand, Fähigkeiten, Namen) darf es überschreiben.
foreach (BEOBACHTET as $attribut) {
    $soll = null;
    $abweichungen = [];
    foreach ($spur as [$m, $werte]) {
        if ($werte[$attribut] === null) {
            continue;
        }
        if ($m['attribut'] === $attribut) {
            $soll = $werte[$attribut];
            continue;
        }
        if ($soll !== null && $werte[$attribut] !== $soll) {
            $abweichungen[] = sprintf('%s %s=%s → %s (gemeldet %s)', $m['zeit'], $m['attribut'], $m['payload'], var_export($werte[$attribut], true), var_export($soll, true));
        }
    }
    pruefe($abweichungen === [], $attribut . ' bleibt bis zur nächsten eigenen Meldung stehen'
        . ($abweichungen === [] ? '' : ': ' . count($abweichungen) . ' Abweichungen, erste ' . $abweichungen[0]));
}

// 2. Stand bei eingeschaltetem Licht, nach der letzten Meldung vor dem Ausschalten (supported_features).
$letzteAn = null;
foreach ($spur as $i => [$m]) {
    if ($m['attribut'] === 'state' && $m['payload'] === 'off' && $i > 0 && $letzteAn !== null) {
        break;
    }
    if ($m['attribut'] === 'state' && $m['payload'] === 'on') {
        $letzteAn = $i;
    }
    if ($letzteAn !== null) {
        $anStand = $spur[$i][1];
    }
}
pruefe(isset($anStand) && is_int($anStand['brightness']) && $anStand['brightness'] > 0, 'Licht an: Helligkeit nicht 0 (ist ' . var_export($anStand['brightness'] ?? null, true) . ')');
pruefe(isset($anStand) && $anStand['color_temp_kelvin'] === 2702, 'Licht an: Farbtemperatur 2702 K (ist ' . var_export($anStand['color_temp_kelvin'] ?? null, true) . ')');
pruefe(isset($anStand) && $anStand['color_mode'] === 'color_temp', 'Licht an: Farbmodus color_temp (ist ' . var_export($anStand['color_mode'] ?? null, true) . ')');
pruefe(isset($anStand) && is_string($anStand['hs_color']) && str_contains($anStand['hs_color'], '28.391'), 'Licht an: HS-Farbe gesetzt (ist ' . var_export($anStand['hs_color'] ?? null, true) . ')');

// 3. Stand nach dem Ausschalten: HA meldet null, daraus wird wie im vollen Pfad 0 bzw. ''.
$ausStand = end($spur)[1];
pruefe($ausStand['brightness'] === 0, 'Licht aus: Helligkeit 0 (ist ' . var_export($ausStand['brightness'], true) . ')');
pruefe($ausStand['color_temp_kelvin'] === 0, 'Licht aus: Farbtemperatur 0 (ist ' . var_export($ausStand['color_temp_kelvin'], true) . ')');
pruefe($ausStand['color_mode'] === '', 'Licht aus: Farbmodus leer (ist ' . var_export($ausStand['color_mode'], true) . ')');
pruefe($ausStand['hs_color'] === '', 'Licht aus: HS-Farbe leer (ist ' . var_export($ausStand['hs_color'], true) . ')');

@unlink($datei);
ergebnis();
