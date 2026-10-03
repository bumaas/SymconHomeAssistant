<?php

declare(strict_types=1);

final class HACameraDefinitions
{
    public const string DOMAIN = 'camera';
    public const int VARIABLE_TYPE = VARIABLETYPE_STRING;
    public const string PRESENTATION = VARIABLE_PRESENTATION_VALUE_PRESENTATION;

    public const int FEATURE_ON_OFF = 1;
    public const int FEATURE_STREAM = 2;

    // Zustände einer Kamera in Home Assistant (CameraState). Der Rohwert bleibt in der Variable, die
    // Darstellung nennt die möglichen Werte und übersetzt sie (MCP-Regel 14, nuc 03.10.2026).
    public const array STATE_OPTIONS = [
        'idle' => 'idle',
        'recording' => 'recording',
        'streaming' => 'streaming'
    ];

    public const array SUPPORTED_FEATURES = [
        self::FEATURE_ON_OFF => 'Camera feature: On/Off',
        self::FEATURE_STREAM => 'Camera feature: Stream'
    ];
}
