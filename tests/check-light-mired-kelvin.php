<?php

declare(strict_types=1);

// Home Assistant kennt seit 2026.3 keine Mired mehr (Commit #161777 „Cleanup deprecated mired
// handling in light platform"): light.turn_on weist color_temp und kelvin mit HTTP 400 ab, und
// der Zustand einer Lampe enthält weder color_temp noch min_mireds/max_mireds.
// Geprüft wird, dass der Splitter Mired beim Senden in color_temp_kelvin übersetzt, dass eine
// vorhandene Mired-Variable aus dem Kelvin-Wert weiter befüllt wird und dass die Mired-Variable
// nur noch angelegt wird, wenn HA selbst Mired meldet.

require_once __DIR__ . '/harness.php';
require_once dirname(__DIR__) . '/libs/Domains/HALightDefinitions.php';

$check = static function (string $label, $actual, $expected): void {
    pruefe($actual === $expected, $label, 'erwartet: ' . var_export($expected, true) . ', ist: ' . var_export($actual, true));
};
$call = static function (string $method, ...$args) {
    if (!method_exists(HALightDefinitions::class, $method)) {
        return 'Methode HALightDefinitions::' . $method . '() fehlt';
    }
    return HALightDefinitions::$method(...$args);
};

// Mitschnitt light.hue_aurelle, HA 2026.9.3, 23.09.2026 (GET /api/states, Lampe aus).
$attributesHa2026_9 = [
    'min_color_temp_kelvin' => 2202,
    'max_color_temp_kelvin' => 4000,
    'supported_color_modes' => ['color_temp'],
    'color_mode'            => null,
    'brightness'            => null,
    'color_temp_kelvin'     => null,
    'hs_color'              => null,
    'rgb_color'             => null,
    'xy_color'              => null,
    'friendly_name'         => 'Hue Aurelle',
    'supported_features'    => 32
];
// Dieselbe Lampe im Format bis HA 2026.2: dort standen Mired und Kelvin nebeneinander
// (Attributnamen laut light/__init__.py, Tag 2026.2.0).
$attributesHa2026_2 = $attributesHa2026_9 + ['min_mireds' => 250, 'max_mireds' => 454, 'color_temp' => null];

// --- Senden: light.turn_on darf nur noch color_temp_kelvin sehen ---
$check('Mired 300 wird zu color_temp_kelvin 3333',
    HALightDefinitions::buildRestServicePayload(['color_temp' => 300]),
    ['turn_on', ['color_temp_kelvin' => 3333]]);
$check('Mired neben Helligkeit und state ON',
    HALightDefinitions::buildRestServicePayload(['state' => 'ON', 'brightness' => 128, 'color_temp' => 370]),
    ['turn_on', ['brightness' => 128, 'color_temp_kelvin' => 2703]]);
$check('Mired als String aus einer Variablen',
    HALightDefinitions::buildRestServicePayload(['color_temp' => '454']),
    ['turn_on', ['color_temp_kelvin' => 2203]]);
$check('altes Feld kelvin wird zu color_temp_kelvin',
    HALightDefinitions::buildRestServicePayload(['kelvin' => 3000]),
    ['turn_on', ['color_temp_kelvin' => 3000]]);
$check('color_temp_kelvin bleibt unverändert',
    HALightDefinitions::buildRestServicePayload(['color_temp_kelvin' => 2700]),
    ['turn_on', ['color_temp_kelvin' => 2700]]);
$check('nicht umrechenbares Mired bleibt stehen, HA meldet den Fehler',
    HALightDefinitions::buildRestServicePayload(['color_temp' => 0]),
    ['turn_on', ['color_temp' => 0]]);
$check('Schalten ohne Farbtemperatur unberührt',
    HALightDefinitions::buildRestServicePayload(true),
    ['turn_on', []]);

// --- Empfangen: vorhandene Mired-Variable folgt dem Kelvin-Wert ---
$check('Kelvin 2700 ergibt Mired 370',
    $call('withDerivedMired', ['color_temp_kelvin' => 2700]),
    ['color_temp_kelvin' => 2700, 'color_temp' => 370]);
$check('Lampe aus: Kelvin null ergibt Mired null',
    $call('withDerivedMired', ['color_temp_kelvin' => null]),
    ['color_temp_kelvin' => null, 'color_temp' => null]);
$check('von HA gemeldetes Mired bleibt unangetastet',
    $call('withDerivedMired', ['color_temp_kelvin' => 2700, 'color_temp' => 371]),
    ['color_temp_kelvin' => 2700, 'color_temp' => 371]);
$check('ohne Kelvin kein Mired',
    $call('withDerivedMired', ['brightness' => 128]),
    ['brightness' => 128]);

// --- Anlegen: Mired-Variable nur, wenn HA Mired meldet ---
$check('HA 2026.9 (Mitschnitt) meldet kein Mired', $call('reportsMired', $attributesHa2026_9), false);
$check('HA 2026.2 meldet Mired', $call('reportsMired', $attributesHa2026_2), true);

ergebnis();
