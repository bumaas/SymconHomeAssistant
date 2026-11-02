<?php

declare(strict_types=1);

/**
 * Verzögerte Übernahme (Timer DeferredApply) einheitlich über RequestAction (Store-Review Punkt 13,
 * 02.11.2026).
 *
 * Device und Entity der klassischen Bridge rufen seit 1.4 build 142 aus dem Timer
 * IPS_RequestAction($_IPS["TARGET"], "DeferredApply", "") und führen ApplyChanges in RequestAction
 * selbst aus. MQTT Discovery Device und Splitter riefen bis build 219 aus dem Timer
 * IPS_ApplyChanges($_IPS["TARGET"]) auf die eigene Instanz; der Store-Regeltext sagt, IPS_ApplyChanges
 * solle nie verwendet werden. Jetzt arbeiten alle vier Module gleich.
 *
 * Geprüft: kein Modul registriert einen Timer mit IPS_ApplyChanges; RequestAction('DeferredApply')
 * übernimmt bei Discovery-Device und -Splitter genau einmal neu und stellt den Timer ab; im
 * Reload-Fenster tut sie nichts (Hausregel: jeder Einstiegspunkt prüft zuerst isInstanceCreated()).
 * Dazu ruft kein Formularknopf IPS_ApplyChanges($id); „Reload bundle“ des Discovery-Splitters geht
 * über denselben Ident. Keine Fixture.
 */

require_once __DIR__ . '/device-harness.php';

error_reporting(E_ALL);

// 1. Statisch: kein Timer-Skript mit IPS_ApplyChanges.
foreach (glob(dirname(__DIR__) . '/*/module.php') as $datei) {
    $code = (string)file_get_contents($datei);
    $treffer = preg_match_all('/RegisterTimer\([^;]*IPS_ApplyChanges/s', $code);
    pruefe($treffer === 0, basename(dirname($datei)) . ': kein Timer ruft IPS_ApplyChanges', $treffer . ' Treffer');
}

// 2. Laufzeit: RequestAction('DeferredApply') übernimmt einmal neu und stellt den Timer ab.
foreach ([DiscoverySplitterHarness::class, DiscoveryDeviceHarness::class] as $klasse) {
    $modul = neueFensterInstanz($klasse);
    $name = $klasse::MODULE_NAME;

    $modul->rufe('SetTimerInterval', 'DeferredApply', 2000);
    $vorher = $klasse::$uebernahmen;
    $modul->RequestAction('DeferredApply', '');
    pruefe($klasse::$uebernahmen - $vorher === 1, $name . ': DeferredApply übernimmt genau einmal neu',
        (string)($klasse::$uebernahmen - $vorher));

    // Reload-Fenster: Properties und Attribute sind nicht registriert, nichts darf laufen.
    $modul = neueAusfuehrung($modul);
    $modul->attributeRegistriert = false;
    $vorher = $klasse::$uebernahmen;
    $modul->RequestAction('DeferredApply', '');
    pruefe($klasse::$uebernahmen === $vorher, $name . ': im Reload-Fenster keine Übernahme');
    $modul->attributeRegistriert = true;
}

// 3. Statisch: kein Formularknopf ruft IPS_ApplyChanges auf die eigene Instanz. Der Knopf
// „Reload bundle“ des Discovery-Splitters tat das bis build 222 (Code-Review 02.11.2026).
foreach (glob(dirname(__DIR__) . '/*/form.json') as $datei) {
    $formular = (string)file_get_contents($datei);
    $treffer = preg_match_all('/IPS_ApplyChanges\(\s*\$id\s*\)/', $formular);
    pruefe($treffer === 0, basename(dirname($datei)) . ': kein Knopf ruft IPS_ApplyChanges($id)', $treffer . ' Treffer');
}

$knopf = null;
$splitterFormular = json_decode(
    (string)file_get_contents(dirname(__DIR__) . '/Home Assistant MQTT Discovery Splitter/form.json'),
    true, 512, JSON_THROW_ON_ERROR
);
$suche = static function (array $knoten) use (&$suche, &$knopf): void {
    if (($knoten['name'] ?? null) === 'ButtonReloadBundle') {
        $knopf = $knoten;
        return;
    }
    foreach ($knoten as $kind) {
        if (is_array($kind)) {
            $suche($kind);
        }
    }
};
$suche($splitterFormular);
pruefe(is_array($knopf), 'Splitter: Knopf „Reload bundle“ vorhanden');
pruefe(
    preg_match('/^IPS_RequestAction\(\$id,\s*[\'"]DeferredApply[\'"],\s*[\'"]{2}\);$/', (string)($knopf['onClick'] ?? '')) === 1,
    'Splitter: „Reload bundle“ übernimmt über RequestAction DeferredApply',
    (string)($knopf['onClick'] ?? '')
);

$modul = neueFensterInstanz(DiscoverySplitterHarness::class);
$modul->rufe('SetTimerInterval', 'DeferredApply', 2000);
$modul->RequestAction('DeferredApply', '');
pruefe($modul->timer('DeferredApply') === 0, 'Splitter: Timer nach der Übernahme aus', (string)$modul->timer('DeferredApply'));

ergebnis();
