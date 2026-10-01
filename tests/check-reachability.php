<?php

declare(strict_types=1);

/**
 * Prüft die Erreichbarkeitsvariable „reachable" am ECHTEN Modul (Home Assistant Device,
 * Bundle-Modus) über den offiziellen Kernel-Stub:
 * - Ein Gerät gilt erst als nicht erreichbar, wenn ALLE Entitäten mit Cache-Eintrag länger als
 *   REACHABILITY_DELAY_S „unavailable" melden (Entprellung gegen den nächtlichen HA-Neustart).
 * - Ein einziger gültiger Zustand macht es sofort wieder erreichbar.
 * - Im Normalbetrieb (gültiger Wert, Gerät erreichbar) bleibt der heiße Pfad ohne Auswertung.
 *
 * Jede Meldung läuft als MQTT-Nachricht durch ReceiveData(), Flush und Timer durch RequestAction() -
 * jeweils in einer neuen Ausführung.
 *
 * Fixtures: echter HA-Recorder-Verlauf (tests/fixtures/reachability/*.json).
 */

require_once __DIR__ . '/device-harness.php';

error_reporting(E_ALL);

const REACHABLE = HADeviceConstants::REACHABLE_IDENT;
const TIMER = HADeviceConstants::TIMER_REACHABILITY;

/** @var list<string> $bundleDateien */
$bundleDateien = [];

/**
 * Gerät im Bundle-Modus mit den genannten Entitäten. Die Fixtures tragen nur die Entitäts-IDs
 * (der Recorder-Verlauf kam mit minimal_response), die Zeilen haben deshalb keine Attribute.
 *
 * @param list<string> $entityIds
 */
