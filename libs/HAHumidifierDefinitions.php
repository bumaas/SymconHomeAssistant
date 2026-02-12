<?php

declare(strict_types=1);

final class HAHumidifierDefinitions
{
    public const string DOMAIN = 'humidifier';
    public const int VARIABLE_TYPE = VARIABLETYPE_BOOLEAN;
    public const string PRESENTATION = VARIABLE_PRESENTATION_SWITCH;

    public const int FEATURE_MODES = 1;

    public const array SUPPORTED_FEATURES = [
        self::FEATURE_MODES => 'Humidifier feature: Modes'
    ];

    public const array ATTRIBUTE_DEFINITIONS = [
        'target_humidity' => [
            'caption' => 'Target Humidity',
            'type' => VARIABLETYPE_FLOAT,
            'writable' => true
        ],
        'current_humidity' => [
            'caption' => 'Current Humidity',
            'type' => VARIABLETYPE_FLOAT,
            'writable' => false
        ],
        'mode' => [
            'caption' => 'Mode',
            'type' => VARIABLETYPE_STRING,
            'writable' => true,
            'requires_features' => [self::FEATURE_MODES]
        ],
        'action' => [
            'caption' => 'Action',
            'type' => VARIABLETYPE_STRING,
            'writable' => false
        ]
    ];

    public const array ATTRIBUTE_ORDER = [
        'status',
        'target_humidity',
        'current_humidity',
        'mode',
        'action'
    ];
}
