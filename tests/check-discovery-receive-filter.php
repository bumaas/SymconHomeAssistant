<?php

declare(strict_types=1);

/**
 * Prüft am ECHTEN MQTT Discovery Device über den Kernel-Stub den Empfangsfilter: Eine Meldung erreicht nur die
 * Geräte, die ihr Topic auswerten.
 *
 * Anlass: Ein Anwender mit Z-Wave JS UI (72 Geräte, 26.11.2026). Sein Performance-Mitschnitt zeigt
 * im Splitter je Meldung 1,1 bis 1,3 s allein für SendDataToChildren, und zwei mitgeschnittene Geräte bekamen jede
 * Meldung, auch die fremder Geräte. Der Filter fasste Topics über ihren gemeinsamen Anfang zusammen (mindestens
 * drei Zeichen). Bei Z-Wave JS UI beginnen die Topics eines Geräts und die availability-Topics des Gateways alle
 * mit „Z-Wave-JS/“; der Filter jedes Geräts lautete damit „Z-Wave-JS/ und irgendetwas“.
 *
 * Fixtures: tests/fixtures/discovery_ems_esp_20261116.json (EMS-ESP, drei Geräte unter „ems-esp/“),
 * tests/fixtures/ha_mqtt_discovery_bundle_zigbee2mqtt_light_current_v2.json (Zigbee2MQTT, 88 Lampen) und
 * tests/fixtures/discovery_zwavejs_20261125.json (Z-Wave JS UI, ein Gerät). Die Meldungen in Teil 2 stammen aus
 * dem Mitschnitt des Anwenders, Raum- und Gerätenamen wie in der Fixture ersetzt.
 */

require_once __DIR__ . '/device-harness.php';

error_reporting(E_ALL);

