<?php

declare(strict_types=1);

final class HARestPayloadBuilder
{
    public static function buildBooleanTogglePayload(mixed $value, string $onService, string $offService): array
    {
        if (is_bool($value)) {
            return [$value ? $onService : $offService, []];
        }

        $command = strtolower(trim((string)$value));
        return match ($command) {
            'on', 'turn_on' => [$onService, []],
            'off', 'turn_off' => [$offService, []],
            default => ['', []],
        };
    }

    public static function buildSimpleValuePayload(mixed $value, string $service, string $key, string $type = 'string'): array
    {
        $normalized = self::normalizeValue($value, $type);
        if ($normalized === null) {
            return ['', []];
        }

        return [$service, [$key => $normalized]];
    }

    public static function buildServiceOnlyPayload(string $service): array
    {
        return [$service, []];
    }

    private static function normalizeValue(mixed $value, string $type): mixed
    {
        return match ($type) {
            'string' => self::normalizeString($value),
            'int' => is_numeric($value) ? (int)$value : null,
            'float' => is_numeric($value) ? (float)$value : null,
            'bool' => is_bool($value) ? $value : null,
            default => null,
        };
    }

    private static function normalizeString(mixed $value): ?string
    {
        $text = trim((string)$value);
        return $text !== '' ? $text : null;
    }
}
