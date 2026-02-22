<?php

declare(strict_types=1);

final class HAHumidifierDefinitions
{
    public const string DOMAIN = 'humidifier';
    public const int VARIABLE_TYPE = VARIABLETYPE_BOOLEAN;
    public const string PRESENTATION = VARIABLE_PRESENTATION_SWITCH;

    public const string ATTRIBUTE_TARGET_HUMIDITY = 'target_humidity';
    public const string ATTRIBUTE_CURRENT_HUMIDITY = 'current_humidity';
    public const string ATTRIBUTE_MODE = 'mode';
    public const string ATTRIBUTE_ACTION = 'action';

    public const int FEATURE_MODES = 1;

    public const array SUPPORTED_FEATURES = [
        self::FEATURE_MODES => 'Humidifier feature: Modes'
    ];

    public const array ATTRIBUTE_DEFINITIONS = [
        self::ATTRIBUTE_TARGET_HUMIDITY => [
            'caption' => 'Target Humidity',
            'type' => VARIABLETYPE_FLOAT,
            'writable' => true
        ],
        self::ATTRIBUTE_CURRENT_HUMIDITY => [
            'caption' => 'Current Humidity',
            'type' => VARIABLETYPE_FLOAT,
            'writable' => false
        ],
        self::ATTRIBUTE_MODE => [
            'caption' => 'Mode',
            'type' => VARIABLETYPE_STRING,
            'writable' => true,
            'requires_features' => [self::FEATURE_MODES]
        ],
        self::ATTRIBUTE_ACTION => [
            'caption' => 'Action',
            'type' => VARIABLETYPE_STRING,
            'writable' => false
        ]
    ];

    public const array ATTRIBUTE_REFRESH_TRIGGERS = [
        self::ATTRIBUTE_TARGET_HUMIDITY => ['min_humidity', 'max_humidity', 'target_humidity_step'],
        self::ATTRIBUTE_MODE => ['available_modes']
    ];

    public const array ACTION_STATE_REFRESH_TRIGGERS = [
        self::ATTRIBUTE_MODE => ['supported_features', 'available_modes']
    ];

    public const array ATTRIBUTE_ORDER = [
        'status',
        self::ATTRIBUTE_TARGET_HUMIDITY,
        self::ATTRIBUTE_CURRENT_HUMIDITY,
        self::ATTRIBUTE_MODE,
        self::ATTRIBUTE_ACTION
    ];
}
