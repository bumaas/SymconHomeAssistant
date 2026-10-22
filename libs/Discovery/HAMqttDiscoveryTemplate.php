<?php

declare(strict_types=1);

final class HAMqttDiscoveryTemplate
{
    /** @var string[] */
    private const array SUPPORTED_FILTERS = ['lower', 'upper', 'trim'];

    /** Zugriff auf ein JSON-Feld: .feld, ["feld"], ['feld'] oder [0], beliebig verkettet. */
    private const string JSON_ACCESSOR = '((?:\.\w+|\[(?:"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|\d+)])+)';

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

        $conditional = self::parseConditionalJsonTemplate($raw);
        if ($conditional !== null) {
            return $conditional + ['raw' => $raw];
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

    /**
     * Bedingte Templates, die am Ende genau ein JSON-Feld ausgeben (mitsubishi2mqtt u. a.):
     *   {{ value_json.mode if (…) else 'off' }}   → Feld mode, Rückfallwert „off“
     *   {{ value_json.room_temperature if (…) }}   → Feld room_temperature, ohne Rückfallwert
     *   {% if … %}{{ value_json.temperature }}{% elif … %}16.00{% endif %} → Feld temperature
     * Die Bedingung selbst wertet das Modul nicht aus. Beim Block bleiben Begrenzungen wie 16.00 außen
     * vor: gilt der Wert, steht er im Feld. Der Rückfallwert greift, wenn das Feld fehlt oder leer ist
     * (so prüfen die Producer mit `is defined` und `|length`).
     */
    private static function parseConditionalJsonTemplate(string $raw): ?array
    {
        $literal = '(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|-?\d+(?:\.\d+)?)';
        if (preg_match('/^{{\s*value_json' . self::JSON_ACCESSOR . '\s+if\s+.+?(?:\s+else\s+' . $literal . ')?\s*}}$/s', $raw, $matches) === 1) {
            $path = self::parseJsonAccessorPath($matches[1]);
            if ($path === []) {
                return null;
            }
            $default = isset($matches[2]) && $matches[2] !== '' ? self::parseLiteral($matches[2]) : null;
            return ['kind' => 'json_path', 'path' => $path, 'filters' => [], 'supported' => true, 'default' => $default];
        }

        if (!str_starts_with($raw, '{%')) {
            return null;
        }

        // Block: Steueranweisungen entfernen; übrig bleiben Ausgaben {{ … }} und Text dazwischen.
        $outputs = preg_split('/{%.*?%}/s', $raw);
        $path = null;
        foreach ($outputs ?: [] as $piece) {
            foreach (preg_split('/({{.*?}})/s', $piece, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
                $part = trim($part);
                if ($part === '' || is_numeric($part)) {
                    continue;
                }
                if (preg_match('/^{{\s*value_json' . self::JSON_ACCESSOR . '\s*}}$/', $part, $matches) !== 1) {
                    return null;
                }
                $candidate = self::parseJsonAccessorPath($matches[1]);
                if ($candidate === [] || ($path !== null && $candidate !== $path)) {
                    return null;
                }
                $path = $candidate;
            }
        }

        return $path === null ? null : ['kind' => 'json_path', 'path' => $path, 'filters' => [], 'supported' => true, 'default' => null];
    }

    private static function parseLiteral(string $literal): string
    {
        if ($literal[0] === '\'' || $literal[0] === '"') {
            return stripcslashes(substr($literal, 1, -1));
        }
        return $literal;
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

            if (preg_match('/\G\[(?:"((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\'\\\\]|\\\\.)*)\'|(\d+))]/A', $path, $matches, PREG_UNMATCHED_AS_NULL, $offset) === 1) {
                if ($matches[1] !== null) {
                    $segment = stripcslashes($matches[1]);
                } elseif ($matches[2] !== null) {
                    $segment = stripcslashes($matches[2]);
                } elseif ($matches[3] !== null) {
                    $segment = $matches[3];
                } else {
                    return [];
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
