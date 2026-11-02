<?php

declare(strict_types=1);

/**
 * Prüft am ECHTEN Modul (Home Assistant Device, Bundle-Modus) über den offiziellen Kernel-Stub, dass
 * Variablen aus dem alten Ident-Schema als veraltet gelten: ohne Aktion, in der Liste veralteter
 * Variablen, einmal im Log, im Formular mit Löschknopf.
 *
 * Bis Mai 2026 trug jeder Ident den vollen Namen der Entität (media_player_denon_wohnzimmer_volume_level).
 * Seit der Vereinheitlichung (ab131d3, 18.05.2026) fällt er weg, wenn die Entität wie das Gerät heißt
 * (media_player_volume_level). Die Kennzeichnung prüfte das alte Präfix aber nur, wenn es NICHT unter
 * einem aktiven Präfix lag — beim gekürzten Ident liegt es dort immer. Am nuc blieben so rund 45 tote
 * Variablen mit gleichem Namen und weiter vorhandener Aktion stehen (Denon: zweimal „Lautstärke").
 *
 * Abgrenzung (Rückfrage Burkhard 03.10.2026): Angefasst wird nur der Namensraum der Entität.
 * Variablen ohne Ident, mit fremdem Ident und lebende Variablen bleiben unberührt — auch solche, die
 * das Modul erst später anlegt (Attributvariablen beim ersten MQTT-Attribut).
 *
 * Store-Review Punkt 11 (02.11.2026): Name und Position gehören nach dem Anlegen dem Anwender. Bis
 * build 220 hängte das Modul „(veraltet)" an den Namen. Jetzt bleiben Name und Position, die Variable
 * verliert nur ihre Aktion. Das Log nennt sie einmal, das Formular zeigt sie mit dem Knopf „Veraltete
 * Variablen löschen“, der nach Rückfrage löscht (Muster Roborock 2.6 build 112).
 *
 * Fixture: tests/fixtures/legacy_idents_20261003.json — Konfiguration und Variablen zweier Instanzen
 * am nuc (Denon Wohnzimmer, Gast-Rollladen), so wie sie dort stehen.
 */

require_once __DIR__ . '/device-harness.php';

error_reporting(E_ALL);

/** @return list<string> Idents in der Liste veralteter Variablen */
function veraltetListe(DeviceHarness $g): array
{
    try {
        return json_decode($g->attribut('ObsoleteVariables'), true, 512, JSON_THROW_ON_ERROR);
    } catch (Exception) {
        return []; // Stand vor build 221: Attribut gibt es nicht
    }
}

/** @return array<string, array> Aktionselemente des Formulars mit Namen */
function formAktionen(IPSModuleStrict $g): array
{
    $form = json_decode($g->GetConfigurationForm(), true, 512, JSON_THROW_ON_ERROR);
    $elemente = [];
    foreach ($form['actions'] ?? [] as $element) {
        if (isset($element['name'])) {
            $elemente[(string)$element['name']] = $element;
        }
    }
    return $elemente;
}

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

