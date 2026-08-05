<?php

declare(strict_types=1);

// Verifies the value casting used by the "Emulate status" option (castToVariableType).
// The bool path uses the real HAMqttDiscoveryLightRuntime::coerceBooleanActionValue; the
// numeric/string paths mirror the module's castToVariableType match arms exactly.

foreach (['VARIABLETYPE_BOOLEAN' => 0, 'VARIABLETYPE_INTEGER' => 1, 'VARIABLETYPE_FLOAT' => 2, 'VARIABLETYPE_STRING' => 3] as $n => $v) {
    if (!defined($n)) {
        define($n, $v);
    }
}

require_once dirname(__DIR__) . '/libs/Domains/HALightDefinitions.php';
require_once dirname(__DIR__) . '/libs/Discovery/HAMqttDiscoveryLightRuntime.php';

// Mirror of module::castToVariableType (private, IPS-bound); logic must stay in sync.
function castToVariableType(mixed $value, int $type): bool|int|float|string|null
{
    return match ($type) {
        VARIABLETYPE_BOOLEAN => HAMqttDiscoveryLightRuntime::coerceBooleanActionValue($value),
        VARIABLETYPE_INTEGER => is_numeric($value) ? (int) round((float) $value) : null,
        VARIABLETYPE_FLOAT   => is_numeric($value) ? (float) $value : null,
        VARIABLETYPE_STRING  => is_scalar($value) ? (string) $value : null,
        default              => null,
    };
}

$fail = 0;
$check = static function (string $label, $actual, $expected) use (&$fail): void {
    $ok = $actual === $expected;
    printf("[%s] %s\n", $ok ? 'OK ' : 'FAIL', $label);
    if (!$ok) {
        $fail++;
        echo "     erwartet: " . var_export($expected, true) . "  ist: " . var_export($actual, true) . "\n";
    }
};

$check('bool from true',      castToVariableType(true, VARIABLETYPE_BOOLEAN), true);
$check('bool from "ON"',      castToVariableType('ON', VARIABLETYPE_BOOLEAN), true);
$check('bool from 0',         castToVariableType(0, VARIABLETYPE_BOOLEAN), false);
$check('int from 128.6',      castToVariableType(128.6, VARIABLETYPE_INTEGER), 129);
$check('int from "50"',       castToVariableType('50', VARIABLETYPE_INTEGER), 50);
$check('int from garbage',    castToVariableType('x', VARIABLETYPE_INTEGER), null);
$check('float from "21.5"',   castToVariableType('21.5', VARIABLETYPE_FLOAT), 21.5);
$check('string from xy',      castToVariableType('[0.46,0.41]', VARIABLETYPE_STRING), '[0.46,0.41]');
$check('string from array=>null', castToVariableType([1, 2], VARIABLETYPE_STRING), null);

echo "\n";
if ($fail === 0) {
    echo "Alle Assertions grün.\n";
    exit(0);
}
printf("%d Assertion(en) fehlgeschlagen.\n", $fail);
exit(1);