$ems = json_decode((string)file_get_contents(__DIR__ . '/fixtures/discovery_ems_esp_20261116.json'), true, 512, JSON_THROW_ON_ERROR);
$zwave = json_decode((string)file_get_contents(__DIR__ . '/fixtures/discovery_zwavejs_20261125.json'), true, 512, JSON_THROW_ON_ERROR);
$z2m = json_decode((string)file_get_contents(__DIR__ . '/fixtures/ha_mqtt_discovery_bundle_zigbee2mqtt_light_current_v2.json'), true, 512, JSON_THROW_ON_ERROR);
$text = static fn(mixed $p): string => is_string($p) ? $p : json_encode($p, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

const DIMMER = 'zwavejs2mqtt_0x00000001_node23';

/**
 * Legt einen Splitter mit den Ankündigungen an und je Gerät eine Instanz. Liefert je Geräte-ID den gesetzten
 * Empfangsfilter und die Topics, die das Gerät auswertet (aus seinem Topic-Index, unabhängig vom Filter).
 */
function geraeteMitFilter(array $saetze, callable $text): array
{
    $splitter = neueFensterInstanz(DiscoverySplitterHarness::class);
    neueAusfuehrung($splitter)->ApplyChanges();
    $ids = [];
    $parser = new HAMqttDiscoveryParser();
    foreach ($saetze as $satz) {
        $payload = $text($satz['payload']);
        $ids[(string)$parser->resolveRecordDeviceId($payload)] = true;
        neueAusfuehrung($splitter)->ReceiveData(json_encode([
            'DataID'           => HAIds::DATA_MQTT_RX,
            'Topic'            => $satz['topic'],
            'Payload'          => bin2hex($payload),
            'Retain'           => false,
            'QualityOfService' => 0,
        ], JSON_THROW_ON_ERROR));
    }
    unset($ids['']);

    DiscoveryDeviceHarness::$topicPayloadAntwort = ['Items' => []];
    DiscoveryDeviceHarness::$parentAktiv = true;
    $ergebnis = [];
    foreach (array_keys($ids) as $deviceId) {
        DiscoveryDeviceHarness::$splitterAntwort = json_decode(neueAusfuehrung($splitter)->ForwardData(json_encode([
            'DataID'          => HAIds::DATA_MQTT_DISCOVERY_DEVICE_TO_SPLITTER,
            'DiscoveryAction' => 'GetDiscoveryConfigs',
            'DeviceID'        => $deviceId,
        ], JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);

        $geraet = neueFensterInstanz(DiscoveryDeviceHarness::class);
        IPS_ConnectInstance($geraet->id(), $splitter->id());
        $geraet->SetProperty('DeviceID', (string)$deviceId);
        neueAusfuehrung($geraet)->ApplyChanges();
        $lauf = neueAusfuehrung($geraet);
        $lauf->ApplyChanges();
        $index = (new ReflectionMethod($lauf, 'readTopicProcessingIndex'))->invoke($lauf);
        // Ausgewertet wird, was zu einem Wert führt. Der Index nennt unter „topics“ und „warnings“ auch die
        // Zustands-Topics abgewählter Entitäten; deren Warnung gleicht das Formular beim Öffnen ab.
        $topics = [];
        foreach (['state', 'attributes', 'availability', 'climate', 'light'] as $bereich) {
            $topics += array_fill_keys(array_map('strval', array_keys($index[$bereich])), true);
        }
        ksort($topics, SORT_STRING);
        $ergebnis[(string)$deviceId] = ['filter' => $lauf->GetReceiveDataFilter(), 'topics' => array_map('strval', array_keys($topics))];
    }

    return $ergebnis;
}

/** Die Meldung, wie der Splitter sie an die Geräte gibt, gegen den Filter eines Geräts. */
function kommtAn(string $filter, string $topic, int $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES): bool
{
    $meldung = json_encode([
        'DataID'  => HAIds::DATA_MQTT_DISCOVERY_SPLITTER_TO_DEVICE,
        'Topic'   => $topic,
        'Payload' => bin2hex('{"value":1}'),
        'Retain'  => false,
    ], JSON_THROW_ON_ERROR | $flags);

    return preg_match(chr(1) . $filter . chr(1), $meldung) === 1;
}

/**
 * Jedes Topic, das eines der Geräte auswertet, erreicht genau die Geräte, die es auswerten: mit „/“ und mit „\/“
 * (der Kernel reicht den Text des Senders durch, ältere Wege maskieren den Schrägstrich).
 */
function pruefeZustellung(string $name, array $geraete): void
{
    $besitzerJeTopic = [];
    foreach ($geraete as $deviceId => $geraet) {
        foreach ($geraet['topics'] as $topic) {
            $besitzerJeTopic[$topic][$deviceId] = true;
        }
    }
    $ohneTopics = array_keys(array_filter($geraete, static fn(array $g): bool => $g['topics'] === []));
    pruefe($ohneTopics === [], $name . ': jedes Gerät wertet Topics aus', implode(', ', array_slice($ohneTopics, 0, 3)));

    $fehlt = [];
    $fehltMaskiert = [];
    $zuViel = [];
    $fremdeFaelle = 0;
    foreach ($besitzerJeTopic as $topic => $besitzer) {
        foreach ($geraete as $deviceId => $geraet) {
            $an = kommtAn($geraet['filter'], (string)$topic);
            if (isset($besitzer[$deviceId])) {
                if (!$an) {
                    $fehlt[] = $deviceId . ' ← ' . $topic;
                }
                if (!kommtAn($geraet['filter'], (string)$topic, JSON_UNESCAPED_UNICODE)) {
                    $fehltMaskiert[] = $deviceId . ' ← ' . $topic;
                }
                continue;
            }
            $fremdeFaelle++;
            if ($an) {
                $zuViel[] = $deviceId . ' ← ' . $topic;
            }
        }
    }
    pruefe($fremdeFaelle > 0, $name . ': es gibt Topics, die nicht jedes Gerät auswertet', (string)$fremdeFaelle);
    pruefe($fehlt === [], $name . ': jedes Gerät bekommt jedes eigene Topic', implode(' | ', array_slice($fehlt, 0, 3)));
    pruefe($fehltMaskiert === [], $name . ': eigene Topics kommen auch mit maskierten Schrägstrichen an', implode(' | ', array_slice($fehltMaskiert, 0, 3)));
    pruefe($zuViel === [], $name . ': kein Gerät bekommt ein Topic, das es nicht auswertet', count($zuViel) . ' von ' . $fremdeFaelle . ', z. B. ' . implode(' | ', array_slice($zuViel, 0, 3)));
}

// --- Teil 1: Mehrere Geräte unter derselben Wurzel. Jedes Topic erreicht nur die Geräte, die es auswerten.
$emsGeraete = geraeteMitFilter($ems['discovery_configs'], $text);
pruefe(count($emsGeraete) === 3, 'EMS-ESP: drei Geräte', implode(', ', array_keys($emsGeraete)));
pruefeZustellung('EMS-ESP', $emsGeraete);

// Zigbee2MQTT: Der Zustand liegt auf „zigbee2mqtt/<name>“, die Verfügbarkeit darunter und die der Bridge daneben.
$z2mGeraete = geraeteMitFilter($z2m['discovery_configs'], $text);
pruefe(count($z2mGeraete) === 88, 'Zigbee2MQTT: 88 Geräte', (string)count($z2mGeraete));
pruefeZustellung('Zigbee2MQTT', $z2mGeraete);
$bridge = count(array_filter($z2mGeraete, static fn(array $g): bool => kommtAn($g['filter'], 'zigbee2mqtt/bridge/state')));
pruefe($bridge === 88, 'Zigbee2MQTT: die Verfügbarkeit der Bridge erreicht alle Geräte', (string)$bridge);
$eins = $z2mGeraete['zigbee2mqtt_device_001'] ?? ['filter' => '^$'];
pruefe(kommtAn($eins['filter'], 'zigbee2mqtt/light_fixture_001'), 'Zigbee2MQTT: Zustand des eigenen Geräts kommt an');
pruefe(kommtAn($eins['filter'], 'zigbee2mqtt/light_fixture_001/availability'), 'Zigbee2MQTT: Verfügbarkeit unter dem Zustands-Topic kommt an');
pruefe(!kommtAn($eins['filter'], 'zigbee2mqtt/light_fixture_001/set'), 'Zigbee2MQTT: der eigene Befehl kommt nicht zurück');
pruefe(!kommtAn($eins['filter'], 'zigbee2mqtt/bridge/logging'), 'Zigbee2MQTT: andere Meldungen der Bridge kommen nicht an');

// --- Teil 2: Z-Wave JS UI. Meldungen aus dem Mitschnitt des Anwenders, die das Gerät nicht auswertet.
$dimmer = geraeteMitFilter($zwave['discovery_configs'], $text)[DIMMER] ?? null;
pruefe($dimmer !== null && $dimmer['topics'] !== [], 'Z-Wave JS: Gerät wertet Topics aus', $dimmer === null ? 'fehlt' : implode(', ', $dimmer['topics']));
$dimmer ??= ['filter' => '^$', 'topics' => []];

foreach ($dimmer['topics'] as $topic) {
    pruefe(kommtAn($dimmer['filter'], (string)$topic), 'Z-Wave JS: eigenes Topic kommt an: ' . $topic);
}
pruefe(in_array('Z-Wave-JS/_CLIENTS/ZWAVE_GATEWAY-zwave-js-ui/status', $dimmer['topics'], true), 'Z-Wave JS: das availability-Topic des Gateways gehört zu den eigenen Topics');

$fremd = [
    'Z-Wave-JS/_EVENTS/ZWAVE_GATEWAY-zwave-js-ui/node/statistics_updated',
    'Z-Wave-JS/_EVENTS/ZWAVE_GATEWAY-zwave-js-ui/node/node_value_updated',
    'Z-Wave-JS/_EVENTS/ZWAVE_GATEWAY-zwave-js-ui/controller/statistics_updated',
    'Z-Wave-JS/Raum/Bild/lastActive',
    // Zustand einer abgewählten Entität (Geräteparameter sind in Z-Wave JS UI von Haus aus abgewählt).
    'Z-Wave-JS/Raum/Bild/configuration/endpoint_0/Ignore_Start_Level',
    'Z-Wave-JS/Raum2/Dimmer/lastActive',
    'Z-Wave-JS/Raum2/Dimmer/switch_multilevel/endpoint_0/currentValue',
    'Z-Wave-JS/Raum2/Steckdose/meter/endpoint_0/value/66049',
];
foreach ($fremd as $topic) {
    pruefe(!in_array($topic, $dimmer['topics'], true), 'Z-Wave JS: wertet das Gerät nicht aus: ' . $topic);
    pruefe(!kommtAn($dimmer['filter'], $topic), 'Z-Wave JS: kommt nicht an: ' . $topic);
}

// --- Teil 3: Genau das Topic, kein Anfang und keine Verlängerung davon.
$eigenes = 'Z-Wave-JS/_CLIENTS/ZWAVE_GATEWAY-zwave-js-ui/status';
pruefe(!kommtAn($dimmer['filter'], $eigenes . '/mehr'), 'Verlängerung eines eigenen Topics kommt nicht an');
pruefe(!kommtAn($dimmer['filter'], $eigenes . '2'), 'eigenes Topic mit angehängtem Zeichen kommt nicht an');
pruefe(!kommtAn($dimmer['filter'], 'Z-Wave-JS/_CLIENTS/ZWAVE_GATEWAY-zwave-js-ui'), 'Anfang eines eigenen Topics kommt nicht an');
pruefe(!kommtAn($dimmer['filter'], 'x/' . $eigenes), 'eigenes Topic unter einer anderen Wurzel kommt nicht an');

// --- Teil 4: Topic mit Umlaut (Anwender benennt Räume so). Kein Mitschnitt: dieselbe Fixture, „Raum“ durch
// „Küche“ ersetzt. Ob der Kernel den Text mit „ü“ oder als „ü“ gegen den Filter hält, ist nicht gemessen;
// der Filter muss beides durchlassen, sonst bliebe ein solches Gerät ohne Werte.
$kuecheSaetze = [];
foreach ($zwave['discovery_configs'] as $satz) {
    $kuecheSaetze[] = ['topic' => $satz['topic'], 'payload' => str_replace('Z-Wave-JS/Raum/', 'Z-Wave-JS/Küche/', $text($satz['payload']))];
}
$kueche = geraeteMitFilter($kuecheSaetze, $text)[DIMMER] ?? ['filter' => '^$', 'topics' => []];
$mitUmlaut = array_values(array_filter($kueche['topics'], static fn(string $t): bool => str_contains($t, 'Küche')));
pruefe($mitUmlaut !== [], 'Umlaut: Gerät wertet Topics unter „Küche“ aus', (string)count($mitUmlaut));
foreach ($mitUmlaut as $topic) {
    pruefe(kommtAn($kueche['filter'], $topic), 'Umlaut: kommt an mit „ü“: ' . $topic);
    pruefe(kommtAn($kueche['filter'], $topic, JSON_UNESCAPED_SLASHES), 'Umlaut: kommt an als ü: ' . $topic);
    pruefe(!kommtAn($kueche['filter'], str_replace('Küche', 'Keller', $topic)), 'Umlaut: anderer Raum kommt nicht an: ' . str_replace('Küche', 'Keller', $topic));
}
pruefe(!kommtAn($kueche['filter'], 'Z-Wave-JS/Küche/Bild/lastActive'), 'Umlaut: fremdes Topic desselben Geräts kommt nicht an');

ergebnis();