function geraetMit(array $entityIds): DeviceHarness
{
    global $bundleDateien;
    $zeilen = [];
    foreach ($entityIds as $entityId) {
        [$domain, $name] = explode('.', $entityId, 2);
        $zeilen[] = [
            'entity_id'   => $entityId,
            'name'        => $name,
            'domain'      => $domain,
            'device_id'   => 'devid_reachability',
            'device_name' => 'Testgerät',
            'create_var'  => true,
            'attributes'  => [],
        ];
    }
    $datei = tempnam(sys_get_temp_dir(), 'ha_reachability_check_');
    if ($datei === false) {
        throw new RuntimeException('Temp-Datei für das Bundle nicht angelegt');
    }
    $bundleDateien[] = $datei;
    file_put_contents($datei, json_encode($zeilen, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    DeviceHarness::$jetzt = 0;
    return neuesGeraet([
        'SourceMode' => 'bundle',
        'BundlePath' => $datei,
        'DeviceName' => 'Testgerät',
        'DeviceID'   => 'devid_reachability',
    ]);
}

// Ein Meldungseingang, wie ihn der Splitter aus mqtt_statestream weiterreicht.
function melde(DeviceHarness $g, string $entityId, string $state): void
{
    [$domain, $name] = explode('.', $entityId, 2);
    neueAusfuehrung($g)->ReceiveData(mqttMeldung('statestream/' . $domain . '/' . $name . '/state', $state));
}

// Der StateCacheFlush-Timer läuft ab.
function cacheFlush(DeviceHarness $g): void
{
    neueAusfuehrung($g)->RequestAction(HADeviceConstants::ACTION_STATE_CACHE_FLUSH, '');
}

// Der ReachabilityTimer läuft ab.
function timerLaeuftAb(DeviceHarness $g): void
{
    neueAusfuehrung($g)->RequestAction(HADeviceConstants::ACTION_REACHABILITY_CHECK, '');
}

/** @return array{entities: list<string>, ereignisse: list<array{ts:int, entity_id:string, state:string}>} */
function ladeFixture(string $name): array
{
    $json = json_decode((string)file_get_contents(__DIR__ . '/fixtures/reachability/' . $name), true, 512, JSON_THROW_ON_ERROR);
    return $json;
}

/**
 * Spielt einen Recorder-Verlauf ab. Nach jeder Zeitstempel-Gruppe läuft der Flush (so wie ihn der
 * 10-s-Flush-Timer auslöst); läuft zwischen zwei Gruppen der Erreichbarkeits-Timer ab, wird er
 * vorher ausgelöst. Liefert je Gruppe [ts, reachable, timer].
 *
 * @return list<array{0:int, 1:mixed, 2:int}>
 */
function abspielen(DeviceHarness $g, array $ereignisse, ?int $bis = null): array
{
    $gruppen = [];
    foreach ($ereignisse as $e) {
        $gruppen[$e['ts']][] = $e;
    }
    ksort($gruppen);
    $spur = [];
    $timerFaellig = null;
    foreach ($gruppen as $ts => $gruppe) {
        if ($timerFaellig !== null && $timerFaellig <= $ts) {
            DeviceHarness::$jetzt = $timerFaellig;
            timerLaeuftAb($g);
            $spur[] = [$timerFaellig, $g->wert(REACHABLE), $g->timer(TIMER)];
        }
        DeviceHarness::$jetzt = $ts;
        foreach ($gruppe as $e) {
            melde($g, $e['entity_id'], $e['state']);
        }
        cacheFlush($g);
        $spur[] = [$ts, $g->wert(REACHABLE), $g->timer(TIMER)];
        $timerFaellig = $g->timer(TIMER) > 0 ? $ts + intdiv($g->timer(TIMER), 1000) : null;
    }
    if ($bis !== null && $timerFaellig !== null && $timerFaellig <= $bis) {
        DeviceHarness::$jetzt = $timerFaellig;
        timerLaeuftAb($g);
        $spur[] = [$timerFaellig, $g->wert(REACHABLE), $g->timer(TIMER)];
    }
    return $spur;
}

$delay = HADeviceConstants::REACHABILITY_DELAY_S;
pruefe($delay === 600, 'Entprellung beträgt 10 Minuten (Messung 30.09.2026: HA-Neustart bis 218 s)');

// ---- Teil 1: Variable wird angelegt, startet als erreichbar ----

$g = geraetMit(['sensor.a']);
$reachableId = $g->variablenId(REACHABLE);
pruefe($reachableId !== null && IPS_GetVariable($reachableId)['VariableType'] === VARIABLETYPE_BOOLEAN, 'reachable wird als Boolean angelegt');
pruefe($g->wert(REACHABLE) === true, 'Ohne Cache-Einträge gilt das Gerät als erreichbar');

// ---- Teil 2: Luftentfeuchter 30.09.2026 — tot, nach 10 min false, bei Rückkehr sofort true ----

$f = ladeFixture('luftentfeuchter_20260930.json');
$g = geraetMit($f['entities']);
$spur = abspielen($g, $f['ereignisse']);
$start = $f['ereignisse'][0]['ts'];
pruefe($spur[0][1] === true && $spur[0][2] === $delay * 1000, 'Entfeuchter: alle unavailable → noch erreichbar, Timer auf 600 s');
$nachTimer = array_values(array_filter($spur, static fn(array $s): bool => $s[0] === $start + $delay));
pruefe(count($nachTimer) === 1 && $nachTimer[0][1] === false, 'Entfeuchter: nach 600 s nicht erreichbar');
pruefe(($nachTimer[0][2] ?? -1) === 0, 'Entfeuchter: Timer nach dem Kippen aus');
$rueckkehr = strtotime('2026-09-30T14:07:49+00:00');
$beiRueckkehr = array_values(array_filter($spur, static fn(array $s): bool => $s[0] === $rueckkehr));
pruefe(count($beiRueckkehr) === 1 && $beiRueckkehr[0][1] === true, 'Entfeuchter: erste gültige Meldung (14:07:49) macht sofort erreichbar');
pruefe(end($spur)[1] === true && end($spur)[2] === 0, 'Entfeuchter: am Ende erreichbar, kein Timer');

// ---- Teil 3: Wandthermostat 29.09.2026 — HA-Neustart, 143–161 s weg, bleibt durchgehend true ----

$f = ladeFixture('wandthermostat_20260929.json');
$g = geraetMit($f['entities']);
$spur = abspielen($g, $f['ereignisse'], strtotime($f['fenster'][1]));
pruefe(array_all($spur, static fn(array $s): bool => $s[1] === true), 'Wandthermostat: beim HA-Neustart nie nicht erreichbar');
pruefe(array_any($spur, static fn(array $s): bool => $s[2] > 0), 'Wandthermostat: Timer wurde beim Ausfall gestellt');
pruefe(end($spur)[2] === 0, 'Wandthermostat: Timer nach Rückkehr wieder aus');

// ---- Teil 4: Geschirrspüler 27.09.2026 — nie alle weg ----

$f = ladeFixture('geschirrspueler_20260927.json');
$g = geraetMit($f['entities']);
$spur = abspielen($g, $f['ereignisse'], strtotime($f['fenster'][1]));
pruefe(array_all($spur, static fn(array $s): bool => $s[1] === true), 'Geschirrspüler: Teilausfall hält das Gerät erreichbar');

// ---- Teil 5: Backofen 29.09.2026 — von Anfang an alle unavailable ----

$f = ladeFixture('backofen_20260929.json');
$g = geraetMit($f['entities']);
$spur = abspielen($g, $f['ereignisse'], strtotime($f['fenster'][1]));
pruefe(end($spur)[1] === false, 'Backofen: dauerhaft getrennt → nicht erreichbar');
$ersterFalse = array_values(array_filter($spur, static fn(array $s): bool => $s[1] === false))[0][0] ?? null;
pruefe($ersterFalse === $f['ereignisse'][0]['ts'] + $delay, 'Backofen: genau nach der Entprellzeit gekippt');

// ---- Teil 6: heißer Pfad bleibt im Normalbetrieb ohne Auswertung ----

$g = geraetMit(['sensor.a', 'sensor.b']);
DeviceHarness::$jetzt = 1000;
melde($g, 'sensor.a', '21.5');
melde($g, 'sensor.b', '40');
cacheFlush($g);
pruefe($g->wert(REACHABLE) === true, 'Normalbetrieb: erreichbar');
$vorher = KernelZaehler::stand();
for ($i = 0; $i < 20; $i++) {
    DeviceHarness::$jetzt = 1001 + $i;
    melde($g, 'sensor.a', (string)(21 + $i / 10));
}
cacheFlush($g);
$arbeit = KernelZaehler::seit($vorher);
pruefe(($arbeit['ConfiguredEntities:evaluateReachability'] ?? 0) === 0, 'Normalbetrieb: keine Konfigurationsschleife bei gültigen Meldungen', json_encode($arbeit));
pruefe(($arbeit['SetValue:' . REACHABLE] ?? 0) === 0, 'Normalbetrieb: reachable wird nicht neu geschrieben', json_encode($arbeit));
pruefe($g->puffer(HADeviceConstants::BUFFER_REACHABILITY_DIRTY) === '', 'Normalbetrieb: kein Dirty-Flag');

// ---- Teil 7: Zustand überlebt eine neue PHP-Ausführung ----

$g = geraetMit(['sensor.a']);
DeviceHarness::$jetzt = 5000;
melde($g, 'sensor.a', 'unavailable');
cacheFlush($g);
DeviceHarness::$jetzt = 5000 + $delay;
pruefe(neueAusfuehrung($g)->rufe('handleReachabilityAction', HADeviceConstants::ACTION_REACHABILITY_CHECK), 'Timer-Action wird behandelt');
pruefe($g->wert(REACHABLE) === false, 'Nach neuer Ausführung zählt der gemerkte Beginn des Ausfalls');
pruefe(!neueAusfuehrung($g)->rufe('handleReachabilityAction', 'irgendwas'), 'Fremder Action-Ident wird nicht behandelt');

// ---- Teil 8: unknown ist kein Ausfall ----

$g = geraetMit(['sensor.a', 'sensor.b']);
DeviceHarness::$jetzt = 100;
melde($g, 'sensor.a', 'unavailable');
melde($g, 'sensor.b', 'unknown');
cacheFlush($g);
DeviceHarness::$jetzt = 100 + $delay;
neueAusfuehrung($g)->rufe('evaluateReachability');
pruefe($g->wert(REACHABLE) === true, 'unknown zählt nicht als unavailable');

// ---- Teil 9: Bestandsinstanz ohne registrierten Timer (Update 1.4 → 1.5) ----
// Beim Neuladen des Moduls läuft ApplyChanges schon mit dem neuen Code, bevor Create() den
// Timer registriert hat. Der Kernel meldet dann „Timer ReachabilityTimer does not exist".

$g = geraetMit(['sensor.a']);
$g->erreichbarkeitsTimerRegistriert = false;
$warnung = null;
try {
    neueAusfuehrung($g)->ApplyChanges();
} catch (ErrorException $e) {
    $warnung = $e->getMessage();
}
pruefe($warnung === null, 'Ohne registrierten Timer: Auswertung läuft ohne Warnung', (string)$warnung);
pruefe($g->wert(REACHABLE) === true, 'Ohne registrierten Timer: Gerät gilt als erreichbar');

DeviceHarness::$jetzt = 100;
$warnung = null;
try {
    melde($g, 'sensor.a', 'unavailable');
    cacheFlush($g);
} catch (ErrorException $e) {
    $warnung = $e->getMessage();
}
pruefe($warnung === null, 'Ohne registrierten Timer: Ausfall wird ohne Warnung vorgemerkt', (string)$warnung);
pruefe($g->puffer(HADeviceConstants::BUFFER_REACHABILITY_SINCE) === '100', 'Ohne registrierten Timer: Beginn des Ausfalls bleibt gemerkt');

$g->erreichbarkeitsTimerRegistriert = true;
DeviceHarness::$jetzt = 100 + $delay;
neueAusfuehrung($g)->rufe('evaluateReachability');
pruefe($g->wert(REACHABLE) === false, 'Nach dem neuen Create(): der gemerkte Ausfall kippt die Variable');

// ---- Teil 10: Ein bekannter Ausfall bleibt über ApplyChanges und Kernel-Neustart bestehen ----
// Blindtest 01.10.2026 am nuc: Backofen, WLED, MYGGSPRAY — seit Monaten weg — sprangen nach dem
// Neuladen für 10 Minuten auf „erreichbar". Die Entprellung gilt nur für den Weg erreichbar → nicht
// erreichbar; ein Gerät, das schon als nicht erreichbar gilt, hat nichts abzuwarten.

$g = geraetMit(['sensor.a', 'sensor.b']);
DeviceHarness::$jetzt = 1000;
melde($g, 'sensor.a', 'unavailable');
melde($g, 'sensor.b', 'unavailable');
cacheFlush($g);
DeviceHarness::$jetzt = 1000 + $delay;
timerLaeuftAb($g);
pruefe($g->wert(REACHABLE) === false, 'Ausfall: nach der Entprellzeit nicht erreichbar');

$schreibvorgaenge = [];
DeviceHarness::$jetzt = 5000;
$vorher = KernelZaehler::stand();
neueAusfuehrung($g)->ApplyChanges();
$schreibvorgaenge[] = KernelZaehler::seit($vorher)['SetValue:' . REACHABLE] ?? 0;
pruefe($g->wert(REACHABLE) === false, 'Ausfall: bleibt nach ApplyChanges nicht erreichbar');

$g->pufferVerwerfen();
DeviceHarness::$jetzt = 6000;
$vorher = KernelZaehler::stand();
neueAusfuehrung($g)->ApplyChanges();
$schreibvorgaenge[] = KernelZaehler::seit($vorher)['SetValue:' . REACHABLE] ?? 0;
pruefe($g->wert(REACHABLE) === false, 'Ausfall: bleibt nach Kernel-Neustart nicht erreichbar');
pruefe($g->timer(TIMER) === 0, 'Ausfall: nach Kernel-Neustart kein Timer, es gibt nichts abzuwarten');

DeviceHarness::$jetzt = 6100;
melde($g, 'sensor.a', 'unavailable');
cacheFlush($g);
$vorher = KernelZaehler::stand();
DeviceHarness::$jetzt = 6000 + $delay;
timerLaeuftAb($g);
$schreibvorgaenge[] = KernelZaehler::seit($vorher)['SetValue:' . REACHABLE] ?? 0;
pruefe($g->wert(REACHABLE) === false, 'Ausfall: weitere unavailable-Meldungen ändern nichts');
pruefe(array_sum($schreibvorgaenge) === 0, 'Ausfall: reachable wird kein einziges Mal neu geschrieben (kein Ereignis für Anwender)', json_encode($schreibvorgaenge));

DeviceHarness::$jetzt = 7000;
melde($g, 'sensor.b', '21.5');
cacheFlush($g);
pruefe($g->wert(REACHABLE) === true, 'Ausfall: erste gültige Meldung nach dem Neustart macht sofort erreichbar');

// ---- Teil 11: eine neu angelegte Variable kennt keinen Ausfall ----
// Code-Review build 168: Die Variable entsteht mit dem Standardwert false. Für „gilt schon als nicht
// erreichbar, nichts abzuwarten" (Teil 10) hieß das: Eine Instanz, die während des nächtlichen
// HA-Neustarts angelegt oder auf 1.5 gehoben wird, stand sofort auf „nicht erreichbar" — ohne
// Entprellung, bis zur nächsten gültigen Meldung.

$g = geraetMit(['sensor.a', 'sensor.b']);
DeviceHarness::$jetzt = 1000;
melde($g, 'sensor.a', 'unavailable');
melde($g, 'sensor.b', 'unavailable');
cacheFlush($g);
// Stand vor dem Update auf 1.5: keine Variable, nach dem Neuladen leere Buffer.
IPS_DeleteVariable((int)$g->variablenId(REACHABLE));
$g->pufferVerwerfen();

DeviceHarness::$jetzt = 2000;
neueAusfuehrung($g)->ApplyChanges();
pruefe($g->wert(REACHABLE) === true, 'Neue Variable: gilt zunächst als erreichbar', var_export($g->wert(REACHABLE), true));
pruefe($g->timer(TIMER) > 0, 'Neue Variable: die Entprellung läuft');

DeviceHarness::$jetzt = 2000 + $delay;
timerLaeuftAb($g);
pruefe($g->wert(REACHABLE) === false, 'Neue Variable: nach der Entprellzeit nicht erreichbar');

foreach ($bundleDateien as $datei) {
    @unlink($datei);
}

ergebnis();
