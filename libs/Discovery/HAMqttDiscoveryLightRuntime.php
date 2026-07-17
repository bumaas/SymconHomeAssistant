<?php

declare(strict_types=1);

final class HAMqttDiscoveryLightRuntime
{
    public const array ATTRIBUTE_COMMANDS = [
        'brightness' => 'direct',
        'color_temp' => 'direct',
        'color_temp_kelvin' => 'kelvin_to_mired',
        'effect' => 'direct',
        'flash' => 'direct',
        'transition' => 'direct',
        'xy_color' => 'color',
        'hs_color' => 'color'
    ];

    public static function extractStateValue(mixed $value): mixed
    {
        if (!is_array($value) || !array_key_exists('state', $value)) {
            return $value;
        }

        return $value['state'];
    }

    public static function extractAttributes(mixed $value, array $metadata = []): array
    {
        if (!is_array($value)) {
            return [];
        }

        $attributes = [];

        foreach (['brightness', 'color_temp', 'color_temp_kelvin'] as $key) {
            if (is_numeric($value[$key] ?? null)) {
                $attributes[$key] = (int) round((float) $value[$key]);
            }
        }

        if (!isset($attributes['color_temp_kelvin']) && isset($attributes['color_temp']) && self::hasKelvinMetadata($metadata)) {
            $attributes['color_temp_kelvin'] = self::convertMiredToKelvin($attributes['color_temp']);
        }

        if (!isset($attributes['color_temp']) && is_numeric($value['color_temp_kelvin'] ?? null)) {
            $kelvin = (int) round((float) $value['color_temp_kelvin']);
            $attributes['color_temp'] = self::convertKelvinToMired($kelvin);
            $attributes['color_temp_kelvin'] = $kelvin;
        }

        foreach (['color_mode', 'effect', 'flash'] as $key) {
            $normalized = self::normalizeNullableString($value[$key] ?? null);
            if ($normalized !== null) {
                $attributes[$key] = $normalized;
            }
        }

        if (is_numeric($value['transition'] ?? null)) {
            $attributes['transition'] = (float) $value['transition'];
        }

        foreach (['rgb_color' => 3, 'rgbw_color' => 4, 'rgbww_color' => 5, 'hs_color' => 2, 'xy_color' => 2] as $key => $expectedCount) {
            $list = self::normalizeNumberList($value[$key] ?? null, $expectedCount);
            if ($list !== null) {
                $attributes[$key] = $list;
            }
        }

        $color = $value['color'] ?? null;
        if (is_array($color)) {
            $xyColor = self::normalizeNumberList([$color['x'] ?? null, $color['y'] ?? null], 2);
            if ($xyColor !== null && !isset($attributes['xy_color'])) {
                $attributes['xy_color'] = $xyColor;
            }

            $hsColor = self::normalizeNumberList([
                $color['h'] ?? $color['hue'] ?? null,
                $color['s'] ?? $color['saturation'] ?? null
            ], 2);
            if ($hsColor !== null && !isset($attributes['hs_color'])) {
                $attributes['hs_color'] = $hsColor;
            }

            $rgbColor = self::normalizeNumberList([
                $color['r'] ?? null,
                $color['g'] ?? null,
                $color['b'] ?? null
            ], 3, false);
            if ($rgbColor !== null && !isset($attributes['rgb_color'])) {
                $attributes['rgb_color'] = $rgbColor;
            }
        }

        return $attributes;
    }

    public static function coerceBooleanActionValue(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value !== 0;
        }

        if (!is_string($value)) {
            return null;
        }

