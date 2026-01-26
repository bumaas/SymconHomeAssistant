<?php

declare(strict_types=1);

final class HALightDefinitions
{
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
        'color_mode'             => ['caption' => 'Color Mode', 'type' => VARIABLETYPE_STRING, 'profile' => '', 'suffix' => 'mode', 'writable' => false],
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
}
