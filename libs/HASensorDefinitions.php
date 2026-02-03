<?php

declare(strict_types=1);

final class HASensorDefinitions
{
    public const string DOMAIN = 'sensor';

    public const string DEVICE_CLASS_ENUM = 'enum';

    public const array DEVICE_CLASS_SUFFIX = [
        'battery' => '%',
        'humidity' => '%',
        'moisture' => '%',
        'power_factor' => '%'
    ];
}
