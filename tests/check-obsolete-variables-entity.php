<?php

declare(strict_types=1);

/**
 * Veraltete Variablen am ECHTEN Entity-Modul über den offiziellen Kernel-Stub (Store-Review Punkt 11,
 * 02.11.2026). Device und Discovery Device prüfen check-legacy-idents.php, check-removed-entities.php
 * und check-discovery-reachability.php mit echten Konfigurationen; hier geht es um den gemeinsamen Weg
 * im Entity-Modul: Kennzeichnen (markVariableAsLegacy, wie cleanupRenamedSharedEntityObjects es ruft),
 * Formular, Löschknopf und die Rückkehr über das zentrale MaintainVariable.
 *
 * Bis build 220 hängte markVariableAsLegacy „(veraltet)" an den Namen; Formular und Löschknopf gab es
 * nicht. Jetzt bleibt der Name, die Variable verliert die Aktion und steht in der Liste
 * veralteter Variablen. Wird sie wieder gepflegt, fällt sie aus der Liste.
 *
 * Keine Fixture: Die Variable wird hier angelegt; geprüft wird nur der Weg durch das Modul.
 */

require_once __DIR__ . '/device-harness.php';

error_reporting(E_ALL);

/** @return list<string> */
function veraltetListe(EntityHarness $e): array
{
    try {
        return json_decode($e->attribut('ObsoleteVariables'), true, 512, JSON_THROW_ON_ERROR);
    } catch (Exception) {
        return []; // Stand vor build 221: Attribut gibt es nicht
    }
}

/** @return array<string, array> */
function formAktionen(EntityHarness $e): array
{
    $form = json_decode($e->GetConfigurationForm(), true, 512, JSON_THROW_ON_ERROR);
    $elemente = [];
    foreach ($form['actions'] ?? [] as $element) {
        if (isset($element['name'])) {
            $elemente[(string)$element['name']] = $element;
        }
    }
    return $elemente;
}

$e = neueEntitaet([
    'EntityID'   => 'sensor.test_temperatur',
    'DeviceID'   => 'sensor.test_temperatur',
    'DeviceName' => 'Test Temperatur',
    'DeviceArea' => 'Sonstiges',
]);

// Zwei Variablen im Namensraum der Entität, eine davon vom Anwender umbenannt und verschoben.
foreach (['sensor_test_temperatur_alt' => 'Alter Wert', 'sensor_test_temperatur_lebt' => 'Lebt'] as $ident => $name) {
    $id = IPS_CreateVariable(VARIABLETYPE_FLOAT);
    IPS_SetParent($id, $e->id());
    IPS_SetIdent($id, $ident);
    IPS_SetName($id, $name);
    IPS_SetPosition($id, 7);
    IPS\VariableManager::setVariableAction($id, $e->id());
}
$alt = (int)$e->variablenId('sensor_test_temperatur_alt');
IPS_SetName($alt, 'Mein Name');

// 1. Kennzeichnen: Name und Position bleiben, Aktion weg, in der Liste, eine Meldung im Log.
$e = neueAusfuehrung($e);
$e->rufe('markVariableAsLegacy', $alt);
pruefe(IPS_GetName($alt) === 'Mein Name', 'Name bleibt', IPS_GetName($alt));
pruefe(IPS_GetObject($alt)['ObjectPosition'] === 7, 'Position bleibt', (string)IPS_GetObject($alt)['ObjectPosition']);
pruefe(!$e->hatAktion('sensor_test_temperatur_alt'), 'Aktion entfernt');
pruefe(veraltetListe($e) === ['sensor_test_temperatur_alt'], 'In der Liste veralteter Variablen', json_encode(veraltetListe($e)));
$log = IPS\LogServer::getLogMessages((string)$e->id());
pruefe(count($log) === 1 && $log[0]['Type'] === KL_MESSAGE && str_contains($log[0]['Message'], 'Mein Name'),
    'Eine Meldung im Log mit dem Namen', json_encode(array_column($log, 'Message'), JSON_UNESCAPED_UNICODE));

// 2. Ein zweites Kennzeichnen meldet nichts mehr.
neueAusfuehrung($e)->rufe('markVariableAsLegacy', $alt);
pruefe(count(IPS\LogServer::getLogMessages((string)$e->id())) === 1, 'Zweites Kennzeichnen: keine zweite Meldung');

// 3. Formular: Hinweis mit Namen und Löschknopf mit Rückfrage.
$aktionen = formAktionen(neueAusfuehrung($e));
pruefe(str_contains((string)($aktionen['ObsoleteVariablesHint']['caption'] ?? ''), '"Mein Name"') && ($aktionen['ObsoleteVariablesHint']['visible'] ?? false),
    'Formular nennt die veraltete Variable', json_encode($aktionen['ObsoleteVariablesHint'] ?? null, JSON_UNESCAPED_UNICODE));
pruefe(($aktionen['DeleteObsoleteVariables']['visible'] ?? false) && ($aktionen['DeleteObsoleteVariables']['confirm'] ?? '') !== '',
    'Löschknopf mit Rückfrage sichtbar', json_encode($aktionen['DeleteObsoleteVariables'] ?? null, JSON_UNESCAPED_UNICODE));

