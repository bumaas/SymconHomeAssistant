<?php

declare(strict_types=1);

final class HAVacuumDefinitions
{
    public const array STATE_OPTIONS = [
        'cleaning' => ['caption' => 'cleaning', 'icon' => 'robot'],
        'docked' => ['caption' => 'docked', 'icon' => 'house'],
        'idle' => ['caption' => 'idle', 'icon' => 'robot'],
        'paused' => ['caption' => 'paused', 'icon' => 'pause'],
        'returning' => ['caption' => 'returning', 'icon' => 'arrow-rotate-left'],
        'error' => ['caption' => 'error', 'icon' => 'triangle-exclamation']
    ];
}
