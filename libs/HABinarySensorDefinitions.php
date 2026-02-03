<?php

declare(strict_types=1);

final class HABinarySensorDefinitions
{
    public const string DOMAIN = 'binary_sensor';
    public const int VARIABLE_TYPE = VARIABLETYPE_BOOLEAN;
    public const string PRESENTATION = VARIABLE_PRESENTATION_VALUE_PRESENTATION;
    public const string DEFAULT_TRUE = 'an';
    public const string DEFAULT_FALSE = 'aus';

    public const array DEVICE_CLASS_MAP = [
        'battery' => ['Batterie niedrig', 'Batterie ok', 'battery-exclamation'],
        'battery_charging' => ['lädt', 'lädt nicht', 'battery-bolt'],
        'cold' => ['kalt', 'normal', 'snowflake'],
        'connectivity' => ['verbunden', 'getrennt', 'wifi'],
        'door' => ['offen', 'geschlossen', 'door-open'],
        'garage_door' => ['offen', 'geschlossen', 'garage-open'],
        'gas' => ['Gas erkannt', 'kein Gas', 'cloud-bolt'],
        'heat' => ['heiß', 'normal', 'fire'],
        'light' => ['Licht erkannt', 'kein Licht', 'lightbulb-on'],
        'lock' => ['entsperrt', 'gesperrt', 'lock-open'],
        'moisture' => ['nass', 'trocken', 'droplet'],
        'motion' => ['Bewegung', 'keine Bewegung', 'person-running'],
        'moving' => ['in Bewegung', 'still', 'person-running'],
        'occupancy' => ['belegt', 'frei', 'house-person-return'],
        'opening' => ['offen', 'geschlossen', 'up-right-from-square'],
        'plug' => ['eingesteckt', 'ausgesteckt', 'plug'],
        'power' => ['Strom erkannt', 'kein Strom', 'bolt'],
        'presence' => ['anwesend', 'abwesend', 'user'],
        'problem' => ['Problem', 'kein Problem', 'triangle-exclamation'],
        'running' => ['läuft', 'gestoppt', 'play'],
        'safety' => ['gefährlich', 'sicher', 'shield-exclamation'],
        'smoke' => ['Rauch', 'kein Rauch', 'fire-smoke'],
        'sound' => ['Geräusch', 'kein Geräusch', 'volume-high'],
        'tamper' => ['Manipulation', 'keine Manipulation', 'hand'],
        'update' => ['Update verfügbar', 'aktuell', 'arrows-rotate'],
        'vibration' => ['Vibration', 'keine Vibration', 'chart-fft'],
        'window' => ['offen', 'geschlossen', 'window-frame-open']
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
