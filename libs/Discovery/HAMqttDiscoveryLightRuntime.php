<?php

declare(strict_types=1);

final class HAMqttDiscoveryLightRuntime
{
    public static function extractStateValue(mixed $value): mixed
    {
        if (!is_array($value) || !array_key_exists('state', $value)) {
            return $value;
        }

        return $value['state'];
    }

    public static function coerceBooleanActionValue(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value != 0;
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
}
