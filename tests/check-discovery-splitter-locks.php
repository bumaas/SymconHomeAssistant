<?php

declare(strict_types=1);

/**
 * Prüft am ECHTEN Modul (Home Assistant MQTT Discovery Splitter) über den Kernel-Stub die Sperren um die
 * Zwischenspeicher, die build 246 eingeführt hat.
 *
 * Anlass: Code-Review vom 26.11.2026 über build 245 bis 248, Befunde 1 bis 3 (gelesen, nicht an einer Anlage
 * nachgestellt):
 *   1. Ein Leser wartet höchstens 5 s auf die Bündelsperre und liest danach den alten Stand. pruneCaches (aus
 *      ApplyChanges) und RemoveStaleDiscoveryConfigs schreiben diesen alten Stand dann zurück und überschreiben
 *      das Ergebnis des laufenden Bündelns.
 *   2. Ab 16 vollen Vormerk-Abschnitten bündelt ReceiveData selbst und wartet dabei bis zu 5 s auf die Sperre,
 *      je Meldung. Läuft gerade ein langes Bündeln, staut sich der Empfang wieder.
 *   3. Ist die Vormerk-Sperre länger als 1 s belegt, verwirft ReceiveData die Meldung für den Zwischenspeicher
 *      und schreibt nur eine Debug-Zeile.
 *
 * Der Test läuft in einem Thread. „Ein anderer Thread hält die Sperre“ heißt hier: Der Name steht in der
 * Semaphorenliste des Stubs, IPS_SemaphoreEnter liefert dann sofort false. Wie lange das Modul warten würde,
 * zeigt die Naht enterSemaphore (DiscoverySplitterHarness::$sperrAnfragen).
 *
 * Fixture: tests/fixtures/discovery_zwavejs_20261125.json (Bundle eines Anwenders, neutralisiert, Gerät „Raum-Bild“).
 */

require_once __DIR__ . '/device-harness.php';

error_reporting(E_ALL);

$bundle = json_decode((string)file_get_contents(__DIR__ . '/fixtures/discovery_zwavejs_20261125.json'), true, 512, JSON_THROW_ON_ERROR);

const CACHE_ATTRIBUTE = ['MqttDiscoveryConfigCache', 'MqttTopicPayloadCache', 'MqttReferencedTopicLookup'];
const DIMMER = 'Z-Wave-JS/Raum/Bild/switch_multilevel/endpoint_0/currentValue';
const DIMMER_CONFIG = 'homeassistant/light/Raum-Bild/dimmer/config';
const FREMD = 'fremd/nicht/referenziert';

