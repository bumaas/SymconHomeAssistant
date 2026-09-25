<?php

declare(strict_types=1);

/**
 * Prüft die zweistufige Persistenz des EntityStateCache (HAEntityStoreTrait):
 * - heißer Pfad schreibt nur in den Buffer (kein Attribut-Write) und bewaffnet den Flush-Timer
 * - Flush schreibt den Buffer-Stand einmalig ins Attribut und schaltet den Timer ab
 * - Lesen bevorzugt den Buffer, fällt ohne Buffer auf das Attribut zurück (Kernel-Neustart)
 * - touchLastMqttMessage drosselt Attribut-Write + Diagnose-Labels auf LAST_MQTT_LABEL_THROTTLE_SEC
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

function check(bool $condition, string $label): void
{
    pruefe($condition, $label);
}

final class StateCacheHarness implements HADeviceConstants
{
    use HAEntityStoreTrait;

    /** @var array<string, array<string, mixed>> */
    public array $entities = [];

    /** @var array<string, string> */
    public array $buffers = [];
    /** @var array<string, string> */
    public array $attributes = ['EntityStateCache' => '{}', 'LastMQTTMessage' => ''];
    /** @var array<string, int> */
    public array $timerIntervals = [];
    public int $attributeWrites = 0;
    public int $bufferCacheWrites = 0;
    public int $diagnosticsUpdates = 0;

    public function write(array $cache): void
    {
        $this->writeEntityStateCache($cache);
    }

    public function read(): array
    {
        return $this->readEntityStateCache();
    }

    public function touch(): void
    {
        $this->touchLastMqttMessage();
    }

    public function handleFlushAction(string $ident): bool
    {
        return $this->handleStateCacheFlushAction($ident);
    }

    public function flush(): void
    {
        $this->flushEntityStateCache();
    }

    protected function GetBuffer(string $name): string
    {
        return $this->buffers[$name] ?? '';
    }

    protected function SetBuffer(string $name, string $value): void
    {
        if ($name === self::BUFFER_ENTITY_STATE_CACHE) {
            $this->bufferCacheWrites++;
        }
        $this->buffers[$name] = $value;
    }

    protected function ReadAttributeString(string $name): string
    {
        return $this->attributes[$name] ?? '';
    }

    protected function WriteAttributeString(string $name, string $value): void
    {
        $this->attributeWrites++;
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

    private function updateDiagnosticsLabels(): void
    {
        $this->diagnosticsUpdates++;
    }

    protected function debugExpert(string $context, string $message, array $data = [], bool $log = false): void
    {
    }
}

// ---- Teil 1: Schreiben landet nur im Buffer, Flush persistiert gebündelt ----

$h = new StateCacheHarness();
$h->write(['sensor.a' => ['state' => '1', 'ts' => 1]]);
check($h->attributeWrites === 0, 'Heißer Pfad schreibt kein Attribut');
check($h->bufferCacheWrites === 1, 'Heißer Pfad schreibt den Buffer');
check(($h->timerIntervals[StateCacheHarness::TIMER_STATE_CACHE_FLUSH] ?? 0) === StateCacheHarness::STATE_CACHE_FLUSH_DELAY_MS, 'Flush-Timer wird bewaffnet');

$h->write(['sensor.a' => ['state' => '1', 'ts' => 1]]);
check($h->bufferCacheWrites === 1, 'Identischer Stand wird nicht erneut geschrieben');

$h->write(['sensor.a' => ['state' => '2', 'ts' => 2]]);
check($h->bufferCacheWrites === 2, 'Geänderter Stand aktualisiert den Buffer');
check($h->attributeWrites === 0, 'Attribut bleibt bis zum Flush unberührt');

check(!$h->handleFlushAction('irgendwas'), 'Fremder Action-Ident wird nicht behandelt');
check($h->handleFlushAction(StateCacheHarness::ACTION_STATE_CACHE_FLUSH), 'Flush-Action wird behandelt');
check($h->attributeWrites === 1, 'Flush schreibt das Attribut genau einmal');
check($h->timerIntervals[StateCacheHarness::TIMER_STATE_CACHE_FLUSH] === 0, 'Flush schaltet den Timer ab');
check(str_contains($h->attributes['EntityStateCache'], '"state":"2"'), 'Attribut enthält den letzten Stand');

$h->flush();
check($h->attributeWrites === 1, 'Unveränderter Flush schreibt nicht erneut');

// ---- Teil 2: Lesen — Buffer gewinnt, Attribut ist Fallback ----

$fresh = new StateCacheHarness();
$fresh->attributes['EntityStateCache'] = '{"sensor.b":{"state":"alt"}}';
check(($fresh->read()['sensor.b']['state'] ?? null) === 'alt', 'Ohne Buffer gilt der Attribut-Stand (Kernel-Neustart)');

$fresh2 = new StateCacheHarness();
$fresh2->attributes['EntityStateCache'] = '{"sensor.b":{"state":"alt"}}';
$fresh2->buffers[StateCacheHarness::BUFFER_ENTITY_STATE_CACHE] = '{"sensor.b":{"state":"neu"}}';
check(($fresh2->read()['sensor.b']['state'] ?? null) === 'neu', 'Mit Buffer gewinnt der Buffer-Stand');

// ---- Teil 3: touchLastMqttMessage-Drossel ----

$t = new StateCacheHarness();
$t->touch();
check($t->attributeWrites === 1 && $t->diagnosticsUpdates === 1, 'Erster Touch schreibt Attribut und Labels');
$t->touch();
check($t->attributeWrites === 1 && $t->diagnosticsUpdates === 1, 'Sofortiger zweiter Touch wird gedrosselt');
$t->buffers[StateCacheHarness::BUFFER_LAST_MQTT_TOUCH] = (string)(time() - StateCacheHarness::LAST_MQTT_LABEL_THROTTLE_SEC - 1);
$t->touch();
check($t->attributeWrites === 2 && $t->diagnosticsUpdates === 2, 'Nach Ablauf der Drossel wird wieder geschrieben');

ergebnis();