// 4. Rückkehr: Pflegt das Modul den Ident wieder, fällt er aus der Liste (Name bleibt).
$e = neueAusfuehrung($e);
$e->rufe('MaintainVariable', 'sensor_test_temperatur_alt', 'Standardname', VARIABLETYPE_FLOAT, '', 0, true);
pruefe(veraltetListe($e) === [], 'Wieder gepflegt: nicht mehr in der Liste', json_encode(veraltetListe($e)));
pruefe(IPS_GetName($alt) === 'Mein Name', 'Wieder gepflegt: Name bleibt', IPS_GetName($alt));
pruefe(!(formAktionen(neueAusfuehrung($e))['DeleteObsoleteVariables']['visible'] ?? false), 'Wieder gepflegt: kein Löschknopf');

// 5. Löschen: erneut veraltet, dann gelöscht; die lebende Variable bleibt.
neueAusfuehrung($e)->rufe('markVariableAsLegacy', $alt);
$knopf = formAktionen(neueAusfuehrung($e))['DeleteObsoleteVariables'] ?? null;
$e = neueAusfuehrung($e);
drueckeKnopf($e, $knopf);
pruefe($e->variablenId('sensor_test_temperatur_alt') === null, 'Löschen entfernt die veraltete Variable');
pruefe($e->variablenId('sensor_test_temperatur_lebt') !== null, 'Löschen lässt die lebende Variable stehen');
pruefe(veraltetListe($e) === [], 'Liste nach dem Löschen leer');

// 6. Hat der Anwender die Variable selbst gelöscht, bietet das Formular sie nicht mehr an.
$lebt = (int)$e->variablenId('sensor_test_temperatur_lebt');
neueAusfuehrung($e)->rufe('markVariableAsLegacy', $lebt);
IPS_DeleteVariable($lebt);
pruefe(!(formAktionen(neueAusfuehrung($e))['DeleteObsoleteVariables']['visible'] ?? false), 'Von Hand gelöscht: kein Löschknopf mehr');

// 7. Gelöscht wird nur, was das Formular angezeigt hat (Code-Review 02.11.2026). Das Formular ist offen
// und nennt „Gezeigt"; bevor der Anwender klickt, kennzeichnet ein ApplyChanges im Hintergrund
// „Später". Bis build 223 löschte der Knopf die Liste zum Zeitpunkt des Klicks, also beide.
$ids = [];
foreach (['sensor_test_temperatur_gezeigt' => 'Gezeigt', 'sensor_test_temperatur_spaeter' => 'Später'] as $ident => $name) {
    $ids[$ident] = IPS_CreateVariable(VARIABLETYPE_FLOAT);
    IPS_SetParent($ids[$ident], $e->id());
    IPS_SetIdent($ids[$ident], $ident);
    IPS_SetName($ids[$ident], $name);
}
neueAusfuehrung($e)->rufe('markVariableAsLegacy', $ids['sensor_test_temperatur_gezeigt']);
$aktionen = formAktionen(neueAusfuehrung($e));
pruefe(!str_contains((string)($aktionen['ObsoleteVariablesHint']['caption'] ?? ''), 'Später'), 'Offenes Formular nennt nur „Gezeigt“');
neueAusfuehrung($e)->rufe('markVariableAsLegacy', $ids['sensor_test_temperatur_spaeter']);
$e = neueAusfuehrung($e);
drueckeKnopf($e, $aktionen['DeleteObsoleteVariables'] ?? null);
pruefe($e->variablenId('sensor_test_temperatur_gezeigt') === null, 'Angezeigte Variable gelöscht');
pruefe($e->variablenId('sensor_test_temperatur_spaeter') !== null, 'Nicht angezeigte Variable bleibt stehen');
pruefe(veraltetListe($e) === ['sensor_test_temperatur_spaeter'], 'Nicht angezeigte Variable bleibt in der Liste', json_encode(veraltetListe($e)));
$aktionen = formAktionen(neueAusfuehrung($e));
pruefe(str_contains((string)($aktionen['ObsoleteVariablesHint']['caption'] ?? ''), '"Später"') && ($aktionen['DeleteObsoleteVariables']['visible'] ?? false),
    'Formular bietet sie danach an', json_encode($aktionen['ObsoleteVariablesHint'] ?? null, JSON_UNESCAPED_UNICODE));

// 8. Wird eine angezeigte Variable vor dem Klick wieder gepflegt, bleibt sie stehen.
neueAusfuehrung($e)->rufe('markVariableAsLegacy', $ids['sensor_test_temperatur_spaeter']);
$knopf = formAktionen(neueAusfuehrung($e))['DeleteObsoleteVariables'] ?? null;
neueAusfuehrung($e)->rufe('MaintainVariable', 'sensor_test_temperatur_spaeter', 'Später', VARIABLETYPE_FLOAT, '', 0, true);
$e = neueAusfuehrung($e);
drueckeKnopf($e, $knopf);
pruefe($e->variablenId('sensor_test_temperatur_spaeter') !== null, 'Vor dem Klick wieder gepflegt: bleibt stehen');

ergebnis();
