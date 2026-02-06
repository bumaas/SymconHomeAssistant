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
}
