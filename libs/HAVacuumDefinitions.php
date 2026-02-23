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

    public const array STATE_OPTIONS = [
        'cleaning' => ['caption' => 'cleaning', 'icon' => 'robot'],
        'docked' => ['caption' => 'docked', 'icon' => 'house'],
        'idle' => ['caption' => 'idle', 'icon' => 'robot'],
        'paused' => ['caption' => 'paused', 'icon' => 'pause'],
        'returning' => ['caption' => 'returning', 'icon' => 'arrow-rotate-left'],
        'error' => ['caption' => 'error', 'icon' => 'triangle-exclamation']
    ];

    public const array SUPPORTED_FEATURES = [
        1 => 'Vacuum feature: Clean Spot',
        2 => 'Vacuum feature: Fan Speed',
        4 => 'Vacuum feature: Locate',
        8 => 'Vacuum feature: Map',
        16 => 'Vacuum feature: Pause',
        32 => 'Vacuum feature: Return Home',
        64 => 'Vacuum feature: Send Command',
        128 => 'Vacuum feature: Start',
        256 => 'Vacuum feature: State',
        512 => 'Vacuum feature: Stop'
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
