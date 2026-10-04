<?php

declare(strict_types=1);

/**
 * Prüft den Aktionsvertrag der Hauptvariablen am ECHTEN Modul (Home Assistant Device, Bundle-Modus)
 * über den offiziellen Kernel-Stub:
 *
 * - Ein ungültiger Wert in RequestAction wird als Fehler gemeldet statt still verworfen
 *   (MCP-Evaluierung 01.10.2026, B6: RequestAction(#24021, 'D') lieferte true, erlaubt A/B/C).
 * - Eine schreibbare Hauptvariable, die ohne Aktion dasteht, bekommt sie beim nächsten
 *   ApplyChanges zurück (B7: Test Zahl #56984 mit Slider, aber VariableAction = 0; auf dem nuc
 *   am 01.10.2026 insgesamt 38 Slider-Variablen in 13 Instanzen).
 * - Der heiße Pfad (Zustandsmeldung) setzt dabei keine Aktion neu.
 *
 * Fixture: echte Zustände der beiden Test-Entitäten aus HA (/api/states, 01.10.2026),
 * tests/fixtures/ha_states_test_entities.json.
 */

require_once __DIR__ . '/device-harness.php';

error_reporting(E_ALL);

/** Löst RequestAction in einer neuen Ausführung aus und liefert die Fehlermeldung (Warnung oder Ausnahme), sonst null. */
function requestActionFehler(DeviceHarness|EntityHarness $modul, string $ident, mixed $wert): ?string
{
    try {
        neueAusfuehrung($modul)->RequestAction($ident, $wert);
    } catch (Throwable $e) {
        return $e->getMessage();
    }
    return null;
}

/** @return list<array<string, mixed>> Bundle-Zeilen aus HA-Zuständen (/api/states) */
function bundleZeilen(array $zustaende): array
{
    $zeilen = [];
    foreach ($zustaende as $zustand) {
        $zeilen[] = [
            'entity_id'   => $zustand['entity_id'],
            'name'        => $zustand['attributes']['friendly_name'],
            'domain'      => explode('.', $zustand['entity_id'], 2)[0],
            'device_id'   => 'devid_test_entities',
            'device_name' => 'Testgerät',
            'create_var'  => true,
            'attributes'  => $zustand['attributes'],
        ];
    }
    return $zeilen;
}

// ---- Fixture: echte HA-Zustände als Bundle-Zeilen ----

$zustaende = json_decode((string)file_get_contents(__DIR__ . '/fixtures/ha_states_test_entities.json'), true, 512, JSON_THROW_ON_ERROR);

