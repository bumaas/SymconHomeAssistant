<?php

declare(strict_types=1);

final class HAEventDefinitions
{
    public const string DOMAIN = 'event';
    public const string STATE_SUFFIX = 'event_type';
    public const int VARIABLE_TYPE = VARIABLETYPE_STRING;
    public const string PRESENTATION = VARIABLE_PRESENTATION_VALUE_PRESENTATION;
    public const string ATTRIBUTE_EVENT_TYPE = 'event_type';
    public const string ATTRIBUTE_EVENT_TYPES = 'event_types';

    public const array EVENT_TYPE_TRANSLATION_KEYS = [
        'initial_press' => 'Event type: initial press',
        'short_release' => 'Event type: short release',
        'long_press' => 'Event type: long press',
        'long_release' => 'Event type: long release'
    ];

    public static function buildStateTopic(string $base, string $name): string
    {
        return $base . '/' . self::DOMAIN . '/' . $name . '/' . self::STATE_SUFFIX;
    }
}
