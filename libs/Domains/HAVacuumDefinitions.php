<?php

declare(strict_types=1);

final class HAVacuumDefinitions
{
    public const string DOMAIN = 'vacuum';
    public const int VARIABLE_TYPE = VARIABLETYPE_STRING;
    public const string PRESENTATION = VARIABLE_PRESENTATION_ENUMERATION;

    public const int ACTION_START = 0;
    public const int ACTION_STOP = 1;
    public const int ACTION_PAUSE = 2;
    public const int ACTION_RETURN_HOME = 3;
    public const int ACTION_CLEAN_SPOT = 4;
    public const int ACTION_LOCATE = 5;

    // Home Assistant VacuumEntityFeature (current bitset)
    public const int FEATURE_TURN_ON = 1;
    public const int FEATURE_TURN_OFF = 2;
    public const int FEATURE_PAUSE = 4;
    public const int FEATURE_STOP = 8;
    public const int FEATURE_RETURN_HOME = 16;
    public const int FEATURE_FAN_SPEED = 32;
    public const int FEATURE_BATTERY = 64;
    public const int FEATURE_STATUS = 128;
    public const int FEATURE_SEND_COMMAND = 256;
    public const int FEATURE_LOCATE = 512;
    public const int FEATURE_CLEAN_SPOT = 1024;
    public const int FEATURE_MAP = 2048;
    public const int FEATURE_STATE = 4096;
    public const int FEATURE_START = 8192;

    public const array STATE_OPTIONS = [
        'cleaning' => ['caption' => 'cleaning', 'icon' => 'robot'],
        'docked' => ['caption' => 'docked', 'icon' => 'house'],
        'idle' => ['caption' => 'idle', 'icon' => 'robot'],
        'paused' => ['caption' => 'paused', 'icon' => 'pause'],
        'returning' => ['caption' => 'returning', 'icon' => 'arrow-rotate-left'],
        'error' => ['caption' => 'error', 'icon' => 'triangle-exclamation']
    ];

    public const array SUPPORTED_FEATURES = [
        self::FEATURE_TURN_ON => 'Vacuum feature: Turn On',
        self::FEATURE_TURN_OFF => 'Vacuum feature: Turn Off',
        self::FEATURE_PAUSE => 'Vacuum feature: Pause',
        self::FEATURE_STOP => 'Vacuum feature: Stop',
        self::FEATURE_RETURN_HOME => 'Vacuum feature: Return Home',
        self::FEATURE_FAN_SPEED => 'Vacuum feature: Fan Speed',
        self::FEATURE_BATTERY => 'Vacuum feature: Battery',
        self::FEATURE_STATUS => 'Vacuum feature: Status',
        self::FEATURE_SEND_COMMAND => 'Vacuum feature: Send Command',
        self::FEATURE_LOCATE => 'Vacuum feature: Locate',
        self::FEATURE_CLEAN_SPOT => 'Vacuum feature: Clean Spot',
        self::FEATURE_MAP => 'Vacuum feature: Map',
        self::FEATURE_STATE => 'Vacuum feature: State',
        self::FEATURE_START => 'Vacuum feature: Start'
    ];

    // Map MQTT "set" payloads to HA vacuum services/data.
    public static function buildRestServicePayload(mixed $value): array
    {
        if (is_array($value)) {
            if (isset($value['fan_speed'])) {
                return ['set_fan_speed', ['fan_speed' => (string)$value['fan_speed']]];
            }
            if (isset($value['command'])) {
                $data = ['command' => (string)$value['command']];
                if (isset($value['params'])) {
                    $data['params'] = $value['params'];
                }
                return ['send_command', $data];
            }
        }

        if (is_bool($value)) {
            return [$value ? 'start' : 'stop', []];
        }

        $command = strtolower(trim((string)$value));
        if ($command === 'clean' || $command === 'start' || $command === 'on') {
            return ['start', []];
        }
        if ($command === 'stop' || $command === 'off') {
            return ['stop', []];
        }
        if ($command === 'pause') {
            return ['pause', []];
        }
        if ($command === 'return' || $command === 'return_to_base' || $command === 'dock' || $command === 'home') {
            return ['return_to_base', []];
        }
        if ($command === 'clean_spot' || $command === 'spot') {
            return ['clean_spot', []];
        }
        if ($command === 'locate') {
            return ['locate', []];
        }
        if ($command !== '') {
            return ['set_fan_speed', ['fan_speed' => $command]];
        }

        return ['', []];
    }
}
