<?php

declare(strict_types=1);

/**
 * Red-Green-Check für den ReceiveData-Hotpath des klassischen Home-Assistant-Device-Moduls.
 *
 * Hintergrund (Supportfall, 08/2026): Bei Geräten mit vielen Entitäten wird pro MQTT-Message
 * die komplette Entitäten-Konfiguration (ResolvedConfig, 150–250 KB) mehrfach neu gelesen,
 * dekodiert und mit Kernel-Aufrufen pro Entität benannt — der Splitter staut sich dadurch.
 *
 * Der Check lädt das ECHTE Modul (Home Assistant Device/module.php) über den offiziellen
 * Kernel-Stub (tests/device-harness.php) und simuliert Symcon-Semantik: Jede MQTT-Message läuft
 * in einer eigenen PHP-„Ausführung" (frisches Modul-Objekt), während Properties/Attribute/Buffer/
 * Variablen überleben (wie Kernel-Settings bzw. Instanz-Buffer).
 * Gemessen wird Arbeit (Attribut-Reads, Kernel-Aufrufe), nicht Zeit — deterministisch.
 * Gezählt wird an den Modul-Methoden; das globale IPS_GetObject() des Stubs lässt sich nicht
 * mitzählen, die Kernel-Objektaufrufe sind deshalb die GetIDForIdent-Aufrufe.
 *
 * Rot vor dem ConfiguredEntities-Cache (Build 147), grün danach:
 *   1. ≤1 ResolvedConfig-Attribut-Read pro Message (statt 2 je Durchlauf bei 3–4 Durchläufen)
 *   2. ≤15 GetIDForIdent pro Message (statt ~1–2 je Entität und Durchlauf)
 *   3. Instanz-Memo: zweiter getConfiguredEntities-Aufruf derselben Ausführung ist gratis
 *   4. P5: EnableExpertDebug/EnablePerformanceLog werden nicht pro Debug-Aufruf neu gelesen
 *   5. P7: Unavailable-Entities-JSON wird nicht pro Message mit {} überschrieben,
 *      sondern beim StateCacheFlush korrekt befüllt
 *
 * Dauerhafte Regressionswächter (auch vor dem Umbau grün):
 *   6. Cache-Hit-Ergebnis identisch mit frischem Rebuild (Idents, Dedup-Namenszähler)
 *   7. Konfigurationsänderung (ApplyChanges) schlägt auf den nächsten Aufruf durch
 */

require_once __DIR__ . '/device-harness.php';

error_reporting(E_ALL);

// ---------------------------------------------------------------------------
// Check-Gerüst
// ---------------------------------------------------------------------------
function check(bool $condition, string $label, string $detail = ''): void
{
    pruefe($condition, $label, $detail);
}

function newExecution(): DeviceHarness
{
    // Frisches Objekt = neue PHP-Ausführung in Symcon; der Kernel-Zustand bleibt.
    global $geraet;
    return neueAusfuehrung($geraet);
}

/**
 * Simuliert eine einzelne MQTT-Message in einer eigenen Ausführung
 * und liefert die dabei angefallene Arbeit als Zähler-Delta.
 *
 * @return array<string, int>
 */
function runMessage(string $topic, string $payload): array
{
    global $lastMessageDevice;
    $before = KernelZaehler::stand();
    $device = newExecution();
    $lastMessageDevice = $device;
    $device->ReceiveData(mqttMeldung($topic, $payload));
    return KernelZaehler::seit($before);
}

function kernelCallCount(array $diff): int
{
    return $diff['GetIDForIdent'] ?? 0;
}

// ---------------------------------------------------------------------------
// Fixture: Gerät mit vielen Sensor-Entitäten (synthetisch, keine privaten Daten)
// ---------------------------------------------------------------------------
const CHECK_ENTITY_COUNT = 180;
const CHECK_DEVICE_NAME = 'Testgerät WP';

