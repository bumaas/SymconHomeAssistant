<?php

declare(strict_types=1);

final class HAMediaPlayerDefinitions
{
    public const string DOMAIN = 'media_player';
    public const int VARIABLE_TYPE = VARIABLETYPE_STRING;
    public const string PRESENTATION = VARIABLE_PRESENTATION_VALUE_PRESENTATION;

    public const int ACTION_PLAY = 0;
    public const int ACTION_PAUSE = 1;
    public const int ACTION_STOP = 2;
    public const int ACTION_NEXT = 3;
    public const int ACTION_PREVIOUS = 4;

    public const int FEATURE_PAUSE = 1;
    public const int FEATURE_SEEK = 2;
    public const int FEATURE_VOLUME_SET = 4;
    public const int FEATURE_VOLUME_MUTE = 8;
    public const int FEATURE_PREVIOUS_TRACK = 16;
    public const int FEATURE_NEXT_TRACK = 32;
    public const int FEATURE_TURN_ON = 128;
    public const int FEATURE_TURN_OFF = 256;
    public const int FEATURE_PLAY_MEDIA = 512;
    public const int FEATURE_VOLUME_STEP = 1024;
    public const int FEATURE_SELECT_SOURCE = 2048;
    public const int FEATURE_STOP = 4096;
    public const int FEATURE_CLEAR_PLAYLIST = 8192;
    public const int FEATURE_PLAY = 16384;
    public const int FEATURE_SHUFFLE_SET = 32768;
    public const int FEATURE_SELECT_SOUND_MODE = 65536;
    public const int FEATURE_BROWSE_MEDIA = 131072;
    public const int FEATURE_REPEAT_SET = 262144;
    public const int FEATURE_GROUPING = 524288;
    public const int FEATURE_MEDIA_ANNOUNCE = 1048576;
    public const int FEATURE_MEDIA_ENQUEUE = 2097152;
    public const int FEATURE_SEARCH_MEDIA = 4194304;

    public const array STATE_OPTIONS = [
        'off' => 'off',
        'on' => 'on',
        'idle' => 'idle',
        'playing' => 'playing',
        'paused' => 'paused',
        'buffering' => 'buffering'
    ];

    public const array REPEAT_OPTIONS = [
        'off',
        'one',
        'all'
    ];

    public const array SUPPORTED_FEATURES = [
        self::FEATURE_BROWSE_MEDIA => 'Media player feature: Browse Media',
        self::FEATURE_CLEAR_PLAYLIST => 'Media player feature: Clear Playlist',
        self::FEATURE_GROUPING => 'Media player feature: Grouping',
        self::FEATURE_MEDIA_ANNOUNCE => 'Media player feature: Media Announce',
        self::FEATURE_MEDIA_ENQUEUE => 'Media player feature: Media Enqueue',
        self::FEATURE_NEXT_TRACK => 'Media player feature: Next Track',
        self::FEATURE_PAUSE => 'Media player feature: Pause',
        self::FEATURE_PLAY => 'Media player feature: Play',
        self::FEATURE_PLAY_MEDIA => 'Media player feature: Play Media',
        self::FEATURE_PREVIOUS_TRACK => 'Media player feature: Previous Track',
        self::FEATURE_REPEAT_SET => 'Media player feature: Repeat Set',
        self::FEATURE_SEARCH_MEDIA => 'Media player feature: Search Media',
        self::FEATURE_SEEK => 'Media player feature: Seek',
        self::FEATURE_SELECT_SOUND_MODE => 'Media player feature: Select Sound Mode',
        self::FEATURE_SELECT_SOURCE => 'Media player feature: Select Source',
        self::FEATURE_SHUFFLE_SET => 'Media player feature: Shuffle Set',
        self::FEATURE_STOP => 'Media player feature: Stop',
        self::FEATURE_TURN_OFF => 'Media player feature: Turn Off',
        self::FEATURE_TURN_ON => 'Media player feature: Turn On',
        self::FEATURE_VOLUME_MUTE => 'Media player feature: Volume Mute',
        self::FEATURE_VOLUME_SET => 'Media player feature: Volume Set',
        self::FEATURE_VOLUME_STEP => 'Media player feature: Volume Step'
    ];

    public const array ATTRIBUTE_DEFINITIONS = [
        'volume_level' => [
            'caption' => 'Volume',
            'type' => VARIABLETYPE_FLOAT,
            'writable' => true,
            'min' => 0,
            'max' => 1,
            'step' => 0.01,
            'percentage' => true,
            'usage_type' => 3, //Lautstärke
            'requires_features' => [self::FEATURE_VOLUME_SET]
        ],
        'is_volume_muted' => [
            'caption' => 'Mute',
            'type' => VARIABLETYPE_BOOLEAN,
            'writable' => true,
            'requires_features' => [self::FEATURE_VOLUME_MUTE]
        ],
        'media_position' => [
            'caption' => 'Position',
            'type' => VARIABLETYPE_INTEGER,
            'writable' => true,
            'suffix' => 's',
            'requires_features' => [self::FEATURE_SEEK]
        ],
        'media_duration' => [
            'caption' => 'Duration',
            'type' => VARIABLETYPE_INTEGER,
            'writable' => false,
            'suffix' => 's'
        ],
        'repeat' => [
            'caption' => 'Repeat',
            'type' => VARIABLETYPE_STRING,
            'writable' => true,
            'requires_features' => [self::FEATURE_REPEAT_SET]
        ],
        'shuffle' => [
            'caption' => 'Shuffle',
            'type' => VARIABLETYPE_BOOLEAN,
            'writable' => true,
            'requires_features' => [self::FEATURE_SHUFFLE_SET]
        ],
        'media_image_url' => [
            'caption' => 'Media URL',
            'type' => VARIABLETYPE_STRING,
            'writable' => false
        ],
        'media_artist' => [
            'caption' => 'Artist',
            'type' => VARIABLETYPE_STRING,
            'writable' => false
        ],
        'media_title' => [
            'caption' => 'Title',
            'type' => VARIABLETYPE_STRING,
            'writable' => false
        ],
        'media_playlist' => [
            'caption' => 'Playlist',
            'type' => VARIABLETYPE_STRING,
            'writable' => false
        ]
    ];

    public const array ALLOWED_ATTRIBUTES = [
        'app_id',
        'app_name',
        'device_class',
        'group_members',
        'is_volume_muted',
        'media_album_artist',
        'media_album_name',
        'media_artist',
        'media_channel',
        'media_content_id',
        'media_content_type',
        'media_duration',
        'media_episode',
        'media_image_hash',
        'media_image_remotely_accessible',
        'media_image_url',
        'media_playlist',
        'media_position',
        'media_position_updated_at',
        'media_season',
        'media_series_title',
        'media_title',
        'media_track',
        'repeat',
        'shuffle',
        'sound_mode',
        'sound_mode_list',
        'source',
        'source_list',
        'state',
        'volume_level',
        'volume_step',
        'supported_features'
    ];
}