$text = static fn(mixed $p): string => is_string($p) ? $p : json_encode($p, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

function neuerSplitter(): DiscoverySplitterHarness
{
    $s = neueFensterInstanz(DiscoverySplitterHarness::class);
    neueAusfuehrung($s)->ApplyChanges();
    return $s;
}

function sende(DiscoverySplitterHarness $s, string $topic, string $payload): void
{
    neueAusfuehrung($s)->ReceiveData(json_encode([
        'DataID'           => HAIds::DATA_MQTT_RX,
        'Topic'            => $topic,
        'Payload'          => bin2hex($payload),
        'Retain'           => false,
        'QualityOfService' => 0,
    ], JSON_THROW_ON_ERROR));
}

function frage(DiscoverySplitterHarness $s, string $aktion, array $zusatz = []): array
{
    $antwort = neueAusfuehrung($s)->ForwardData(json_encode([
        'DataID'          => HAIds::DATA_MQTT_DISCOVERY_DEVICE_TO_SPLITTER,
        'DiscoveryAction' => $aktion,
    ] + $zusatz, JSON_THROW_ON_ERROR));
    return json_decode($antwort, true, 512, JSON_THROW_ON_ERROR);
}

/** @return array<string, string> Topic => Payload */
function payloads(DiscoverySplitterHarness $s, array $topics): array
{
    $ergebnis = [];
    foreach (frage($s, 'GetTopicPayloads', ['Topics' => $topics])['Items'] as $satz) {
        $ergebnis[$satz['topic']] = $satz['payload'];
    }
    return $ergebnis;
}

function ladeBundle(DiscoverySplitterHarness $s, array $bundle, Closure $text): void
{
    foreach ($bundle['discovery_configs'] as $c) {
        sende($s, $c['topic'], $text($c['payload']));
    }
    foreach ($bundle['topic_payloads'] as $p) {
        sende($s, $p['topic'], $text($p['payload']));
    }
}

function cacheSchreibzugriffe(): int
{
    $summe = 0;
    foreach (CACHE_ATTRIBUTE as $name) {
        $summe += DiscoverySplitterHarness::$attributZugriffe['w:' . $name] ?? 0;
    }
    return $summe;
}

/** Ein anderer Thread hält die Semaphore $name. */
function sperreHalten(string $name): void
{
    $liste = new ReflectionProperty(IPS\ScriptEngine::class, 'semaphores');
    $gehalten = $liste->getValue();
    $gehalten[] = $name;
    $liste->setValue(null, $gehalten);
}

function sperreFreigeben(string $name): void
{
    IPS_SemaphoreLeave($name);
}

function bündelSperre(DiscoverySplitterHarness $s): string
{
    return 'HAMD_CacheFlush_' . $s->id();
}

function vormerkSperre(DiscoverySplitterHarness $s): string
{
    return 'HAMD_PendingCacheUpdates_' . $s->id();
}

$dimmerConfig = array_find($bundle['discovery_configs'], static fn(array $c): bool => $c['topic'] === DIMMER_CONFIG);

// --- Teil 1 (Befund 1): Während ein anderer Thread bündelt, schreibt ApplyChanges (pruneCaches) die
// Zwischenspeicher nicht. Sonst landet der Stand ohne die gerade gebündelten Meldungen über dem Ergebnis.
$s = neuerSplitter();
ladeBundle($s, $bundle, $text);
frage($s, 'GetDiscoveryConfigs'); // alles in die Attribute geschrieben
// Ein nicht referenziertes Topic im Wert-Zwischenspeicher: pruneCaches filtert es heraus, schreibt also.
$werte = json_decode($s->attribut('MqttTopicPayloadCache'), true, 512, JSON_THROW_ON_ERROR);
$werte[FREMD] = ['topic' => FREMD, 'payload' => 'x', 'received_at' => 1];
$s->attributSetzen('MqttTopicPayloadCache', json_encode($werte, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

sperreHalten(bündelSperre($s));
DiscoverySplitterHarness::$attributZugriffe = [];
neueAusfuehrung($s)->ApplyChanges();
pruefe(cacheSchreibzugriffe() === 0, 'ApplyChanges schreibt die Zwischenspeicher nicht, solange ein anderer Thread bündelt',
    json_encode(array_filter(DiscoverySplitterHarness::$attributZugriffe, static fn(string $k): bool => str_starts_with($k, 'w:'), ARRAY_FILTER_USE_KEY)));
sperreFreigeben(bündelSperre($s));
neueAusfuehrung($s)->ApplyChanges();
$werte = json_decode($s->attribut('MqttTopicPayloadCache'), true, 512, JSON_THROW_ON_ERROR);
pruefe(!isset($werte[FREMD]), 'Ohne fremde Sperre räumt ApplyChanges weiter auf', implode(', ', array_keys($werte)));
pruefe(count($werte) === 7, 'Die sieben Werte bleiben dabei', (string)count($werte));

// --- Teil 2 (Befund 1): Dasselbe für „Veraltete entfernen“.
$s = neuerSplitter();
ladeBundle($s, $bundle, $text);
frage($s, 'GetDiscoveryConfigs');
// Ankündigungen aus einer früheren Sitzung: Der Knopf würde sie entfernen.
$configs = json_decode($s->attribut('MqttDiscoveryConfigCache'), true, 512, JSON_THROW_ON_ERROR);
foreach ($configs as $topic => $satz) {
    $configs[$topic]['session_id'] = 'fruehere-sitzung';
}
$s->attributSetzen('MqttDiscoveryConfigCache', json_encode($configs, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

sperreHalten(bündelSperre($s));
DiscoverySplitterHarness::$attributZugriffe = [];
ob_start();
neueAusfuehrung($s)->RemoveStaleDiscoveryConfigs();
$hinweis = (string)ob_get_clean();
pruefe(cacheSchreibzugriffe() === 0, '„Veraltete entfernen“ schreibt nicht, solange ein anderer Thread bündelt',
    json_encode(array_filter(DiscoverySplitterHarness::$attributZugriffe, static fn(string $k): bool => str_starts_with($k, 'w:'), ARRAY_FILTER_USE_KEY)));
pruefe($hinweis !== '', 'Der Knopf sagt dem Anwender, dass er es gleich noch einmal versuchen soll', $hinweis);
sperreFreigeben(bündelSperre($s));
ob_start();
neueAusfuehrung($s)->RemoveStaleDiscoveryConfigs();
ob_end_clean();
pruefe(frage($s, 'GetDiscoveryConfigs')['Count'] === 0, 'Ohne fremde Sperre entfernt der Knopf die vier veralteten Ankündigungen',
    (string)frage($s, 'GetDiscoveryConfigs')['Count']);

// --- Teil 3 (Befund 2): Bei vollem Vormerk-Puffer wartet ReceiveData nicht auf ein laufendes Bündeln.
$s = neuerSplitter();
sende($s, DIMMER_CONFIG, $text($dimmerConfig['payload']));
frage($s, 'GetDiscoveryConfigs');
sperreHalten(bündelSperre($s));
DiscoverySplitterHarness::$sperrAnfragen = [];
$lang = str_repeat('x', 2000);
for ($i = 1; $i <= 600; $i++) {
    sende($s, DIMMER, '{"value":' . $i . ',"fuell":"' . $lang . '"}');
}
$anfragen = array_values(array_filter(DiscoverySplitterHarness::$sperrAnfragen, static fn(array $a): bool => $a[0] === bündelSperre($s)));
pruefe($anfragen !== [], 'Voller Vormerk-Puffer: ReceiveData versucht zu bündeln', (string)count($anfragen));
$wartend = array_filter($anfragen, static fn(array $a): bool => $a[1] > 0);
pruefe($wartend === [], 'ReceiveData wartet dabei nicht auf die Bündelsperre', json_encode(array_slice(array_values($wartend), 0, 3)));
sperreFreigeben(bündelSperre($s));
pruefe(str_starts_with((string)(payloads($s, [DIMMER])[DIMMER] ?? ''), '{"value":600,'), 'Nach dem Bündeln gilt die letzte von 600 Meldungen',
    substr((string)(payloads($s, [DIMMER])[DIMMER] ?? 'fehlt'), 0, 20));

// --- Teil 4 (Befund 3): Eine Meldung, die wegen einer belegten Vormerk-Sperre nicht in den Zwischenspeicher
// kommt, verschwindet nicht still. Das Modul wartet deutlich länger als 1 s und meldet es dann als Warnung.
$s = neuerSplitter();
sende($s, DIMMER_CONFIG, $text($dimmerConfig['payload']));
frage($s, 'GetDiscoveryConfigs');
sperreHalten(vormerkSperre($s));
DiscoverySplitterHarness::$sperrAnfragen = [];
DiscoverySplitterHarness::$meldungen = [];
sende($s, DIMMER, '{"value":11}');
sperreFreigeben(vormerkSperre($s));
$anfragen = array_values(array_filter(DiscoverySplitterHarness::$sperrAnfragen, static fn(array $a): bool => $a[0] === vormerkSperre($s)));
pruefe($anfragen !== [] && min(array_column($anfragen, 1)) >= 10000, 'ReceiveData wartet mindestens 10 s auf die Vormerk-Sperre', json_encode($anfragen));
$warnungen = array_filter(DiscoverySplitterHarness::$meldungen, static fn(array $m): bool => $m[1] === KL_WARNING && str_contains($m[0], DIMMER));
pruefe(count($warnungen) === 1, 'Die verlorene Meldung steht als Warnung im Meldungsfenster, mit Topic', json_encode(DiscoverySplitterHarness::$meldungen, JSON_UNESCAPED_UNICODE));
// Die nächste Meldung kommt wieder an.
sende($s, DIMMER, '{"value":12}');
pruefe((payloads($s, [DIMMER])[DIMMER] ?? null) === '{"value":12}', 'Danach kommt die nächste Meldung wieder an', json_encode(payloads($s, [DIMMER])));

ergebnis();
