<?php

declare(strict_types=1);

/**
 * Prüft am ECHTEN Modul (Home Assistant Device, Bundle-Modus) über den offiziellen Kernel-Stub, dass
 * Variablen aus dem alten Ident-Schema als „(veraltet)" gekennzeichnet werden.
 *
 * Bis Mai 2026 trug jeder Ident den vollen Namen der Entität (media_player_denon_wohnzimmer_volume_level).
 * Seit der Vereinheitlichung (ab131d3, 18.05.2026) fällt er weg, wenn die Entität wie das Gerät heißt
 * (media_player_volume_level). Die Kennzeichnung prüfte das alte Präfix aber nur, wenn es NICHT unter
 * einem aktiven Präfix lag — beim gekürzten Ident liegt es dort immer. Am nuc blieben so rund 45 tote
 * Variablen mit gleichem Namen und weiter vorhandener Aktion stehen (Denon: zweimal „Lautstärke").
 *
 * Abgrenzung (Rückfrage Burkhard 03.10.2026): Angefasst wird nur der Namensraum der Entität.
 * Variablen ohne Ident, mit fremdem Ident und lebende Variablen bleiben unberührt — auch solche, die
 * das Modul erst später anlegt (Attributvariablen beim ersten MQTT-Attribut). Vom Anwender geänderte
 * Namen bleiben erhalten, nur der Zusatz kommt dazu.
 *
 * Fixture: tests/fixtures/legacy_idents_20261003.json — Konfiguration und Variablen zweier Instanzen
 * am nuc (Denon Wohnzimmer, Gast-Rollladen), so wie sie dort stehen.
 */

require_once __DIR__ . '/device-harness.php';

error_reporting(E_ALL);

const ZUSATZ = ' (veraltet)';

/** Erwartung: am nuc seit Mai nicht mehr aktualisiert, Ident im alten Schema (voller Entitätsname). */
const VERALTET = [
    'Denon Wohnzimmer'    => [
        'media_player_denon_wohnzimmer',
        'media_player_denon_wohnzimmer_is_volume_muted',
        'media_player_denon_wohnzimmer_power',
        'media_player_denon_wohnzimmer_sound_mode',
        'media_player_denon_wohnzimmer_source',
        'media_player_denon_wohnzimmer_status',
        'media_player_denon_wohnzimmer_volume_level',
    ],
    'Gast.Licht.Rolladen' => [
        'cover_gast_licht_rolladen',
        'cover_gast_licht_rolladen_cover_action',
        'cover_gast_licht_rolladen_status',
    ],
];

/** @var list<string> $bundleDateien */
$bundleDateien = [];

/**
 * Instanz wie am nuc: Gerät aus der echten Konfiguration, danach alle Variablen der Fixture, die das
 * Anlegen nicht selbst erzeugt hat (die Altlasten und die erst später angelegten Attributvariablen).
 */
