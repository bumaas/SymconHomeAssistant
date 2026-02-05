<?php

declare(strict_types=1);

final class HACoverDefinitions
{
    public const string DOMAIN = 'cover';
    public const int VARIABLE_TYPE = VARIABLETYPE_FLOAT;
    public const string PRESENTATION = VARIABLE_PRESENTATION_ENUMERATION;

    public const string ATTRIBUTE_POSITION = 'current_cover_position';
    public const string ATTRIBUTE_TILT_POSITION = 'current_cover_tilt_position';
    public const string ATTRIBUTE_POSITION_ALT = 'current_position';
    public const string ATTRIBUTE_TILT_POSITION_ALT = 'current_tilt_position';

    public const string PAYLOAD_POSITION = 'position';
    public const string PAYLOAD_TILT_POSITION = 'tilt_position';

    public const array STATE_OPTIONS = [
        'open' => 'Cover state: open',
        'closed' => 'Cover state: closed',
        'opening' => 'Cover state: opening',
        'closing' => 'Cover state: closing'
    ];

    public const string DEVICE_CLASS_AWNING = 'awning';
    public const string DEVICE_CLASS_BLIND = 'blind';
    public const string DEVICE_CLASS_CURTAIN = 'curtain';
    public const string DEVICE_CLASS_DAMPER = 'damper';
    public const string DEVICE_CLASS_DOOR = 'door';
    public const string DEVICE_CLASS_GARAGE = 'garage';
    public const string DEVICE_CLASS_GATE = 'gate';
    public const string DEVICE_CLASS_SHADE = 'shade';
    public const string DEVICE_CLASS_SHUTTER = 'shutter';
    public const string DEVICE_CLASS_WINDOW = 'window';

    public const array DEVICE_CLASSES = [
        self::DEVICE_CLASS_AWNING,
        self::DEVICE_CLASS_BLIND,
        self::DEVICE_CLASS_CURTAIN,
        self::DEVICE_CLASS_DAMPER,
        self::DEVICE_CLASS_DOOR,
        self::DEVICE_CLASS_GARAGE,
        self::DEVICE_CLASS_GATE,
        self::DEVICE_CLASS_SHADE,
        self::DEVICE_CLASS_SHUTTER,
        self::DEVICE_CLASS_WINDOW
    ];

    public const array SHUTTER_PRESENTATION_CLASSES = [
        self::DEVICE_CLASS_BLIND,
        self::DEVICE_CLASS_SHUTTER
    ];

    public static function usesShutterPresentation(string $deviceClass): bool
    {
        return in_array($deviceClass, self::SHUTTER_PRESENTATION_CLASSES, true);
    }

    public const array SUPPORTED_FEATURES = [
        1 => 'Cover feature: Open',
        2 => 'Cover feature: Close',
        4 => 'Cover feature: Set Position',
        8 => 'Cover feature: Stop',
        16 => 'Cover feature: Open Tilt',
        32 => 'Cover feature: Close Tilt',
        64 => 'Cover feature: Set Tilt Position',
        128 => 'Cover feature: Stop Tilt'
    ];

    public const array ATTRIBUTE_DEFINITIONS = [
        self::ATTRIBUTE_POSITION => [
            'caption' => 'Position',
            'type' => VARIABLETYPE_FLOAT,
            'suffix' => '%',
            'payload_key' => self::PAYLOAD_POSITION,
            'writable' => true
        ],
        self::ATTRIBUTE_TILT_POSITION => [
            'caption' => 'Tilt Position',
            'type' => VARIABLETYPE_FLOAT,
            'suffix' => '%',
            'payload_key' => self::PAYLOAD_TILT_POSITION,
            'writable' => true
        ],
        self::ATTRIBUTE_POSITION_ALT => [
            'caption' => 'Position',
            'type' => VARIABLETYPE_FLOAT,
            'suffix' => '%',
            'payload_key' => self::PAYLOAD_POSITION,
            'writable' => true
        ],
        self::ATTRIBUTE_TILT_POSITION_ALT => [
            'caption' => 'Tilt Position',
            'type' => VARIABLETYPE_FLOAT,
            'suffix' => '%',
            'payload_key' => self::PAYLOAD_TILT_POSITION,
            'writable' => true
        ]
    ];

    public const array ALLOWED_ATTRIBUTES = [
        self::ATTRIBUTE_POSITION,
        self::ATTRIBUTE_TILT_POSITION,
        self::ATTRIBUTE_POSITION_ALT,
        self::ATTRIBUTE_TILT_POSITION_ALT,
        'device_class',
        'supported_features'
    ];

    public static function normalizeCommand(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'open' : 'close';
        }
        if (is_numeric($value)) {
            return (string)(int)$value;
        }
        $text = strtolower(trim((string)$value));
        return match ($text) {
            'open', 'opening', 'opened', 'on' => 'open',
            'close', 'closed', 'closing', 'off' => 'close',
            'stop', 'stopped', 'pause' => 'stop',
            'open_tilt', 'tilt_open' => 'open_tilt',
            'close_tilt', 'tilt_close' => 'close_tilt',
            'stop_tilt', 'tilt_stop' => 'stop_tilt',
            default => $text
        };
    }
}
