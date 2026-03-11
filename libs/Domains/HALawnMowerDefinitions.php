<?php

declare(strict_types=1);

final class HALawnMowerDefinitions
{
    public const string DOMAIN = 'lawn_mower';
    public const int VARIABLE_TYPE = VARIABLETYPE_STRING;
    public const string PRESENTATION = VARIABLE_PRESENTATION_VALUE_PRESENTATION;

    public const int ACTION_START_MOWING = 0;
    public const int ACTION_PAUSE = 1;
    public const int ACTION_DOCK = 2;

    // Home Assistant LawnMowerEntityFeature
    public const int FEATURE_START_MOWING = 1;
    public const int FEATURE_PAUSE = 2;
    public const int FEATURE_DOCK = 4;

    public const array STATE_OPTIONS = [
        'mowing' => ['caption' => 'mowing', 'icon' => 'leaf'],
        'docked' => ['caption' => 'docked', 'icon' => 'house'],
        'paused' => ['caption' => 'paused', 'icon' => 'pause'],
        'returning' => ['caption' => 'returning', 'icon' => 'arrow-rotate-left'],
        'error' => ['caption' => 'error', 'icon' => 'triangle-exclamation']
    ];

    public const array SUPPORTED_FEATURES = [
        self::FEATURE_START_MOWING => 'Lawn mower feature: Start Mowing',
        self::FEATURE_PAUSE => 'Lawn mower feature: Pause',
        self::FEATURE_DOCK => 'Lawn mower feature: Dock'
    ];

    public static function buildRestServicePayload(mixed $value): array
    {
        if (is_bool($value)) {
            return [$value ? 'start_mowing' : 'dock', []];
        }

        $command = strtolower(trim((string)$value));
        if ($command === 'start' || $command === 'start_mowing' || $command === 'on') {
            return ['start_mowing', []];
        }
        if ($command === 'pause') {
            return ['pause', []];
        }
        if ($command === 'dock' || $command === 'return' || $command === 'return_to_base' || $command === 'home' || $command === 'stop' || $command === 'off') {
            return ['dock', []];
        }

        return ['', []];
    }
}
