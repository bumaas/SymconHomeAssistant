<?php

declare(strict_types=1);

final class HAUpdateDefinitions
{
    public const string DOMAIN = 'update';
    public const int VARIABLE_TYPE = VARIABLETYPE_BOOLEAN;
    public const string PRESENTATION = VARIABLE_PRESENTATION_VALUE_PRESENTATION;
    public const string TRUE_CAPTION = 'Update Available';
    public const string FALSE_CAPTION = 'Up to Date';
    public const string ICON = 'arrows-rotate';

    public const string ATTRIBUTE_INSTALLED_VERSION = 'installed_version';
    public const string ATTRIBUTE_LATEST_VERSION = 'latest_version';
    public const string ATTRIBUTE_SKIPPED_VERSION = 'skipped_version';
    public const string ATTRIBUTE_TITLE = 'title';
    public const string ATTRIBUTE_RELEASE_SUMMARY = 'release_summary';
    public const string ATTRIBUTE_RELEASE_URL = 'release_url';
    public const string ATTRIBUTE_IN_PROGRESS = 'in_progress';
    public const string ATTRIBUTE_UPDATE_PERCENTAGE = 'update_percentage';

    public const array ATTRIBUTE_ORDER = [
        self::ATTRIBUTE_INSTALLED_VERSION,
        self::ATTRIBUTE_LATEST_VERSION,
        self::ATTRIBUTE_SKIPPED_VERSION,
        self::ATTRIBUTE_IN_PROGRESS,
        self::ATTRIBUTE_UPDATE_PERCENTAGE,
        self::ATTRIBUTE_TITLE,
        self::ATTRIBUTE_RELEASE_SUMMARY,
        self::ATTRIBUTE_RELEASE_URL
    ];

    public const array ATTRIBUTE_DEFINITIONS = [
        self::ATTRIBUTE_INSTALLED_VERSION => [
            'caption' => 'Installed Version',
            'type' => VARIABLETYPE_STRING,
            'writable' => false
        ],
        self::ATTRIBUTE_LATEST_VERSION => [
            'caption' => 'Latest Version',
            'type' => VARIABLETYPE_STRING,
            'writable' => false
        ],
        self::ATTRIBUTE_SKIPPED_VERSION => [
            'caption' => 'Skipped Version',
            'type' => VARIABLETYPE_STRING,
            'writable' => false
        ],
        self::ATTRIBUTE_IN_PROGRESS => [
            'caption' => 'In Progress',
            'type' => VARIABLETYPE_BOOLEAN,
            'writable' => false
        ],
        self::ATTRIBUTE_UPDATE_PERCENTAGE => [
            'caption' => 'Update Percentage',
            'type' => VARIABLETYPE_FLOAT,
            'writable' => false
        ],
        self::ATTRIBUTE_TITLE => [
            'caption' => 'Title',
            'type' => VARIABLETYPE_STRING,
            'writable' => false
        ],
        self::ATTRIBUTE_RELEASE_SUMMARY => [
            'caption' => 'Release Summary',
            'type' => VARIABLETYPE_STRING,
            'writable' => false
        ],
        self::ATTRIBUTE_RELEASE_URL => [
            'caption' => 'Release URL',
            'type' => VARIABLETYPE_STRING,
            'writable' => false
        ]
    ];
}
