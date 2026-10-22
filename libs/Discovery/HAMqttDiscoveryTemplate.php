<?php

declare(strict_types=1);

final class HAMqttDiscoveryTemplate
{
    /** @var string[] */
    private const array SUPPORTED_FILTERS = ['lower', 'upper', 'trim'];

    /** Zugriff auf ein JSON-Feld: .feld, ["feld"], ['feld'] oder [0], beliebig verkettet. */
    private const string JSON_ACCESSOR = '((?:\.\w+|\[(?:"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|\d+)])+)';

    /** Text in einfachen oder doppelten Anführungszeichen oder eine Zahl. */
    private const string LITERAL = '(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|-?\d+(?:\.\d+)?)';

    public static function parseValueTemplate(?string $template): ?array
    {
        if (!is_string($template)) {
            return null;
        }

        $raw = trim($template);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/^{{\s*value_json' . self::JSON_ACCESSOR . '\s*((?:\|\s*[A-Za-z_]+\s*)*)}}$/', $raw, $matches) === 1) {
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
     * Wert eines json_path-Templates aus dem dekodierten Payload. Bedingungen (guards) werden ausgewertet;
     * trifft eine nicht zu, gilt der Rückfallwert, ohne else null (HA gibt dann einen leeren Text aus,
     * den Klima und Sensor verwerfen). Fehlt das Feld, ebenfalls null.
     */
    public static function resolveJsonPathValue(array $template, mixed $decoded): mixed
    {
        $default = $template['default'] ?? null;
        foreach ($template['guards'] ?? [] as $guard) {
            if (!self::evaluateGuard($guard, $decoded)) {
                return $default;
            }
        }

        [$found, $value] = self::lookupPath($decoded, $template['path'] ?? []);
        return $found ? $value : null;
    }

    /**
     * Bedingte Templates, die am Ende genau ein JSON-Feld ausgeben (mitsubishi2mqtt u. a.):
     *   {{ value_json.mode if (…) else 'off' }}   → Feld mode, Rückfallwert „off“
     *   {{ value_json.room_temperature if (…) }}   → Feld room_temperature, ohne Rückfallwert
     *   {% if … %}{{ value_json.temperature }}{% elif … %}16.00{% endif %} → Feld temperature
     * Die Bedingung des Ausdrucks wertet resolveJsonPathValue aus; verstanden werden mit `and`
     * verknüpfte Prüfungen `is defined`, `|length` und Vergleiche mit einem Text oder einer Zahl
     * (optional über |int oder |float). Alles andere (or, not, Funktionen, else mit Ausdruck) ist nicht
     * unterstützt: Ein Wert ohne Bedingung wäre falsch (Licht aus, Helligkeit 120). Beim Block bleiben die
     * Bedingungen und Begrenzungen wie 16.00 außen vor: gilt der Wert, steht er im Feld.
     */
    private static function parseConditionalJsonTemplate(string $raw): ?array
    {
        if (preg_match('/^{{\s*value_json' . self::JSON_ACCESSOR . '\s+if\s+(.+?)(?:\s+else\s+' . self::LITERAL . ')?\s*}}$/s', $raw, $matches) === 1) {
            $path = self::parseJsonAccessorPath($matches[1]);
            if ($path === []) {
                return null;
            }
            $guards = self::parseCondition($matches[2]);
            $default = isset($matches[3]) && $matches[3] !== '' ? self::parseLiteral($matches[3]) : null;
            return [
                'kind'      => 'json_path',
                'path'      => $path,
                'filters'   => [],
                'supported' => $guards !== null,
                'default'   => is_string($default) ? $default : ($default === null ? null : (string)$default),
                'guards'    => $guards ?? []
            ];
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

    private static function parseLiteral(string $literal): string|int|float
    {
        if ($literal[0] === '\'' || $literal[0] === '"') {
            return stripcslashes(substr($literal, 1, -1));
        }
        return str_contains($literal, '.') ? (float)$literal : (int)$literal;
    }

    /** @return list<array>|null null = Bedingung nicht verstanden */
    private static function parseCondition(string $condition): ?array
    {
        $guards = [];
        foreach (preg_split('/\s+and\s+/', self::stripParentheses($condition)) ?: [] as $term) {
            $guard = self::parseGuard(self::stripParentheses($term));
            if ($guard === null) {
                return null;
            }
            $guards[] = $guard;
        }
        return $guards;
    }

    // Äußere Klammern nur, wenn innen keine weiteren stehen: „(a) and (b)“ wird erst je Teil entklammert.
    private static function stripParentheses(string $text): string
    {
        $text = trim($text);
        if (str_starts_with($text, '(') && str_ends_with($text, ')') && strpbrk(substr($text, 1, -1), '()') === false) {
            return trim(substr($text, 1, -1));
        }
        return $text;
    }

    private static function parseGuard(string $term): ?array
    {
        if (preg_match('/^value_json(?:' . self::JSON_ACCESSOR . ')?\s+is\s+defined$/', $term, $matches) === 1) {
            return ['test' => 'defined', 'path' => self::parseJsonAccessorPath($matches[1] ?? '')];
        }
        if (preg_match('/^value_json' . self::JSON_ACCESSOR . '\s*\|\s*length$/', $term, $matches) === 1) {
            return ['test' => 'length', 'path' => self::parseJsonAccessorPath($matches[1])];
        }
        if (preg_match('/^value_json' . self::JSON_ACCESSOR . '\s*(?:\|\s*(int|float)\s*)?(==|!=|<=|>=|<|>)\s*' . self::LITERAL . '$/', $term, $matches) === 1) {
            return [
                'test'    => 'compare',
                'path'    => self::parseJsonAccessorPath($matches[1]),
                'cast'    => $matches[2] !== '' ? $matches[2] : null,
                'op'      => $matches[3],
                'literal' => self::parseLiteral($matches[4])
            ];
        }
        return null;
    }

    private static function evaluateGuard(array $guard, mixed $decoded): bool
    {
        [$found, $value] = self::lookupPath($decoded, $guard['path'] ?? []);
        if (!$found) {
            return false;
        }

        if ($guard['test'] === 'defined') {
            return true;
        }
        if ($guard['test'] === 'length') {
            // Jinja: length einer Zahl ist ein Fehler, HA schreibt dann nichts.
            return (is_string($value) && $value !== '') || (is_array($value) && $value !== []);
        }

        if ($guard['cast'] !== null) {
            $number = is_numeric($value) ? (float)$value : 0.0;
            $value = $guard['cast'] === 'int' ? (int)$number : $number;
        }
        $literal = $guard['literal'];
        if (is_string($literal) !== is_string($value) || (!is_string($value) && !is_int($value) && !is_float($value))) {
            // Text gegen Zahl ist in Jinja nie gleich; ein Größenvergleich wäre ein Fehler.
            return $guard['op'] === '!=';
        }
        $order = is_string($value) ? strcmp($value, $literal) : $value <=> $literal;
        return match ($guard['op']) {
            '==' => $order === 0,
            '!=' => $order !== 0,
            '<' => $order < 0,
            '<=' => $order <= 0,
            '>' => $order > 0,
            '>=' => $order >= 0,
        };
    }

    /** @return array{0: bool, 1: mixed} gefunden, Wert */
    private static function lookupPath(mixed $decoded, array $path): array
    {
        $value = $decoded;
        foreach ($path as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return [false, null];
            }
            $value = $value[$segment];
        }
        return [true, $value];
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
