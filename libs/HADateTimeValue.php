<?php

declare(strict_types=1);

final class HADateTimeValue
{
    public static function resolveCapabilities(
        array $attributes = [],
        ?string $state = null,
        bool $defaultDate = true,
        bool $defaultTime = true
    ): array {
        $hasDate = self::normalizeBooleanAttribute($attributes['has_date'] ?? null);
        $hasTime = self::normalizeBooleanAttribute($attributes['has_time'] ?? null);

        if ($hasDate === null && $hasTime === null && is_string($state)) {
            [$detectedDate, $detectedTime] = self::detectCapabilitiesFromState($state);
            $hasDate = $detectedDate;
            $hasTime = $detectedTime;
        }

        $hasDate ??= $defaultDate;
        $hasTime ??= $defaultTime;

        if (!$hasDate && !$hasTime) {
            $hasDate = $defaultDate;
            $hasTime = $defaultTime;
        }

        return [
            'has_date' => $hasDate,
            'has_time' => $hasTime
        ];
    }

    public static function parseState(
        mixed $value,
        array $attributes = [],
        bool $defaultDate = true,
        bool $defaultTime = true
    ): ?int {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int)round($value);
        }

        $text = trim((string)$value);
        if ($text === '') {
            return null;
        }
        if (is_numeric($text)) {
            return (int)$text;
        }

        $capabilities = self::resolveCapabilities($attributes, $text, $defaultDate, $defaultTime);
        $hasDate = $capabilities['has_date'];
        $hasTime = $capabilities['has_time'];

        if ($hasDate && $hasTime) {
            return self::parseDateTimeString($text);
        }
        if ($hasDate) {
            return self::parseDateString($text);
        }
        if ($hasTime) {
            return self::parseTimeString($text);
        }

        return null;
    }

    public static function formatServiceValue(
        mixed $value,
        array $attributes = [],
        bool $defaultDate = true,
        bool $defaultTime = true
    ): ?string {
        $timestamp = self::parseState($value, $attributes, $defaultDate, $defaultTime);
        if ($timestamp === null) {
            return null;
        }

        $text = is_string($value) ? trim($value) : null;
        $capabilities = self::resolveCapabilities($attributes, $text, $defaultDate, $defaultTime);
        $dateTime = self::createLocalDateTimeFromTimestamp($timestamp);

        if ($capabilities['has_date'] && $capabilities['has_time']) {
            return $dateTime->format('Y-m-d H:i:s');
        }
        if ($capabilities['has_date']) {
            return $dateTime->format('Y-m-d');
        }
        if ($capabilities['has_time']) {
            return $dateTime->format('H:i:s');
        }

        return null;
    }

    private static function detectCapabilitiesFromState(string $state): array
    {
        $trimmed = trim($state);
        if ($trimmed === '') {
            return [null, null];
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) === 1) {
            return [true, false];
        }
        if (preg_match('/^\d{2}:\d{2}(?::\d{2})?$/', $trimmed) === 1) {
            return [false, true];
        }

        return [true, true];
    }

    private static function normalizeBooleanAttribute(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return ((int)$value) !== 0;
        }
        if (!is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));
        return match ($normalized) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => null,
        };
    }

    private static function parseDateTimeString(string $value): ?int
    {
        try {
            $dateTime = new DateTimeImmutable($value, self::getLocalTimeZone());
        } catch (Exception) {
            return null;
        }

        return $dateTime->getTimestamp();
    }

    private static function parseDateString(string $value): ?int
    {
        $dateTime = DateTimeImmutable::createFromFormat('!Y-m-d', $value, self::getLocalTimeZone());
        return $dateTime instanceof DateTimeImmutable ? $dateTime->getTimestamp() : null;
    }

    private static function parseTimeString(string $value): ?int
    {
        foreach (['!H:i:s', '!H:i'] as $format) {
            $dateTime = DateTimeImmutable::createFromFormat($format, $value, self::getLocalTimeZone());
            if ($dateTime instanceof DateTimeImmutable) {
                return $dateTime->getTimestamp();
            }
        }

        return null;
    }

    private static function createLocalDateTimeFromTimestamp(int $timestamp): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone(self::getLocalTimeZone());
    }

    private static function getLocalTimeZone(): DateTimeZone
    {
        try {
            return new DateTimeZone(date_default_timezone_get());
        } catch (Exception) {
            return new DateTimeZone('UTC');
        }
    }
}
