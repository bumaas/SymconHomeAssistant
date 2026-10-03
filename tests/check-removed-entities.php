<?php

declare(strict_types=1);

/**
 * Prüft am ECHTEN Modul (Home Assistant Device, Bundle-Modus) über den offiziellen Kernel-Stub, dass
 * die Variablen einer in Home Assistant entfernten Entität beim Aktualisieren der Konfiguration
 * „(veraltet)" werden.
 *
 * processEntities() kannte die bisherigen Entitäten nur aus $this->entities. Unter der Rust-Edition
 * beginnt jeder Aufruf (HA_UpdateConfiguration, ApplyChanges) in einer frischen PHP-Ausführung, das Feld
 * ist leer — eine entfernte Entität war damit unbekannt und ihre Variablen blieben schaltbar stehen.
 * Am nuc (03.10.2026, homematic-ccu3 #43783) nach dem Löschen von zehn verwaisten Entitäten in HA
 * belegt: HA_UpdateConfiguration entfernte sie aus der Konfiguration, die Variablen blieben unverändert.
 *
 * Jeder Schritt läuft in einer neuen Ausführung (neueAusfuehrung), wie unter Rust.
 *
 * Fixture: tests/fixtures/removed_entities_ccu3_20261003.json (siehe dort „quelle").
 */

require_once __DIR__ . '/device-harness.php';

error_reporting(E_ALL);

const ZUSATZ = ' (veraltet)';

/** @return array<string, array{name:string, aktion:bool}> je Ident */
function bestand(DeviceHarness $g): array
{
    $bestand = [];
    foreach (IPS_GetChildrenIDs($g->id()) as $id) {
        $o = IPS_GetObject($id);
        if ($o['ObjectType'] === OBJECTTYPE_VARIABLE) {
            $bestand[$o['ObjectIdent']] = ['name' => $o['ObjectName'], 'aktion' => IPS_GetVariable($id)['VariableAction'] > 0];
        }
    }
    return $bestand;
}

$fixture = json_decode((string)file_get_contents(__DIR__ . '/fixtures/removed_entities_ccu3_20261003.json'), true, 512, JSON_THROW_ON_ERROR);
$zeile = $fixture['konfiguration_vorher'][0];

$datei = tempnam(sys_get_temp_dir(), 'ha_removed_check_');
if ($datei === false) {
    throw new RuntimeException('Temp-Datei für das Bundle nicht angelegt');
}
file_put_contents($datei, json_encode($fixture['konfiguration_vorher'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

// Ausgangslage wie am nuc vor dem Aktualisieren: 25 Entitäten, Variablen mit Namen und Aktionen von dort.
$g = neuesGeraet([
    'SourceMode' => 'bundle',
    'BundlePath' => $datei,
    'DeviceName' => $zeile['device_name'],
    'DeviceID'   => $zeile['device_id'],
]);
foreach ($fixture['variablen'] as $v) {
    $id = $g->variablenId($v['ident']);
    if ($id === null) {
        $id = IPS_CreateVariable($v['typ']);
        IPS_SetParent($id, $g->id());
        IPS_SetIdent($id, $v['ident']);
    }
    IPS_SetName($id, $v['name']);
    IPS\VariableManager::setVariableAction($id, $v['aktion'] ? $g->id() : 0);
}
$entferntIdents = array_map(static fn(string $e): string => str_replace('.', '_', $e), $fixture['entfernt']);
foreach ($entferntIdents as $ident) {
    pruefe($g->variablenId($ident) !== null, 'Ausgangslage: Variable ' . $ident . ' vorhanden');
}
$vorher = bestand($g);

// In HA gelöscht: die Konfiguration kommt ohne die zehn Entitäten, Aktualisieren in neuer Ausführung.
file_put_contents($datei, json_encode($fixture['konfiguration_nachher'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
neueAusfuehrung($g)->UpdateConfiguration();
$nachher = bestand($g);

// 1. Die Variablen der entfernten Entitäten sind gekennzeichnet und nicht mehr schaltbar.
foreach ($entferntIdents as $ident) {
    $n = $nachher[$ident] ?? null;
    pruefe($n !== null && str_ends_with($n['name'], ZUSATZ) && !$n['aktion'],
        sprintf('%s „%s" → gekennzeichnet, ohne Aktion', $ident, $vorher[$ident]['name'] ?? '?'),
        json_encode($n, JSON_UNESCAPED_UNICODE));
}

// 2. Alle übrigen Variablen bleiben unverändert.
$uebrige = array_diff_key($vorher, array_flip($entferntIdents));
$veraendert = [];
foreach ($uebrige as $ident => $v) {
    if (($nachher[$ident] ?? null) !== $v) {
        $veraendert[] = $ident . ': ' . json_encode($nachher[$ident] ?? null, JSON_UNESCAPED_UNICODE);
    }
}
pruefe($veraendert === [], sprintf('%d übrige Variablen unverändert', count($uebrige)), implode('; ', $veraendert));

// 3. Kein Name kommt unter den aktiven Variablen doppelt vor.
$aktiveNamen = array_column(array_filter($nachher, static fn(array $v): bool => !str_ends_with($v['name'], ZUSATZ)), 'name');
$doppelt = array_keys(array_filter(array_count_values($aktiveNamen), static fn(int $n): bool => $n > 1));
pruefe($doppelt === [], 'kein Name doppelt unter den aktiven Variablen', implode(', ', $doppelt));

// 4. Ein späteres ApplyChanges (Kernel-Neustart) ändert nichts mehr.
neueAusfuehrung($g)->ApplyChanges();
pruefe(bestand($g) === $nachher, 'ApplyChanges danach ändert nichts mehr');

@unlink($datei);
ergebnis();
