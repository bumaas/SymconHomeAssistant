<?php

declare(strict_types=1);

interface HADeviceConstants
{
    public const string KEY_STATE      = 'state';
    public const string KEY_ATTRIBUTES = 'attributes';
    public const string KEY_SUPPORTED_FEATURES = 'supported_features';
    public const string LOCK_ACTION_SUFFIX = '_lock_action';
    public const string COVER_ACTION_SUFFIX = '_cover_action';
    public const string COVER_TILT_ACTION_SUFFIX = '_cover_tilt_action';
    public const string VALVE_ACTION_SUFFIX = '_valve_action';
    public const string VACUUM_ACTION_SUFFIX = '_vacuum_action';
    public const string VACUUM_FAN_SPEED_SUFFIX = '_vacuum_fan_speed';
    public const string LAWN_MOWER_ACTION_SUFFIX = '_lawn_mower_action';
    public const string MEDIA_PLAYER_ACTION_SUFFIX = '_media_player_action';
    public const string MEDIA_PLAYER_POWER_SUFFIX = '_power';
    public const string CLIMATE_POWER_SUFFIX = '_power';
    public const string MEDIA_PLAYER_COVER_SUFFIX = '_media_cover';
    public const string CAMERA_STREAM_SUFFIX = '_camera_stream';
    public const string CAMERA_PREVIEW_SUFFIX = '_camera_preview';
    public const string IMAGE_PREVIEW_SUFFIX = '_image_preview';
    public const string EVENT_TYPE_SUFFIX = '_event_type';
    public const string UNAVAILABLE_ENTITIES_JSON_IDENT = 'unavailable_entities_json';
    public const string TIMER_MEDIA_PLAYER_PROGRESS = 'MediaPlayerProgressTimer';
    public const string BUFFER_MEDIA_PLAYER_PROGRESS_DEBUG = 'MediaPlayerProgressDebug';
    public const int MEDIA_PLAYER_PROGRESS_DEBUG_INTERVAL = 10;

    public const string PROP_DEVICE_AREA = 'DeviceArea';
    public const string PROP_DEVICE_NAME = 'DeviceName';
    public const string PROP_DEVICE_ID = 'DeviceID';
    public const string PROP_ENABLE_EXPERT_DEBUG = 'EnableExpertDebug';
    public const string PROP_SHOW_TECHNICAL_ENTITY_COLUMNS = 'ShowTechnicalEntityColumns';
    public const string PROP_SHOW_UNAVAILABLE_ENTITIES_JSON = 'ShowUnavailableEntitiesJson';
    public const string PROP_OUTPUT_BUFFER_SIZE = 'OutputBufferSize';
    public const string PROP_SOURCE_MODE = 'SourceMode';
    public const string PROP_BUNDLE_PATH = 'BundlePath';
}
