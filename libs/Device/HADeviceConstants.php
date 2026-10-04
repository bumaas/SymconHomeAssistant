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
    // Mediaplayer, deren Quelle nach einer Zustandsmeldung per REST nachgeprüft wird (siehe
    // HAMediaObjectsTrait::scheduleMediaPlayerSourceCheck); läuft über denselben Timer.
    public const string BUFFER_PENDING_SOURCE_CHECKS = 'PendingSourceChecks';
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
    // Ein Button-Druck zählt nur, wenn HA ihn höchstens so lange nach dem Drücken meldet; ältere
    // Zeitpunkte stammen aus Wiederholungen oder aus der Zeit, in der Symcon nicht lief.
    public const int BUTTON_PRESS_MAX_AGE_S = 300;

    // Ausführungsübergreifender Cache der aufgelösten Entitäten-Konfiguration
    // (Begründung: HADeviceCore, getConfiguredEntities). Der Build-Marker in der
    // Signatur entwertet Alt-Blobs nach einem Modul-Update automatisch.
    public const string BUFFER_CONFIGURED_ENTITIES_CACHE = 'ConfiguredEntitiesCache';
    public const string CONFIGURED_ENTITIES_CACHE_MARKER = 'b188';
    public const int CONFIGURED_ENTITIES_CACHE_MAX_BYTES = 1048576;
    public const string BUFFER_CONFIGURED_CACHE_WARN_TS = 'ConfiguredEntitiesCacheWarnEpoch';
    public const int CONFIGURED_ENTITIES_CACHE_WARN_THROTTLE_SEC = 3600;

    // P7: Die Unavailable-Entities-JSON-Variable wird nicht mehr pro Message,
    // sondern gebündelt über den StateCacheFlush-Timer aktualisiert.
    public const string BUFFER_UNAVAILABLE_JSON_DIRTY = 'UnavailableJsonDirty';

    // Erreichbarkeit: Das Gerät gilt als nicht erreichbar, wenn alle Entitäten mit Cache-Eintrag
    // länger als REACHABILITY_DELAY_S „unavailable" melden. Die Entprellung überbrückt den
    // HA-Neustart (gemessen 30.09.2026: Geräte bis 218 s komplett unavailable). Der Buffer hält
    // '0' = erreichbar, sonst den Unix-Zeitpunkt, seit dem alle Entitäten unavailable sind.
    public const string REACHABLE_IDENT = 'reachable';
    public const string TIMER_REACHABILITY = 'ReachabilityTimer';
    public const string ACTION_REACHABILITY_CHECK = 'ReachabilityCheck';
    public const string BUFFER_REACHABILITY_SINCE = 'ReachabilitySince';
    public const string BUFFER_REACHABILITY_DIRTY = 'ReachabilityDirty';
    public const int REACHABILITY_DELAY_S = 600;

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
