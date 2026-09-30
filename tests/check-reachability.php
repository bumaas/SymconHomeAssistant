<?php

declare(strict_types=1);

/**
 * Prüft die Erreichbarkeitsvariable „reachable" (HAEntityStoreTrait):
 * - Ein Gerät gilt erst als nicht erreichbar, wenn ALLE Entitäten mit Cache-Eintrag länger als
 *   REACHABILITY_DELAY_S „unavailable" melden (Entprellung gegen den nächtlichen HA-Neustart).
 * - Ein einziger gültiger Zustand macht es sofort wieder erreichbar.
 * - Im Normalbetrieb (gültiger Wert, Gerät erreichbar) bleibt der heiße Pfad ohne Auswertung.
 *
 * Fixtures: echter HA-Recorder-Verlauf (tests/fixtures/reachability/*.json).
 */

foreach ([
    'VARIABLETYPE_BOOLEAN' => 0,
    'VARIABLETYPE_INTEGER' => 1,
    'VARIABLETYPE_FLOAT' => 2,
    'VARIABLETYPE_STRING' => 3,
    'VARIABLE_PRESENTATION_SWITCH' => 'Switch',
    'VARIABLE_PRESENTATION_VALUE_PRESENTATION' => 'ValuePresentation',
    'VARIABLE_PRESENTATION_DATE_TIME' => 'DateTime',
    'VARIABLE_PRESENTATION_DURATION' => 'Duration',
    'VARIABLE_PRESENTATION_ENUMERATION' => 'Enumeration',
    'VARIABLE_PRESENTATION_LEGACY' => 'Legacy',
    'VARIABLE_PRESENTATION_SLIDER' => 'Slider',
    'VARIABLE_PRESENTATION_COLOR' => 'Color',
    'VARIABLE_PRESENTATION_SHUTTER' => 'Shutter'
] as $name => $value) {
    if (!defined($name)) {
        define($name, $value);
    }
}

require_once __DIR__ . '/harness.php';
require_once dirname(__DIR__) . '/libs/HACommonIncludes.php';
require_once dirname(__DIR__) . '/libs/Device/HAEntityStore.php';

final class ReachabilityHarness implements HADeviceConstants
{
    use HAEntityStoreTrait;

    /** @var array<string, array<string, mixed>> */
    public array $entities = [];
    /** @var list<array<string, mixed>> */
    public array $configured = [];
    /** @var array<string, string> */
    public array $buffers = [];
    /** @var array<string, string> */
    public array $attributes = ['EntityStateCache' => '{}', 'LastMQTTMessage' => ''];
    /** @var array<string, int> */
    public array $timerIntervals = [];
    /** @var array<string, mixed> */
    public array $variables = [];
    /** @var array<string, int> */
    public array $maintained = [];
    public int $now = 0;
    public int $configReads = 0;
    public int $valueWrites = 0;

    /** @param list<string> $entityIds */
    public function __construct(array $entityIds)
    {
        foreach ($entityIds as $entityId) {
            $this->configured[] = ['entity_id' => $entityId, 'create_var' => true];
        }
    }

    // Ein Meldungseingang wie in HADomainStateHandlers::tryHandleStateFromTopic.
    public function feed(string $entityId, string $state): void
    {
        $this->updateEntityRawStateCache($entityId, $state);
        $this->updateAvailabilityValue($state);
    }

    public function flush(): void
    {
        $this->flushEntityStateCache();
    }

    public function timerAction(string $ident): bool
    {
        return $this->handleReachabilityAction($ident);
    }

    public function maintain(): void
    {
        $this->maintainReachableVariable();
    }

    public function evaluate(): void
    {
        $this->evaluateReachability();
    }

    public function reachable(): mixed
    {
        return $this->variables[self::REACHABLE_IDENT] ?? null;
    }

    public function timer(): int
    {
        return $this->timerIntervals[self::TIMER_REACHABILITY] ?? 0;
    }

    // Neue PHP-Ausführung: Buffer und Variablen bleiben (kernel-seitig), Speicherspiegel nicht.
    public function newExecution(): self
    {
        $copy = new self([]);
        $copy->configured = $this->configured;
        $copy->buffers = $this->buffers;
        $copy->attributes = $this->attributes;
        $copy->timerIntervals = $this->timerIntervals;
        $copy->variables = $this->variables;
        $copy->maintained = $this->maintained;
        $copy->now = $this->now;
        return $copy;
    }

