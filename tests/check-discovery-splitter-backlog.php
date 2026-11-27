<?php

declare(strict_types=1);

/**
 * Prüft am ECHTEN Modul (Home Assistant MQTT Discovery Splitter) über den Kernel-Stub, dass eine MQTT-Meldung
 * im heißen Pfad die großen Zwischenspeicher nicht anfasst.
 *
 * Anlass: ein Anwender (26.11.2026). Z-Wave JS UI kündigt bei ihm 2384 Einträge an. Der Splitter las
 * und schrieb bei jeder Meldung die kompletten Attribute MqttDiscoveryConfigCache und MqttTopicPayloadCache
 * (mehrere MB JSON) und baute bei laufendem Verkehr jede Sekunde die Diagnose neu, auch ohne offenes Formular.
 * Sein Bundle zeigt den Rückstau: Status-Meldungen mit Sendezeit 10:28 kamen zwischen 12:02 und 17:12 an, am
 * Abend lag der Verzug bei 435 Minuten.
 *
 * Erwartet: Meldungen werden in Buffern vorgemerkt. In die Attribute kommen sie gebündelt, per Timer, bei
 * vollem Vormerk-Puffer oder sobald jemand die Zwischenspeicher liest. Wer liest, sieht dabei jede schon
 * empfangene Meldung.
 *
 * Fixture: tests/fixtures/discovery_zwavejs_20261125.json (Bundle eines Anwenders, neutralisiert, Gerät „Raum-Bild“).
 */

require_once __DIR__ . '/device-harness.php';

error_reporting(E_ALL);

$bundle = json_decode((string)file_get_contents(__DIR__ . '/fixtures/discovery_zwavejs_20261125.json'), true, 512, JSON_THROW_ON_ERROR);

const CACHE_ATTRIBUTE = ['MqttDiscoveryConfigCache', 'MqttTopicPayloadCache', 'MqttReferencedTopicLookup'];
const DIMMER = 'Z-Wave-JS/Raum/Bild/switch_multilevel/endpoint_0/currentValue';
const DIMMER_CONFIG = 'homeassistant/light/Raum-Bild/dimmer/config';

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

/** Anfrage eines Kindes (Device oder Konfigurator) an den Splitter. */
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

