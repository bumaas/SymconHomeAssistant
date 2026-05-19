<?php

declare(strict_types=1);

final class HAMqttDiscoveryTemplate
{
    /** @var string[] */
    private const array SUPPORTED_FILTERS = ['lower', 'upper', 'trim'];

    public static function parseValueTemplate(?string $template): ?array
    {
        if (!is_string($template)) {
            return null;
        }

        $raw = trim($template);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/^{{\s*value_json((?:\.\w+|\[(?:"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|\d+)])+)\s*((?:\|\s*[A-Za-z_]+\s*)*)}}$/', $raw, $matches) === 1) {
            $filters = self::parseFilters($matches[2] ?? '');
            $path = self::parseJsonAccessorPath($matches[1]);
            if ($path === []) {
                return [
                    'kind'      => 'raw_template',
                    'path'      => [],
                    'filters'   => [],
                    'supported' => false,
                    'raw'       => $raw
                ];
            }

            return [
                'kind'      => 'json_path',
                'path'      => $path,
                'filters'   => $filters,
                'supported' => self::areFiltersSupported($filters),
                'raw'       => $raw
            ];
        }

        if (preg_match('/^{{\s*value\s*((?:\|\s*[A-Za-z_]+\s*)*)}}$/', $raw, $matches) === 1) {
            $filters = self::parseFilters($matches[1] ?? '');
            return [
                'kind'      => 'raw_value',
                'path'      => [],
                'filters'   => $filters,
                'supported' => self::areFiltersSupported($filters),
                'raw'       => $raw
            ];
        }

        return [
            'kind'      => 'raw_template',
            'path'      => [],
            'filters'   => [],
            'supported' => false,
            'raw'       => $raw
        ];
    }

    public static function parseCommandTemplate(?string $template): ?array
    {
        if (!is_string($template)) {
            return null;
        }

        $raw = trim($template);
        if ($raw === '') {
            return null;
        }

        $placeholderPattern = '/{{\s*value\s*}}/';
        $placeholderCount = preg_match_all($placeholderPattern, $raw);
        if ($placeholderCount === 1) {
            $withoutValuePlaceholder = preg_replace($placeholderPattern, '', $raw, 1);
            if (is_string($withoutValuePlaceholder) && !str_contains($withoutValuePlaceholder, '{{')) {
                return [
                    'kind'      => 'value_embed',
                    'supported' => true,
                    'raw'       => $raw
                ];
            }
        }

        return [
            'kind'      => 'raw_template',
            'supported' => false,
            'raw'       => $raw
        ];
    }

    private static function parseJsonAccessorPath(string $path): array
    {
        $segments = [];
        $offset = 0;
        $length = strlen($path);

        while ($offset < $length) {
            if (preg_match('/\G\.(\w+)/A', $path, $matches, 0, $offset) === 1) {
                $segments[] = $matches[1];
                $offset += strlen($matches[0]);
                continue;
            }

            if (preg_match('/\G\[(?:"((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\'\\\\]|\\\\.)*)\'|(\d+))]/A', $path, $matches, 0, $offset) === 1) {
                $segment = $matches[3];
                if ($matches[1] !== '') {
                    $segment = stripcslashes($matches[1]);
                } elseif ($matches[2] !== '') {
                    $segment = stripcslashes($matches[2]);
                }
                $segments[] = $segment;
                $offset += strlen($matches[0]);
                continue;
            }

            return [];
        }

        return $segments;
    }

    private static function parseFilters(string $filters): array
    {
        $result = [];
        if ($filters === '') {
            return $result;
        }

        foreach (explode('|', $filters) as $filter) {
            $filter = trim($filter);
            if ($filter === '') {
                continue;
            }
            $result[] = $filter;
        }

        return $result;
    }

    private static function areFiltersSupported(array $filters): bool
    {
        return array_all($filters, static fn(string $filter): bool => in_array($filter, self::SUPPORTED_FILTERS, true));
    }
}
