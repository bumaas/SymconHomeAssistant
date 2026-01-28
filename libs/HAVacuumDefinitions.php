<?php

declare(strict_types=1);

final class HAVacuumDefinitions
{
    public const array STATE_OPTIONS = [
        'cleaning' => 'cleaning',
        'docked' => 'docked',
        'idle' => 'idle',
        'paused' => 'paused',
        'returning' => 'returning',
        'error' => 'error'
    ];
}
