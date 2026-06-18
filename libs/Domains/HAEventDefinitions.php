<?php

declare(strict_types=1);

final class HAEventDefinitions
{
    public const string DOMAIN = 'event';
    public const string STATE_SUFFIX = 'state';
    public const int VARIABLE_TYPE = VARIABLETYPE_INTEGER;
    public const string PRESENTATION = VARIABLE_PRESENTATION_DATE_TIME;
    public const string ATTRIBUTE_EVENT_TYPE = 'event_type';
    public const string ATTRIBUTE_EVENT_TYPES = 'event_types';

    public const array EVENT_TYPE_TRANSLATION_KEYS = [
        'initial_press' => 'Event type: initial press',
        'short_release' => 'Event type: short release',
        'long_press'    => 'Event type: long press',
        'long_release'  => 'Event type: long release',
        'multi_press_1' => 'Event type: multi press 1',
        'multi_press_2' => 'Event type: multi press 2',
        'multi_press_3' => 'Event type: multi press 3',
        'multi_press_4' => 'Event type: multi press 4',
        'multi_press_5' => 'Event type: multi press 5',
    ];

    public static function buildStateTopic(string $base, string $name): string
    {
        return $base . '/' . self::DOMAIN . '/' . $name . '/' . self::STATE_SUFFIX;
    }
}
