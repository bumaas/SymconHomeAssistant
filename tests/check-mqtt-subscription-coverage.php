<?php

declare(strict_types=1);

// Prüft den MQTT-Wildcard-Matcher und die Abonnement-Abdeckung (HAMqttTopicFilter):
// segmentweises Matching (+/#), Teilbaum-Abdeckung eines Präfixes, Restliste nicht
// abgedeckter Topics samt Abonnement-Vorschlägen sowie das Einsammeln der
// Subscriptions-Liste aus einer MQTT-Client-Konfiguration.

require_once dirname(__DIR__) . '/libs/HAMqttTopicFilter.php';

$fail = 0;
$check = static function (bool $ok, string $label) use (&$fail): void {
    echo ($ok ? 'OK   ' : 'FAIL ') . $label . "\n";
    $fail += $ok ? 0 : 1;
};

// matchesFilter: Grundfälle
$check(HAMqttTopicFilter::matchesFilter('a/b/c', 'a/b/c'), 'Exaktes Topic matcht');
$check(!HAMqttTopicFilter::matchesFilter('a/b/c', 'a/b'), 'Kürzerer Filter ohne Wildcard matcht nicht');
$check(!HAMqttTopicFilter::matchesFilter('a/b', 'a/b/c'), 'Längerer Filter matcht nicht');
$check(HAMqttTopicFilter::matchesFilter('a/b/c', '#'), 'Voll-Wildcard matcht alles');
$check(HAMqttTopicFilter::matchesFilter('a/b/c', 'a/#'), 'Mehrstufen-Wildcard am Ende');
$check(HAMqttTopicFilter::matchesFilter('a', 'a/#'), 'a/# deckt auch das Eltern-Topic a');
$check(!HAMqttTopicFilter::matchesFilter('ab', 'a/#'), 'a/# matcht kein Präfix auf Zeichenebene');
$check(HAMqttTopicFilter::matchesFilter('a/b/c', 'a/+/c'), '+ matcht genau ein Segment');
$check(!HAMqttTopicFilter::matchesFilter('a/c', 'a/+/c'), '+ verlangt ein Segment');
$check(!HAMqttTopicFilter::matchesFilter('a/b/x/c', 'a/+/c'), '+ matcht nicht mehrere Segmente');
$check(HAMqttTopicFilter::matchesFilter('a/b', '+/+'), 'Nur-Wildcard-Filter');
$check(!HAMqttTopicFilter::matchesFilter('a/b/c', 'a/#/c'), '# mitten im Filter ist ungültig');
$check(HAMqttTopicFilter::matchesFilter('/a/b/', 'a/b'), 'Führende/abschließende Slashes werden normalisiert');

// coveredByAny / uncoveredTopics: Fall bgersmann (Configs unter homeassistant/…,
// States unter Garage/gecos/…, abonniert war nur homeassistant/#)
$filters = ['homeassistant/#'];
$topics = [
    'Garage/gecos/outputs/0/24/3',
    'Garage/gecos/inputs/0/20/1',
    'Garage/gecos/status',
    'homeassistant/switch/x/config'
];
$check(!HAMqttTopicFilter::coveredByAny('Garage/gecos/outputs/0/24/3', $filters), 'State-Topic außerhalb des Prefixes ist nicht abgedeckt');
$uncovered = HAMqttTopicFilter::uncoveredTopics($topics, $filters);
$check($uncovered === ['Garage/gecos/inputs/0/20/1', 'Garage/gecos/outputs/0/24/3', 'Garage/gecos/status'], 'uncoveredTopics liefert sortierte Restliste');
$check(HAMqttTopicFilter::suggestFilters($uncovered) === ['Garage/#'], 'Vorschlag aus erstem Segment: Garage/#');
$check(HAMqttTopicFilter::uncoveredTopics($topics, ['homeassistant/#', 'Garage/#']) === [], 'Mit Garage/# ist alles abgedeckt');
$check(HAMqttTopicFilter::uncoveredTopics($topics, ['#']) === [], '# deckt alles ab');
$check(HAMqttTopicFilter::suggestFilters(['x', 'y/z', 'x/1']) === ['x/#', 'y/#'], 'Vorschläge unique und sortiert');

// filterCoversPrefix: Teilbaum-Abdeckung
$check(HAMqttTopicFilter::filterCoversPrefix('#', 'homeassistant'), '# deckt jeden Präfix');
$check(HAMqttTopicFilter::filterCoversPrefix('homeassistant/#', 'homeassistant'), 'prefix/# deckt den Präfix');
$check(HAMqttTopicFilter::filterCoversPrefix('+/#', 'homeassistant'), '+/# deckt einen einstufigen Präfix');
$check(!HAMqttTopicFilter::filterCoversPrefix('homeassistant', 'homeassistant'), 'Exaktes Topic deckt nicht den Teilbaum');
$check(!HAMqttTopicFilter::filterCoversPrefix('homeassistant/sensor/#', 'homeassistant'), 'Teil-Abonnement deckt den Präfix nicht');
$check(!HAMqttTopicFilter::filterCoversPrefix('homeassistant/+/+/config', 'homeassistant'), 'Config-Muster deckt den Teilbaum nicht');
$check(!HAMqttTopicFilter::filterCoversPrefix('zigbee2mqtt/bridge/#', 'zigbee2mqtt'), 'Alte Heuristik-Falle: bridge/# deckt zigbee2mqtt nicht');
$check(HAMqttTopicFilter::filterCoversPrefix('a/b/#', 'a/b'), 'Mehrstufiger Präfix wird abgedeckt');
$check(!HAMqttTopicFilter::filterCoversPrefix('a/b/#', 'a'), 'Tieferer Filter deckt kürzeren Präfix nicht');

