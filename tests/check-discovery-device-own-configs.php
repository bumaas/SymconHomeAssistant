<?php

declare(strict_types=1);

/**
 * Prüft an den ECHTEN Modulen (MQTT Discovery Splitter, Device, Konfigurator) über den Kernel-Stub die erste
 * Etappe aus docs/KONZEPT_DISCOVERY_SPLITTER.md: Jeder bekommt nur, was er braucht.
 *
 * Anlass: Ein Anwender mit Z-Wave JS UI (72 Geräte, 2384 Ankündigungen, 26.11.2026). Jedes Gerät
 * holte beim Übernehmen alle Ankündigungen vom Splitter und wertete alle aus, um die eigenen zu finden. Mit
 * seinem Bundle kostete das im Test 404 ms je Gerät, mit nur den 28 eigenen 10 ms. Dazu:
 * - Das Gerät schrieb bei jeder Meldung das Attribut LastMQTTMessage.
 * - Der Konfigurator hängt am Datenfluss der Geräte, setzte aber keinen Empfangsfilter.
 *
 * Fixtures: tests/fixtures/discovery_ems_esp_20261116.json (EMS-ESP, 149 Ankündigungen für drei Geräte) und
 * tests/fixtures/discovery_zwavejs_20261125.json (Z-Wave JS UI, ein Gerät).
 */

require_once __DIR__ . '/device-harness.php';

error_reporting(E_ALL);