/** @return array<string, array{name:string, aktion:bool, position:int}> je Ident */
function bestand(DeviceHarness $g): array
{
    $bestand = [];
    foreach (IPS_GetChildrenIDs($g->id()) as $id) {
        $o = IPS_GetObject($id);
        if ($o['ObjectType'] !== OBJECTTYPE_VARIABLE) {
            continue;
        }
        $bestand[$o['ObjectIdent'] !== '' ? $o['ObjectIdent'] : '#' . $id] = [
            'name'     => $o['ObjectName'],
            'aktion'   => IPS_GetVariable($id)['VariableAction'] > 0,
            'position' => $o['ObjectPosition'],
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
    $logVorher = count(IPS\LogServer::getLogMessages((string)$g->id()));
    neueAusfuehrung($g)->ApplyChanges();
    $nachher = bestand($g);
    $liste = veraltetListe($g);

    // 1. Jede Altlast ist veraltet: Name und Position unverändert, ohne Aktion, in der Liste.
    foreach ($erwartetVeraltet as $ident) {
        $n = $nachher[$ident] ?? null;
        $v = $vorher[$ident] ?? null;
        pruefe($n !== null && $v !== null && $n['name'] === $v['name'] && $n['position'] === $v['position'] && !$n['aktion'] && in_array($ident, $liste, true),
            sprintf('%s: %s „%s" → veraltet, Name und Position unverändert, ohne Aktion', $name, $ident, $v['name'] ?? '?'),
            json_encode($n, JSON_UNESCAPED_UNICODE));
    }
    sort($liste);
    $soll = $erwartetVeraltet;
    sort($soll);
    pruefe($liste === $soll, $name . ': Liste veralteter Variablen enthält genau die Altlasten', json_encode($liste));

    // 2. Ein vom Anwender geänderter Name bleibt, wie er ist.
    pruefe(($nachher[$umbenannt]['name'] ?? '') === 'Von Hand benannt',
        $name . ': geänderter Name bleibt erhalten', $nachher[$umbenannt]['name'] ?? '');

    // 2a. Das Log nennt jede veraltete Variable einmal.
    $log = array_slice(IPS\LogServer::getLogMessages((string)$g->id()), $logVorher);
    pruefe(count($log) === count($erwartetVeraltet) && array_all($log, static fn(array $m): bool => $m['Type'] === KL_MESSAGE),
        $name . ': je veraltete Variable eine Meldung im Log', json_encode(array_column($log, 'Message'), JSON_UNESCAPED_UNICODE));

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
    $aktiveNamen = array_column(array_diff_key($nachher, array_flip($liste)), 'name');
    $doppelt = array_keys(array_filter(array_count_values($aktiveNamen), static fn(int $n): bool => $n > 1));
    pruefe($doppelt === [], $name . ': kein Name doppelt unter den aktiven Variablen', implode(', ', $doppelt));

    // 5. Ein weiteres ApplyChanges ändert nichts und schreibt nichts mehr ins Log.
    $logVorher = count(IPS\LogServer::getLogMessages((string)$g->id()));
    neueAusfuehrung($g)->ApplyChanges();
    pruefe(bestand($g) === $nachher, $name . ': zweites ApplyChanges ändert nichts mehr');
    pruefe(count(IPS\LogServer::getLogMessages((string)$g->id())) === $logVorher, $name . ': zweites ApplyChanges schreibt nichts ins Log');

    // 6. Das Formular nennt die veralteten Variablen und bietet den Löschknopf mit Rückfrage.
    $aktionen = formAktionen(neueAusfuehrung($g));
    $hinweis = $aktionen['ObsoleteVariablesHint'] ?? null;
    $knopf = $aktionen['DeleteObsoleteVariables'] ?? null;
    pruefe($hinweis !== null && ($hinweis['visible'] ?? true) && str_contains((string)$hinweis['caption'], '"Von Hand benannt"'),
        $name . ': Formular nennt die veralteten Variablen', json_encode($hinweis, JSON_UNESCAPED_UNICODE));
    pruefe($knopf !== null && ($knopf['visible'] ?? true) && ($knopf['confirm'] ?? '') !== '',
        $name . ': Löschknopf mit Rückfrage sichtbar', json_encode($knopf, JSON_UNESCAPED_UNICODE));

    // 7. Löschen entfernt genau die veralteten Variablen; danach ist das Formular ohne Hinweis.
    $g = neueAusfuehrung($g);
    $g->RequestAction('DeleteObsoleteVariables', true);
    $danach = bestand($g);
    pruefe(array_intersect_key($danach, array_flip($erwartetVeraltet)) === [], $name . ': Löschen entfernt die veralteten Variablen');
    pruefe($danach === array_diff_key($nachher, array_flip($erwartetVeraltet)), $name . ': Löschen lässt alle übrigen Variablen stehen');
    pruefe(veraltetListe($g) === [], $name . ': Liste nach dem Löschen leer');
    $aktionen = formAktionen(neueAusfuehrung($g));
    pruefe(!(($aktionen['DeleteObsoleteVariables']['visible'] ?? false)), $name . ': nach dem Löschen kein Knopf mehr');
}

foreach ($bundleDateien as $datei) {
    @unlink($datei);
}

ergebnis();
