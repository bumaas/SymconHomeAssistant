<?php

declare(strict_types=1);

final class HALockDefinitions
{
    public const int FEATURE_OPEN = 1;

    public const array STATE_OPTIONS = [
        'locked' => ['caption' => 'locked', 'command' => 'lock'],
        'unlocking' => ['caption' => 'unlocking', 'command' => null],
        'unlocked' => ['caption' => 'unlocked', 'command' => 'unlock'],
        'locking' => ['caption' => 'locking', 'command' => null],
        'jammed' => ['caption' => 'jammed', 'command' => null],
        'opening' => ['caption' => 'opening', 'command' => null],
        'open' => ['caption' => 'open', 'command' => 'open']
    ];
}