    protected function reachabilityNow(): int
    {
        return $this->now;
    }

    protected function getConfiguredEntities(string $caller = ''): array
    {
        $this->configReads++;
        return $this->configured;
    }

    protected function ReadPropertyBoolean(string $name): bool
    {
        return false;
    }

    protected function GetBuffer(string $name): string
    {
        return $this->buffers[$name] ?? '';
    }

    protected function SetBuffer(string $name, string $value): void
    {
        $this->buffers[$name] = $value;
    }

    protected function ReadAttributeString(string $name): string
    {
        return $this->attributes[$name] ?? '';
    }

    protected function WriteAttributeString(string $name, string $value): void
    {
        $this->attributes[$name] = $value;
    }

    protected function SetTimerInterval(string $name, int $interval): void
    {
        $this->timerIntervals[$name] = $interval;
    }

    protected function GetTimerInterval(string $name): int
    {
        return $this->timerIntervals[$name] ?? 0;
    }

    protected function MaintainVariable(string $ident, string $name, int $type, string|array $presentation = '', int $position = 0, bool $keep = true): bool
    {
        if ($keep) {
            $this->maintained[$ident] = $type;
            $this->variables[$ident] ??= null;
        } else {
            unset($this->maintained[$ident], $this->variables[$ident]);
        }
        return true;
    }

    protected function GetIDForIdent(string $ident): int|false
    {
        return array_key_exists($ident, $this->variables) ? 10000 + crc32($ident) % 40000 : false;
    }

    protected function GetValue(string $ident): mixed
    {
        return $this->variables[$ident] ?? null;
    }

    protected function SetValue(string $ident, mixed $value): bool
    {
        $this->valueWrites++;
        $this->variables[$ident] = $value;
        return true;
    }

    protected function Translate(string $text): string
    {
        return $text;
    }

    private function buildSharedBinarySensorPresentation(string $trueCaption, string $falseCaption, string $icon): array
    {
        return ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION];
    }

    private function updateUnavailableEntitiesJsonVariable(): void
    {
    }

    private function updateDiagnosticsLabels(): void
    {
    }

    protected function debugExpert(string $context, string $message, array $data = [], bool $log = false): void
    {
    }
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
function abspielen(ReachabilityHarness $h, array $ereignisse, ?int $bis = null): array
{
    $gruppen = [];
    foreach ($ereignisse as $e) {
        $gruppen[$e['ts']][] = $e;
    }
    ksort($gruppen);
    $spur = [];
    $timerFaellig = null;
    $letzte = null;
    foreach ($gruppen as $ts => $gruppe) {
        if ($timerFaellig !== null && $timerFaellig <= $ts) {
            $h->now = $timerFaellig;
            $h->timerAction(ReachabilityHarness::ACTION_REACHABILITY_CHECK);
            $spur[] = [$timerFaellig, $h->reachable(), $h->timer()];
        }
        $h->now = $ts;
        foreach ($gruppe as $e) {
            $h->feed($e['entity_id'], $e['state']);
        }
        $h->flush();
        $spur[] = [$ts, $h->reachable(), $h->timer()];
        $timerFaellig = $h->timer() > 0 ? $ts + intdiv($h->timer(), 1000) : null;
        $letzte = $ts;
    }
    if ($bis !== null && $timerFaellig !== null && $timerFaellig <= $bis) {
        $h->now = $timerFaellig;
        $h->timerAction(ReachabilityHarness::ACTION_REACHABILITY_CHECK);
        $spur[] = [$timerFaellig, $h->reachable(), $h->timer()];
    }
    return $spur;
}

$delay = ReachabilityHarness::REACHABILITY_DELAY_S;
pruefe($delay === 600, 'Entprellung beträgt 10 Minuten (Messung 30.09.2026: HA-Neustart bis 218 s)');

// ---- Teil 1: Variable wird angelegt, startet als erreichbar ----

$h = new ReachabilityHarness(['sensor.a']);
$h->maintain();
pruefe(($h->maintained[ReachabilityHarness::REACHABLE_IDENT] ?? null) === VARIABLETYPE_BOOLEAN, 'reachable wird als Boolean angelegt');
$h->evaluate();
pruefe($h->reachable() === true, 'Ohne Cache-Einträge gilt das Gerät als erreichbar');