function buildFixtureRows(): array
{
    $rows = [];
    for ($i = 1; $i <= CHECK_ENTITY_COUNT; $i++) {
        $rows[] = [
            'entity_id'           => "sensor.testgeraet_wp_messwert_$i",
            'name'                => CHECK_DEVICE_NAME . " Messwert $i",
            'domain'              => 'sensor',
            'area'                => 'Keller',
            'device_id'           => 'devid_test_wp',
            'device_name'         => CHECK_DEVICE_NAME,
            'device_model'        => 'EHS-Modell',
            'device_manufacturer' => 'Samsung',
            'create_var'          => true,
            'attributes'          => [
                'unit_of_measurement' => '°C',
                'friendly_name'       => CHECK_DEVICE_NAME . " Messwert $i",
            ],
        ];
    }

    // Zwei Entitäten mit identischem Anzeigenamen: prüft die Dedup-Suffix-Logik
    // (sharedEntityBaseNameCounts) — genau die Stelle, die ein Cache mitsichern muss.
    foreach (['sensor.testgeraet_wp_doppelname', 'sensor.testgeraet_wp_doppelname_2'] as $entityId) {
        $rows[] = [
            'entity_id'           => $entityId,
            'name'                => CHECK_DEVICE_NAME . ' Doppelname',
            'domain'              => 'sensor',
            'area'                => 'Keller',
            'device_id'           => 'devid_test_wp',
            'device_name'         => CHECK_DEVICE_NAME,
            'device_model'        => 'EHS-Modell',
            'device_manufacturer' => 'Samsung',
            'create_var'          => true,
            'attributes'          => ['friendly_name' => CHECK_DEVICE_NAME . ' Doppelname'],
        ];
    }

    // Kandidat für den Unavailable-JSON-Check (P7).
    $rows[] = [
        'entity_id'           => 'sensor.testgeraet_wp_stoerung',
        'name'                => CHECK_DEVICE_NAME . ' Störung',
        'domain'              => 'sensor',
        'area'                => 'Keller',
        'device_id'           => 'devid_test_wp',
        'device_name'         => CHECK_DEVICE_NAME,
        'device_model'        => 'EHS-Modell',
        'device_manufacturer' => 'Samsung',
        'create_var'          => true,
        'attributes'          => ['friendly_name' => CHECK_DEVICE_NAME . ' Störung'],
    ];

    return $rows;
}