        return match (strtolower(trim($value))) {
            '1', 'on', 'true', 'yes', 'ja', 'an', 'ein' => true,
            '0', 'off', 'false', 'no', 'nein', 'aus' => false,
            default => null
        };
    }

    public static function buildCommandPayload(mixed $value): ?string
    {
        $boolValue = self::coerceBooleanActionValue($value);
        if ($boolValue === null) {
            return null;
        }

        return json_encode(
            ['state' => $boolValue ? 'ON' : 'OFF'],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    public static function buildAttributeCommandPayload(string $attribute, mixed $value): ?string
    {
        $mode = self::ATTRIBUTE_COMMANDS[$attribute] ?? null;
        if ($mode === null) {
            return null;
        }

        // Farbe nutzt im HA-JSON-Schema ein verschachteltes "color"-Objekt statt eines flachen
        // Attributschlüssels (z. B. {"color":{"x":..,"y":..}}).
        if ($mode === 'color') {
            return self::buildColorCommandPayload($attribute, $value);
        }

        $payloadValue = match ($mode) {
            'direct' => self::parseAttributeValue($attribute, $value),
            'kelvin_to_mired' => self::convertKelvinActionValue($value),
            default => null
        };
        if ($payloadValue === null) {
            return null;
        }

        $payloadKey = $attribute === 'color_temp_kelvin' ? 'color_temp' : $attribute;

        return json_encode(
            [$payloadKey => $payloadValue],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    public static function formatAttributeValueForStorage(string $attribute, mixed $value): string|int|float|null
    {
        $meta = HALightDefinitions::ATTRIBUTE_DEFINITIONS[$attribute] ?? null;
        if (!is_array($meta)) {
            return null;
        }

        return match ($attribute) {
            'brightness', 'color_temp', 'color_temp_kelvin' => is_numeric($value) ? (int) round((float) $value) : null,
            'transition' => is_numeric($value) ? (float) $value : null,
            'rgb_color' => self::formatRgbStorageValue($value),
            'xy_color' => self::formatXyStorageValue($value),
            'hs_color' => self::formatHsStorageValue($value),
            'rgbw_color', 'rgbww_color' => self::formatListStorageValue($value),
            default => self::normalizeNullableString($value)
        };
    }

    private static function hasKelvinMetadata(array $metadata): bool
    {
        return is_numeric($metadata['min_color_temp_kelvin'] ?? null) || is_numeric($metadata['max_color_temp_kelvin'] ?? null);
    }

    private static function convertMiredToKelvin(int $mired): int
    {
        if ($mired <= 0) {
            return 0;
        }

        return (int) round(1000000 / $mired);
    }

    private static function convertKelvinToMired(int $kelvin): ?int
    {
        if ($kelvin <= 0) {
            return null;
        }

        return (int) round(1000000 / $kelvin);
    }

    private static function convertKelvinActionValue(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }

        return self::convertKelvinToMired((int) round((float) $value));
    }

    private static function parseAttributeValue(string $attribute, mixed $value): string|int|float|null
    {
        return match ($attribute) {
            'brightness', 'color_temp' => is_numeric($value) ? (int) round((float) $value) : null,
            'transition' => is_numeric($value) ? (float) $value : null,
            default => self::normalizeNullableString($value)
        };
    }

    // Baut das HA-JSON-Kommando für Farb-Attribute: ein verschachteltes "color"-Objekt.
    // xy_color -> {"color":{"x":X,"y":Y}}, hs_color -> {"color":{"h":H,"s":S}} (Floats).
    private static function buildColorCommandPayload(string $attribute, mixed $value): ?string
    {
        $components = self::parseNumberListLoose($value, 2);
        if ($components === null) {
            return null;
        }

        $color = match ($attribute) {
            'xy_color' => ['x' => $components[0], 'y' => $components[1]],
            'hs_color' => ['h' => $components[0], 's' => $components[1]],
            default => null
        };
        if ($color === null) {
            return null;
        }

        return json_encode(
            ['color' => $color],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    // String-toleranter Parser für eine feste Anzahl Zahlen. Akzeptiert Arrays, JSON-Strings
    // ("[x,y]") und einfache Trennzeichenformate ("x;y" / "x,y") — analog zum REST-Pfad
    // (HAAttributeActionMapping::parseNumberList), da die WebFront-Farbwahl den Wert als String liefern kann.
    private static function parseNumberListLoose(mixed $value, int $expectedCount): ?array
    {
        $items = $value;
        if (!is_array($items)) {
            $text = trim((string) $value);
            if ($text === '') {
                return null;
            }
            if (($text[0] === '[' || $text[0] === '{')) {
                try {
                    $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($decoded)) {
                        $items = $decoded;
                    }
                } catch (JsonException) {
                    $items = null;
                }
            }
            if (!is_array($items)) {
                $items = array_map('trim', preg_split('/[;,]/', $text) ?: []);
            }
        }

        $items = array_values($items);
        if (count($items) < $expectedCount) {
            return null;
        }

        $numbers = [];
        foreach (array_slice($items, 0, $expectedCount) as $item) {
            if (!is_numeric($item)) {
                return null;
            }
            $numbers[] = (float) $item;
        }

        return $numbers;
    }

    private static function normalizeNumberList(mixed $value, int $expectedCount, bool $allowFloat = true): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $items = array_values($value);
        if (count($items) !== $expectedCount) {
            return null;
        }

        $result = [];
        foreach ($items as $item) {
            if (!is_numeric($item)) {
                return null;
            }

            $number = (float) $item;
            $result[] = $allowFloat ? $number : (int) round($number);
        }

        return $result;
    }

    private static function formatListStorageValue(mixed $value): ?string
    {
        if (!is_array($value)) {
            return null;
        }

        return json_encode(
            array_values($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    // xy (CIE): Symcons Farb-Darstellung (ENCODING 4) erwartet ein JSON-Objekt {"x":..,"y":..},
    // kein Array. (Analog zu formatRgbStorageValue, das {"r","g","b"} liefert.)
    private static function formatXyStorageValue(mixed $value): ?string
    {
        $xy = self::normalizeNumberList($value, 2);
        if ($xy === null) {
            return null;
        }

        return json_encode(
            ['x' => (float) $xy[0], 'y' => (float) $xy[1]],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    // HSV: Symcons Farb-Darstellung (ENCODING 2) erwartet ein JSON-Objekt {"h":..,"s":..,"v":..}.
    // HA liefert bei hs_color nur Hue/Sat; "v" wird als Vollwert (100) gesetzt (Helligkeit steckt in
    // der separaten brightness-Variable). Mangels HS-Testgerät noch praktisch zu verifizieren.
    private static function formatHsStorageValue(mixed $value): ?string
    {
        $hs = self::normalizeNumberList($value, 2);
        if ($hs === null) {
            return null;
        }

        return json_encode(
            ['h' => (float) $hs[0], 's' => (float) $hs[1], 'v' => 100],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    private static function formatRgbStorageValue(mixed $value): ?string
    {
        $rgb = self::normalizeNumberList($value, 3, false);
        if ($rgb === null) {
            return null;
        }

        return json_encode(
            [
                'r' => (int) $rgb[0],
                'g' => (int) $rgb[1],
                'b' => (int) $rgb[2]
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    private static function normalizeNullableString(mixed $value): ?string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        return $value === '' ? null : $value;
    }
}
