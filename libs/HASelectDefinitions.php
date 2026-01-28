<?php

declare(strict_types=1);

final class HASelectDefinitions
{
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