$bundleFile = tempnam(sys_get_temp_dir(), 'ha_cache_check_');
if (!pruefe($bundleFile !== false, 'Temp-Datei für das Bundle angelegt')) {
    ergebnis();
}
file_put_contents($bundleFile, json_encode(buildFixtureRows(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

// ---------------------------------------------------------------------------
// Setup: Instanz im Bundle-Modus initialisieren (kein Parent nötig)
// ---------------------------------------------------------------------------
$geraet = neuesGeraet([
    'SourceMode'                 => 'bundle',
    'BundlePath'                 => $bundleFile,
    'DeviceName'                 => CHECK_DEVICE_NAME,
    'DeviceID'                   => 'devid_test_wp',
    'ShowUnavailableEntitiesJson'=> true,
    'EnableExpertDebug'          => false,
    'EnablePerformanceLog'       => false,
]);

check($geraet->status() === IS_ACTIVE, 'Setup: Instanz ist aktiv (Bundle-Modus)');
$variableCount = count($geraet->idents());
check($variableCount > CHECK_ENTITY_COUNT, 'Setup: Variablen wurden angelegt', "nur $variableCount Variablen");
check($geraet->variablenId('unavailable_entities_json') !== null, 'Setup: Unavailable-JSON-Variable existiert');
check($geraet->variablenId(HADeviceConstants::REACHABLE_IDENT) !== null, 'Setup: Erreichbarkeitsvariable existiert');
check($geraet->wert(HADeviceConstants::REACHABLE_IDENT) === true, 'Setup: Gerät gilt nach ApplyChanges als erreichbar');

// Ident-Snapshot für den Stabilitätsvergleich am Ende
$identSnapshot = $geraet->idents();

// Sentinel: Der Message-Hotpath darf diese Variable nicht anfassen.
$geraet->wertSetzen('unavailable_entities_json', '__SENTINEL__');

// Warm-up-Message (füllt nach dem Umbau den ausführungsübergreifenden Cache)
runMessage('statestream/sensor/testgeraet_wp_messwert_1/state', '20.0');

// ---------------------------------------------------------------------------
// 1+2+4+5a: Arbeit pro heißer Message
// ---------------------------------------------------------------------------
$hotMessages = [
    ['statestream/sensor/testgeraet_wp_messwert_2/state', '21.5', 'Messwert 2'],
    ['statestream/sensor/testgeraet_wp_messwert_3/state', '22.5', 'Messwert 3'],
    ['statestream/sensor/fremdes_geraet_xyz/state',       '1',    'Fremd-Topic (verworfen)'],
];

foreach ($hotMessages as [$topic, $payload, $label]) {
    $diff = runMessage($topic, $payload);
    // 8a: Eine State-Message braucht eine Konfigurationszeile, nicht die ganze Liste.
    // Das Dekodieren des kompletten Caches kostete auf einem Raspberry Pi 42 ms je Message.
    check(
        $lastMessageDevice->lies('configuredEntitiesMemo') === null,
        "Zeilenzugriff [$label]: Hotpath dekodiert nicht die komplette Entitäten-Konfiguration"
    );
    $resolvedReads = $diff['ReadAttr:ResolvedConfig'] ?? 0;
    $kernelCalls = kernelCallCount($diff);
    check($resolvedReads <= 1, "Hotpath [$label]: höchstens 1 ResolvedConfig-Read pro Message", "waren $resolvedReads");
    check($kernelCalls <= 15, "Hotpath [$label]: höchstens 15 Kernel-Objektaufrufe pro Message", "waren $kernelCalls");
    check(($diff['ReadProp:EnableExpertDebug'] ?? 0) <= 2, "P5 [$label]: EnableExpertDebug höchstens 2x gelesen", 'waren ' . ($diff['ReadProp:EnableExpertDebug'] ?? 0));
    check(($diff['ReadProp:EnablePerformanceLog'] ?? 0) <= 2, "P5 [$label]: EnablePerformanceLog höchstens 2x gelesen", 'waren ' . ($diff['ReadProp:EnablePerformanceLog'] ?? 0));
    check(($diff['SetValue:unavailable_entities_json'] ?? 0) === 0, "P7 [$label]: Unavailable-JSON wird im Hotpath nicht geschrieben", ($diff['SetValue:unavailable_entities_json'] ?? 0) . ' Writes');
}

// Sanity: Die Messages wurden fachlich verarbeitet (kein Early-Out gemessen).
$value2 = $geraet->wert('sensor_messwert_2');
check(is_float($value2) && abs($value2 - 21.5) < 0.001, 'Sanity: Messwert 2 wurde auf 21.5 gesetzt', 'Wert: ' . var_export($value2, true));

check(
    $geraet->wert('unavailable_entities_json') === '__SENTINEL__',
    'P7: Unavailable-JSON-Variable nach Messages unangetastet (kein {}-Überschreiben)',
    'Wert: ' . var_export($geraet->wert('unavailable_entities_json'), true)
);

// ---------------------------------------------------------------------------
// 3: Instanz-Memo innerhalb einer Ausführung
// ---------------------------------------------------------------------------
$memoDevice = newExecution();
$memoDevice->rufe('getConfiguredEntities', 'check-memo-1');
$before = KernelZaehler::stand();
$memoResult = $memoDevice->rufe('getConfiguredEntities', 'check-memo-2');
$memoDiff = KernelZaehler::seit($before);
check(is_array($memoResult) && count($memoResult) >= CHECK_ENTITY_COUNT, 'Memo: getConfiguredEntities liefert alle Entitäten');
check(($memoDiff['ReadAttr:ResolvedConfig'] ?? 0) === 0, 'Memo: zweiter Aufruf derselben Ausführung liest das Attribut nicht erneut', 'waren ' . ($memoDiff['ReadAttr:ResolvedConfig'] ?? 0));
check(kernelCallCount($memoDiff) === 0, 'Memo: zweiter Aufruf kommt ohne Kernel-Objektaufrufe aus', 'waren ' . kernelCallCount($memoDiff));

// ---------------------------------------------------------------------------
// 6: Äquivalenz Cache-Hit vs. frischer Rebuild (inkl. Dedup-Namenszähler)
// ---------------------------------------------------------------------------
$hitDevice = newExecution();
$hitRows = $hitDevice->rufe('getConfiguredEntities', 'check-equivalence-hit');
$hitCounts = $hitDevice->lies('sharedEntityBaseNameCounts');

// Kernel-Neustart simulieren: alle Instanz-Buffer verwerfen -> erzwungener Rebuild.
$geraet->pufferVerwerfen();
$rebuildDevice = newExecution();
$rebuildRows = $rebuildDevice->rufe('getConfiguredEntities', 'check-equivalence-rebuild');
$rebuildCounts = $rebuildDevice->lies('sharedEntityBaseNameCounts');

check(
    json_encode($hitRows, JSON_THROW_ON_ERROR) === json_encode($rebuildRows, JSON_THROW_ON_ERROR),
    'Äquivalenz: Cache-Hit-Ergebnis identisch mit frischem Rebuild (Idents, Reihenfolge, Felder)'
);
check($hitCounts === $rebuildCounts, 'Äquivalenz: Dedup-Namenszähler identisch (Cache-Hit vs. Rebuild)');
check(($rebuildCounts['Doppelname'] ?? 0) === 2, 'Äquivalenz: Dedup-Fall im Fixture wirksam (Doppelname 2x)', 'Zähler: ' . var_export($rebuildCounts['Doppelname'] ?? null, true));

// ---------------------------------------------------------------------------
// 7: Invalidierung — Konfigurationsänderung schlägt durch
// ---------------------------------------------------------------------------
$rows = buildFixtureRows();
$rows[] = [
    'entity_id'           => 'sensor.testgeraet_wp_neu',
    'name'                => CHECK_DEVICE_NAME . ' Neu',
    'domain'              => 'sensor',
    'area'                => 'Keller',
    'device_id'           => 'devid_test_wp',
    'device_name'         => CHECK_DEVICE_NAME,
    'device_model'        => 'EHS-Modell',
    'device_manufacturer' => 'Samsung',
    'create_var'          => true,
    'attributes'          => ['friendly_name' => CHECK_DEVICE_NAME . ' Neu'],
];
file_put_contents($bundleFile, json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$applyDevice = newExecution();
$applyDevice->ApplyChanges();

$afterDevice = newExecution();
$afterRows = $afterDevice->rufe('getConfiguredEntities', 'check-invalidation');
$hasNew = is_array($afterRows) && array_any($afterRows, static fn(array $row): bool => ($row['entity_id'] ?? '') === 'sensor.testgeraet_wp_neu');
check($hasNew, 'Invalidierung: neue Entität nach ApplyChanges im nächsten Aufruf sichtbar');

// Ident-Stabilität: Bestehende Variablen wurden weder neu angelegt noch ersetzt.
$identsNow = $geraet->idents();
$missingIdents = array_diff($identSnapshot, $identsNow);
check($missingIdents === [], 'Ident-Stabilität: keine der ursprünglichen Variablen verschwunden', implode(', ', array_slice($missingIdents, 0, 5)));
check((KernelZaehler::$zaehler['VariableTypeChanged'] ?? 0) === 0, 'Ident-Stabilität: keine Variable wegen Typwechsel neu angelegt');

// ---------------------------------------------------------------------------
// 8b: Zeilenzugriff liefert dasselbe wie die volle Liste und bleibt selbstheilend
// ---------------------------------------------------------------------------
$fullDevice = newExecution();
$fullRows = $fullDevice->rufe('getConfiguredEntities', 'check-row-full');
$fullCounts = $fullDevice->lies('sharedEntityBaseNameCounts');
$rowMismatches = [];
foreach ($fullRows as $fullRow) {
    $entityId = (string)($fullRow['entity_id'] ?? '');
    $rowDevice = newExecution();
    $row = $rowDevice->rufe('getConfiguredEntityById', $entityId);
    if (json_encode($row, JSON_THROW_ON_ERROR) !== json_encode($fullRow, JSON_THROW_ON_ERROR)) {
        $rowMismatches[] = $entityId;
    }
}
check($rowMismatches === [], 'Zeilenzugriff: jede Zeile identisch mit der vollen Liste', implode(', ', array_slice($rowMismatches, 0, 5)));

$countsDevice = newExecution();
$countsDevice->rufe('getConfiguredEntityById', 'sensor.testgeraet_wp_doppelname');
check(
    $countsDevice->lies('sharedEntityBaseNameCounts') === $fullCounts,
    'Zeilenzugriff: Dedup-Namenszähler werden mit restauriert'
);
check(
    newExecution()->rufe('getConfiguredEntityById', 'sensor.gibt_es_nicht') === null,
    'Zeilenzugriff: unbekannte Entität liefert null'
);

// Selbstheilung: ResolvedConfig ändert sich am Choke-Point vorbei -> der Zeilenzugriff
// darf keine veraltete Zeile aus dem Cache liefern.
$attributesBackup = $geraet->attribut('ResolvedConfig');
$tampered = json_decode($attributesBackup, true);
$tampered = array_values(array_filter($tampered, static fn(array $row): bool => ($row['entity_id'] ?? '') !== 'sensor.testgeraet_wp_neu'));
$geraet->attributSetzen('ResolvedConfig', json_encode($tampered, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
check(
    newExecution()->rufe('getConfiguredEntityById', 'sensor.testgeraet_wp_neu') === null,
    'Zeilenzugriff: nach Änderung der ResolvedConfig keine veraltete Zeile aus dem Cache'
);
$geraet->attributSetzen('ResolvedConfig', $attributesBackup);
check(
    is_array(newExecution()->rufe('getConfiguredEntityById', 'sensor.testgeraet_wp_neu')),
    'Zeilenzugriff: nach Rückkehr der alten ResolvedConfig ist die Entität wieder da'
);

// ---------------------------------------------------------------------------
// 5b: P7 — Unavailable-JSON wird beim StateCacheFlush korrekt befüllt
// ---------------------------------------------------------------------------
runMessage('statestream/sensor/testgeraet_wp_stoerung/state', 'unavailable');
runMessage('statestream/sensor/testgeraet_wp_messwert_1/state', '23.0');

$flushDevice = newExecution();
$flushDevice->RequestAction(HADeviceConstants::ACTION_STATE_CACHE_FLUSH, '');

$jsonValue = $geraet->wert('unavailable_entities_json');
$decoded = is_string($jsonValue) ? json_decode($jsonValue, true) : null;
check(
    is_array($decoded) && isset($decoded['sensor.testgeraet_wp_stoerung']),
    'P7: Unavailable-JSON enthält nach dem Flush die nicht verfügbare Entität',
    'Wert: ' . var_export($jsonValue, true)
);

@unlink($bundleFile);

ergebnis();
