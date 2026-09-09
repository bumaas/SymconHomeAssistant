<?php

declare(strict_types=1);

// Regression test for the shared presentation builder (HASharedPresentationTrait).
// Verifies the fields that had drifted between the REST device and the MQTT discovery device:
// brightness USAGE_TYPE, color-temp USAGE_TYPE/GRADIENT_TYPE, rgb_color COLOR/ENCODING,
// climate temperature slider USAGE_TYPE 0 + GRADIENT_TYPE 1, binary_sensor options + icon.

foreach ([
    'VARIABLE_PRESENTATION_SLIDER'            => '{5F203CA1-B360-40FB-9407-D2C0C40A7B90}',
    'VARIABLE_PRESENTATION_COLOR'             => '{68296F5D-2AAD-495F-A2CA-693F1F65C388}',
    'VARIABLE_PRESENTATION_VALUE_PRESENTATION' => '{7B15C4F7-C9F0-4E1E-8F1A-1B0F9E2F7A11}',
] as $name => $value) {
    if (!defined($name)) {
        define($name, $value);
    }
}

require_once dirname(__DIR__) . '/libs/HASharedPresentationTrait.php';

final class SharedPresentationHarness
{
    use HASharedPresentationTrait;

    public function brightness(bool $isPercent, ?int $digits, ?string $suffix): array
    {
        return $this->buildSharedLightBrightnessPresentation($isPercent, $digits, $suffix);
    }

    public function colorTemp(mixed $min, mixed $max, bool $isPercent, ?int $digits, ?string $suffix): ?array
    {
        return $this->buildSharedLightColorTempSliderPresentation($min, $max, $isPercent, $digits, $suffix);
    }

    public function rgb(): array
    {
        return $this->buildSharedLightRgbColorPresentation();
    }

    public function xy(): array
    {
        return $this->buildSharedLightXyColorPresentation();
    }

    public function hs(): array
    {
        return $this->buildSharedLightHsColorPresentation();
    }

    public function temperature(mixed $min, mixed $max, mixed $step, ?int $digits, ?string $suffix): ?array
    {
        return $this->buildSharedTemperatureSliderPresentation($min, $max, $step, $digits, $suffix);
    }

    public function binarySensor(string $t, string $f, string $icon): array
    {
        return $this->buildSharedBinarySensorPresentation($t, $f, $icon);
    }

    public function fmtSuffix(string $s): ?string
    {
        return $this->sharedFormatPresentationSuffix($s);
    }
}

$fail = 0;
$check = static function (string $label, $actual, $expected) use (&$fail): void {
    $ok = $actual === $expected;
    printf("[%s] %s\n", $ok ? 'OK ' : 'FAIL', $label);
    if (!$ok) {
        $fail++;
        echo '     erwartet: ' . json_encode($expected) . "\n";
        echo '     ist:      ' . json_encode($actual) . "\n";
    }
};

$h = new SharedPresentationHarness();

// brightness: meta suffix '%' => isPercent true, ' %', USAGE_TYPE 2
$check('brightness', $h->brightness(true, null, $h->fmtSuffix('%')), [
    'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
    'MIN'          => 0,
    'MAX'          => 255,
    'STEP_SIZE'    => 1,
    'PERCENTAGE'   => true,
    'DIGITS'       => 0,
    'USAGE_TYPE'   => 2,
    'SUFFIX'       => ' %',
]);

// color_temp: meta suffix 'mired', bounded => USAGE_TYPE 1 + GRADIENT_TYPE 2
$check('color_temp (mired)', $h->colorTemp(150, 500, false, null, $h->fmtSuffix('mired')), [
    'PRESENTATION'  => VARIABLE_PRESENTATION_SLIDER,
    'MIN'           => 150.0,
    'MAX'           => 500.0,
    'STEP_SIZE'     => 1,
    'PERCENTAGE'    => false,
    'DIGITS'        => 0,
    'USAGE_TYPE'    => 1,
    'GRADIENT_TYPE' => 2,
    'SUFFIX'        => ' mired',
]);

// color_temp_kelvin: meta suffix 'K'
$check('color_temp_kelvin', $h->colorTemp(2000, 6500, false, null, $h->fmtSuffix('K')), [
    'PRESENTATION'  => VARIABLE_PRESENTATION_SLIDER,
    'MIN'           => 2000.0,
    'MAX'           => 6500.0,
    'STEP_SIZE'     => 1,
    'PERCENTAGE'    => false,
    'DIGITS'        => 0,
    'USAGE_TYPE'    => 1,
    'GRADIENT_TYPE' => 2,
    'SUFFIX'        => ' K',
]);

// color_temp without bounds => null (caller falls through)
$check('color_temp ohne Grenzen => null', $h->colorTemp(null, null, false, null, ' mired'), null);

// rgb_color: COLOR + ENCODING 0
$check('rgb_color', $h->rgb(), [
    'PRESENTATION' => VARIABLE_PRESENTATION_COLOR,
    'ENCODING'     => 0,
]);

// xy_color: COLOR + ENCODING 4 (xy)
$check('xy_color', $h->xy(), [
    'PRESENTATION' => VARIABLE_PRESENTATION_COLOR,
    'ENCODING'     => 4,
]);

// hs_color: COLOR + ENCODING 2 (HSV)
$check('hs_color', $h->hs(), [
    'PRESENTATION' => VARIABLE_PRESENTATION_COLOR,
    'ENCODING'     => 2,
]);

// climate target-temperature slider: USAGE_TYPE 0 (Temperatur) + GRADIENT_TYPE 1, no PERCENTAGE
$check('climate temperature', $h->temperature(5, 30, 0.5, 1, $h->fmtSuffix('°C')), [
    'PRESENTATION'  => VARIABLE_PRESENTATION_SLIDER,
    'MIN'           => 5.0,
    'MAX'           => 30.0,
    'STEP_SIZE'     => 0.5,
    'DIGITS'        => 1,
    'USAGE_TYPE'    => 0,
    'GRADIENT_TYPE' => 1,
    'SUFFIX'        => ' °C',
]);

// climate without bounds => null
$check('climate ohne Grenzen => null', $h->temperature(null, null, 1, 0, null), null);

// binary_sensor (device_class motion): two captions + icon, no color/icon-active
$check('binary_sensor (motion)', $h->binarySensor('Bewegung', 'Keine Bewegung', 'person-running'), [
    'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
    'OPTIONS'      => json_encode([
        ['Value' => false, 'Caption' => 'Keine Bewegung', 'IconActive' => false, 'IconValue' => '', 'ColorActive' => false, 'ColorValue' => -1],
        ['Value' => true,  'Caption' => 'Bewegung',       'IconActive' => false, 'IconValue' => '', 'ColorActive' => false, 'ColorValue' => -1],
    ], JSON_THROW_ON_ERROR),
    'ICON'         => 'person-running',
]);

// binary_sensor without device class => default captions, no ICON key (icon empty)
$check('binary_sensor (kein Icon)', $h->binarySensor('An', 'Aus', ''), [
    'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
    'OPTIONS'      => json_encode([
        ['Value' => false, 'Caption' => 'Aus', 'IconActive' => false, 'IconValue' => '', 'ColorActive' => false, 'ColorValue' => -1],
        ['Value' => true,  'Caption' => 'An',  'IconActive' => false, 'IconValue' => '', 'ColorActive' => false, 'ColorValue' => -1],
    ], JSON_THROW_ON_ERROR),
]);

echo "\n";
if ($fail === 0) {
    echo "Alle Assertions grün.\n";
    exit(0);
}
printf("%d Assertion(en) fehlgeschlagen.\n", $fail);
exit(1);
