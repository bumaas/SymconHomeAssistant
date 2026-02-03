<?php

declare(strict_types=1);

final class HALockDefinitions
{
    public const string DOMAIN = 'lock';
    public const int VARIABLE_TYPE = VARIABLETYPE_STRING;
    public const string PRESENTATION = VARIABLE_PRESENTATION_ENUMERATION;

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

    public static function normalizeCommand(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'lock' : 'unlock';
        }
        $text = strtolower(trim((string)$value));
        return match ($text) {
            'locked', 'lock', 'lock_on' => 'lock',
            'unlocked', 'unlock', 'unlock_off' => 'unlock',
            'open', 'open_latch', 'unlatch' => 'open',
            default => ''
        };
    }
}
