<?php

declare(strict_types=1);

final class HASelectDefinitions
{
    public const string DOMAIN = 'select';
    public const int VARIABLE_TYPE = VARIABLETYPE_STRING;
    public const string PRESENTATION = VARIABLE_PRESENTATION_ENUMERATION;

    public static function normalizeSelection(mixed $value, mixed $options): ?string
    {
        $text = trim((string)$value);
        if ($text === '') {
            return null;
        }
        $normalizedOptions = self::normalizeOptions($options);
        if ($normalizedOptions === []) {
            return $text;
        }
        if (in_array($text, $normalizedOptions, true)) {
            return $text;
        }
        return null;
    }

    public static function normalizeOptions(mixed $options): array
    {
        if (!is_array($options)) {
            return [];
        }
        $result = [];
        foreach ($options as $option) {
            if (is_scalar($option)) {
                $result[] = (string)$option;
            }
        }
        return array_values(array_unique($result));
    }
}
