<?php

declare(strict_types=1);

final class HAClimateDefinitions
{
    public const string DOMAIN = 'climate';
    public const int VARIABLE_TYPE = VARIABLETYPE_FLOAT;

    public const string ATTRIBUTE_CURRENT_TEMPERATURE = 'current_temperature';
    public const string ATTRIBUTE_TARGET_TEMPERATURE = 'target_temperature';
    public const string ATTRIBUTE_TARGET_TEMPERATURE_LOW = 'target_temperature_low';
    public const string ATTRIBUTE_TARGET_TEMPERATURE_HIGH = 'target_temperature_high';
    public const string ATTRIBUTE_TARGET_TEMPERATURE_STEP = 'target_temperature_step';
    public const string ATTRIBUTE_TEMPERATURE_UNIT = 'temperature_unit';
    public const string ATTRIBUTE_MIN_TEMP = 'min_temp';
    public const string ATTRIBUTE_MAX_TEMP = 'max_temp';
    public const string ATTRIBUTE_CURRENT_HUMIDITY = 'current_humidity';
    public const string ATTRIBUTE_TARGET_HUMIDITY = 'target_humidity';
    public const string ATTRIBUTE_MIN_HUMIDITY = 'min_humidity';
    public const string ATTRIBUTE_MAX_HUMIDITY = 'max_humidity';
    public const string ATTRIBUTE_HVAC_MODE = 'hvac_mode';
    public const string ATTRIBUTE_HVAC_MODES = 'hvac_modes';
    public const string ATTRIBUTE_HVAC_ACTION = 'hvac_action';
    public const string ATTRIBUTE_PRESET_MODE = 'preset_mode';
    public const string ATTRIBUTE_PRESET_MODES = 'preset_modes';
    public const string ATTRIBUTE_FAN_MODE = 'fan_mode';
    public const string ATTRIBUTE_FAN_MODES = 'fan_modes';
    public const string ATTRIBUTE_SWING_MODE = 'swing_mode';
    public const string ATTRIBUTE_SWING_MODES = 'swing_modes';
    public const string ATTRIBUTE_SWING_HORIZONTAL_MODE = 'swing_horizontal_mode';
    public const string ATTRIBUTE_SWING_HORIZONTAL_MODES = 'swing_horizontal_modes';
    public const string ATTRIBUTE_SUPPORTED_FEATURES = 'supported_features';

    public const array SUPPORTED_FEATURES = [
        1 => 'Target Temp',
        2 => 'Target Temp Range',
        4 => 'Target Humidity',
        8 => 'Fan Mode',
        16 => 'Preset Mode',
        32 => 'Swing Mode',
        64 => 'Swing Horizontal Mode',
        128 => 'Turn On',
        256 => 'Turn Off'
    ];

    public const array ATTRIBUTE_DEFINITIONS = [
        self::ATTRIBUTE_CURRENT_TEMPERATURE => ['caption' => 'Current Temperature', 'type' => VARIABLETYPE_FLOAT, 'suffix' => '', 'digits' => 1, 'writable' => false],
        self::ATTRIBUTE_TARGET_TEMPERATURE_LOW => ['caption' => 'Target Temperature Low', 'type' => VARIABLETYPE_FLOAT, 'suffix' => '', 'writable' => false],
        self::ATTRIBUTE_TARGET_TEMPERATURE_HIGH => ['caption' => 'Target Temperature High', 'type' => VARIABLETYPE_FLOAT, 'suffix' => '', 'writable' => false],
        self::ATTRIBUTE_CURRENT_HUMIDITY => ['caption' => 'Current Humidity', 'type' => VARIABLETYPE_FLOAT, 'suffix' => '%', 'writable' => false],
        self::ATTRIBUTE_TARGET_HUMIDITY => ['caption' => 'Target Humidity', 'type' => VARIABLETYPE_FLOAT, 'suffix' => '%', 'writable' => false],
        self::ATTRIBUTE_HVAC_MODE => ['caption' => 'HVAC Mode', 'type' => VARIABLETYPE_STRING, 'suffix' => '', 'writable' => false],
        self::ATTRIBUTE_HVAC_ACTION => ['caption' => 'HVAC Action', 'type' => VARIABLETYPE_STRING, 'suffix' => '', 'writable' => false],
        self::ATTRIBUTE_PRESET_MODE => ['caption' => 'Preset', 'type' => VARIABLETYPE_STRING, 'suffix' => '', 'writable' => false],
        self::ATTRIBUTE_FAN_MODE => ['caption' => 'Fan Mode', 'type' => VARIABLETYPE_STRING, 'suffix' => '', 'writable' => false],
        self::ATTRIBUTE_SWING_MODE => ['caption' => 'Swing Mode', 'type' => VARIABLETYPE_STRING, 'suffix' => '', 'writable' => false],
        self::ATTRIBUTE_SWING_HORIZONTAL_MODE => ['caption' => 'Swing Horizontal Mode', 'type' => VARIABLETYPE_STRING, 'suffix' => '', 'writable' => false]
    ];

    public const array ALLOWED_ATTRIBUTES = [
        self::ATTRIBUTE_CURRENT_TEMPERATURE,
        self::ATTRIBUTE_TARGET_TEMPERATURE,
        self::ATTRIBUTE_TARGET_TEMPERATURE_LOW,
        self::ATTRIBUTE_TARGET_TEMPERATURE_HIGH,
        self::ATTRIBUTE_TARGET_TEMPERATURE_STEP,
        self::ATTRIBUTE_TEMPERATURE_UNIT,
        self::ATTRIBUTE_MIN_TEMP,
        self::ATTRIBUTE_MAX_TEMP,
        self::ATTRIBUTE_CURRENT_HUMIDITY,
        self::ATTRIBUTE_TARGET_HUMIDITY,
        self::ATTRIBUTE_MIN_HUMIDITY,
        self::ATTRIBUTE_MAX_HUMIDITY,
        self::ATTRIBUTE_HVAC_MODE,
        self::ATTRIBUTE_HVAC_MODES,
        self::ATTRIBUTE_HVAC_ACTION,
        self::ATTRIBUTE_PRESET_MODE,
        self::ATTRIBUTE_PRESET_MODES,
        self::ATTRIBUTE_FAN_MODE,
        self::ATTRIBUTE_FAN_MODES,
        self::ATTRIBUTE_SWING_MODE,
        self::ATTRIBUTE_SWING_MODES,
        self::ATTRIBUTE_SWING_HORIZONTAL_MODE,
        self::ATTRIBUTE_SWING_HORIZONTAL_MODES,
        self::ATTRIBUTE_SUPPORTED_FEATURES
    ];
}