// collectSubscriptionsFromConfig / flattenSubscriptionTopics: Symcon-MQTT-Client-Format
$config = [
    'ClientID' => 'symcon',
    'Subscriptions' => '[{"Topic":"homeassistant/#","QoS":1},{"Topic":"Garage/#","QoS":0}]',
    'UserName' => 'x'
];
$check(HAMqttTopicFilter::collectSubscriptionsFromConfig($config) === ['homeassistant/#', 'Garage/#'], 'Subscriptions-JSON des MQTT Clients wird eingesammelt');
$check(HAMqttTopicFilter::collectSubscriptionsFromConfig(['Subscriptions' => '[]']) === [], 'Leere Abonnement-Liste');
$check(HAMqttTopicFilter::collectSubscriptionsFromConfig(['ClientID' => 'x']) === [], 'Keine Subscription-Felder');
$check(HAMqttTopicFilter::flattenSubscriptionTopics('homeassistant/#') === ['homeassistant/#'], 'Einzelner Topic-String');
$check(HAMqttTopicFilter::flattenSubscriptionTopics([['Topic' => 'a/#'], ['Topic' => 'b/#']]) === ['a/#', 'b/#'], 'Verschachtelte Topic-Arrays');

// receiveDataFilterPattern: der Kernel-Filter des klassischen Splitters lässt nur Datenpakete
// unterhalb des Base-Topics durch. Gegen echte Datenpaket-JSONs geprüft — einmal mit escapten
// Slashes (Standard von json_encode), einmal ohne (JSON_UNESCAPED_SLASHES).
$pattern = HAMqttTopicFilter::receiveDataFilterPattern('homeassistant');
$matches = static fn(string $json): bool => (bool)preg_match('/^' . $pattern . '$/s', $json);

$rxEscaped   = '{"DataID":"{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}","PacketType":3,"Topic":"homeassistant\/sensor\/evcc_pv_power\/state","Payload":"1234"}';
$rxPlain     = '{"DataID":"{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}","PacketType":3,"Topic":"homeassistant/sensor/evcc_pv_power/state","Payload":"1234"}';
$foreign     = '{"DataID":"{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}","PacketType":3,"Topic":"hb\/device\/HR2C04000089HH3\/status","Payload":"{}"}';
$foreignZ    = '{"DataID":"{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}","PacketType":3,"Topic":"zigbee2mqtt/0x001788/state","Payload":"ON"}';

$check($matches($rxEscaped), 'Filter: eigenes Topic mit escapten Slashes kommt durch');
$check($matches($rxPlain), 'Filter: eigenes Topic mit unescapten Slashes kommt durch');
$check(!$matches($foreign), 'Filter: fremdes Topic (hb/device/...) wird ausgesortiert');
$check(!$matches($foreignZ), 'Filter: fremdes Topic (zigbee2mqtt/...) wird ausgesortiert');

// Das Muster darf keinen Slash enthalten (der Kernel wendet es mit eigenem Delimiter an) und
// Sonderzeichen des Base-Topics müssen literal behandelt werden.
$patternDeep = HAMqttTopicFilter::receiveDataFilterPattern('/ha.prod/state/');
$check(!str_contains($patternDeep, '/'), 'Filter: Muster enthält keinen Slash');
$matchesDeep = static fn(string $json): bool => (bool)preg_match('~^' . $patternDeep . '$~s', $json);
$check($matchesDeep('{"Topic":"ha.prod\/state\/sensor\/x\/state"}'), 'Filter: mehrstufiges Base-Topic matcht über erstes Segment');
$check(!$matchesDeep('{"Topic":"haXprod/state/sensor/x/state"}'), 'Filter: Punkt wird als Literal behandelt (kein RegEx-Joker)');
$check(HAMqttTopicFilter::receiveDataFilterPattern('') === '.*', 'Filter: leeres Base-Topic filtert nicht');
$check(HAMqttTopicFilter::receiveDataFilterPattern('  ') === '.*', 'Filter: nur Leerzeichen filtert nicht');
$check(HAMqttTopicFilter::receiveDataFilterPattern('/') === '.*', 'Filter: nur Slash filtert nicht');

echo $fail === 0 ? "Alle Prüfungen bestanden.\n" : "$fail Prüfung(en) fehlgeschlagen.\n";
exit($fail === 0 ? 0 : 1);
