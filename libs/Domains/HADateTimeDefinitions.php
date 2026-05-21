<?php

declare(strict_types=1);

final class HADateTimeDefinitions
{
    public const string DOMAIN = 'datetime';
    public const int VARIABLE_TYPE = VARIABLETYPE_INTEGER;

    public static function buildRestServicePayload(mixed $value, array $attributes = []): array
    {
        $text = HADateTimeValue::formatServiceValue($value, $attributes, true, true);
        if ($text === null) {
            return ['', []];
        }

        return ['set_value', ['datetime' => $text]];
    }
}
