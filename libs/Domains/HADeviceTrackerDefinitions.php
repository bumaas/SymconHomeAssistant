<?php

declare(strict_types=1);

final class HADeviceTrackerDefinitions
{
    public const string DOMAIN = 'device_tracker';
    public const int VARIABLE_TYPE = VARIABLETYPE_STRING;
    public const string PRESENTATION = VARIABLE_PRESENTATION_VALUE_PRESENTATION;

    public const string SOURCE_TYPE_GPS = 'gps';
    public const string SOURCE_TYPE_ROUTER = 'router';
    public const string SOURCE_TYPE_BLUETOOTH = 'bluetooth';
    public const string SOURCE_TYPE_BLUETOOTH_LE = 'bluetooth_le';

    public const array ATTRIBUTE_ORDER = [
        'source_type',
        'latitude',
        'longitude',
        'gps_accuracy',
        'altitude'
    ];

    public const array ATTRIBUTE_DEFINITIONS = [
        'source_type' => [
            'caption' => 'Source Type',
            'type' => VARIABLETYPE_STRING,
            'writable' => false
        ],
        'latitude' => [
            'caption' => 'Latitude',
            'type' => VARIABLETYPE_FLOAT,
            'writable' => false
        ],
        'longitude' => [
            'caption' => 'Longitude',
            'type' => VARIABLETYPE_FLOAT,
            'writable' => false
        ],
        'gps_accuracy' => [
            'caption' => 'GPS Accuracy',
            'type' => VARIABLETYPE_INTEGER,
            'writable' => false
        ],
        'altitude' => [
            'caption' => 'Altitude',
            'type' => VARIABLETYPE_FLOAT,
            'writable' => false
        ]
    ];
}