function instanzWieAmNuc(array $instanz): DeviceHarness
{
    global $bundleDateien;
    $datei = tempnam(sys_get_temp_dir(), 'ha_legacy_check_');
    if ($datei === false) {
        throw new RuntimeException('Temp-Datei für das Bundle nicht angelegt');
    }
    $bundleDateien[] = $datei;
    file_put_contents($datei, json_encode($instanz['konfiguration'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $zeile = $instanz['konfiguration'][0];

    $g = neuesGeraet([
        'SourceMode' => 'bundle',
        'BundlePath' => $datei,
        'DeviceName' => $zeile['device_name'],
        'DeviceID'   => $zeile['device_id'],
    ]);
    foreach ($instanz['variablen'] as $v) {
        $id = $g->variablenId($v['ident']);
        if ($id === null) {
            $id = IPS_CreateVariable($v['typ']);
            IPS_SetParent($id, $g->id());
            IPS_SetIdent($id, $v['ident']);
        }
        IPS_SetName($id, $v['name']);
        IPS\VariableManager::setVariableAction($id, $v['aktion'] ? $g->id() : 0);
    }
    return $g;
}

/** @return array<string, array{name:string, aktion:bool}> je Ident */
function bestand(DeviceHarness $g): array
{
    $bestand = [];
    foreach (IPS_GetChildrenIDs($g->id()) as $id) {
        $o = IPS_GetObject($id);
        if ($o['ObjectType'] !== OBJECTTYPE_VARIABLE) {
            continue;
        }
        $bestand[$o['ObjectIdent'] !== '' ? $o['ObjectIdent'] : '#' . $id] = [
            'name'   => $o['ObjectName'],
            'aktion' => IPS_GetVariable($id)['VariableAction'] > 0,
        ];
    }
    return $bestand;
}

$fixture = json_decode((string)file_get_contents(__DIR__ . '/fixtures/legacy_idents_20261003.json'), true, 512, JSON_THROW_ON_ERROR);

foreach ($fixture['instanzen'] as $instanz) {
    $name = $instanz['instanz'];
    $erwartetVeraltet = VERALTET[$name];
    $g = instanzWieAmNuc($instanz);

    // Anwenderfälle neben den Altlasten
    $fremd = IPS_CreateVariable(VARIABLETYPE_INTEGER);
    IPS_SetParent($fremd, $g->id());
    IPS_SetIdent($fremd, 'eigener_zaehler');
    IPS_SetName($fremd, 'Mein Zähler');
    $ohneIdent = IPS_CreateVariable(VARIABLETYPE_STRING);
    IPS_SetParent($ohneIdent, $g->id());
    IPS_SetName($ohneIdent, 'Notiz');
    $umbenannt = $erwartetVeraltet[array_key_last($erwartetVeraltet)];
    IPS_SetName((int)$g->variablenId($umbenannt), 'Von Hand benannt');

    $vorher = bestand($g);
    neueAusfuehrung($g)->ApplyChanges();
    $nachher = bestand($g);

    // 1. Jede Altlast ist gekennzeichnet und nicht mehr schaltbar.
    foreach ($erwartetVeraltet as $ident) {
        $n = $nachher[$ident] ?? null;
        pruefe($n !== null && str_ends_with($n['name'], ZUSATZ) && !$n['aktion'],
            sprintf('%s: %s „%s" → gekennzeichnet, ohne Aktion', $name, $ident, $vorher[$ident]['name'] ?? '?'),
            json_encode($n, JSON_UNESCAPED_UNICODE));
    }

    // 2. Ein vom Anwender geänderter Name bleibt, nur der Zusatz kommt dazu.
    pruefe(($nachher[$umbenannt]['name'] ?? '') === 'Von Hand benannt' . ZUSATZ,
        $name . ': geänderter Name bleibt erhalten', $nachher[$umbenannt]['name'] ?? '');

    // 3. Alles andere bleibt unverändert: lebende Variablen (auch später angelegte), fremder Ident,
    //    Variable ohne Ident.
    $unberuehrt = array_diff_key($vorher, array_flip($erwartetVeraltet));
    $veraendert = [];
    foreach ($unberuehrt as $ident => $v) {
        if (($nachher[$ident] ?? null) !== $v) {
            $veraendert[] = $ident . ': ' . json_encode($nachher[$ident] ?? null, JSON_UNESCAPED_UNICODE);
        }
    }
    pruefe($veraendert === [], sprintf('%s: %d übrige Variablen unverändert', $name, count($unberuehrt)), implode('; ', $veraendert));
    pruefe(isset($unberuehrt['eigener_zaehler'], $unberuehrt['#' . $ohneIdent]), $name . ': fremder Ident und Variable ohne Ident sind im Vergleich enthalten');

    // 4. Kein Name kommt unter den aktiven Variablen doppelt vor.
    $aktiveNamen = array_column(array_filter($nachher, static fn(array $v): bool => !str_ends_with($v['name'], ZUSATZ)), 'name');
    $doppelt = array_keys(array_filter(array_count_values($aktiveNamen), static fn(int $n): bool => $n > 1));
    pruefe($doppelt === [], $name . ': kein Name doppelt unter den aktiven Variablen', implode(', ', $doppelt));

    // 5. Ein weiteres ApplyChanges hängt den Zusatz nicht noch einmal an.
    neueAusfuehrung($g)->ApplyChanges();
    pruefe(bestand($g) === $nachher, $name . ': zweites ApplyChanges ändert nichts mehr');
}

foreach ($bundleDateien as $datei) {
    @unlink($datei);
}

ergebnis();
