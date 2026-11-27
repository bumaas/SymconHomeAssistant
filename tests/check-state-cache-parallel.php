<?php

declare(strict_types=1);

/**
 * Prüft den Zustands-Cache von Device und Entity der klassischen Bridge, wenn zwei Ausführungen derselben Instanz
 * nebeneinander laufen, an den ECHTEN Modulen über den offiziellen Kernel-Stub.
 *
 * Der Kernel lässt mehrere Aufrufe gleichzeitig in dieselbe Instanz. Gemessen am nuc (27.11.2026, Rust-Edition):
 * Während ein Übernehmen in einem Gerät mit 110 Entitäten 3,6 s lief, kehrten fünf weitere Aufrufe derselben
 * Instanz in je 8 bis 19 ms zurück, und keine MQTT-Meldung des Geräts wartete darauf. Meldungen untereinander
 * kommen nacheinander.
 *
 * Bis build 259 las jede Ausführung den ganzen Cache einmal und schrieb bei jeder Änderung den ganzen gemerkten
 * Stand zurück. Was eine Meldung in der Zwischenzeit geschrieben hatte, war danach wieder weg:
 *
 * - Übernehmen bzw. SyncStates (Teil 1 und 2): Die Zustände kommen je Entität per REST. Eine Meldung zu einer
 *   schon abgefragten Entität ging mit der nächsten Entität wieder verloren. Meldete die REST-Antwort
 *   „unavailable“ (Home Assistant startet noch) und kam die Entität währenddessen zurück, führte der Cache sie
 *   weiter als ausgefallen; standen alle anderen auch so da, meldete das Gerät nach zehn Minuten „Nicht
 *   erreichbar“.
 * - Schalten mit „Status emulieren“ in der Entity-Instanz (Teil 3): Der Cache wird vor dem Senden gelesen und
 *   danach geschrieben. In dieser Zeit meldet Home Assistant die Folgen des Schaltens. Das nächste Attribut-Topic
 *   glich die Lichtvariablen dann mit den alten Attributen ab, die gerade gemeldete Helligkeit sprang auf 0.
 *
 * Die zweite Ausführung läuft über die Naht HaSendungen::$unterwegs, also mitten in der ersten, während deren
 * Anfrage an Home Assistant unterwegs ist. Der Inhalt der Meldungen ist echt. Welche Meldungen in die Laufzeit
 * einer Anfrage fallen, ist angenommen (in Teil 3 der erste Schwung des Mitschnitts bis 20:47:00.084, der Rest
 * danach); an der Anlage lässt sich das nicht mitschneiden. Die REST-Antworten „unavailable“ in Teil 2 sind die
 * echten Zustände mit ersetztem `state`.
 *
 * Fixtures (echte Daten vom nuc):
 * - ha_states_test_entities.json: vier Test-Entitäten (/api/states)
 * - hue_aurelle_config_20261019.json, hue_aurelle_statestream_20261019.txt: Licht, Ein- und Ausschalten
 */

require_once __DIR__ . '/device-harness.php';

error_reporting(E_ALL);

const LICHT = 'light.hue_aurelle';
const CACHE_PUFFER = 'EntityStateCacheBuffer';
const REACHABLE = HADeviceConstants::REACHABLE_IDENT;

function melde(DeviceHarness|EntityHarness $modul, string $topic, string $payload): void
{
    neueAusfuehrung($modul)->ReceiveData(mqttMeldung('statestream/' . $topic, $payload));
}

