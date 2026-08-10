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
    public const string UPDATE_INSTALL_SUFFIX = '_update_install';
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
    public const string TIMER_DEFERRED_APPLY = 'DeferredApplyTimer';
    public const string ACTION_DEFERRED_APPLY = 'DeferredApply';
    public const int DEFERRED_APPLY_DELAY_MS = 100;

    // Entkoppelte Bild-Downloads (Kamera-Preview, Image-Preview, Media-Player-Cover): Auslöser reihen
    // nur einen Auftrag in den Buffer ein (Debounce bündelt Trigger-Salven, z. B. die HA-Token-Rotation),
    // der One-Shot-Timer lädt dann außerhalb des MQTT-Hotpaths. Der Mindestabstand pro Medienobjekt
    // begrenzt die Abrufe bei flatternden Zuständen; Timer-Callback via IPS_RequestAction, damit der
    // Trait-Code präfix-unabhängig für Device (HA_) und Entity (HAE_) funktioniert.
    public const string TIMER_MEDIA_REFRESH = 'MediaRefreshTimer';
    public const string ACTION_MEDIA_REFRESH = 'MediaRefresh';
    public const int MEDIA_REFRESH_DELAY_MS = 1000;
    public const string BUFFER_PENDING_MEDIA_JOBS = 'PendingMediaJobs';
    public const string BUFFER_MEDIA_LAST_FETCH = 'MediaLastFetch';
    public const int MEDIA_REFRESH_MIN_INTERVAL_SEC = 10;

    // Entkoppelte Persistenz des EntityStateCache (Begründung: HAEntityStore, readEntityStateCache).
    public const string TIMER_STATE_CACHE_FLUSH = 'StateCacheFlushTimer';
    public const string ACTION_STATE_CACHE_FLUSH = 'StateCacheFlush';
    public const int STATE_CACHE_FLUSH_DELAY_MS = 10000;
    public const string BUFFER_ENTITY_STATE_CACHE = 'EntityStateCacheBuffer';

    // Drossel für LastMQTTMessage + Diagnose-Labels (Muster wie im Splitter): Zeitstempel im Buffer,
    // weil ReceiveData über getrennte PHP-Ausführungen läuft.
    public const string BUFFER_LAST_MQTT_TOUCH = 'LastMqttTouchEpoch';
    public const int LAST_MQTT_LABEL_THROTTLE_SEC = 5;

    // Ausführungsübergreifender Cache der aufgelösten Entitäten-Konfiguration
    // (Begründung: HADeviceCore, getConfiguredEntities). Der Build-Marker in der
    // Signatur entwertet Alt-Blobs nach einem Modul-Update automatisch.
    public const string BUFFER_CONFIGURED_ENTITIES_CACHE = 'ConfiguredEntitiesCache';
    public const string CONFIGURED_ENTITIES_CACHE_MARKER = 'b147';
    public const int CONFIGURED_ENTITIES_CACHE_MAX_BYTES = 1048576;
    public const string BUFFER_CONFIGURED_CACHE_WARN_TS = 'ConfiguredEntitiesCacheWarnEpoch';
    public const int CONFIGURED_ENTITIES_CACHE_WARN_THROTTLE_SEC = 3600;

    // P7: Die Unavailable-Entities-JSON-Variable wird nicht mehr pro Message,
    // sondern gebündelt über den StateCacheFlush-Timer aktualisiert.
    public const string BUFFER_UNAVAILABLE_JSON_DIRTY = 'UnavailableJsonDirty';

    public const string PROP_DEVICE_AREA = 'DeviceArea';
    public const string PROP_DEVICE_NAME = 'DeviceName';
    public const string PROP_DEVICE_ID = 'DeviceID';
    public const string PROP_ENABLE_EXPERT_DEBUG = 'EnableExpertDebug';
    public const string PROP_ENABLE_PERFORMANCE_LOG = 'EnablePerformanceLog';
    public const string PROP_SHOW_TECHNICAL_ENTITY_COLUMNS = 'ShowTechnicalEntityColumns';
    public const string PROP_SHOW_UNAVAILABLE_ENTITIES_JSON = 'ShowUnavailableEntitiesJson';
    public const string PROP_EMULATE_STATUS = 'EmulateStatus';
    public const string PROP_OUTPUT_BUFFER_SIZE = 'OutputBufferSize';
    public const string PROP_SOURCE_MODE = 'SourceMode';
    public const string PROP_BUNDLE_PATH = 'BundlePath';
}
