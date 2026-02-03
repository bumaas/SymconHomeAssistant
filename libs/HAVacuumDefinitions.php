<?php

declare(strict_types=1);

final class HAVacuumDefinitions
{
    public const string DOMAIN = 'vacuum';
    public const int VARIABLE_TYPE = VARIABLETYPE_STRING;
    public const string PRESENTATION = VARIABLE_PRESENTATION_ENUMERATION;

    public const array STATE_OPTIONS = [
        'cleaning' => ['caption' => 'cleaning', 'icon' => 'robot'],
        'docked' => ['caption' => 'docked', 'icon' => 'house'],
        'idle' => ['caption' => 'idle', 'icon' => 'robot'],
        'paused' => ['caption' => 'paused', 'icon' => 'pause'],
        'returning' => ['caption' => 'returning', 'icon' => 'arrow-rotate-left'],
        'error' => ['caption' => 'error', 'icon' => 'triangle-exclamation']
    ];
}