/** @return array{0: DeviceHarness, 1: string} Gerät mit den Test-Entitäten und seine Bundle-Datei */
function testGeraet(array $zustaende): array
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
    $datei = (string)tempnam(sys_get_temp_dir(), 'ha_cache_parallel_');
    file_put_contents($datei, json_encode($zeilen, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    DeviceHarness::$jetzt = 0;
    $geraet = neuesGeraet([
        'SourceMode' => 'bundle',
        'BundlePath' => $datei,
        'DeviceName' => 'Testgerät',
        'DeviceID'   => 'devid_test_entities',
    ]);
    return [$geraet, $datei];
}

/**
 * SyncStates wie nach „Zustände synchronisieren“ bzw. am Ende des Übernehmens: je Entität eine REST-Abfrage.
 * $dazwischen läuft, während die Abfrage der zweiten Entität unterwegs ist.
 *
 * @param list<array<string, mixed>> $antworten REST-Antworten in der Reihenfolge der Entitäten
 */
function synchronisiere(DeviceHarness $geraet, array $antworten, ?Closure $dazwischen): int
{
    HaSendungen::$zustaende = [];
    foreach ($antworten as $antwort) {
        HaSendungen::$zustaende[$antwort['entity_id']] = $antwort;
    }
    HaSendungen::$zustandsAbfragen = [];
    HaSendungen::$parentAktiv = true;
    $eingeschoben = 0;
    $zweite = $antworten[1]['entity_id'];
    HaSendungen::$unterwegs = $dazwischen === null ? null : static function (string $art, string $ziel) use ($zweite, $dazwischen, &$eingeschoben): void {
        if ($art === 'zustand' && $ziel === $zweite && $eingeschoben === 0) {
            $eingeschoben++;
            $dazwischen();
        }
    };
    neueAusfuehrung($geraet)->SyncStates();
    HaSendungen::$unterwegs = null;
    HaSendungen::$parentAktiv = null;
    HaSendungen::$zustaende = [];
    return $eingeschoben;
}

$zustaende = json_decode((string)file_get_contents(__DIR__ . '/fixtures/ha_states_test_entities.json'), true, 512, JSON_THROW_ON_ERROR);
[$auswahl, $zahl] = $zustaende;
$alleIds = array_column($zustaende, 'entity_id');
pruefe(count($zustaende) === 4 && $auswahl['state'] === 'A' && in_array('B', $auswahl['attributes']['options'], true),
    'Fixture: vier Entitäten, die Auswahl steht in HA auf A, B ist eine Option');

// ---- Teil 1: SyncStates fragt je Entität per REST, eine Meldung kommt dazwischen ----

[$geraet, $datei1] = testGeraet($zustaende);
$auswahlIdent = (string)(neueAusfuehrung($geraet)->rufe('getConfiguredEntityById', $auswahl['entity_id'])['ident'] ?? '');

// Während die zweite Entität abgefragt wird, meldet statestream die erste mit einem neuen Zustand.
$eingeschoben = synchronisiere($geraet, $zustaende, static fn() => melde($geraet, 'input_select/test_auswahl/state', 'B'));

pruefe(HaSendungen::$zustandsAbfragen === $alleIds, 'SyncStates fragte die vier Entitäten der Reihe nach ab', json_encode(HaSendungen::$zustandsAbfragen));
pruefe($eingeschoben === 1, 'Die Meldung lief während der zweiten Abfrage');
pruefe($geraet->wert($auswahlIdent) === 'B', 'Auswahl zeigt den gemeldeten Zustand B', var_export($geraet->wert($auswahlIdent), true));
$cacheZustand = neueAusfuehrung($geraet)->rufe('getCachedEntityState', $auswahl['entity_id']);
pruefe($cacheZustand === 'B', 'Cache führt für die Auswahl den gemeldeten Zustand B', var_export($cacheZustand, true));
$cacheRoh = neueAusfuehrung($geraet)->rufe('getCachedEntityRawState', $auswahl['entity_id']);
pruefe($cacheRoh === 'B', 'Cache führt für die Auswahl den gemeldeten Rohzustand B', var_export($cacheRoh, true));
foreach (array_slice($zustaende, 1) as $zustand) {
    $roh = neueAusfuehrung($geraet)->rufe('getCachedEntityRawState', $zustand['entity_id']);
    pruefe($roh === $zustand['state'], 'Cache führt für ' . $zustand['entity_id'] . ' den Zustand aus der REST-Antwort', var_export($roh, true));
}

// ---- Teil 2: sichtbare Folge bei der Erreichbarkeit ----
// Home Assistant startet noch: Die REST-Antworten melden alle vier Entitäten als „unavailable“. Während der
// Abfrage kommt die erste zurück. Das Gerät liefert also wieder und darf nicht als ausgefallen gelten.

$ausgefallen = array_map(static fn(array $z): array => ['state' => 'unavailable'] + $z, $zustaende);
$nachFrist = static function (DeviceHarness $g): void {
    neueAusfuehrung($g)->RequestAction(HADeviceConstants::ACTION_STATE_CACHE_FLUSH, '');
    DeviceHarness::$jetzt += HADeviceConstants::REACHABILITY_DELAY_S + 1;
    neueAusfuehrung($g)->RequestAction(HADeviceConstants::ACTION_REACHABILITY_CHECK, '');
};

// Gegenprobe ohne Meldung: Alle bleiben „unavailable“, nach der Frist gilt das Gerät als nicht erreichbar.
[$tot, $datei2] = testGeraet($zustaende);
DeviceHarness::$jetzt = 1000;
synchronisiere($tot, $ausgefallen, null);
pruefe($tot->wert(REACHABLE) === true, 'Gegenprobe: vor Ablauf der Frist noch erreichbar');
$nachFrist($tot);
pruefe($tot->wert(REACHABLE) === false, 'Gegenprobe: alle Entitäten unavailable, nach der Frist nicht erreichbar');

[$lebt, $datei3] = testGeraet($zustaende);
DeviceHarness::$jetzt = 1000;
$eingeschoben = synchronisiere($lebt, $ausgefallen, static fn() => melde($lebt, 'input_select/test_auswahl/state', 'A'));
pruefe($eingeschoben === 1, 'Die Auswahl meldete sich während der zweiten Abfrage zurück');
$roh = neueAusfuehrung($lebt)->rufe('getCachedEntityRawState', $auswahl['entity_id']);
pruefe($roh === 'A', 'Cache führt die zurückgekehrte Auswahl mit Rohzustand A', var_export($roh, true));
$nachFrist($lebt);
pruefe($lebt->wert(REACHABLE) === true, 'Eine Entität liefert wieder: Das Gerät bleibt nach der Frist erreichbar');
DeviceHarness::$jetzt = 0;

// ---- Teil 3: Entity-Instanz, Schalten mit „Status emulieren“, Meldungen während des Sendens ----

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

/** @param list<array{zeit:string, attribut:string, payload:string}> $meldungen */
function spiele(EntityHarness $licht, array $meldungen): void
{
    foreach ($meldungen as $m) {
        melde($licht, 'light/hue_aurelle/' . $m['attribut'], $m['payload']);
    }
}

$zeile = json_decode((string)file_get_contents(__DIR__ . '/fixtures/hue_aurelle_config_20261019.json'), true, 512, JSON_THROW_ON_ERROR)[0];
$mitschnitt = ladeMitschnitt('hue_aurelle_statestream_20261019.txt');
$retained = array_values(array_filter($mitschnitt, static fn(array $m): bool => str_starts_with($m['zeit'], '20:46:54')));
$waehrendSenden = array_values(array_filter($mitschnitt, static fn(array $m): bool => in_array($m['zeit'], ['20:47:00.041', '20:47:00.084'], true)));
$danach = array_values(array_filter($mitschnitt, static fn(array $m): bool => $m['zeit'] === '20:47:00.131'));
pruefe(count($retained) === 15 && count($waehrendSenden) === 10 && count($danach) === 4, 'Mitschnitt geteilt: 15 retained, 10 während des Sendens, 4 danach',
    count($retained) . ' / ' . count($waehrendSenden) . ' / ' . count($danach));
pruefe(in_array(['zeit' => '20:47:00.084', 'attribut' => 'brightness', 'payload' => '128'], $waehrendSenden, true), 'Während des Sendens meldet HA die Helligkeit 128');
pruefe(end($danach)['attribut'] === 'supported_features', 'Danach kommt als Letztes supported_features');

// Entity-Instanz wie nach UpdateConfiguration(): Zustand „off“ aus dem Mitschnitt, Attribute aus der Konfiguration.
$licht = neueEntitaet([
    'EntityID'      => LICHT,
    'DeviceID'      => LICHT,
    'DeviceName'    => $zeile['name'],
    'DeviceArea'    => 'Sonstiges',
    'EmulateStatus' => true,
]);
$aufgeloest = $licht->rufe('buildResolvedEntityRow', ['entity_id' => LICHT, 'state' => 'off', 'attributes' => $zeile['attributes']], true, true);
$licht->rufe('writeResolvedConfig', json_encode([$aufgeloest], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$neu = neueAusfuehrung($licht);
$neu->rufe('processEntities', $neu->rufe('getConfiguredEntities', 'check'), 'statestream');

spiele($licht, $retained);
$statusIdent = (string)(neueAusfuehrung($licht)->rufe('getConfiguredEntityById', LICHT)['ident'] ?? '');
$helligkeitIdent = (string)neueAusfuehrung($licht)->rufe('buildSharedAttributeIdent', LICHT, 'brightness');
$farbtemperaturIdent = (string)neueAusfuehrung($licht)->rufe('buildSharedAttributeIdent', LICHT, 'color_temp_kelvin');
$farbmodusIdent = (string)neueAusfuehrung($licht)->rufe('buildSharedAttributeIdent', LICHT, 'color_mode');
pruefe($licht->wert($statusIdent) === false && $licht->wert($helligkeitIdent) === 0, 'Ausgangslage: Licht aus, Helligkeit 0',
    var_export($licht->wert($statusIdent), true) . ' / ' . var_export($licht->wert($helligkeitIdent), true));

HaSendungen::abholen();
$eingeschoben = 0;
HaSendungen::$unterwegs = static function (string $art, string $ziel) use ($licht, $waehrendSenden, &$eingeschoben): void {
    HaSendungen::$unterwegs = null; // die eingeschobenen Meldungen stellen selbst keine Anfragen
    if ($art === 'mqtt' && $ziel === 'statestream/light/hue_aurelle/set') {
        spiele($licht, $waehrendSenden);
        $eingeschoben = count($waehrendSenden);
    }
};
neueAusfuehrung($licht)->RequestAction($statusIdent, true);
HaSendungen::$unterwegs = null;

$sendungen = HaSendungen::abholen();
pruefe($sendungen === [['mqtt', 'statestream/light/hue_aurelle/set', 'ON']], 'Schalten ging als set-Topic hinaus (der Splitter führt es per REST aus)', json_encode($sendungen));
pruefe($eingeschoben === 10, 'Die zehn Meldungen liefen während des Sendens', (string)$eingeschoben);
pruefe($licht->wert($statusIdent) === true, 'Licht steht auf an');
pruefe($licht->wert($helligkeitIdent) === 128, 'Nach dem Schalten: Helligkeit 128 aus der Meldung', var_export($licht->wert($helligkeitIdent), true));
$cacheAttribute = neueAusfuehrung($licht)->rufe('getCachedEntityAttributes', LICHT);
pruefe(($cacheAttribute['brightness'] ?? null) === 128, 'Nach dem Schalten: Cache führt die gemeldete Helligkeit 128', var_export($cacheAttribute['brightness'] ?? null, true));
pruefe(($cacheAttribute['color_temp_kelvin'] ?? null) === 3003, 'Nach dem Schalten: Cache führt die gemeldete Farbtemperatur 3003',
    var_export($cacheAttribute['color_temp_kelvin'] ?? null, true));

spiele($licht, $danach);
pruefe($licht->wert($helligkeitIdent) === 128, 'Nach supported_features: Helligkeit bleibt 128', var_export($licht->wert($helligkeitIdent), true));
pruefe($licht->wert($farbtemperaturIdent) === 3003, 'Nach supported_features: Farbtemperatur bleibt 3003', var_export($licht->wert($farbtemperaturIdent), true));
pruefe($licht->wert($farbmodusIdent) === 'color_temp', 'Nach supported_features: Farbmodus bleibt color_temp', var_export($licht->wert($farbmodusIdent), true));
pruefe($licht->wert($statusIdent) === true, 'Nach supported_features: Licht bleibt an');

// ---- Teil 4: Kosten im heißen Pfad ----
// Jede Änderung liest den Buffer des Caches neu, um fremde Änderungen zu sehen. Eine Zustandsmeldung ändert
// den Cache zweimal (Rohzustand, Zustand), eine Attributmeldung einmal.

DeviceHarness::$pufferLesungen = [];
melde($geraet, 'input_select/test_auswahl/state', 'C');
$lesungenZustand = DeviceHarness::$pufferLesungen[CACHE_PUFFER] ?? 0;
pruefe($lesungenZustand <= 2, 'Eine Zustandsmeldung liest den Cache-Buffer höchstens zweimal', (string)$lesungenZustand);
pruefe(neueAusfuehrung($geraet)->rufe('getCachedEntityState', $auswahl['entity_id']) === 'C', 'Die Zustandsmeldung steht im Cache');

DeviceHarness::$pufferLesungen = [];
melde($geraet, 'input_number/test_zahl/max', '200');
$lesungenAttribut = DeviceHarness::$pufferLesungen[CACHE_PUFFER] ?? 0;
pruefe($lesungenAttribut <= 2, 'Eine Attributmeldung liest den Cache-Buffer höchstens zweimal', (string)$lesungenAttribut);
echo 'Lesungen des Cache-Buffers: Zustandsmeldung ', $lesungenZustand, ', Attributmeldung ', $lesungenAttribut, "\n";

foreach ([$datei1, $datei2, $datei3] as $datei) {
    @unlink($datei);
}
ergebnis();
