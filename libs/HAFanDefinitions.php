<?php

declare(strict_types=1);

final class HAFanDefinitions
{
    public const string DOMAIN = 'fan';
    public const int VARIABLE_TYPE = VARIABLETYPE_BOOLEAN;
    public const string PRESENTATION = VARIABLE_PRESENTATION_SWITCH;

    public const int FEATURE_SET_SPEED = 1;
    public const int FEATURE_OSCILLATE = 2;
    public const int FEATURE_DIRECTION = 4;
    public const int FEATURE_PRESET_MODE = 8;
    public const int FEATURE_TURN_ON = 16;
    public const int FEATURE_TURN_OFF = 32;

    public const array SUPPORTED_FEATURES = [
        self::FEATURE_DIRECTION => 'Fan feature: Direction',
        self::FEATURE_OSCILLATE => 'Fan feature: Oscillate',
        self::FEATURE_PRESET_MODE => 'Fan feature: Preset Mode',
        self::FEATURE_SET_SPEED => 'Fan feature: Set Speed',
        self::FEATURE_TURN_OFF => 'Fan feature: Turn Off',
        self::FEATURE_TURN_ON => 'Fan feature: Turn On'
    ];

    public const array ATTRIBUTE_DEFINITIONS = [
        'percentage' => [
            'caption' => 'Speed',
            'type' => VARIABLETYPE_INTEGER,
            'writable' => true,
            'min' => 0,
            'max' => 100,
            'step_size' => 1,
            'percentage' => true,
            'requires_features' => [self::FEATURE_SET_SPEED]
        ],
        'oscillating' => [
            'caption' => 'Oscillating',
            'type' => VARIABLETYPE_BOOLEAN,
            'writable' => true,
            'requires_features' => [self::FEATURE_OSCILLATE]
        ],
        'preset_mode' => [
            'caption' => 'Preset Mode Fan',
            'type' => VARIABLETYPE_STRING,
            'writable' => true,
            'requires_features' => [self::FEATURE_PRESET_MODE]
        ],
        'direction' => [
            'caption' => 'Direction',
            'type' => VARIABLETYPE_STRING,
            'writable' => true,
            'requires_features' => [self::FEATURE_DIRECTION]
        ],
        'current_direction' => [
            'caption' => 'Current Direction',
            'type' => VARIABLETYPE_STRING,
            'writable' => false
        ]
    ];

    public const array ATTRIBUTE_REFRESH_TRIGGERS = [
        'preset_mode' => ['preset_modes'],
        'direction' => ['direction_list'],
        'current_direction' => ['direction_list']
    ];

    public const array ACTION_STATE_REFRESH_TRIGGERS = [
        'preset_mode' => ['preset_modes', 'supported_features'],
        'direction' => ['direction_list', 'supported_features']
    ];

    public const array ATTRIBUTE_ORDER = [
        'status',
        'percentage',
        'oscillating',
        'preset_mode',
        'direction',
        'current_direction'
    ];
}
