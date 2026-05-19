<?php

declare(strict_types=1);

final class HAValveDefinitions
{
    public const string DOMAIN = 'valve';
    public const string PRESENTATION = VARIABLE_PRESENTATION_ENUMERATION;

    public const string ATTRIBUTE_POSITION = 'current_valve_position';
    public const string ATTRIBUTE_POSITION_ALT = 'current_position';
    public const string ATTRIBUTE_REPORTS_POSITION = 'reports_position';

    public const string PAYLOAD_POSITION = 'position';

    /** @noinspection PhpUnused */
    public const string DEVICE_CLASS_GAS = 'gas';
    /** @noinspection PhpUnused */
    public const string DEVICE_CLASS_WATER = 'water';

    public const int FEATURE_OPEN = 1;
    public const int FEATURE_CLOSE = 2;
    public const int FEATURE_SET_POSITION = 4;
    public const int FEATURE_STOP = 8;

    public const int ACTION_OPEN = 0;
    public const int ACTION_CLOSE = 1;
    public const int ACTION_STOP = 2;

    public const array STATE_OPTIONS = [
        'open' => 'Valve state: open',
        'closed' => 'Valve state: closed',
        'opening' => 'Valve state: opening',
        'closing' => 'Valve state: closing',
        'stopped' => 'Valve state: stopped'
    ];

    public const array SUPPORTED_FEATURES = [
        self::FEATURE_OPEN => 'Valve feature: Open',
        self::FEATURE_CLOSE => 'Valve feature: Close',
        self::FEATURE_SET_POSITION => 'Valve feature: Set Position',
        self::FEATURE_STOP => 'Valve feature: Stop'
    ];

    public static function normalizeCommand(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'open' : 'close';
        }
        if (is_numeric($value)) {
            return (string)(int)round((float)$value);
        }

        $text = strtolower(trim((string)$value));
        return match ($text) {
            'open', 'opened', 'opening', 'on' => 'open',
            'close', 'closed', 'closing', 'off' => 'close',
            'stop', 'stopped', 'pause' => 'stop',
            default => $text
        };
    }

    public static function buildRestServicePayload(mixed $value): array
    {
        if (is_array($value) && isset($value[self::PAYLOAD_POSITION]) && is_numeric($value[self::PAYLOAD_POSITION])) {
            return ['set_valve_position', ['position' => (float)$value[self::PAYLOAD_POSITION]]];
        }

        if (is_numeric($value)) {
            return ['set_valve_position', ['position' => (float)$value]];
        }

        if (is_bool($value)) {
            return [$value ? 'open_valve' : 'close_valve', []];
        }

        if (is_string($value)) {
            $command = strtolower(trim($value));
            return match ($command) {
                'open', 'opened', 'opening' => ['open_valve', []],
                'close', 'closed', 'closing' => ['close_valve', []],
                'stop', 'stopped' => ['stop_valve', []],
                default => ['', []],
            };
        }

        return ['', []];
    }
}
