<?php

declare(strict_types=1);

final class HANumberDefinitions
{
    public const string DOMAIN = 'number';
    public const int VARIABLE_TYPE = VARIABLETYPE_FLOAT;

    public const array DEVICE_CLASS_SUFFIX = [
        'battery' => '%',
        'humidity' => '%',
        'moisture' => '%',
        'power_factor' => '%',
        'wind_direction' => '°'
    ];
    // Map MQTT "set" payloads to HA number services/data.
    public static function buildRestServicePayload(mixed $value): array
    {
        return ['set_value', ['value' => (float)$value]];
    }
}