// ---- Teil 2: Luftentfeuchter 30.09.2026 — tot, nach 10 min false, bei Rückkehr sofort true ----

$f = ladeFixture('luftentfeuchter_20260930.json');
$h = new ReachabilityHarness($f['entities']);
$h->maintain();
$spur = abspielen($h, $f['ereignisse']);
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
$h = new ReachabilityHarness($f['entities']);
$h->maintain();
$spur = abspielen($h, $f['ereignisse'], strtotime($f['fenster'][1]));
pruefe(array_all($spur, static fn(array $s): bool => $s[1] === true), 'Wandthermostat: beim HA-Neustart nie nicht erreichbar');
pruefe(array_any($spur, static fn(array $s): bool => $s[2] > 0), 'Wandthermostat: Timer wurde beim Ausfall gestellt');
pruefe(end($spur)[2] === 0, 'Wandthermostat: Timer nach Rückkehr wieder aus');

// ---- Teil 4: Geschirrspüler 27.09.2026 — nie alle weg ----

$f = ladeFixture('geschirrspueler_20260927.json');
$h = new ReachabilityHarness($f['entities']);
$h->maintain();
$spur = abspielen($h, $f['ereignisse'], strtotime($f['fenster'][1]));
pruefe(array_all($spur, static fn(array $s): bool => $s[1] === true), 'Geschirrspüler: Teilausfall hält das Gerät erreichbar');

// ---- Teil 5: Backofen 29.09.2026 — von Anfang an alle unavailable ----

$f = ladeFixture('backofen_20260929.json');
$h = new ReachabilityHarness($f['entities']);
$h->maintain();
$spur = abspielen($h, $f['ereignisse'], strtotime($f['fenster'][1]));
pruefe(end($spur)[1] === false, 'Backofen: dauerhaft getrennt → nicht erreichbar');
$ersterFalse = array_values(array_filter($spur, static fn(array $s): bool => $s[1] === false))[0][0] ?? null;
pruefe($ersterFalse === $f['ereignisse'][0]['ts'] + $delay, 'Backofen: genau nach der Entprellzeit gekippt');

// ---- Teil 6: heißer Pfad bleibt im Normalbetrieb ohne Auswertung ----

$h = new ReachabilityHarness(['sensor.a', 'sensor.b']);
$h->maintain();
$h->now = 1000;
$h->feed('sensor.a', '21.5');
$h->feed('sensor.b', '40');
$h->flush();
pruefe($h->reachable() === true, 'Normalbetrieb: erreichbar');
$h = $h->newExecution();
$reads = $h->configReads;
$writes = $h->valueWrites;
for ($i = 0; $i < 20; $i++) {
    $h->now = 1001 + $i;
    $h->feed('sensor.a', (string)(21 + $i / 10));
}
$h->flush();
pruefe($h->configReads === $reads, 'Normalbetrieb: keine Konfigurationsschleife bei gültigen Meldungen');
pruefe($h->valueWrites === $writes, 'Normalbetrieb: reachable wird nicht neu geschrieben');
pruefe(($h->buffers[ReachabilityHarness::BUFFER_REACHABILITY_DIRTY] ?? '') === '', 'Normalbetrieb: kein Dirty-Flag');

// ---- Teil 7: Zustand überlebt eine neue PHP-Ausführung ----

$h = new ReachabilityHarness(['sensor.a']);
$h->maintain();
$h->now = 5000;
$h->feed('sensor.a', 'unavailable');
$h->flush();
$h = $h->newExecution();
$h->now = 5000 + $delay;
pruefe($h->timerAction(ReachabilityHarness::ACTION_REACHABILITY_CHECK), 'Timer-Action wird behandelt');
pruefe($h->reachable() === false, 'Nach neuer Ausführung zählt der gemerkte Beginn des Ausfalls');
pruefe(!$h->timerAction('irgendwas'), 'Fremder Action-Ident wird nicht behandelt');

// ---- Teil 8: unknown ist kein Ausfall ----

$h = new ReachabilityHarness(['sensor.a', 'sensor.b']);
$h->maintain();
$h->now = 100;
$h->feed('sensor.a', 'unavailable');
$h->feed('sensor.b', 'unknown');
$h->flush();
$h->now = 100 + $delay;
$h->evaluate();
pruefe($h->reachable() === true, 'unknown zählt nicht als unavailable');

ergebnis();
