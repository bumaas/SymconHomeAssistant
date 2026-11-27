<?php

declare(strict_types=1);

/**
 * Prüft am ECHTEN MQTT Discovery Device über den Kernel-Stub, dass eine Meldung nur die Zeilen ihres Topics aus
 * einem Cache im Instanz-Buffer liest, statt alle Entitäten des Geräts neu aufzubauen.
 *
 * Anlass: Ein Anwender mit Z-Wave JS UI (72 Geräte, Raspberry Pi, Mitschnitt 27.11.2026). Unter der Rust-Edition
 * läuft jede Meldung in einer eigenen PHP-Ausführung, das Memo im Objekt hielt deshalb nie (`cached: false` bei
 * allen 75 Meldungen). Das Gerät las je Meldung seine Definition zweimal, dekodierte und normalisierte alle
 * Entitäten und baute den Topic-Index neu auf: 8 bis 14 ms bei 18 bis 32 Entitäten. Das klassische Gerät löst
 * dasselbe seit build 147 und 165 über einen Buffer mit Signatur und Zeilenzugriff; das Discovery-Gerät nutzt
 * jetzt dasselbe Format (libs/HAKeyedBlob.php).
 *
 * Gemessen wird Arbeit (Attribut-Lesezugriffe, Inhalt des Buffers), nicht Zeit.
 *
 * Fixtures: tests/fixtures/discovery_zwavejs_20261125.json (Z-Wave JS UI, ein Gerät, drei availability-Topics je
 * Entität) und tests/fixtures/discovery_ems_esp_20261116.json (EMS-ESP, drei Geräte; ein Topic trägt rund hundert
 * Entitäten).
 */

require_once __DIR__ . '/device-harness.php';

error_reporting(E_ALL);

const PUFFER = 'RuntimeContextCache';
const DIMMER = 'zwavejs2mqtt_0x00000001_node23';
const WERT_TOPIC = 'Z-Wave-JS/Raum/Bild/switch_multilevel/endpoint_0/currentValue';
const LEISTUNG_TOPIC = 'Z-Wave-JS/Raum/Bild/meter/endpoint_0/value/66049';
const KNOTEN = 'Z-Wave-JS/Raum/Bild/status';

