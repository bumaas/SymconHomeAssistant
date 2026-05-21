<?php

declare(strict_types=1);

final class HAInputDateTimeDefinitions
{
    public const string DOMAIN = 'input_datetime';
    public const int VARIABLE_TYPE = VARIABLETYPE_INTEGER;

    public static function buildRestServicePayload(mixed $value, array $attributes = []): array
    {
        $timestamp = HADateTimeValue::parseState($value, $attributes, true, true);
        if ($timestamp === null) {
            return ['', []];
        }

        return ['set_datetime', ['timestamp' => $timestamp]];
    }
}
