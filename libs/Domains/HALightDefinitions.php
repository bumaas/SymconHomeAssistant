<?php

declare(strict_types=1);

final class HALightDefinitions
{
    public const string DOMAIN = 'light';
    public const int VARIABLE_TYPE = VARIABLETYPE_BOOLEAN;
    public const string PRESENTATION = VARIABLE_PRESENTATION_SWITCH;

    public const array ATTRIBUTE_ORDER = [
        'brightness',
        'color_temp',
        'color_temp_kelvin',
        'color_mode',
        'transition',
        'rgb_color',
        'rgbw_color',
        'rgbww_color',
        'hs_color',
        'xy_color'
    ];

    public const array SUPPORTED_FEATURES = [
        4  => 'Effect',
        8  => 'Flash',
        32 => 'Transition'
    ];

    public const array ATTRIBUTE_DEFINITIONS = [
        'brightness'             => [
            'caption' => 'Brightness',
            'type' => VARIABLETYPE_INTEGER,
            'profile' => '',
            'suffix' => '%',
            'writable' => true,
            'requires_color_modes' => ['brightness', 'color_temp', 'hs', 'xy', 'rgb', 'rgbw', 'rgbww', 'white']
        ],
        'color_mode'             => ['caption' => 'Color Mode', 'type' => VARIABLETYPE_STRING, 'profile' => '', 'suffix' => '', 'writable' => false],
        'color_temp'             => [
            'caption' => 'Color Temp (Mired)',
            'type' => VARIABLETYPE_INTEGER,
            'profile' => '',
            'suffix' => 'mired',
            'writable' => true,
            'requires_color_modes' => ['color_temp']
        ],
        'color_temp_kelvin'      => [
            'caption' => 'Color Temp K',
            'type' => VARIABLETYPE_INTEGER,
            'profile' => '',
            'suffix' => 'K',
            'writable' => true,
            'requires_color_modes' => ['color_temp']
        ],
        'effect'                 => [
            'caption' => 'Effect',
            'type' => VARIABLETYPE_STRING,
            'profile' => '',
            'writable' => true,
            'requires_features' => [4]
        ],
        'flash'                  => [
            'caption' => 'Flash',
            'type' => VARIABLETYPE_STRING,
            'profile' => '',
            'writable' => true,
            'requires_features' => [8]
        ],
        'hs_color'               => [
            'caption' => 'HS Color',
            'type' => VARIABLETYPE_STRING,
            'profile' => '',
            'writable' => true,
            'requires_color_modes' => ['hs']
        ],
        'rgb_color'              => [
            'caption' => 'RGB Color',
            'type' => VARIABLETYPE_STRING,
            'profile' => '',
            'writable' => true,
            'requires_color_modes' => ['rgb']
        ],
        'rgbw_color'             => [
            'caption' => 'RGBW Color',
            'type' => VARIABLETYPE_STRING,
            'profile' => '',
            'writable' => true,
            'requires_color_modes' => ['rgbw']
        ],
        'rgbww_color'            => [
            'caption' => 'RGBWW Color',
            'type' => VARIABLETYPE_STRING,
            'profile' => '',
            'writable' => true,
            'requires_color_modes' => ['rgbww']
        ],
        'transition'             => [
            'caption' => 'Transition',
            'type' => VARIABLETYPE_FLOAT,
            'profile' => '',
            'suffix' => 's',
            'writable' => true,
            'requires_features' => [32]
        ],
        'xy_color'               => [
            'caption' => 'XY Color',
            'type' => VARIABLETYPE_STRING,
            'profile' => '',
            'writable' => true,
            'requires_color_modes' => ['xy']
        ],
    ];

    public const array ATTRIBUTE_REFRESH_TRIGGERS = [
        'brightness' => ['supported_color_modes'],
        'color_temp' => ['supported_color_modes', 'min_mireds', 'max_mireds'],
        'color_temp_kelvin' => ['supported_color_modes', 'min_color_temp_kelvin', 'max_color_temp_kelvin'],
        'color_mode' => ['supported_color_modes'],
        'effect' => ['supported_features', 'effect_list'],
        'flash' => ['supported_features'],
        'hs_color' => ['supported_color_modes'],
        'rgb_color' => ['supported_color_modes'],
        'rgbw_color' => ['supported_color_modes'],
        'rgbww_color' => ['supported_color_modes'],
        'transition' => ['supported_features'],
        'xy_color' => ['supported_color_modes']
    ];

    public const array ACTION_STATE_REFRESH_TRIGGERS = [
        '*' => ['supported_features', 'effect_list', 'supported_color_modes']
    ];

    /** @noinspection PhpUnused */
    public const array ALLOWED_ATTRIBUTES = [
        'brightness',
        'color_mode',
        'color_temp',
        'color_temp_kelvin',
        'effect',
        'flash',
        'hs_color',
        'rgb_color',
        'rgbw_color',
        'rgbww_color',
        'transition',
        'xy_color',
        'supported_features'
    ];

    // Map MQTT "set" payloads to HA light services/data.
    public static function buildRestServicePayload(mixed $value): array
    {
        if (is_array($value)) {
            $data = $value;
            $service = 'turn_on';
            if (array_key_exists('state', $data)) {
                $state = $data['state'];
                unset($data['state']);
                if ($state === false || $state === 0 || strtoupper((string)$state) === 'OFF') {
                    $service = 'turn_off';
                }
            }
            return [$service, self::normalizeColorTempServiceData($data)];
        }

        if (is_bool($value)) {
            return [$value ? 'turn_on' : 'turn_off', []];
        }

        return ['', []];
    }

    // HA kennt seit 2026.3 keine Mired mehr (#161777): light.turn_on weist color_temp und kelvin ab.
    // Beide werden in color_temp_kelvin übersetzt; ein nicht umrechenbarer Wert bleibt stehen, damit HA ihn meldet.
    public static function normalizeColorTempServiceData(array $data): array
    {
        if (array_key_exists('color_temp', $data)) {
            $kelvin = self::convertMiredKelvin($data['color_temp']);
            if ($kelvin !== null) {
                unset($data['color_temp']);
                $data['color_temp_kelvin'] ??= $kelvin;
            }
        }
        if (array_key_exists('kelvin', $data) && is_numeric($data['kelvin'])) {
            $data['color_temp_kelvin'] ??= (int) round((float) $data['kelvin']);
            unset($data['kelvin']);
        }
        return $data;
    }

    // Seit HA 2026.3 fehlt color_temp im Zustand; eine vorhandene Mired-Variable folgt dann dem Kelvin-Wert.
    public static function withDerivedMired(array $attributes): array
    {
        if (!array_key_exists('color_temp', $attributes) && array_key_exists('color_temp_kelvin', $attributes)) {
            $attributes['color_temp'] = self::convertMiredKelvin($attributes['color_temp_kelvin']);
        }
        return $attributes;
    }

    // Die Mired-Variable wird nur angelegt, wenn HA selbst noch Mired meldet (bis 2026.2).
    public static function reportsMired(array $attributes): bool
    {
        return array_key_exists('color_temp', $attributes)
            || array_key_exists('min_mireds', $attributes)
            || array_key_exists('max_mireds', $attributes);
    }

    // Mired und Kelvin rechnen sich in beide Richtungen über 1.000.000 / Wert um.
    public static function convertMiredKelvin(mixed $value): ?int
    {
        if (!is_numeric($value) || (float) $value <= 0.0) {
            return null;
        }
        return (int) round(1000000 / (float) $value);
    }
}
