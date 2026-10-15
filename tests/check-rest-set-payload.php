<?php

declare(strict_types=1);

/**
 * Prüft am ECHTEN Splitter über den Kernel-Stub, dass ein `…/set`-Befehl für eine Auswahl die Option
 * unverändert an HA gibt.
 *
 * Anlass: Friemler (PN t/144231 #24/#26, 14./15.10.2026) schaltete die Auswahl `power` seiner
 * Samsung-Wärmepumpe aus Symcon, HA übernahm nichts, im MQTT Explorer stand nur das `set`-Topic. Der
 * Splitter wandelte jeden Befehl vorab in einen Wert um (`normalizePayload`): „on"/„off" wurden
 * true/false, „null" wurde null, Zahlen wurden float. Für `select_option` wurde aus true „1" — HA
 * antwortet darauf mit 400 („Option 1 is not valid for entity select.hue_aurelle_wiedereinschaltverhalten,
 * valid options are: on, off, toggle, previous", nuc 15.10.2026) — und aus false bzw. null ein leerer
 * Wert, für den gar kein Befehl entstand. In beiden Fällen ging der Befehl als `set` an den Broker, wo
 * ihn niemand liest.
 *
 * Fixture tests/fixtures/rest_set_select_20261015.json: echte Zustände von Auswahl-Entitäten aus HA am nuc
 * (Hue, evcc), deren Optionen wie Schalt-, JSON- oder Zahlenwerte aussehen. Extraktion:
 * tools/fixture_rest_set_select.php.
 */

require_once __DIR__ . '/device-harness.php';

error_reporting(E_ALL);

$fixture = json_decode((string)file_get_contents(__DIR__ . '/fixtures/rest_set_select_20261015.json'), true, 512, JSON_THROW_ON_ERROR);

$splitter = neueFensterInstanz(SplitterHarness::class);
// Ohne den Rahmen (Code vor dem Fix) schlägt der echte Aufruf an diese Adresse sofort fehl.
$splitter->SetProperty('HAUrl', 'http://127.0.0.1:9');
$splitter->SetProperty('HAToken', 'test');
$splitter->ApplyChanges();

/** Schickt einen Befehl so, wie ihn Device und Entity senden (HADeviceCore::sendMqttMessage). */
$sende = static function (string $entityId, string $payload) use ($splitter): array {
    [$domain, $objekt] = explode('.', $entityId, 2);
    SplitterHarness::$restAufrufe = [];
    SplitterHarness::$mqttWeiterleitungen = [];
    neueAusfuehrung($splitter)->ForwardData(json_encode([
        'DataID'           => HAIds::DATA_DEVICE_TO_SPLITTER,
        'PacketType'       => 3,
        'QualityOfService' => 0,
        'Retain'           => false,
        'Topic'            => 'homeassistant/' . $domain . '/' . $objekt . '/set',
        'Payload'          => bin2hex($payload),
    ], JSON_THROW_ON_ERROR));
    return [SplitterHarness::$restAufrufe, SplitterHarness::$mqttWeiterleitungen];
};

// ---- Teil 1: jede Option jeder Auswahl geht wörtlich als select_option an HA ----
foreach ($fixture['zustaende'] as $zustand) {
    $entityId = $zustand['entity_id'];
    foreach ($zustand['attributes']['options'] as $option) {
        [$rest, $mqtt] = $sende($entityId, $option);
        $aufruf = $rest[0] ?? null;
        pruefe(
            count($rest) === 1
            && str_ends_with($aufruf['url'], '/api/services/select/select_option')
            && $aufruf['daten'] === ['option' => $option, 'entity_id' => $entityId],
            $entityId . ': Option „' . $option . '" geht unverändert an HA',
            json_encode($rest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
        pruefe($mqtt === [], $entityId . ': Option „' . $option . '" landet nicht als set-Topic auf MQTT', json_encode($mqtt, JSON_UNESCAPED_SLASHES));
    }
}

// ---- Teil 2: Domains mit Schalt- und Zahlenwerten wandeln weiter um ----
[$rest] = $sende('switch.test_schalter', 'ON');
pruefe(
    count($rest) === 1 && str_ends_with($rest[0]['url'], '/api/services/switch/turn_on'),
    'Schalter: „ON" wird weiter zu turn_on',
    json_encode($rest, JSON_UNESCAPED_SLASHES)
);
[$rest] = $sende('switch.test_schalter', 'OFF');
pruefe(
    count($rest) === 1 && str_ends_with($rest[0]['url'], '/api/services/switch/turn_off'),
    'Schalter: „OFF" wird weiter zu turn_off',
    json_encode($rest, JSON_UNESCAPED_SLASHES)
);
[$rest] = $sende('input_number.test_zahl', '21.5');
pruefe(
    count($rest) === 1 && ($rest[0]['daten']['value'] ?? null) === 21.5,
    'Zahl: „21.5" geht als Zahl an HA',
    json_encode($rest, JSON_UNESCAPED_SLASHES)
);

ergebnis();
