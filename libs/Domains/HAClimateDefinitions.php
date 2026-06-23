<?php

declare(strict_types=1);

final class HAClimateDefinitions
{
    public const string DOMAIN = 'climate';
    public const int VARIABLE_TYPE = VARIABLETYPE_FLOAT;
    public const int FEATURE_TURN_OFF = 128;
    public const int FEATURE_TURN_ON = 256;

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
        self::FEATURE_TURN_ON => 'Turn On',
        self::FEATURE_TURN_OFF => 'Turn Off',
        512 => 'Swing Horizontal Mode'
    ];

    public const array ATTRIBUTE_DEFINITIONS = [
        self::ATTRIBUTE_CURRENT_TEMPERATURE => ['caption' => 'Current Temperature', 'type' => VARIABLETYPE_FLOAT, 'suffix' => '', 'digits' => 1, 'writable' => false],
        self::ATTRIBUTE_TARGET_TEMPERATURE_LOW => ['caption' => 'Target Temperature Low', 'type' => VARIABLETYPE_FLOAT, 'suffix' => '', 'writable' => true, 'requires_features' => [2]],
        self::ATTRIBUTE_TARGET_TEMPERATURE_HIGH => ['caption' => 'Target Temperature High', 'type' => VARIABLETYPE_FLOAT, 'suffix' => '', 'writable' => true, 'requires_features' => [2]],
        self::ATTRIBUTE_CURRENT_HUMIDITY => ['caption' => 'Current Humidity', 'type' => VARIABLETYPE_FLOAT, 'suffix' => '%', 'writable' => false],
        self::ATTRIBUTE_TARGET_HUMIDITY => ['caption' => 'Target Humidity', 'type' => VARIABLETYPE_FLOAT, 'suffix' => '%', 'writable' => true, 'requires_features' => [4]],
        self::ATTRIBUTE_HVAC_MODE => ['caption' => 'HVAC Mode', 'type' => VARIABLETYPE_STRING, 'suffix' => '', 'writable' => true],
        self::ATTRIBUTE_HVAC_ACTION => ['caption' => 'HVAC Action', 'type' => VARIABLETYPE_STRING, 'suffix' => '', 'writable' => false],
        self::ATTRIBUTE_PRESET_MODE => ['caption' => 'Preset', 'type' => VARIABLETYPE_STRING, 'suffix' => '', 'writable' => true, 'requires_features' => [16]],
        self::ATTRIBUTE_FAN_MODE => ['caption' => 'Fan Mode', 'type' => VARIABLETYPE_STRING, 'suffix' => '', 'writable' => true, 'requires_features' => [8]],
        self::ATTRIBUTE_SWING_MODE => ['caption' => 'Swing Mode', 'type' => VARIABLETYPE_STRING, 'suffix' => '', 'writable' => true, 'requires_features' => [32]],
        self::ATTRIBUTE_SWING_HORIZONTAL_MODE => ['caption' => 'Swing Horizontal Mode', 'type' => VARIABLETYPE_STRING, 'suffix' => '', 'writable' => true, 'requires_features' => [512]]
    ];

    public const array ACTION_STATE_REFRESH_TRIGGERS = [
        self::ATTRIBUTE_TARGET_TEMPERATURE_LOW => [self::ATTRIBUTE_SUPPORTED_FEATURES],
        self::ATTRIBUTE_TARGET_TEMPERATURE_HIGH => [self::ATTRIBUTE_SUPPORTED_FEATURES],
        self::ATTRIBUTE_TARGET_HUMIDITY => [self::ATTRIBUTE_SUPPORTED_FEATURES],
        self::ATTRIBUTE_HVAC_MODE => [self::ATTRIBUTE_HVAC_MODES],
        self::ATTRIBUTE_PRESET_MODE => [self::ATTRIBUTE_PRESET_MODES, self::ATTRIBUTE_SUPPORTED_FEATURES],
        self::ATTRIBUTE_FAN_MODE => [self::ATTRIBUTE_FAN_MODES, self::ATTRIBUTE_SUPPORTED_FEATURES],
        self::ATTRIBUTE_SWING_MODE => [self::ATTRIBUTE_SWING_MODES, self::ATTRIBUTE_SUPPORTED_FEATURES],
        self::ATTRIBUTE_SWING_HORIZONTAL_MODE => [self::ATTRIBUTE_SWING_HORIZONTAL_MODES, self::ATTRIBUTE_SUPPORTED_FEATURES]
    ];

    public const array ATTRIBUTE_REFRESH_TRIGGERS = [
        self::ATTRIBUTE_TARGET_TEMPERATURE => [
            self::ATTRIBUTE_MIN_TEMP,
            self::ATTRIBUTE_MAX_TEMP,
            self::ATTRIBUTE_TARGET_TEMPERATURE_STEP,
            self::ATTRIBUTE_TEMPERATURE_UNIT,
            'unit_of_measurement',
            'unit',
            'display_unit',
            'native_unit_of_measurement',
            'device_class',
            'precision',
            'step',
            'native_step',
            'suggested_display_precision'
        ],
        self::ATTRIBUTE_TARGET_TEMPERATURE_LOW => [
            self::ATTRIBUTE_MIN_TEMP,
            self::ATTRIBUTE_MAX_TEMP,
            self::ATTRIBUTE_TARGET_TEMPERATURE_STEP,
            self::ATTRIBUTE_TEMPERATURE_UNIT,
            'unit_of_measurement',
            'unit',
            'display_unit',
            'native_unit_of_measurement',
            'device_class',
            'precision',
            'step',
            'native_step',
            'suggested_display_precision'
        ],
        self::ATTRIBUTE_TARGET_TEMPERATURE_HIGH => [
            self::ATTRIBUTE_MIN_TEMP,
            self::ATTRIBUTE_MAX_TEMP,
            self::ATTRIBUTE_TARGET_TEMPERATURE_STEP,
            self::ATTRIBUTE_TEMPERATURE_UNIT,
            'unit_of_measurement',
            'unit',
            'display_unit',
            'native_unit_of_measurement',
            'device_class',
            'precision',
            'step',
            'native_step',
            'suggested_display_precision'
        ],
        self::ATTRIBUTE_CURRENT_TEMPERATURE => [
            self::ATTRIBUTE_MIN_TEMP,
            self::ATTRIBUTE_MAX_TEMP,
            self::ATTRIBUTE_TARGET_TEMPERATURE_STEP,
            self::ATTRIBUTE_TEMPERATURE_UNIT,
            'unit_of_measurement',
            'unit',
            'display_unit',
            'native_unit_of_measurement',
            'device_class',
            'precision',
            'step',
            'native_step',
            'suggested_display_precision'
        ],
        self::ATTRIBUTE_TARGET_HUMIDITY => [
            self::ATTRIBUTE_MIN_HUMIDITY,
            self::ATTRIBUTE_MAX_HUMIDITY,
            'target_humidity_step'
        ],
        self::ATTRIBUTE_CURRENT_HUMIDITY => [
            self::ATTRIBUTE_MIN_HUMIDITY,
            self::ATTRIBUTE_MAX_HUMIDITY,
            'target_humidity_step'
        ],
        self::ATTRIBUTE_HVAC_MODE => [self::ATTRIBUTE_HVAC_MODES],
        self::ATTRIBUTE_PRESET_MODE => [self::ATTRIBUTE_PRESET_MODES],
        self::ATTRIBUTE_FAN_MODE => [self::ATTRIBUTE_FAN_MODES],
        self::ATTRIBUTE_SWING_MODE => [self::ATTRIBUTE_SWING_MODES],
        self::ATTRIBUTE_SWING_HORIZONTAL_MODE => [self::ATTRIBUTE_SWING_HORIZONTAL_MODES]
    ];

    /** @noinspection PhpUnused */
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

    // Map MQTT "set" payloads to HA climate services/data.
    public static function buildRestServicePayload(mixed $value): array
    {
        if (is_array($value)) {
            if (array_key_exists(self::ATTRIBUTE_HVAC_MODE, $value)) {
                return ['set_hvac_mode', [self::ATTRIBUTE_HVAC_MODE => (string)$value[self::ATTRIBUTE_HVAC_MODE]]];
            }
            if (array_key_exists(self::ATTRIBUTE_PRESET_MODE, $value)) {
                return ['set_preset_mode', [self::ATTRIBUTE_PRESET_MODE => (string)$value[self::ATTRIBUTE_PRESET_MODE]]];
            }
            if (array_key_exists(self::ATTRIBUTE_FAN_MODE, $value)) {
                return ['set_fan_mode', [self::ATTRIBUTE_FAN_MODE => (string)$value[self::ATTRIBUTE_FAN_MODE]]];
            }
            if (array_key_exists(self::ATTRIBUTE_SWING_MODE, $value)) {
                return ['set_swing_mode', [self::ATTRIBUTE_SWING_MODE => (string)$value[self::ATTRIBUTE_SWING_MODE]]];
            }
            if (array_key_exists(self::ATTRIBUTE_SWING_HORIZONTAL_MODE, $value)) {
                return ['set_swing_horizontal_mode', [self::ATTRIBUTE_SWING_HORIZONTAL_MODE => (string)$value[self::ATTRIBUTE_SWING_HORIZONTAL_MODE]]];
            }
            if (array_key_exists(self::ATTRIBUTE_TARGET_HUMIDITY, $value) || array_key_exists('humidity', $value)) {
                $humidity = $value[self::ATTRIBUTE_TARGET_HUMIDITY] ?? $value['humidity'] ?? null;
                if (is_numeric($humidity)) {
                    return ['set_humidity', ['humidity' => (float)$humidity]];
                }
            }
            if (array_key_exists(self::ATTRIBUTE_TARGET_TEMPERATURE_LOW, $value)
                || array_key_exists(self::ATTRIBUTE_TARGET_TEMPERATURE_HIGH, $value)
                || array_key_exists('temperature', $value)) {
                $data = [];
                if (array_key_exists(self::ATTRIBUTE_TARGET_TEMPERATURE_LOW, $value) && is_numeric($value[self::ATTRIBUTE_TARGET_TEMPERATURE_LOW])) {
                    $data[self::ATTRIBUTE_TARGET_TEMPERATURE_LOW] = (float)$value[self::ATTRIBUTE_TARGET_TEMPERATURE_LOW];
                }
                if (array_key_exists(self::ATTRIBUTE_TARGET_TEMPERATURE_HIGH, $value) && is_numeric($value[self::ATTRIBUTE_TARGET_TEMPERATURE_HIGH])) {
                    $data[self::ATTRIBUTE_TARGET_TEMPERATURE_HIGH] = (float)$value[self::ATTRIBUTE_TARGET_TEMPERATURE_HIGH];
                }
                if (array_key_exists('temperature', $value) && is_numeric($value['temperature'])) {
                    $data['temperature'] = (float)$value['temperature'];
                }
                if ($data !== []) {
                    return ['set_temperature', $data];
                }
            }
            return ['', []];
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === 'turn_on') {
                return ['turn_on', []];
            }
            if ($normalized === 'turn_off') {
                return ['turn_off', []];
            }
            if ($normalized === 'toggle') {
                return ['toggle', []];
            }
        }

        if (is_numeric($value)) {
            return ['set_temperature', ['temperature' => (float)$value]];
        }
        return ['', []];
    }
}