function cacheZugriffe(): int
{
    $summe = 0;
    foreach (CACHE_ATTRIBUTE as $name) {
        $summe += (DiscoverySplitterHarness::$attributZugriffe['r:' . $name] ?? 0) + (DiscoverySplitterHarness::$attributZugriffe['w:' . $name] ?? 0);
    }
    return $summe;
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

$wertTopics = array_column($bundle['topic_payloads'], 'topic');

// --- Teil 1: Wer die Zwischenspeicher liest, sieht alle empfangenen Meldungen.
$s = neuerSplitter();
ladeBundle($s, $bundle, $text);
$configs = frage($s, 'GetDiscoveryConfigs');
pruefe($configs['Count'] === 4, 'Alle vier Ankündigungen beim Lesen sichtbar', (string)$configs['Count']);
$werte = payloads($s, $wertTopics);
pruefe(count($werte) === 7, 'Alle sieben Wert-Meldungen beim Lesen sichtbar', (string)count($werte));
$dimmerSatz = array_find($bundle['topic_payloads'], static fn(array $p): bool => $p['topic'] === DIMMER);
pruefe(($werte[DIMMER] ?? null) === $text($dimmerSatz['payload']), 'Payload des Dimmers unverändert', (string)($werte[DIMMER] ?? 'fehlt'));

// --- Teil 2: Eine Meldung im heißen Pfad fasst die großen Zwischenspeicher nicht an.
DiscoverySplitterHarness::$attributZugriffe = [];
sende($s, DIMMER, '{"time":1795700000000,"value":42}');
pruefe(cacheZugriffe() === 0, 'Wert-Meldung: kein Zugriff auf die Cache-Attribute', json_encode(DiscoverySplitterHarness::$attributZugriffe));

DiscoverySplitterHarness::$attributZugriffe = [];
$dimmerConfig = array_find($bundle['discovery_configs'], static fn(array $c): bool => $c['topic'] === DIMMER_CONFIG);
$geaendert = json_decode($text($dimmerConfig['payload']), true, 512, JSON_THROW_ON_ERROR);
$geaendert['name'] = 'Dimmer neu';
sende($s, DIMMER_CONFIG, json_encode($geaendert, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
pruefe(cacheZugriffe() === 0, 'Ankündigung: kein Zugriff auf die Cache-Attribute', json_encode(DiscoverySplitterHarness::$attributZugriffe));

DiscoverySplitterHarness::$attributZugriffe = [];
sende($s, 'fremd/irgendein/topic', 'x');
pruefe(cacheZugriffe() === 0, 'Fremdes Topic: kein Zugriff auf die Cache-Attribute', json_encode(DiscoverySplitterHarness::$attributZugriffe));

// Beim Lesen sind beide Meldungen da.
pruefe((payloads($s, [DIMMER])[DIMMER] ?? null) === '{"time":1795700000000,"value":42}', 'Neuer Wert beim Lesen sichtbar', json_encode(payloads($s, [DIMMER])));
$satz = array_find(frage($s, 'GetDiscoveryConfigs')['Items'], static fn(array $c): bool => $c['topic'] === DIMMER_CONFIG);
pruefe(str_contains((string)($satz['payload'] ?? ''), 'Dimmer neu'), 'Geänderte Ankündigung beim Lesen sichtbar', (string)($satz['payload'] ?? 'fehlt'));
pruefe(payloads($s, ['fremd/irgendein/topic']) === [], 'Fremdes Topic wird nicht gespeichert', json_encode(payloads($s, ['fremd/irgendein/topic'])));

// --- Teil 3: Reihenfolge innerhalb eines Bündels wie bisher je Meldung.
$s = neuerSplitter();
// Wert vor der Ankündigung: das Topic ist da noch nicht referenziert, der Wert wird verworfen.
sende($s, DIMMER, '{"value":1}');
sende($s, DIMMER_CONFIG, $text($dimmerConfig['payload']));
pruefe(payloads($s, [DIMMER]) === [], 'Wert vor seiner Ankündigung wird nicht gespeichert', json_encode(payloads($s, [DIMMER])));
// Wert nach der Ankündigung, noch im selben Bündel: wird gespeichert.
$s = neuerSplitter();
sende($s, DIMMER_CONFIG, $text($dimmerConfig['payload']));
sende($s, DIMMER, '{"value":2}');
sende($s, DIMMER, '{"value":3}');
pruefe((payloads($s, [DIMMER])[DIMMER] ?? null) === '{"value":3}', 'Wert nach der Ankündigung im selben Bündel gespeichert, letzter gewinnt', json_encode(payloads($s, [DIMMER])));
// Leere Ankündigung entfernt Gerät und Wert.
sende($s, DIMMER_CONFIG, '');
sende($s, DIMMER, '{"value":4}');
pruefe(frage($s, 'GetDiscoveryConfigs')['Count'] === 0, 'Leere Ankündigung entfernt den Eintrag', (string)frage($s, 'GetDiscoveryConfigs')['Count']);
pruefe(payloads($s, [DIMMER]) === [], 'Wert eines entfernten Eintrags ist weg', json_encode(payloads($s, [DIMMER])));

// --- Teil 4: Der Timer schreibt die vorgemerkten Meldungen in die Attribute.
$s = neuerSplitter();
ladeBundle($s, $bundle, $text);
pruefe($s->timer('DiagnosticsRefresh') > 0, 'Nach einer Meldung läuft der Timer', (string)$s->timer('DiagnosticsRefresh'));
neueAusfuehrung($s)->RefreshDiscoveryDiagnostics();
pruefe($s->timer('DiagnosticsRefresh') === 0, 'Timer nach dem Lauf aus', (string)$s->timer('DiagnosticsRefresh'));
$attribut = json_decode($s->attribut('MqttDiscoveryConfigCache'), true, 512, JSON_THROW_ON_ERROR);
pruefe(count($attribut) === 4, 'Attribut enthält nach dem Timerlauf alle Ankündigungen', (string)count($attribut));
$attribut = json_decode($s->attribut('MqttTopicPayloadCache'), true, 512, JSON_THROW_ON_ERROR);
pruefe(count($attribut) === 7, 'Attribut enthält nach dem Timerlauf alle Werte', (string)count($attribut));
// Danach ist nichts mehr vorgemerkt: Ein weiterer Lauf schreibt nichts.
DiscoverySplitterHarness::$attributZugriffe = [];
neueAusfuehrung($s)->RefreshDiscoveryDiagnostics();
$schreibzugriffe = array_sum(array_filter(DiscoverySplitterHarness::$attributZugriffe, static fn(string $k): bool => str_starts_with($k, 'w:Mqtt'), ARRAY_FILTER_USE_KEY));
pruefe($schreibzugriffe === 0, 'Zweiter Timerlauf ohne neue Meldung schreibt nichts', json_encode(DiscoverySplitterHarness::$attributZugriffe));

// --- Teil 5: Die Diagnose wird nur für ein kürzlich geöffnetes Formular neu gebaut.
$s = neuerSplitter();
ladeBundle($s, $bundle, $text);
DiscoverySplitterHarness::$formularFelder = [];
neueAusfuehrung($s)->RefreshDiscoveryDiagnostics();
pruefe(DiscoverySplitterHarness::$formularFelder === [], 'Ohne geöffnetes Formular keine Diagnose je Timerlauf', json_encode(array_keys(DiscoverySplitterHarness::$formularFelder)));
neueAusfuehrung($s)->GetConfigurationForm();
neueAusfuehrung($s)->RefreshDiscoveryDiagnostics();
pruefe(str_contains((string)(DiscoverySplitterHarness::$formularFelder['DiagDiscovery.caption'] ?? ''), '4/'), 'Nach dem Öffnen zeigt die Diagnose vier Ankündigungen',
    (string)(DiscoverySplitterHarness::$formularFelder['DiagDiscovery.caption'] ?? 'fehlt'));
// Solange das Formular kürzlich geöffnet wurde, aktualisiert eine neue Meldung die Diagnose weiter.
DiscoverySplitterHarness::$formularFelder = [];
sende($s, DIMMER, '{"value":5}');
neueAusfuehrung($s)->RefreshDiscoveryDiagnostics();
pruefe(isset(DiscoverySplitterHarness::$formularFelder['DiagDiscovery.caption']), 'Offenes Formular: Meldung aktualisiert die Diagnose', json_encode(array_keys(DiscoverySplitterHarness::$formularFelder)));

// --- Teil 6: Der Vormerk-Puffer bleibt begrenzt, auch wenn niemand liest und kein Timer läuft.
$s = neuerSplitter();
sende($s, DIMMER_CONFIG, $text($dimmerConfig['payload']));
$lang = str_repeat('x', 2000);
for ($i = 1; $i <= 600; $i++) {
    sende($s, DIMMER, '{"value":' . $i . ',"fuell":"' . $lang . '"}');
}
$vorgemerkt = 0;
$puffer = (new ReflectionProperty(IPS\InstanceManager::class, 'interfaces'))->getValue()[$s->id()];
foreach ((new ReflectionClass(HomeAssistantMQTTDiscoverySplitter::class))->getConstants() as $name => $wert) {
    if (str_starts_with($name, 'BUFFER_PENDING') && is_string($wert)) {
        for ($n = 0; $n < 200; $n++) {
            $vorgemerkt += strlen($puffer->puffer($wert . $n)) + ($n === 0 ? strlen($puffer->puffer($wert)) : 0);
        }
    }
}
pruefe($vorgemerkt < 1048576, 'Vorgemerkte Meldungen bleiben unter 1 MB', (string)$vorgemerkt);
pruefe(str_starts_with((string)(payloads($s, [DIMMER])[DIMMER] ?? ''), '{"value":600,'), 'Nach 600 Meldungen gilt die letzte', substr((string)(payloads($s, [DIMMER])[DIMMER] ?? 'fehlt'), 0, 20));

// --- Teil 7: Kaputter oder verlorener Vormerk-Puffer stört nicht.
$s = neuerSplitter();
ladeBundle($s, $bundle, $text);
frage($s, 'GetDiscoveryConfigs'); // alles geschrieben
sende($s, DIMMER, '{"value":77}');
$s->pufferVerwerfen(); // Kernel-Neustart: Buffer weg
pruefe(frage($s, 'GetDiscoveryConfigs')['Count'] === 4, 'Nach Verlust der Buffer bleiben die geschriebenen Ankündigungen', (string)frage($s, 'GetDiscoveryConfigs')['Count']);
sende($s, DIMMER, '{"value":78}');
pruefe((payloads($s, [DIMMER])[DIMMER] ?? null) === '{"value":78}', 'Danach kommen neue Meldungen wieder an', json_encode(payloads($s, [DIMMER])));

// --- Teil 8: Der Export enthält auch die vorgemerkten Meldungen.
$s = neuerSplitter();
ladeBundle($s, $bundle, $text);
$export = json_decode(neueAusfuehrung($s)->ExportDiscoveryBundle(true, false, false), true, 512, JSON_THROW_ON_ERROR);
pruefe(count($export['discovery_configs']) === 4 && count($export['topic_payloads']) === 7, 'Export enthält 4 Ankündigungen und 7 Werte',
    count($export['discovery_configs']) . '/' . count($export['topic_payloads']));

ergebnis();
