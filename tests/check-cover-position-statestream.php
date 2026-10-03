<?php

declare(strict_types=1);

/**
 * Prüft die Hauptvariable eines Rollladens (cover) im MQTT-Betrieb mit mqtt_statestream am ECHTEN
 * Modul (Home Assistant Device, Bundle-Modus) über den offiziellen Kernel-Stub.
 *
 * statestream sendet Zustand und Attribute als getrennte Topics, den Zustand zuerst. Die Position
 * einer Meldung „state = open" steht deshalb nicht in der Meldung selbst, sondern im State-Cache
 * (letztes current_position-Topic). Unter der Rust-Edition beginnt jede Meldung in einer frischen
 * PHP-Ausführung; die Entität wird dann aus der Konfiguration rehydriert, deren current_position den
 * Stand des letzten ApplyChanges trägt (hier 100). Bis build 183 sprang die Hauptvariable so bei
 * jedem „state = open" auf 100, obwohl Home Assistant nie 100 gemeldet hat.
 *
 * Jede Meldung läuft als MQTT-Nachricht durch ReceiveData(), jede in einer neuen Ausführung.
 *
 * Fixtures (echte Mitschnitte vom nuc, 03.10.2026):
 * - cover_config_gast_20261003.json: Konfiguration der Instanz (HA_ExportConfigBundleDataUrl)
 * - cover_statestream_gast_20261003.txt: statestream-Topics der Entität samt Symcon-Spur
 */

require_once __DIR__ . '/device-harness.php';

error_reporting(E_ALL);

const ENTITY = 'cover.gast_licht_rolladen';

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

function geraetAusKonfiguration(string $name): array
{
    $datei = tempnam(sys_get_temp_dir(), 'ha_cover_check_');
    if ($datei === false) {
        throw new RuntimeException('Temp-Datei für das Bundle nicht angelegt');
    }
    copy(__DIR__ . '/fixtures/' . $name, $datei);
    $zeile = json_decode((string)file_get_contents($datei), true, 512, JSON_THROW_ON_ERROR)[0];

    $g = neuesGeraet([
        'SourceMode' => 'bundle',
        'BundlePath' => $datei,
        'DeviceName' => $zeile['device_name'],
        'DeviceID'   => $zeile['device_id'],
    ]);
    return [$g, $datei];
}

/**
 * Spielt den Mitschnitt ab und liefert je Meldung [Zeit, Attribut, Payload, Wert der Hauptvariable].
 *
 * @return list<array{0:string, 1:string, 2:string, 3:mixed}>
 */
function abspielen(DeviceHarness $g, array $meldungen, string $ident): array
{
    [$domain, $name] = explode('.', ENTITY, 2);
    $spur = [];
    foreach ($meldungen as $m) {
        neueAusfuehrung($g)->ReceiveData(mqttMeldung('statestream/' . $domain . '/' . $name . '/' . $m['attribut'], $m['payload']));
        $spur[] = [$m['zeit'], $m['attribut'], $m['payload'], $g->wert($ident)];
    }
    return $spur;
}

[$geraet, $bundle] = geraetAusKonfiguration('cover_config_gast_20261003.json');
$mainIdent = neueAusfuehrung($geraet)->rufe('getSharedEntityMainIdent', ENTITY);
pruefe($geraet->variablenId($mainIdent) !== null, 'Hauptvariable ' . $mainIdent . ' angelegt');

$meldungen = ladeMitschnitt('cover_statestream_gast_20261003.txt');
pruefe(count($meldungen) > 50, 'Mitschnitt gelesen (' . count($meldungen) . ' Meldungen)');
$spur = abspielen($geraet, $meldungen, $mainIdent);

// 1. Sobald eine Position gemeldet wurde, folgt die Hauptvariable allein den Positionen.
//    Home Assistant meldet in diesem Mitschnitt nie 100.
$letztePosition = null;
$abweichungen = [];
foreach ($spur as [$zeit, $attribut, $payload, $wert]) {
    if ($attribut === 'current_position') {
        $letztePosition = (float)$payload;
    }
    if ($letztePosition === null) {
        continue;
    }
    if (!is_numeric($wert) || abs((float)$wert - $letztePosition) > 0.001) {
        $abweichungen[] = sprintf('%s %s=%s → %s (Position %s)', $zeit, $attribut, $payload, var_export($wert, true), $letztePosition);
    }
}
pruefe($abweichungen === [], 'Hauptvariable folgt der zuletzt gemeldeten Position'
    . ($abweichungen === [] ? '' : ': ' . count($abweichungen) . ' Abweichungen, erste ' . $abweichungen[0]));

// 2. Kein einziger Wert 100 nach der ersten Positionsmeldung — so zeigte sich der Fehler am nuc.
$sprungAuf100 = array_filter(
    array_slice($spur, (int)array_search('current_position', array_column($spur, 1), true)),
    static fn(array $s): bool => is_numeric($s[3]) && (float)$s[3] === 100.0
);
pruefe($sprungAuf100 === [], 'kein Sprung auf 100 (' . count($sprungAuf100) . ' Meldungen mit 100)');

// 3. Jede einzelne „state = open"-Meldung mit bekannter Position lässt die Position stehen.
$letztePosition = null;
foreach ($spur as [$zeit, $attribut, $payload, $wert]) {
    if ($attribut === 'current_position') {
        $letztePosition = (float)$payload;
        continue;
    }
    if ($attribut === 'state' && $payload === 'open' && $letztePosition !== null) {
        pruefe(is_numeric($wert) && (float)$wert === $letztePosition, sprintf('%s state=open lässt Position %s stehen (Wert %s)', $zeit, $letztePosition, var_export($wert, true)));
    }
}

// 4. Endstand wie in Home Assistant.
pruefe($geraet->wert($mainIdent) == 19, 'Endstand 19 (ist ' . var_export($geraet->wert($mainIdent), true) . ')');

@unlink($bundle);
ergebnis();