$bundleFile = tempnam(sys_get_temp_dir(), 'ha_action_check_');
if (!pruefe($bundleFile !== false, 'Temp-Datei für das Bundle angelegt')) {
    ergebnis();
}
file_put_contents($bundleFile, json_encode(bundleZeilen($zustaende), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$geraet = neuesGeraet([
    'SourceMode' => 'bundle',
    'BundlePath' => $bundleFile,
    'DeviceName' => 'Testgerät',
    'DeviceID'   => 'devid_test_entities',
]);

$selectIdent = neueAusfuehrung($geraet)->rufe('getConfiguredEntityById', 'input_select.test_auswahl')['ident'] ?? '';
$numberIdent = neueAusfuehrung($geraet)->rufe('getConfiguredEntityById', 'input_number.test_zahl')['ident'] ?? '';

// ---- Teil 1: frisch angelegte Variablen ----

pruefe($geraet->variablenId($selectIdent) !== null, 'Auswahl-Variable angelegt', $selectIdent);
pruefe($geraet->variablenId($numberIdent) !== null, 'Zahl-Variable angelegt', $numberIdent);
pruefe($geraet->hatAktion($selectIdent), 'Neu angelegte Auswahl-Variable hat eine Aktion');
pruefe($geraet->hatAktion($numberIdent), 'Neu angelegte Zahl-Variable hat eine Aktion');
$zahlId = $geraet->variablenId($numberIdent);
pruefe(
    $zahlId !== null && IPS_GetVariable($zahlId)['VariableType'] === VARIABLETYPE_INTEGER,
    'Zahl-Variable ist Integer (step 1, wie #56984)'
);

// ---- Teil 2 (B6): ungültiger Wert wird als Fehler gemeldet ----

// ($fehler ist in harness.php vergeben - deshalb $meldung.)
$meldung = requestActionFehler($geraet, $selectIdent, 'D');
pruefe($meldung !== null, 'Auswahl: nicht erlaubte Option „D" wird als Fehler gemeldet');
pruefe(
    $meldung !== null && str_contains($meldung, 'D') && str_contains($meldung, 'A, B, C'),
    'Auswahl: Fehlermeldung nennt den Wert und die erlaubten Optionen',
    (string)$meldung
);
// Blindtest 04.10.2026: Die Meldung nannte den Ident („select_status"); eine KI und ein Anwender
// kennen die Variable aber unter ihrem Namen.
$selectName = IPS_GetName((int)$geraet->variablenId($selectIdent));
pruefe(
    $meldung !== null && str_contains($meldung, '"' . $selectName . '"') && !str_contains($meldung, '"' . $selectIdent . '"'),
    'Auswahl: Fehlermeldung nennt den Variablennamen statt des Idents',
    (string)$meldung
);
pruefe(requestActionFehler($geraet, $selectIdent, 'B') === null, 'Auswahl: erlaubte Option „B" meldet keinen Fehler');

$meldung = requestActionFehler($geraet, $numberIdent, 'abc');
pruefe($meldung !== null, 'Zahl: nicht numerischer Wert „abc" wird als Fehler gemeldet');
pruefe(requestActionFehler($geraet, $numberIdent, 56) === null, 'Zahl: gültiger Wert 56 meldet keinen Fehler');

// ---- Teil 3 (B7): bestehende Variable ohne Aktion bekommt sie bei ApplyChanges zurück ----

// Zustand wie am nuc beobachtet: Variable vorhanden, Slider-Darstellung, VariableAction = 0.
$geraet->aktionEntfernen($numberIdent);
pruefe(!$geraet->hatAktion($numberIdent), 'Ausgangslage: Zahl-Variable ohne Aktion');

neueAusfuehrung($geraet)->ApplyChanges();
pruefe($geraet->variablenId($numberIdent) === $zahlId, 'ApplyChanges legt die Zahl-Variable nicht neu an');
pruefe($geraet->hatAktion($numberIdent), 'Nach ApplyChanges hat die bestehende Zahl-Variable wieder eine Aktion');

// ---- Teil 4: der heiße Pfad setzt keine Aktion ----

$vorher = KernelZaehler::stand();
neueAusfuehrung($geraet)->ReceiveData(mqttMeldung('statestream/input_number/test_zahl/state', '56.0'));
$arbeit = KernelZaehler::seit($vorher);
pruefe(
    $geraet->wert($numberIdent) === 56,
    'Zustandsmeldung kommt in der Zahl-Variable an',
    var_export($geraet->wert($numberIdent), true)
);
pruefe(($arbeit['EnableAction'] ?? 0) === 0, 'Zustandsmeldung setzt keine Aktion neu', json_encode($arbeit));

@unlink($bundleFile);

// ---- Teil 5 (B6 am Entity-Modul): dort läuft RequestAction über den gemeinsamen Core-Trait ----

// Properties wie an der Instanz #14804 (IPS_GetConfiguration).
$entitaet = neueEntitaet([
    'EntityID'   => 'input_select.test_auswahl',
    'DeviceID'   => 'input_select.test_auswahl',
    'DeviceName' => 'Test Auswahl',
    'DeviceArea' => 'Sonstiges',
]);
// Wie UpdateConfiguration(): Rohdaten aus HA → aufgelöste Zeile → ResolvedConfig.
$zeile = $entitaet->rufe('buildResolvedEntityRow', $zustaende[0], true, true);
$entitaet->rufe('writeResolvedConfig', json_encode([$zeile], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
// Variable anlegen wie ApplyChanges: An der Anlage gehört jeder Ident, den RequestAction erreicht, zu einer Variable.
$neu = neueAusfuehrung($entitaet);
$neu->rufe('processEntities', $neu->rufe('getConfiguredEntities', 'check'), 'statestream');
$entityIdent = neueAusfuehrung($entitaet)->rufe('getConfiguredEntityById', 'input_select.test_auswahl')['ident'] ?? '';
pruefe($entityIdent === 'select_status', 'Entity-Modul: Ident wie an der Anlage (#24021 select_status)', $entityIdent);

$meldung = requestActionFehler($entitaet, $entityIdent, 'D');
pruefe(
    $meldung !== null && str_contains($meldung, 'A, B, C'),
    'Entity-Modul: nicht erlaubte Option „D" wird als Fehler mit den erlaubten Optionen gemeldet',
    (string)$meldung
);

pruefe(
    $meldung !== null && str_contains($meldung, '"' . IPS_GetName((int)$entitaet->variablenId($entityIdent)) . '"') && !str_contains($meldung, '"select_status"'),
    'Entity-Modul: Fehlermeldung nennt den Variablennamen statt des Idents',
    (string)$meldung
);

$meldung = requestActionFehler($entitaet, $entityIdent, 'C');
pruefe($meldung === null, 'Entity-Modul: erlaubte Option „C" meldet keinen Fehler', (string)$meldung);

// ---- Teil 6 bis 9: Befunde des Code-Reviews vom 01.10.2026 ----
// Jeder Teil arbeitet an einem eigenen Gerät mit allen drei Test-Entitäten. Die Instanzen stehen wie
// nach der REST-Initialisierung im MQTT-Betrieb da: Der State-Cache kennt Zustand und Attribute.

/** Spielt die Zustände ein, wie es UpdateConfiguration() mit den Antworten von /api/states tut. */
function restInitialisierung(DeviceHarness|EntityHarness $modul, array $zustaende): void
{
    $neu = neueAusfuehrung($modul);
    foreach ($zustaende as $zustand) {
        $neu->rufe('applyInitialState', $zustand['entity_id'], $zustand);
    }
}

/** @return array{0: DeviceHarness, 1: string} Gerät und Bundle-Datei */
function testGeraet(array $zustaende): array
{
    $datei = (string)tempnam(sys_get_temp_dir(), 'ha_action_check_');
    file_put_contents($datei, json_encode(bundleZeilen($zustaende), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $geraet = neuesGeraet([
        'SourceMode' => 'bundle',
        'BundlePath' => $datei,
        'DeviceName' => 'Testgerät',
        'DeviceID'   => 'devid_test_entities',
    ]);
    restInitialisierung($geraet, $zustaende);
    return [$geraet, $datei];
}

function ident(DeviceHarness|EntityHarness $modul, string $entityId): string
{
    return neueAusfuehrung($modul)->rufe('getConfiguredEntityById', $entityId)['ident'] ?? '';
}

function melde(DeviceHarness|EntityHarness $modul, string $topic, string $payload): void
{
    neueAusfuehrung($modul)->ReceiveData(mqttMeldung('statestream/' . $topic, $payload));
}

/** Entity-Instanz für einen HA-Zustand, eingerichtet wie nach UpdateConfiguration(). */
function testEntitaet(array $zustand): EntityHarness
{
    $entitaet = neueEntitaet([
        'EntityID'   => $zustand['entity_id'],
        'DeviceID'   => $zustand['entity_id'],
        'DeviceName' => $zustand['attributes']['friendly_name'],
        'DeviceArea' => 'Sonstiges',
    ]);
    $zeile = $entitaet->rufe('buildResolvedEntityRow', $zustand, true, true);
    $entitaet->rufe('writeResolvedConfig', json_encode([$zeile], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $neu = neueAusfuehrung($entitaet);
    $neu->rufe('processEntities', $neu->rufe('getConfiguredEntities', 'check'), 'statestream');
    restInitialisierung($entitaet, [$zustand]);
    return $entitaet;
}

[$auswahl, $zahl, $text] = $zustaende;
// Der Parent nimmt REST-Aufrufe an - so wird sichtbar, was gesendet würde.
HaSendungen::$restAntwort = true;

// ---- Teil 6 (Befund 1): ein Typwechsel im heißen Pfad lässt die Variable nicht ohne Aktion zurück ----

[$geraet, $bundleFile] = testGeraet($zustaende);
$numberIdent = ident($geraet, 'input_number.test_zahl');
$zahlId = $geraet->variablenId($numberIdent);
$zahlName = IPS_GetName((int)$zahlId);
pruefe($geraet->wert($numberIdent) === 55 && $geraet->hatAktion($numberIdent), 'Typwechsel, Ausgangslage: Integer-Variable mit Wert 55 und Aktion');

// HA meldet eine neue Schrittweite: statestream veröffentlicht das Attribut als eigenes Topic.
melde($geraet, 'input_number/test_zahl/step', '0.5');
$neueZahlId = $geraet->variablenId($numberIdent);
pruefe(
    $neueZahlId !== null && IPS_GetVariable($neueZahlId)['VariableType'] === VARIABLETYPE_FLOAT && $neueZahlId !== $zahlId,
    'Typwechsel: Schrittweite 0.5 legt die Zahl-Variable als Float neu an'
);
pruefe($geraet->hatAktion($numberIdent), 'Typwechsel: die neu angelegte Variable hat wieder eine Aktion');
pruefe($geraet->wert($numberIdent) === 55.0, 'Typwechsel: die neu angelegte Variable trägt den letzten Wert', var_export($geraet->wert($numberIdent), true));
// Gegencheck am nuc 01.10.2026: Die im heißen Pfad neu angelegte Variable hieß „test_zahl" — der
// Attribut-Pfad legte die Laufzeit-Entität mit der Objekt-ID als Namen an, statt die Zeile aus der
// Konfiguration zu nehmen. MaintainVariable benennt eine bestehende Variable nie um; der Name bleibt.
pruefe(
    IPS_GetName((int)$neueZahlId) === $zahlName,
    'Typwechsel: die neu angelegte Variable heißt wie zuvor („' . $zahlName . '")',
    IPS_GetName((int)$neueZahlId)
);
@unlink($bundleFile);

// ---- Teil 7 (Befund 2): geprüft wird gegen die aktuellen Optionen, nicht gegen den Stand von ApplyChanges ----

[$geraet, $bundleFile] = testGeraet($zustaende);
$selectIdent = ident($geraet, 'input_select.test_auswahl');
melde($geraet, 'input_select/test_auswahl/options', '["A", "B", "C", "D"]');
melde($geraet, 'input_select/test_auswahl/state', 'D');
pruefe($geraet->wert($selectIdent) === 'D', 'Neue Option: Zustandsmeldung „D" kommt in der Variable an', var_export($geraet->wert($selectIdent), true));

HaSendungen::abholen();
$meldung = requestActionFehler($geraet, $selectIdent, 'D');
pruefe($meldung === null, 'Neue Option: „D" ist nach der Erweiterung in HA ein gültiger Wert', (string)$meldung);
pruefe(
    HaSendungen::abholen() === [['mqtt', 'statestream/input_select/test_auswahl/set', 'D']],
    'Neue Option: „D" wird gesendet'
);
$meldung = requestActionFehler($geraet, $selectIdent, 'E');
pruefe(
    $meldung !== null && str_contains($meldung, 'A, B, C, D'),
    'Neue Option: die Fehlermeldung nennt die aktuellen Optionen',
    (string)$meldung
);

melde($geraet, 'input_select/test_auswahl/options', '["A", "B"]');
$meldung = requestActionFehler($geraet, $selectIdent, 'C');
pruefe(
    $meldung !== null && str_contains($meldung, '(') && !str_contains($meldung, 'C,') && str_contains($meldung, 'A, B)'),
    'Entfallene Option: „C" wird nach der Kürzung in HA abgewiesen',
    (string)$meldung
);
@unlink($bundleFile);

$entitaet = testEntitaet($auswahl);
$entityIdent = ident($entitaet, 'input_select.test_auswahl');
melde($entitaet, 'input_select/test_auswahl/options', '["A", "B", "C", "D"]');
$meldung = requestActionFehler($entitaet, $entityIdent, 'D');
pruefe($meldung === null, 'Entity-Modul: „D" ist nach der Erweiterung in HA ein gültiger Wert', (string)$meldung);

// ---- Teil 8 (Befund 3): ein leerer Text ist ein Wert, kein Fehler ----

[$geraet, $bundleFile] = testGeraet($zustaende);
$textIdent = ident($geraet, 'input_text.test_text');
$numberIdent = ident($geraet, 'input_number.test_zahl');
pruefe($geraet->variablenId($textIdent) !== null && $geraet->hatAktion($textIdent), 'Text-Variable angelegt, mit Aktion', $textIdent);

HaSendungen::abholen();
pruefe(requestActionFehler($geraet, $textIdent, 'neu') === null, 'Text: „neu" meldet keinen Fehler');
pruefe(
    HaSendungen::abholen() === [['rest', 'input_text', 'set_value', ['entity_id' => 'input_text.test_text', 'value' => 'neu']]],
    'Text: „neu" geht als input_text.set_value an HA'
);

$meldung = requestActionFehler($geraet, $textIdent, '');
pruefe($meldung === null, 'Text: der leere Text meldet keinen Fehler (min 0)', (string)$meldung);
$sendungen = HaSendungen::abholen();
pruefe(
    $sendungen === [['rest', 'input_text', 'set_value', ['entity_id' => 'input_text.test_text', 'value' => '']]],
    'Text: der leere Text geht als input_text.set_value an HA',
    json_encode($sendungen)
);

// Code-Review build 167: Der REST-Weg kürzte den Text — „   " leerte ihn in HA, „ abc " kam als
// „abc" an. Der MQTT-Weg sendet ihn seit jeher unverändert; beide Wege gleich.
foreach (['   ', ' abc '] as $textWert) {
    $meldung = requestActionFehler($geraet, $textIdent, $textWert);
    $sendungen = HaSendungen::abholen();
    pruefe(
        $meldung === null
        && $sendungen === [['rest', 'input_text', 'set_value', ['entity_id' => 'input_text.test_text', 'value' => $textWert]]],
        'Text: „' . $textWert . '" geht unverändert an HA',
        (string)$meldung . json_encode($sendungen)
    );
}

$meldung = requestActionFehler($geraet, $numberIdent, false);
pruefe(
    $meldung !== null && str_contains($meldung, '"false"'),
    'Zahl: false wird abgewiesen, die Meldung nennt den Wert',
    (string)$meldung
);
@unlink($bundleFile);

$entitaet = testEntitaet($text);
HaSendungen::abholen();
$meldung = requestActionFehler($entitaet, ident($entitaet, 'input_text.test_text'), '');
pruefe($meldung === null, 'Entity-Modul: der leere Text meldet keinen Fehler', (string)$meldung);
$sendungen = HaSendungen::abholen();
pruefe(
    $sendungen === [['rest', 'input_text', 'set_value', ['entity_id' => 'input_text.test_text', 'value' => '']]],
    'Entity-Modul: der leere Text geht als input_text.set_value an HA',
    json_encode($sendungen)
);

// ---- Teil 9 (Befund 4): eine Zahl außerhalb von min/max wird abgewiesen statt gesendet ----

[$geraet, $bundleFile] = testGeraet($zustaende);
$numberIdent = ident($geraet, 'input_number.test_zahl');

HaSendungen::abholen();
$meldung = requestActionFehler($geraet, $numberIdent, 500);
pruefe($meldung !== null, 'Zahl: 500 liegt über max 100 und wird als Fehler gemeldet');
pruefe(
    $meldung !== null && str_contains($meldung, '"500"') && str_contains($meldung, '0 – 100'),
    'Zahl: die Fehlermeldung nennt den Wert und den erlaubten Bereich',
    (string)$meldung
);
pruefe(requestActionFehler($geraet, $numberIdent, -1) !== null, 'Zahl: -1 liegt unter min 0 und wird als Fehler gemeldet');
// Code-Review build 167: Bei einer Ganzzahl lief der Vergleich erst nach dem Abschneiden — 100.9
// ging als 100 durch, -0.5 als 0.
pruefe(requestActionFehler($geraet, $numberIdent, 100.9) !== null, 'Zahl (Ganzzahl): 100.9 liegt über max 100 und wird als Fehler gemeldet');
pruefe(requestActionFehler($geraet, $numberIdent, -0.5) !== null, 'Zahl (Ganzzahl): -0.5 liegt unter min 0 und wird als Fehler gemeldet');
pruefe(requestActionFehler($geraet, $numberIdent, '100,5') !== null, 'Zahl (Ganzzahl): „100,5" liegt über max 100 und wird als Fehler gemeldet');
pruefe(HaSendungen::abholen() === [], 'Zahl: abgewiesene Werte werden nicht gesendet');

pruefe(requestActionFehler($geraet, $numberIdent, 0) === null, 'Zahl: die Untergrenze 0 ist gültig');
pruefe(requestActionFehler($geraet, $numberIdent, 100) === null, 'Zahl: die Obergrenze 100 ist gültig');
$sendungen = HaSendungen::abholen();
pruefe(
    $sendungen === [
        ['rest', 'input_number', 'set_value', ['entity_id' => 'input_number.test_zahl', 'value' => 0.0]],
        ['rest', 'input_number', 'set_value', ['entity_id' => 'input_number.test_zahl', 'value' => 100.0]],
    ],
    'Zahl: beide Grenzwerte gehen als input_number.set_value an HA',
    json_encode($sendungen)
);

// HA hebt die Obergrenze an: Der Bereich folgt den aktuellen Attributen.
$zahlId = $geraet->variablenId($numberIdent);
melde($geraet, 'input_number/test_zahl/max', '200');
$meldung = requestActionFehler($geraet, $numberIdent, 150);
pruefe($meldung === null, 'Zahl: nach max 200 in HA ist 150 gültig', (string)$meldung);
pruefe($geraet->variablenId($numberIdent) === $zahlId, 'Zahl: eine neue Obergrenze legt die Variable nicht neu an');
@unlink($bundleFile);

$entitaet = testEntitaet($zahl);
$meldung = requestActionFehler($entitaet, ident($entitaet, 'input_number.test_zahl'), 500);
pruefe(
    $meldung !== null && str_contains($meldung, '0 – 100'),
    'Entity-Modul: 500 wird mit dem erlaubten Bereich abgewiesen',
    (string)$meldung
);

// ---- Teil 10: Kosten des heißen Pfads (Code-Review build 167, Befund 8) ----
// Die Typwechsel-Erkennung aus Teil 6 kostete je Attributmeldung ein zusätzliches GetIDForIdent;
// davor lasen GetIDForIdent und IPS_GetObject (Altnamen-Prüfung) die Variable schon einmal. Bei
// Attributfluten (1.250–2.800 Meldungen/min, Supportfall) ist jeder Kernel-Aufruf ~0,9 ms Wegzeit.
// Gemessen am Stand build 175: 5 GetIDForIdent je Attributmeldung.

[$geraet, $bundleFile] = testGeraet($zustaende);
$numberIdent = ident($geraet, 'input_number.test_zahl');
$vorher = KernelZaehler::stand();
melde($geraet, 'input_number/test_zahl/unit_of_measurement', '"mm"');
$arbeit = KernelZaehler::seit($vorher);
pruefe(($arbeit['GetIDForIdent'] ?? 0) <= 3, 'Heißer Pfad: eine Attributmeldung ohne Typwechsel kostet höchstens 3 GetIDForIdent', json_encode($arbeit));
pruefe(!isset($arbeit['VariableTypeChanged']) && !isset($arbeit['VariableCreated']), 'Heißer Pfad: die Variable bleibt dieselbe');
pruefe($geraet->hatAktion($numberIdent), 'Heißer Pfad: die Aktion bleibt bestehen');
@unlink($bundleFile);

// ---- Teil 11: retained Attribut-Topics ohne REST-Antwort legen die Variable nicht neu an ----
// Bisher offen (CLAUDE.md, Schalten): Lief ApplyChanges ohne REST-Antwort, kennt der State-Cache
// keine Attribute. Der Attribut-Pfad rechnete den Typ einer number dann mit dem einen eingehenden
// Attribut — beim Einspielen der retained Topics wurde die Variable zweimal neu angelegt
// (Integer → Float → Integer, neue ID, Archiv und Verknüpfungen hängen an der alten).

$datei = (string)tempnam(sys_get_temp_dir(), 'ha_action_check_');
file_put_contents($datei, json_encode(bundleZeilen($zustaende), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$geraet = neuesGeraet(['SourceMode' => 'bundle', 'BundlePath' => $datei, 'DeviceName' => 'Testgerät', 'DeviceID' => 'devid_test_entities']);
$numberIdent = ident($geraet, 'input_number.test_zahl');
$zahlId = $geraet->variablenId($numberIdent);
$zahlName = IPS_GetName((int)$zahlId);
pruefe(IPS_GetVariable((int)$zahlId)['VariableType'] === VARIABLETYPE_INTEGER, 'Ohne REST, Ausgangslage: Zahl-Variable als Integer aus der Konfiguration');

$vorher = KernelZaehler::stand();
foreach ($zahl['attributes'] as $attribut => $wert) {
    melde($geraet, 'input_number/test_zahl/' . $attribut, json_encode($wert, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
}
$arbeit = KernelZaehler::seit($vorher);
pruefe(!isset($arbeit['VariableTypeChanged']), 'Ohne REST: die retained Attribut-Topics legen die Zahl-Variable nicht neu an', json_encode($arbeit));
pruefe($geraet->variablenId($numberIdent) === $zahlId, 'Ohne REST: die Zahl-Variable behält ihre ID');
pruefe(IPS_GetName((int)$geraet->variablenId($numberIdent)) === $zahlName, 'Ohne REST: die Zahl-Variable behält ihren Namen');
@unlink($datei);

ergebnis();
