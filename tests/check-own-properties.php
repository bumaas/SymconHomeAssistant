<?php

declare(strict_types=1);

/**
 * Store-Review Punkt 13 (02.11.2026): Nach dem Anlegen gehören die Properties einer Instanz dem
 * Anwender. Das Modul ändert sie nicht selbst, also kein IPS_SetProperty auf die eigene Instanz;
 * andere Werte darf es nur per UpdateFormField vorschlagen.
 *
 * Bis build 217 taten genau das HA_ActivateBundleMode/HA_ActivateMqttMode (Device) und
 * HAMD_ActivateBundleMode/HAMD_ActivateMqttMode (MQTT Discovery Splitter): IPS_SetProperty auf
 * SourceMode, BundlePath usw., danach IPS_ApplyChanges. Sie sind entfallen; ein Skript des Anwenders
 * schaltet den Bundle-Modus mit denselben Aufrufen selbst um (README).
 *
 * Statischer Test über alle module.php und libs/: kein IPS_SetProperty($this->InstanceID, …), und
 * die vier Funktionen gibt es nicht mehr. Keine Fixture.
 */

require_once __DIR__ . '/harness.php';

$root = dirname(__DIR__);
$dateien = glob($root . '/*/module.php');
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/libs', FilesystemIterator::SKIP_DOTS));
foreach ($it as $datei) {
    if ($datei->getExtension() === 'php') {
        $dateien[] = $datei->getPathname();
    }
}
pruefe(count($dateien) > 8, 'Quelltexte gefunden', (string)count($dateien));

foreach ($dateien as $datei) {
    $code = (string)file_get_contents($datei);
    $treffer = preg_match_all('/IPS_SetProperty\(\s*\$this->InstanceID\b/', $code);
    $name = substr(str_replace('\\', '/', $datei), strlen(str_replace('\\', '/', $root)) + 1);
    pruefe($treffer === 0, $name . ': kein IPS_SetProperty auf die eigene Instanz', $treffer . ' Aufruf(e)', true);
}

foreach (
    [
        'Home Assistant Device'                  => ['ActivateBundleMode', 'ActivateMqttMode'],
        'Home Assistant MQTT Discovery Splitter' => ['ActivateBundleMode', 'ActivateMqttMode'],
    ] as $modul => $funktionen
) {
    $code = (string)file_get_contents($root . '/' . $modul . '/module.php');
    foreach ($funktionen as $funktion) {
        pruefe(!preg_match('/public\s+function\s+' . $funktion . '\s*\(/', $code), $modul . ': öffentliche Funktion ' . $funktion . ' entfallen');
    }
}

ergebnis();
