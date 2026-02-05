<?php

declare(strict_types=1);

final class HAAttributeFilter
{
    /**
     * @param array<string, mixed> $attributes
     * @param array<int, string> $allowedAttributes
     * @param callable(string, string, array<string, mixed>): void|null $logger
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function filterAllowedAttributes(
        array $attributes,
        array $allowedAttributes,
        ?callable $logger = null,
        string $category = 'Attributes',
        string $message = 'Removed non-official attributes',
        array $context = []
    ): array {
        $unknownKeys = array_diff(array_keys($attributes), $allowedAttributes);
        if ($unknownKeys === []) {
            return $attributes;
        }

        foreach ($unknownKeys as $key) {
            unset($attributes[$key]);
        }

        if ($logger !== null) {
            $logger($category, $message, array_merge($context, ['Keys' => array_values($unknownKeys)]));
        }

        return $attributes;
    }
}
