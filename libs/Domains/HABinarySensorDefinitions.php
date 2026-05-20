<?php

declare(strict_types=1);

final class HABinarySensorDefinitions
{
    public const string DOMAIN = 'binary_sensor';
    public const int VARIABLE_TYPE = VARIABLETYPE_BOOLEAN;
    public const string PRESENTATION = VARIABLE_PRESENTATION_VALUE_PRESENTATION;
    public const string DEFAULT_TRUE = 'On';
    public const string DEFAULT_FALSE = 'Off';

    public const array DEVICE_CLASS_MAP = [
        'battery' => ['Battery Low', 'Battery OK', 'battery-exclamation'],
        'battery_charging' => ['Charging', 'Not Charging', 'battery-bolt'],
        'co' => ['CO Detected', 'No CO', 'triangle-exclamation'],
        'cold' => ['Cold', 'Normal', 'snowflake'],
        'connectivity' => ['Connected', 'Disconnected', 'wifi'],
        'door' => ['Open', 'Closed', 'door-open'],
        'garage_door' => ['Open', 'Closed', 'garage-open'],
        'gas' => ['Gas Detected', 'No Gas', 'cloud-bolt'],
        'heat' => ['Hot', 'Normal', 'fire'],
        'light' => ['Light Detected', 'No Light', 'lightbulb-on'],
        'lock' => ['Unlocked', 'Locked', 'lock-open'],
        'moisture' => ['Wet', 'Dry', 'droplet'],
        'motion' => ['Motion', 'No Motion', 'person-running'],
        'moving' => ['Moving', 'Still', 'person-running'],
        'occupancy' => ['Occupied', 'Free', 'house-person-return'],
        'opening' => ['Open', 'Closed', 'up-right-from-square'],
        'plug' => ['Plugged In', 'Unplugged', 'plug'],
        'power' => ['Power Detected', 'No Power', 'bolt'],
        'presence' => ['Present', 'Away', 'user'],
        'problem' => ['Problem', 'No Problem', 'triangle-exclamation'],
        'running' => ['Running', 'Stopped', 'play'],
        'safety' => ['Danger', 'Safe', 'shield-exclamation'],
        'smoke' => ['Smoke', 'No Smoke', 'fire-smoke'],
        'sound' => ['Sound', 'No Sound', 'volume-high'],
        'tamper' => ['Tamper', 'No Tamper', 'hand'],
        'update' => ['Update Available', 'Up to Date', 'arrows-rotate'],
        'vibration' => ['Vibration', 'No Vibration', 'chart-fft'],
        'window' => ['Open', 'Closed', 'window-frame-open']
    ];

    public static function getPresentationMeta(string $deviceClass): array
    {
        $deviceClass = trim($deviceClass);
        if ($deviceClass !== '' && isset(self::DEVICE_CLASS_MAP[$deviceClass])) {
            return self::DEVICE_CLASS_MAP[$deviceClass];
        }
        return [self::DEFAULT_TRUE, self::DEFAULT_FALSE, ''];
    }
}
