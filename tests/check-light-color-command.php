<?php

declare(strict_types=1);

// Verifies the MQTT color command payload for light xy_color/hs_color:
// nested {"color":{...}} object, string/array/delimited input tolerance, null on bad input.

require_once __DIR__ . '/harness.php';
require_once dirname(__DIR__) . '/libs/Domains/HALightDefinitions.php';
require_once dirname(__DIR__) . '/libs/Discovery/HAMqttDiscoveryLightRuntime.php';

$check = static function (string $label, $actual, $expected): void {
    pruefe($actual === $expected, $label, 'erwartet: ' . var_export($expected, true) . ', ist: ' . var_export($actual, true));
};

$build = static fn(string $a, $v) => HAMqttDiscoveryLightRuntime::buildAttributeCommandPayload($a, $v);

$check('xy_color JSON-String', $build('xy_color', '[0.46,0.41]'), '{"color":{"x":0.46,"y":0.41}}');
$check('xy_color Array',       $build('xy_color', [0.46, 0.41]),   '{"color":{"x":0.46,"y":0.41}}');
$check('xy_color delimited',   $build('xy_color', '0.46;0.41'),    '{"color":{"x":0.46,"y":0.41}}');
$check('hs_color JSON-String', $build('hs_color', '[210,75]'),     '{"color":{"h":210.0,"s":75.0}}');
$check('xy_color garbage => null', $build('xy_color', 'garbage'), null);
$check('xy_color too few => null', $build('xy_color', '[0.46]'),  null);

// unaffected scalar attributes still use the flat payload
$check('brightness bleibt flach', $build('brightness', 128), '{"brightness":128}');

ergebnis();