$ems = json_decode((string)file_get_contents(__DIR__ . '/fixtures/discovery_ems_esp_20261116.json'), true, 512, JSON_THROW_ON_ERROR);
$zwave = json_decode((string)file_get_contents(__DIR__ . '/fixtures/discovery_zwavejs_20261125.json'), true, 512, JSON_THROW_ON_ERROR);
$alle = array_merge($ems['discovery_configs'], $zwave['discovery_configs']);
$text = static fn(mixed $p): string => is_string($p) ? $p : json_encode($p, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

const BOILER = 'ems-esp-boiler';
const DIMMER = 'zwavejs2mqtt_0x00000001_node23';

$parser = new HAMqttDiscoveryParser();
$grouping = new HAMqttDiscoveryGrouping();

// --- Teil 1: Die Geräte-ID einer Ankündigung ergibt sich aus ihr allein, so wie die Gruppierung sie bildet.
$abweichungen = [];
$jeGeraet = [];
foreach ($alle as $satz) {
    $payload = $text($satz['payload']);
    $ausDemSatz = method_exists($parser, 'resolveRecordDeviceId') ? $parser->resolveRecordDeviceId($payload) : null;
    foreach ($grouping->groupEntitiesToDevices($parser->parseConfigMessages([['topic' => $satz['topic'], 'payload' => $payload]])) as $gruppe) {
        if (str_starts_with($gruppe['device_id'], 'entity:')) {
            continue;
        }
        $jeGeraet[$gruppe['device_id']] = ($jeGeraet[$gruppe['device_id']] ?? 0) + 1;
        if ($gruppe['device_id'] !== $ausDemSatz) {
            $abweichungen[] = $satz['topic'] . ': ' . $gruppe['device_id'] . ' ≠ ' . var_export($ausDemSatz, true);
        }
    }
}
pruefe(count($jeGeraet) === 4, 'Fixtures: vier Geräte', implode(', ', array_keys($jeGeraet)));
pruefe($abweichungen === [], 'Geräte-ID aus der einzelnen Ankündigung stimmt mit der Gruppierung überein', implode(' | ', array_slice($abweichungen, 0, 3)));

// --- Teil 2: Der Splitter liefert auf Anfrage nur die Ankündigungen eines Geräts.
$splitter = neueFensterInstanz(DiscoverySplitterHarness::class);
neueAusfuehrung($splitter)->ApplyChanges();
foreach ($alle as $satz) {
    neueAusfuehrung($splitter)->ReceiveData(json_encode([
        'DataID'           => HAIds::DATA_MQTT_RX,
        'Topic'            => $satz['topic'],
        'Payload'          => bin2hex($text($satz['payload'])),
        'Retain'           => false,
        'QualityOfService' => 0,
    ], JSON_THROW_ON_ERROR));
}

function frage(DiscoverySplitterHarness $s, array $zusatz = []): array
{
    $antwort = neueAusfuehrung($s)->ForwardData(json_encode([
        'DataID'          => HAIds::DATA_MQTT_DISCOVERY_DEVICE_TO_SPLITTER,
        'DiscoveryAction' => 'GetDiscoveryConfigs',
    ] + $zusatz, JSON_THROW_ON_ERROR));
    return json_decode($antwort, true, 512, JSON_THROW_ON_ERROR);
}

$gesamt = frage($splitter);
pruefe($gesamt['Count'] === count($alle), 'Ohne Geräte-ID liefert der Splitter alle Ankündigungen (Konfigurator)', $gesamt['Count'] . ' von ' . count($alle));
$nurDimmer = frage($splitter, ['DeviceID' => DIMMER]);
pruefe($nurDimmer['Count'] === 4, 'Mit Geräte-ID des Dimmers: seine vier Ankündigungen', (string)$nurDimmer['Count']);
$nurBoiler = frage($splitter, ['DeviceID' => BOILER]);
pruefe($nurBoiler['Count'] === $jeGeraet[BOILER], 'Mit Geräte-ID des Kessels: nur dessen Ankündigungen', $nurBoiler['Count'] . ' statt ' . $jeGeraet[BOILER]);
pruefe(isset($nurBoiler['SessionStartedAt'], $nurBoiler['DiscoveryPrefix']), 'Die gefilterte Antwort trägt Sitzungsbeginn und Präfix wie bisher', json_encode(array_keys($nurBoiler)));
pruefe(frage($splitter, ['DeviceID' => 'gibt-es-nicht'])['Count'] === 0, 'Unbekannte Geräte-ID: leere Liste', (string)frage($splitter, ['DeviceID' => 'gibt-es-nicht'])['Count']);

// --- Teil 3: Das Gerät fragt mit seiner Geräte-ID und kommt mit der gefilterten Antwort zur selben Definition.
DiscoveryDeviceHarness::$parentAktiv = true;
DiscoveryDeviceHarness::$topicPayloadAntwort = ['Items' => []];

function definitionMit(DiscoverySplitterHarness $splitter, array $items, string $geraeteId): array
{
    DiscoveryDeviceHarness::$splitterAntwort = ['Items' => $items, 'Count' => count($items), 'DiscoveryPrefix' => 'homeassistant', 'SessionStartedAt' => time() - 3600];
    $g = neueFensterInstanz(DiscoveryDeviceHarness::class);
    IPS_ConnectInstance($g->id(), $splitter->id());
    $g->SetProperty('DeviceID', $geraeteId);
    DiscoveryDeviceHarness::$anfragen = [];
    neueAusfuehrung($g)->ApplyChanges();
    return [json_decode($g->attribut('ResolvedDeviceDefinition'), true, 512, JSON_THROW_ON_ERROR), count(IPS_GetChildrenIDs($g->id())), $g];
}

[$ausAllen, $variablenAlle] = definitionMit($splitter, $gesamt['Items'], BOILER);
$anfrage = array_values(array_filter(DiscoveryDeviceHarness::$anfragen, static fn(array $a): bool => $a['DiscoveryAction'] === 'GetDiscoveryConfigs'))[0] ?? [];
pruefe(($anfrage['DeviceID'] ?? null) === BOILER, 'Das Gerät nennt in seiner Anfrage die eigene Geräte-ID', json_encode($anfrage));
[$ausEigenen, $variablenEigene] = definitionMit($splitter, $nurBoiler['Items'], BOILER);
pruefe(count($ausEigenen['entities'] ?? []) > 0 && $ausEigenen === $ausAllen, 'Definition aus den eigenen Ankündigungen gleicht der aus allen',
    count($ausEigenen['entities'] ?? []) . ' gegen ' . count($ausAllen['entities'] ?? []) . ' Entitäten');
pruefe($variablenEigene === $variablenAlle && $variablenEigene > 0, 'Gleich viele Variablen', "$variablenEigene gegen $variablenAlle");

// Ein Gerät, das der Splitter nicht kennt, gilt weiter als nicht angekündigt (Status 202 nach der Frist).
[, , $fremd] = definitionMit($splitter, [], 'gibt-es-nicht');
pruefe($fremd->status() === 202, 'Unbekanntes Gerät steht nach der Frist auf „nicht angekündigt“ (202)', (string)$fremd->status());

// --- Teil 4: Das Gerät schreibt LastMQTTMessage nicht bei jeder Meldung.
[, , $dimmer] = definitionMit($splitter, $nurDimmer['Items'], DIMMER);
$wertTopic = 'Z-Wave-JS/Raum/Bild/switch_multilevel/endpoint_0/currentValue';
DiscoveryDeviceHarness::$attributZugriffe = [];
for ($i = 1; $i <= 5; $i++) {
    neueAusfuehrung($dimmer)->ReceiveData(mqttMeldung($wertTopic, '{"time":1795700000000,"value":' . (10 * $i) . '}'));
}
$geschrieben = DiscoveryDeviceHarness::$attributZugriffe['w:LastMQTTMessage'] ?? 0;
pruefe($geschrieben === 1, 'Fünf Meldungen in Folge: LastMQTTMessage einmal geschrieben', (string)$geschrieben);
pruefe($dimmer->attribut('LastMQTTMessage') !== '', 'LastMQTTMessage trägt einen Zeitpunkt', $dimmer->attribut('LastMQTTMessage'));

// --- Teil 5: Der Konfigurator nimmt keine Meldungen aus dem Datenfluss an.
DiscoveryConfiguratorHarness::$parentAktiv = true;
$konfigurator = neueFensterInstanz(DiscoveryConfiguratorHarness::class);
IPS_ConnectInstance($konfigurator->id(), $splitter->id());
neueAusfuehrung($konfigurator)->ApplyChanges();
pruefe($konfigurator->GetReceiveDataFilter() === '^$', 'Konfigurator: Empfangsfilter lässt nichts durch', var_export($konfigurator->GetReceiveDataFilter(), true));

DiscoveryDeviceHarness::$splitterAntwort = null;
DiscoveryDeviceHarness::$topicPayloadAntwort = null;
DiscoveryDeviceHarness::$parentAktiv = false;
DiscoveryConfiguratorHarness::$parentAktiv = false;
ergebnis();