$zwave = json_decode((string)file_get_contents(__DIR__ . '/fixtures/discovery_zwavejs_20261125.json'), true, 512, JSON_THROW_ON_ERROR);
$ems = json_decode((string)file_get_contents(__DIR__ . '/fixtures/discovery_ems_esp_20261116.json'), true, 512, JSON_THROW_ON_ERROR);
$text = static fn(mixed $p): string => is_string($p) ? $p : json_encode($p, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

DiscoveryDeviceHarness::$parentAktiv = true;
DiscoveryDeviceHarness::$topicPayloadAntwort = ['Items' => []];
$splitter = neueFensterInstanz(DiscoverySplitterHarness::class);

function antwort(array $ankuendigungen): array
{
    return ['Items' => $ankuendigungen, 'Count' => count($ankuendigungen), 'DiscoveryPrefix' => 'homeassistant', 'SessionStartedAt' => time() - 3600];
}

function geraet(array $ankuendigungen, string $geraeteId): DiscoveryDeviceHarness
{
    global $splitter;
    DiscoveryDeviceHarness::$splitterAntwort = antwort($ankuendigungen);
    $g = neueFensterInstanz(DiscoveryDeviceHarness::class);
    IPS_ConnectInstance($g->id(), $splitter->id());
    $g->SetProperty('DeviceID', $geraeteId);
    neueAusfuehrung($g)->ApplyChanges();
    return $g;
}

function melde(DiscoveryDeviceHarness $g, string $topic, string $payload): void
{
    neueAusfuehrung($g)->ReceiveData(mqttMeldung($topic, $payload));
}

/** Lesezugriffe auf die beiden großen Attribute während einer Meldung in einer frischen Ausführung. */
function lesungen(DiscoveryDeviceHarness $g, string $topic, string $payload): array
{
    DiscoveryDeviceHarness::$attributZugriffe = [];
    melde($g, $topic, $payload);
    return [
        DiscoveryDeviceHarness::$attributZugriffe['r:ResolvedDeviceDefinition'] ?? 0,
        DiscoveryDeviceHarness::$attributZugriffe['r:TopicProcessingIndex'] ?? 0,
    ];
}

/** @return array<string, mixed> Ident => Wert aller Variablen der Instanz */
function werte(DiscoveryDeviceHarness $g): array
{
    $werte = [];
    foreach (IPS_GetChildrenIDs($g->id()) as $id) {
        if (IPS_GetObject($id)['ObjectType'] === OBJECTTYPE_VARIABLE) {
            $werte[IPS_GetObject($id)['ObjectIdent']] = GetValue($id);
        }
    }
    ksort($werte);
    return $werte;
}

function wert(DiscoveryDeviceHarness $g, string $ident): mixed
{
    return werte($g)[$ident] ?? null;
}

/** Der Ausschnitt für ein Topic, wie ihn der volle Aufbau liefert: betroffene Entitäten und Index-Einträge. */
function ausschnittVoll(DiscoveryDeviceHarness $g, string $topic): array
{
    $frisch = neueAusfuehrung($g);
    $entities = $frisch->rufe('getConfiguredEntities');
    $lookup = $frisch->rufe('buildEntityLookup', $entities);
    $index = $frisch->rufe('readTopicProcessingIndex');
    $ausschnitt = ['lookup' => [], 'index' => []];
    foreach (['warnings', 'state', 'attributes', 'availability', 'climate', 'light'] as $art) {
        $keys = $index[$art][$topic] ?? [];
        if ($keys === []) {
            continue;
        }
        $ausschnitt['index'][$art] = $keys;
        foreach ($keys as $key) {
            if (isset($lookup[$key])) {
                $ausschnitt['lookup'][$key] = $lookup[$key];
            }
        }
    }
    ksort($ausschnitt['lookup']);
    return $ausschnitt;
}

function ausschnittCache(DiscoveryDeviceHarness $g, string $topic): ?array
{
    $frisch = neueAusfuehrung($g);
    if (!method_exists($frisch, 'getTopicProcessingSlice')) {
        return null;
    }
    $slice = $frisch->rufe('getTopicProcessingSlice', $topic);
    $ausschnitt = ['lookup' => $slice['lookup'], 'index' => []];
    foreach (['warnings', 'state', 'attributes', 'availability', 'climate', 'light'] as $art) {
        if (($slice['topicIndex'][$art][$topic] ?? []) !== []) {
            $ausschnitt['index'][$art] = $slice['topicIndex'][$art][$topic];
        }
    }
    ksort($ausschnitt['lookup']);
    return $ausschnitt;
}

// --- Teil 1: Eine Meldung liest die Definition einmal und füllt den Buffer; die nächste kommt ohne Neuaufbau aus.
$dimmer = geraet($zwave['discovery_configs'], DIMMER);
pruefe(count(IPS_GetChildrenIDs($dimmer->id())) > 0, 'Fixture: Der Dimmer hat Variablen', (string)count(IPS_GetChildrenIDs($dimmer->id())));
melde($dimmer, WERT_TOPIC, '{"time":1795700000000,"value":40}');
$blob = $dimmer->puffer(PUFFER);
pruefe($blob !== '', 'Nach der ersten Meldung liegt der Verarbeitungskontext im Buffer', strlen($blob) . ' Bytes');
pruefe(class_exists('HAKeyedBlob') && is_array(HAKeyedBlob::header($blob)) && isset(HAKeyedBlob::header($blob)['sig']),
    'Der Buffer trägt das gemeinsame Format mit Signatur im Kopf', substr($blob, 0, 80));

[$definition, $index] = lesungen($dimmer, WERT_TOPIC, '{"time":1795700001000,"value":55}');
pruefe($definition === 1, 'Meldung aus dem Cache: Definition einmal gelesen (für die Signatur)', (string)$definition);
pruefe($index === 1, 'Meldung aus dem Cache: Topic-Index einmal gelesen (Merkmal des Reload-Fensters)', (string)$index);
pruefe(wert($dimmer, 'light_dimmer_brightness') === 142, 'Die Meldung aus dem Cache kommt in der Variable an (55 von 99 ergibt 142 von 255)', json_encode(werte($dimmer)));

// Ein Topic, das das Gerät nicht kennt, kostet keinen Neuaufbau und ändert nichts.
$vorher = werte($dimmer);
[$definition] = lesungen($dimmer, 'Z-Wave-JS/Raum/Bild/lastActive', '{"time":1795700002000,"value":1795700002000}');
pruefe($definition <= 1 && werte($dimmer) === $vorher, 'Unbekanntes Topic: kein Neuaufbau, keine Änderung', (string)$definition);

// --- Teil 2: Der Ausschnitt aus dem Cache gleicht dem aus dem vollen Aufbau, für jedes Topic jedes Geräts.
$grouping = new HAMqttDiscoveryGrouping();
$parser = new HAMqttDiscoveryParser();
$geraete = [];
foreach ([$zwave, $ems] as $bundle) {
    $saetze = array_map(static fn(array $s): array => ['topic' => $s['topic'], 'payload' => $text($s['payload'])], $bundle['discovery_configs']);
    foreach ($grouping->groupEntitiesToDevices($parser->parseConfigMessages($saetze)) as $gruppe) {
        if (!str_starts_with($gruppe['device_id'], 'entity:')) {
            $geraete[$gruppe['device_id']] = $bundle;
        }
    }
}
pruefe(count($geraete) === 4, 'Fixtures: vier Geräte', implode(', ', array_keys($geraete)));

$topicsGesamt = 0;
$entitaetenGesamt = 0;
$abweichungen = [];
$instanzen = [];
foreach ($geraete as $geraeteId => $bundle) {
    $g = geraet($bundle['discovery_configs'], $geraeteId);
    $instanzen[$geraeteId] = $g;
    $index = neueAusfuehrung($g)->rufe('readTopicProcessingIndex');
    $topics = [];
    foreach (['warnings', 'state', 'attributes', 'availability', 'climate', 'light'] as $art) {
        $topics += array_fill_keys(array_keys($index[$art]), true);
    }
    foreach (array_keys($topics) as $topic) {
        $topic = (string)$topic;
        $voll = ausschnittVoll($g, $topic);
        $cache = ausschnittCache($g, $topic);
        $topicsGesamt++;
        $entitaetenGesamt += count($voll['lookup']);
        if ($cache !== $voll) {
            $abweichungen[] = $geraeteId . ' ' . $topic;
        }
    }
}
pruefe($topicsGesamt >= 15 && $entitaetenGesamt > 300, 'Fixtures: genug Topics und Entitäten für den Vergleich', "$topicsGesamt Topics, $entitaetenGesamt Entitäten");
pruefe($abweichungen === [], 'Ausschnitt aus dem Cache gleicht dem aus dem vollen Aufbau (Typen eingeschlossen)',
    count($abweichungen) . ' Abweichungen, z. B. ' . implode(' | ', array_slice($abweichungen, 0, 3)));

// --- Teil 3: Mit und ohne Cache dasselbe Ergebnis in den Variablen (echte Meldungen des Kessels und des Dimmers).
$payloads = [];
foreach (array_merge($ems['topic_payloads'], $zwave['topic_payloads']) as $satz) {
    $payloads[$satz['topic']] = $text($satz['payload']);
}
foreach (['ems-esp-boiler' => $ems, DIMMER => $zwave] as $geraeteId => $bundle) {
    $mitCache = geraet($bundle['discovery_configs'], $geraeteId);
    $ohneCache = geraet($bundle['discovery_configs'], $geraeteId);
    $gesendet = 0;
    foreach ([1, 2] as $runde) {
        foreach ($bundle['topic_payloads'] as $satz) {
            melde($mitCache, $satz['topic'], $payloads[$satz['topic']]);
            $ohneCache->pufferVerwerfen(); // wie nach einem Kernel-Neustart: jede Meldung baut neu auf
            melde($ohneCache, $satz['topic'], $payloads[$satz['topic']]);
            $gesendet++;
        }
    }
    if ($geraeteId === DIMMER) {
        foreach ([[WERT_TOPIC, '{"time":1795700000000,"value":40}'], [LEISTUNG_TOPIC, '{"time":1795700000000,"value":12.5}']] as [$topic, $payload]) {
            melde($mitCache, $topic, $payload);
            $ohneCache->pufferVerwerfen();
            melde($ohneCache, $topic, $payload);
            $gesendet++;
        }
    }
    $a = werte($mitCache);
    $b = werte($ohneCache);
    $belegt = count(array_filter($a, static fn(mixed $w): bool => $w !== 0 && $w !== 0.0 && $w !== '' && $w !== false));
    pruefe($a === $b && $belegt > 2, "$geraeteId: $gesendet Meldungen ergeben mit und ohne Cache dieselben Werte", count($a) . ' Variablen, ' . $belegt . ' belegt');
}

// --- Teil 4: Der Cache folgt der Konfiguration.
// 4a: Geänderte Ankündigung (anderes State-Topic für die Leistung): nach dem Übernehmen gilt das neue Topic.
$dimmer = geraet($zwave['discovery_configs'], DIMMER);
melde($dimmer, LEISTUNG_TOPIC, '{"time":1795700000000,"value":12.5}');
$leistungIdent = array_key_first(array_filter(werte($dimmer), static fn(mixed $w): bool => $w === 12.5));
pruefe($leistungIdent !== null, 'Leistung kommt über das angekündigte Topic an', json_encode(werte($dimmer)));
$geaendert = $zwave['discovery_configs'];
foreach ($geaendert as &$satz) {
    $satz['payload'] = str_replace('meter/endpoint_0/value/66049', 'meter/endpoint_0/value/99999', $text($satz['payload']));
}
unset($satz);
DiscoveryDeviceHarness::$splitterAntwort = antwort($geaendert);
neueAusfuehrung($dimmer)->ApplyChanges();
melde($dimmer, LEISTUNG_TOPIC, '{"time":1795700003000,"value":77.5}');
pruefe(wert($dimmer, (string)$leistungIdent) === 12.5, 'Nach geänderter Ankündigung: das alte Topic schreibt nicht mehr', var_export(wert($dimmer, (string)$leistungIdent), true));
melde($dimmer, 'Z-Wave-JS/Raum/Bild/meter/endpoint_0/value/99999', '{"time":1795700004000,"value":88.5}');
pruefe(wert($dimmer, (string)$leistungIdent) === 88.5, 'Nach geänderter Ankündigung: das neue Topic schreibt', var_export(wert($dimmer, (string)$leistungIdent), true));

// 4b: Ein Blob mit fremder Signatur (anderer Stand der Definition) wird nicht benutzt, sondern ersetzt.
$fremd = "{\"sig\":\"veraltet\",\"baseNameCounts\":[]}\n\"t:" . WERT_TOPIC . "\"\t{\"state\":[\"gibt-es-nicht\"]}";
$dimmer->rufe('SetBuffer', PUFFER, $fremd);
melde($dimmer, WERT_TOPIC, '{"time":1795700005000,"value":70}');
pruefe(wert($dimmer, 'light_dimmer_brightness') === 180, 'Blob mit fremder Signatur: Meldung wird trotzdem richtig verarbeitet (70 von 99 ergibt 180)', json_encode(werte($dimmer)));
pruefe($dimmer->puffer(PUFFER) !== $fremd && $dimmer->puffer(PUFFER) !== '', 'Blob mit fremder Signatur: durch den aktuellen Stand ersetzt', substr($dimmer->puffer(PUFFER), 0, 60));

// 4c: Beschädigte Zeile im sonst gültigen Blob: voller Aufbau statt falscher Verarbeitung.
$gueltig = $dimmer->puffer(PUFFER);
$kaputt = preg_replace('/^("e:[^\t]*")\t.*$/m', "\$1\tkein json", $gueltig) ?? '';
pruefe($kaputt !== $gueltig && $kaputt !== '', 'Fixture: Entitätszeilen im Blob beschädigt', (string)strlen($kaputt));
$dimmer->rufe('SetBuffer', PUFFER, $kaputt);
melde($dimmer, WERT_TOPIC, '{"time":1795700006000,"value":20}');
pruefe(wert($dimmer, 'light_dimmer_brightness') === 52, 'Beschädigte Zeile: Meldung wird über den vollen Aufbau verarbeitet (20 von 99 ergibt 52)', json_encode(werte($dimmer)));

// --- Teil 5: Erreichbarkeit braucht alle Entitäten und funktioniert mit gefülltem Cache weiter.
$g = geraet($zwave['discovery_configs'], DIMMER);
melde($g, 'Z-Wave-JS/driver/status', $payloads['Z-Wave-JS/driver/status']);
melde($g, 'Z-Wave-JS/_CLIENTS/ZWAVE_GATEWAY-zwave-js-ui/status', $payloads['Z-Wave-JS/_CLIENTS/ZWAVE_GATEWAY-zwave-js-ui/status']);
melde($g, KNOTEN, $payloads[KNOTEN]);
pruefe(wert($g, 'reachable') === true, 'Mit Cache: nach den drei echten Status-Meldungen erreichbar', var_export(wert($g, 'reachable'), true));
$aus = json_decode($payloads[KNOTEN], true, 512, JSON_THROW_ON_ERROR);
$aus['value'] = false;
$aus['status'] = 'Dead';
melde($g, KNOTEN, json_encode($aus, JSON_THROW_ON_ERROR));
pruefe(wert($g, 'reachable') === false, 'Mit Cache: Knoten meldet value false, nicht erreichbar', var_export(wert($g, 'reachable'), true));

// --- Teil 6: Im Reload-Fenster fasst die Meldung den Buffer nicht an.
$g = geraet($zwave['discovery_configs'], DIMMER);
$g->attributeRegistriert = false;
$pufferVorher = DiscoveryDeviceHarness::$pufferLesungen;
melde($g, WERT_TOPIC, '{"time":1795700007000,"value":33}');
pruefe(DiscoveryDeviceHarness::$pufferLesungen === $pufferVorher, 'Reload-Fenster: kein Buffer-Zugriff', (string)(DiscoveryDeviceHarness::$pufferLesungen - $pufferVorher));
$g->attributeRegistriert = true;

DiscoveryDeviceHarness::$splitterAntwort = null;
DiscoveryDeviceHarness::$topicPayloadAntwort = null;
DiscoveryDeviceHarness::$parentAktiv = false;
ergebnis();
