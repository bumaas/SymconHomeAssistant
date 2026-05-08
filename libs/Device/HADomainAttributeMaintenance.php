<?php

declare(strict_types=1);

trait HADomainAttributeMaintenanceTrait
{
    // Media-Player-Attribute entstehen teils indirekt über Listenattribute wie source_list.
    private function shouldCreateMediaPlayerAttribute(string $attribute, array $meta, array $attributes): bool
    {
        $hasAttribute = array_key_exists($attribute, $attributes);
        if (!$hasAttribute) {
            if ($attribute === 'media_image_url') {
                $hasAttribute = array_key_exists('entity_picture', $attributes);
            } elseif ($attribute === 'source') {
                $hasAttribute = array_key_exists('source_list', $attributes);
            } elseif ($attribute === 'sound_mode') {
                $hasAttribute = array_key_exists('sound_mode_list', $attributes);
            }
        }

        if (($meta['writable'] ?? false) === true) {
            return $hasAttribute || $this->isWritableMediaPlayerAttribute($attribute, $attributes);
        }

        return $hasAttribute;
    }


    // Media-Player bündelt viele abgeleitete Attribute und zusätzliche Medienobjekte.
    private function maintainMediaPlayerAttributeVariables(array $entity): void
    {
        $this->maintainStandardAttributeVariables(
            $entity,
            HAMediaPlayerDefinitions::ATTRIBUTE_DEFINITIONS,
            fn(string $attribute, array $meta, array $attributes): bool => $this->shouldCreateMediaPlayerAttribute($attribute, $meta, $attributes),
            fn(string $attribute, array $attributes, array $meta): array => $this->getMediaPlayerAttributePresentation($attribute, $attributes, $meta),
            fn(string $attribute, int $basePosition): int => $this->getMediaPlayerAttributePosition($attribute, $basePosition),
            function (string $attribute, array $attributes, string $ident, array $presentation, array $_meta): void {
                $this->applyMediaPlayerAttributeActionState($attribute, $attributes, $presentation, $ident);
            },
            0,
            fn(string $attribute, array $_meta, array $_attributes): bool => $this->isMediaPlayerAttributeShadowed($attribute),
            function (string $attribute, array $_meta, string $entityId, array $_attributes, int $basePosition): void {
                if ($attribute === 'media_image_url') {
                    $this->maintainMediaPlayerCoverMedia($entityId, $basePosition);
                }
            },
            false
        );
    }

    // Erstellt fehlende Media-Player-Attribute lazily bei Bedarf.
    private function ensureMediaPlayerAttributeVariable(string $entityId, string $attribute): bool
    {
        return $this->ensureStandardAttributeVariable(
            $entityId,
            $attribute,
            HAMediaPlayerDefinitions::ATTRIBUTE_DEFINITIONS,
            fn(string $attributeName, array $meta, array $attributes): bool => $this->shouldCreateMediaPlayerAttribute($attributeName, $meta, $attributes),
            fn(string $attributeName, array $attributes, array $meta): array => $this->getMediaPlayerAttributePresentation($attributeName, $attributes, $meta),
            fn(string $attributeName, int $basePosition): int => $this->getMediaPlayerAttributePosition($attributeName, $basePosition),
            function (string $attributeName, array $attributes, string $ident, array $presentation, array $_meta): void {
                $this->applyMediaPlayerAttributeActionState($attributeName, $attributes, $presentation, $ident);
            },
            0,
            ['name' => $entityId],
            null,
            fn(string $attributeName, array $_meta, array $_attributes): bool => $this->isMediaPlayerAttributeShadowed($attributeName),
            function (string $attributeName, array $_meta, string $resolvedEntityId, array $_attributes, int $basePosition): void {
                if ($attributeName === 'media_image_url') {
                    $this->maintainMediaPlayerCoverMedia($resolvedEntityId, $basePosition);
                }
            }
        );
    }

    // Kamera-Variablen bestehen hauptsächlich aus den zugehörigen Medienobjekten.
    private function maintainCameraAttributeVariables(array $entity): void
    {
        $attributes = $entity['attributes'] ?? [];
        if (!is_array($attributes)) {
            $attributes = [];
        }
        $attributes = $this->normalizeCameraAttributes($attributes, __FUNCTION__);

        $this->maintainCameraPreviewMedia($entity['entity_id'], 0);
        $this->maintainCameraStreamMedia($entity['entity_id'], 0);
        $this->updateCameraAttributeValues($entity['entity_id'], $attributes);
    }

    // Image erzeugt nur die Vorschau und hält den Zustandswert separat.
    private function maintainImageAttributeVariables(array $entity): void
    {
        $attributes = $entity['attributes'] ?? [];
        if (!is_array($attributes)) {
            $attributes = [];
        }
        $attributes = $this->normalizeImageAttributes($attributes, __FUNCTION__);

        $this->maintainImagePreviewMedia($entity['entity_id'], 0);
        $this->updateImageAttributeValues($entity['entity_id'], $attributes);
    }

    // Event trennt Zeitstempel und Ereignistyp in zwei eigene Symcon-Variablen.
    private function maintainEventAttributeVariables(array $entity): void
    {
        $entityId = $entity['entity_id'] ?? '';
        if ($entityId === '') {
            return;
        }

        $attributes = $entity['attributes'] ?? [];
        if (!is_array($attributes)) {
            $attributes = [];
        }

        $hasEventType = array_key_exists(HAEventDefinitions::ATTRIBUTE_EVENT_TYPE, $attributes);
        $hasEventTypes = array_key_exists(HAEventDefinitions::ATTRIBUTE_EVENT_TYPES, $attributes);
        if (!$hasEventType && !$hasEventTypes) {
            return;
        }

        $ident = $this->getEventTypeIdent($entityId);
        $position = $this->getEntityPosition($entityId) + 1;
        $presentation = $this->getEventTypePresentation($attributes);
        $name = $this->getEventTypeVariableName($entity);
        $this->MaintainVariable($ident, $name, VARIABLETYPE_STRING, $presentation, $position, true);

        $eventType = $attributes[HAEventDefinitions::ATTRIBUTE_EVENT_TYPE] ?? null;
        if (!is_scalar($eventType)) {
            return;
        }

        $value = trim((string)$eventType);
        if ($value === '') {
            return;
        }

        $this->setValueWithDebug($ident, $value);
    }

    // Kamera-Updates pflegen Vorschau und optional den RTSP-Stream.
    private function updateCameraAttributeValues(string $entityId, array $attributes): void
    {
        $attributes = $this->normalizeCameraAttributes($attributes, __FUNCTION__);
        $this->updateCameraPreviewMedia($entityId);

        $streamUrl = $this->resolveCameraStreamUrl($entityId, $attributes);
        if ($streamUrl !== '') {
            $this->updateCameraStreamMedia($entityId, $streamUrl);
        }
    }

    // Image kennt nur eine Vorschauquelle, die auf ein Medienobjekt gespiegelt wird.
    private function updateImageAttributeValues(string $entityId, array $attributes): void
    {
        $attributes = $this->normalizeImageAttributes($attributes, __FUNCTION__);
        $previewUrl = $this->resolveImagePreviewUrl($entityId, $attributes);
        if ($previewUrl === '') {
            return;
        }

        $this->updateEntityPreviewMedia(
            $entityId,
            $previewUrl,
            self::IMAGE_PREVIEW_SUFFIX,
            $this->getImagePreviewMediaName($entityId),
            'ImagePreview',
            'ha_image_preview'
        );
    }

    // Image-Status wird als Zeitstempelwert auf die Hauptvariable geschrieben.
    private function updateImageStateValue(string $ident, string $state): void
    {
        if ($state === '') {
            return;
        }

        $value = $this->convertValueByDomain(HAImageDefinitions::DOMAIN, $state);
        if ($value === null || $this->shouldSkipStateSetValue($ident, $value)) {
            return;
        }

        $this->setValueWithDebug($ident, $value);
    }

    private function getEventTypeIdent(string $entityId): string
    {
        return $this->sanitizeIdent($entityId) . self::EVENT_TYPE_SUFFIX;
    }

    private function maintainCameraStreamMedia(string $entityId, int $basePosition): void
    {
        $this->ensureCameraStreamMedia($entityId, $basePosition);
    }

    private function ensureCameraStreamMedia(string $entityId, int $basePosition): bool
    {
        $ident = $this->sanitizeIdent($entityId) . self::CAMERA_STREAM_SUFFIX;
        $objectId = @$this->GetIDForIdent($ident);
        if ($objectId !== false) {
            $object = IPS_GetObject($objectId);
            if (($object['ObjectType'] ?? null) !== 5) {
                $this->debugExpert('CameraStream', 'Ident belegt, kein Medienobjekt', ['Ident' => $ident, 'ObjectType' => $object['ObjectType'] ?? null]);
                return false;
            }
            $this->syncCameraStreamMeta($objectId, $basePosition);
            return true;
        }

        $mediaId = IPS_CreateMedia(MEDIATYPE_STREAM);
        IPS_SetParent($mediaId, $this->InstanceID);
        IPS_SetIdent($mediaId, $ident);
        $this->syncCameraStreamMeta($mediaId, $basePosition);
        return true;
    }

    private function syncCameraStreamMeta(int $mediaId, int $basePosition): void
    {
        $ident = IPS_GetObject($mediaId)['ObjectIdent'] ?? '';
        $entityId = '';
        if (is_string($ident) && str_ends_with($ident, self::CAMERA_STREAM_SUFFIX)) {
            $entityId = substr($ident, 0, -strlen(self::CAMERA_STREAM_SUFFIX));
        }
        $resolvedEntityId = $entityId !== '' ? $this->getEntityIdByIdent($entityId) : null;
        $name = $resolvedEntityId !== null ? $this->getCameraStreamMediaName($resolvedEntityId) : $this->Translate('Stream');
        IPS_SetName($mediaId, $name);
        IPS_SetPosition($mediaId, $basePosition + 21);
        IPS_SetParent($mediaId, $this->InstanceID);
    }

    private function updateCameraStreamMedia(string $entityId, string $url): void
    {
        $trimmed = trim($url);
        if ($trimmed === '' || !$this->isRtspUrl($trimmed)) {
            return;
        }

        $ident = $this->sanitizeIdent($entityId) . self::CAMERA_STREAM_SUFFIX;
        $mediaId = @$this->GetIDForIdent($ident);
        if ($mediaId === false) {
            if (!$this->ensureCameraStreamMedia($entityId, 0)) {
                return;
            }
            $mediaId = @$this->GetIDForIdent($ident);
            if ($mediaId === false) {
                return;
            }
        }

        $media = IPS_GetMedia($mediaId);
        $current = (string)($media['MediaFile'] ?? '');
        if ($current !== $trimmed) {
            IPS_SetMediaFile($mediaId, $trimmed, false);
        }
        $this->debugExpert('CameraStream', 'Stream URL aktualisiert', ['Ident' => $ident, 'Url' => $trimmed]);
    }

    private function resolveCameraStreamUrl(string $entityId, ?array $attributes = null): string
    {
        $entity = $this->entities[$entityId] ?? null;
        if (!is_array($attributes)) {
            $entityAttributes = $entity['attributes'] ?? null;
            $attributes = is_array($entityAttributes) ? $entityAttributes : [];
        }

        $candidates = [];
        $streamSource = $attributes['stream_source'] ?? null;
        if (is_string($streamSource) && trim($streamSource) !== '') {
            $candidates[] = trim($streamSource);
        }
        $rtspSource = $attributes['rtsp_url'] ?? null;
        if (is_string($rtspSource) && trim($rtspSource) !== '') {
            $candidates[] = trim($rtspSource);
        }

        foreach ($candidates as $candidate) {
            if ($this->isRtspUrl($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    private function isRtspUrl(string $url): bool
    {
        return preg_match('#^rtsps?://#i', trim($url)) === 1;
    }

    private function isWritableFanAttribute(string $attribute, array $entityAttributes = []): bool
    {
        $meta = HAFanDefinitions::ATTRIBUTE_DEFINITIONS[$attribute] ?? null;
        if (!is_array($meta) || !($meta['writable'] ?? false)) {
            return false;
        }
        if (!empty($entityAttributes) && !$this->checkSupportedFeatures($meta, $entityAttributes)) {
            return false;
        }
        if (!empty($entityAttributes) && !$this->hasFanSelectableValues($attribute, $entityAttributes)) {
            return false;
        }
        return true;
    }

    // Listenbasierte Fan-Attribute werden nur mit belastbaren HA-Optionen schreibbar.
    private function hasFanSelectableValues(string $attribute, array $entityAttributes): bool
    {
        return match ($attribute) {
            'preset_mode' => $this->getFanSelectableValues($entityAttributes, 'preset_modes') !== [],
            'direction' => $this->getFanSelectableValues($entityAttributes, 'direction_list') !== [],
            default => true,
        };
    }

    private function getFanSelectableValues(array $entityAttributes, string $listAttribute): array
    {
        return HASelectDefinitions::normalizeOptions($entityAttributes[$listAttribute] ?? null);
    }

    private function isWritableClimateAttribute(string $attribute, array $entityAttributes = []): bool
    {
        $meta = HAClimateDefinitions::ATTRIBUTE_DEFINITIONS[$attribute] ?? null;
        if (!is_array($meta) || !($meta['writable'] ?? false)) {
            return false;
        }
        if (!empty($entityAttributes) && !$this->checkSupportedFeatures($meta, $entityAttributes)) {
            return false;
        }

        // Modus-Attribute sind nur sinnvoll, wenn HA die passenden Optionen liefert.
        if ($attribute === HAClimateDefinitions::ATTRIBUTE_HVAC_MODE) {
            return $this->getClimateSelectableValues($entityAttributes, HAClimateDefinitions::ATTRIBUTE_HVAC_MODES) !== [];
        }
        if ($attribute === HAClimateDefinitions::ATTRIBUTE_PRESET_MODE) {
            return $this->getClimateSelectableValues($entityAttributes, HAClimateDefinitions::ATTRIBUTE_PRESET_MODES) !== [];
        }
        if ($attribute === HAClimateDefinitions::ATTRIBUTE_FAN_MODE) {
            return $this->getClimateSelectableValues($entityAttributes, HAClimateDefinitions::ATTRIBUTE_FAN_MODES) !== [];
        }
        if ($attribute === HAClimateDefinitions::ATTRIBUTE_SWING_MODE) {
            return $this->getClimateSelectableValues($entityAttributes, HAClimateDefinitions::ATTRIBUTE_SWING_MODES) !== [];
        }
        if ($attribute === HAClimateDefinitions::ATTRIBUTE_SWING_HORIZONTAL_MODE) {
            return $this->getClimateSelectableValues($entityAttributes, HAClimateDefinitions::ATTRIBUTE_SWING_HORIZONTAL_MODES) !== [];
        }

        return true;
    }

    private function getClimateSelectableValues(array $entityAttributes, string $listAttribute): array
    {
        return HASelectDefinitions::normalizeOptions($entityAttributes[$listAttribute] ?? null);
    }

    private function shouldCreateClimateAttribute(string $attribute, array $meta, array $attributes): bool
    {
        $hasAttribute = array_key_exists($attribute, $attributes);
        if (!$hasAttribute) {
            if ($attribute === HAClimateDefinitions::ATTRIBUTE_HVAC_MODE) {
                $hasAttribute = array_key_exists(HAClimateDefinitions::ATTRIBUTE_HVAC_MODES, $attributes);
            } elseif ($attribute === HAClimateDefinitions::ATTRIBUTE_PRESET_MODE) {
                $hasAttribute = array_key_exists(HAClimateDefinitions::ATTRIBUTE_PRESET_MODES, $attributes);
            } elseif ($attribute === HAClimateDefinitions::ATTRIBUTE_FAN_MODE) {
                $hasAttribute = array_key_exists(HAClimateDefinitions::ATTRIBUTE_FAN_MODES, $attributes);
            } elseif ($attribute === HAClimateDefinitions::ATTRIBUTE_SWING_MODE) {
                $hasAttribute = array_key_exists(HAClimateDefinitions::ATTRIBUTE_SWING_MODES, $attributes);
            } elseif ($attribute === HAClimateDefinitions::ATTRIBUTE_SWING_HORIZONTAL_MODE) {
                $hasAttribute = array_key_exists(HAClimateDefinitions::ATTRIBUTE_SWING_HORIZONTAL_MODES, $attributes);
            }
        }

        if (($meta['writable'] ?? false) === true) {
            return $hasAttribute || $this->isWritableClimateAttribute($attribute, $attributes);
        }
        return $hasAttribute;
    }

    // Light-Attribute hängen stark von Features und Color-Modes ab.
    private function isWritableLightAttribute(string $attribute, array $entityAttributes = []): bool
    {
        $meta = HALightDefinitions::ATTRIBUTE_DEFINITIONS[$attribute] ?? null;
        if (!is_array($meta)) {
            return false;
        }
        if (!($meta['writable'] ?? false)) {
            return false;
        }

        if (!empty($entityAttributes)) {
            if (!$this->checkSupportedFeatures($meta, $entityAttributes)) {
                if ($attribute !== 'effect' || HASelectDefinitions::normalizeOptions($entityAttributes['effect_list'] ?? null) === []) {
                    return false;
                }
            }
            if (!$this->checkSupportedColorModes($meta, $entityAttributes)) {
                return false;
            }
        }

        return true;
    }

    private function shouldCreateLightAttribute(string $attribute, array $attributes): bool
    {
        if (array_key_exists($attribute, $attributes)) {
            return true;
        }

        $meta = HALightDefinitions::ATTRIBUTE_DEFINITIONS[$attribute] ?? null;
        if (!is_array($meta)) {
            return false;
        }

        if ($attribute === 'effect' && HASelectDefinitions::normalizeOptions($attributes['effect_list'] ?? null) !== []) {
            return true;
        }
        if ($attribute === 'color_mode') {
            $modes = HASelectDefinitions::normalizeOptions($attributes['supported_color_modes'] ?? null);
            $currentMode = $attributes['color_mode'] ?? null;
            return $modes !== [] || (is_string($currentMode) && trim($currentMode) !== '');
        }

        // Light-Fähigkeiten kommen oft nur über Features und Color-Modes, nicht über den aktuellen State.
        return $this->checkSupportedFeatures($meta, $attributes)
            && $this->checkSupportedColorModes($meta, $attributes)
            && (($meta['writable'] ?? false) === true);
    }

    // Light-Attribute werden nur angelegt, wenn sie im aktuellen Entity-Kontext sinnvoll sind.
    private function maintainLightAttributeVariables(array $entity): void
    {
        $attributes = $entity['attributes'] ?? [];
        if (!is_array($attributes)) {
            return;
        }

        $basePosition = $this->getEntityPosition((string)$entity['entity_id']);
        $this->maintainStandardAttributeVariables(
            $entity,
            HALightDefinitions::ATTRIBUTE_DEFINITIONS,
            fn(string $attribute, array $_meta, array $entityAttributes): bool => $this->shouldCreateLightAttribute($attribute, $entityAttributes),
            fn(string $attribute, array $entityAttributes, array $meta): array => $this->getLightAttributePresentation($attribute, $entityAttributes, $meta),
            fn(string $attribute, int $positionBase): int => $this->getLightAttributePosition($attribute, $positionBase),
            function (string $attribute, array $entityAttributes, string $ident) {
                $this->syncAttributeActionState($ident, $this->isWritableLightAttribute($attribute, $entityAttributes));
            },
            $basePosition,
            fn(string $attribute, array $_meta, array $entityAttributes): bool => $attribute !== 'color_mode' && !$this->isWritableLightAttribute($attribute, $entityAttributes),
            function (string $attribute, array $meta, string $entityId, array $entityAttributes) {
                $ident = $this->getAttributeVariableIdent($entityId, $attribute);
                $this->debugExpert('LightVars', 'Variable angelegt', [
                    'Ident' => $ident,
                    'Name' => $this->Translate((string)$meta['caption']),
                    'Presentation' => $this->getLightAttributePresentation($attribute, $entityAttributes, $meta)
                ]);
            },
            false
        );
    }

    // Erstellt Light-Attribute bei nachgelieferten Attributen aus Laufzeitdaten.
    private function ensureLightAttributeVariable(string $entityId, string $attribute): bool
    {
        return $this->ensureStandardAttributeVariable(
            $entityId,
            $attribute,
            HALightDefinitions::ATTRIBUTE_DEFINITIONS,
            fn(string $attributeName, array $_meta, array $entityAttributes): bool => $this->shouldCreateLightAttribute($attributeName, $entityAttributes),
            fn(string $attributeName, array $entityAttributes, array $meta): array => $this->getLightAttributePresentation($attributeName, $entityAttributes, $meta),
            fn(string $attributeName, int $positionBase): int => $this->getLightAttributePosition($attributeName, $positionBase),
            function (string $attributeName, array $entityAttributes, string $ident) {
                $this->syncAttributeActionState($ident, $this->isWritableLightAttribute($attributeName, $entityAttributes));
            },
            0,
            ['name' => $entityId],
            null,
            null,
            function (string $attributeName, array $meta, string $resolvedEntityId, array $entityAttributes) {
                $ident = $this->getAttributeVariableIdent($resolvedEntityId, $attributeName);
                $this->debugExpert('LightVars', 'Variable nachträglich angelegt', [
                    'Ident' => $ident,
                    'Name' => $this->Translate((string)$meta['caption']),
                    'Presentation' => $this->getLightAttributePresentation($attributeName, $entityAttributes, $meta)
                ]);
            }
        );
    }
    private function updateLightAttributeValues(string $entityId, array $attributes): void
    {
        $this->updateStandardAttributeValues(
            $entityId,
            $attributes,
            HALightDefinitions::ATTRIBUTE_DEFINITIONS,
            function (string $attribute, mixed $value, array $meta): mixed {
                if ($attribute === 'rgb_color') {
                    $value = $this->formatRgbColorStorageValue($value);
                } elseif (is_array($value)) {
                    $value = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                return $this->castVariableValue($value, $meta['type']);
            },
            null,
            HALightDefinitions::DOMAIN
        );
    }

    // Lock-Zusatzattribute spiegeln optionale HA-Metadaten wie Auslöser und Codeformat.
    private function maintainLockAttributeVariables(array $entity): void
    {
        $basePosition = $this->getEntityPosition((string)($entity['entity_id'] ?? ''));

        $this->maintainStandardAttributeVariables(
            $entity,
            HALockDefinitions::ATTRIBUTE_DEFINITIONS,
            static fn(string $attribute, array $_meta, array $attributes): bool => array_key_exists($attribute, $attributes),
            static fn(string $_attribute, array $_attributes, array $_meta): array => ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION],
            fn(string $attribute, int $positionBase): int => $this->getLockAttributePosition($attribute, $positionBase),
            function (string $_attribute, array $_attributes, string $ident) {
                $this->syncAttributeActionState($ident, false);
            },
            $basePosition
        );
    }

    private function ensureLockAttributeVariable(string $entityId, string $attribute): bool
    {
        $basePosition = $this->getEntityPosition($entityId);

        return $this->ensureStandardAttributeVariable(
            $entityId,
            $attribute,
            HALockDefinitions::ATTRIBUTE_DEFINITIONS,
            static fn(string $attributeName, array $_meta, array $attributes): bool => array_key_exists($attributeName, $attributes),
            static fn(string $_attributeName, array $_attributes, array $_meta): array => ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION],
            fn(string $attributeName, int $positionBase): int => $this->getLockAttributePosition($attributeName, $positionBase),
            function (string $_attributeName, array $_attributes, string $ident) {
                $this->syncAttributeActionState($ident, false);
            },
            $basePosition,
            ['name' => $entityId, 'attributes' => []]
        );
    }

    private function updateLockAttributeValues(string $entityId, array $attributes): void
    {
        $this->updateStandardAttributeValues(
            $entityId,
            $attributes,
            HALockDefinitions::ATTRIBUTE_DEFINITIONS,
            fn(string $_attribute, mixed $value, array $meta): mixed => $this->castVariableValue($value, $meta['type'])
        );
    }

    // Climate erzeugt Attribute auch dann, wenn nur die Optionslisten vorhanden sind.
    private function maintainClimateAttributeVariables(array $entity): void
    {
        $attributes = $entity['attributes'] ?? [];
        if (!is_array($attributes)) {
            $this->debugExpert('ClimateVars', 'Attributes kein Array', ['EntityID' => $entity['entity_id'] ?? null]);
            return;
        }

        $basePosition = $this->getEntityPosition((string)$entity['entity_id']);
        $this->maintainStandardAttributeVariables(
            $entity,
            HAClimateDefinitions::ATTRIBUTE_DEFINITIONS,
            fn(string $attribute, array $meta, array $entityAttributes): bool => $this->shouldCreateClimateAttribute($attribute, $meta, $entityAttributes),
            fn(string $attribute, array $entityAttributes, array $_meta): array => $this->getClimateAttributePresentation($attribute, $entityAttributes),
            fn(string $attribute, int $positionBase): int => $this->getClimateAttributePosition($attribute, $positionBase),
            function (string $attribute, array $entityAttributes, string $ident) {
                $this->syncAttributeActionState($ident, $this->isWritableClimateAttribute($attribute, $entityAttributes));
            },
            $basePosition,
            null,
            null,
            false
        );
    }

    // Climate-Attribute können erst nach dem ersten State vollständig beurteilbar sein.
    private function ensureClimateAttributeVariable(string $entityId, string $attribute): bool
    {
        $basePosition = $this->getEntityPosition($entityId);

        return $this->ensureStandardAttributeVariable(
            $entityId,
            $attribute,
            HAClimateDefinitions::ATTRIBUTE_DEFINITIONS,
            fn(string $attributeName, array $meta, array $entityAttributes): bool => $this->shouldCreateClimateAttribute($attributeName, $meta, $entityAttributes),
            fn(string $attributeName, array $entityAttributes, array $_meta): array => $this->getClimateAttributePresentation($attributeName, $entityAttributes),
            fn(string $attributeName, int $positionBase): int => $this->getClimateAttributePosition($attributeName, $positionBase),
            function (string $attributeName, array $entityAttributes, string $ident) {
                $this->syncAttributeActionState($ident, $this->isWritableClimateAttribute($attributeName, $entityAttributes));
            },
            $basePosition,
            ['name' => $entityId],
            static function (string $attributeName, array $attributes): array {
                if (!array_key_exists($attributeName, $attributes)) {
                    $attributes[$attributeName] = null;
                }
                return $attributes;
            }
        );
    }

    // Für HVAC-Mode wird notfalls der Hauptzustand als Fallback verwendet.
    private function updateClimateAttributeValues(string $entityId, array $attributes): void
    {
        $attributesWithFallback = $attributes;
        if (!array_key_exists(HAClimateDefinitions::ATTRIBUTE_HVAC_MODE, $attributesWithFallback)) {
            $state = $this->entities[$entityId][self::KEY_STATE] ?? null;
            if (is_string($state) && $state !== '') {
                $attributesWithFallback[HAClimateDefinitions::ATTRIBUTE_HVAC_MODE] = $state;
            }
        }

        $this->updateStandardAttributeValues(
            $entityId,
            $attributesWithFallback,
            HAClimateDefinitions::ATTRIBUTE_DEFINITIONS,
            fn(string $_attribute, mixed $value, array $meta): mixed => $this->castVariableValue($value, $meta['type']),
            null,
            HAClimateDefinitions::DOMAIN
        );
    }

    // Climate bleibt bei einer einfachen, festen Reihenfolge entlang der Definitionsliste.
    private function getClimateAttributePosition(string $attribute, int $basePosition): int
    {
        $ordered = array_keys(HAClimateDefinitions::ATTRIBUTE_DEFINITIONS);
        $index   = array_search($attribute, $ordered, true);
        if ($index === false) {
            return $basePosition + 200;
        }
        return $basePosition + 10 + $index;
    }

    // Der Hauptwert bevorzugt je nach Featurelage Soll- oder Ist-Temperatur.
    private function extractClimateMainValue(array $attributes): ?float
    {
        $supported = (int)($attributes[HAClimateDefinitions::ATTRIBUTE_SUPPORTED_FEATURES] ?? 0);
        $preferTarget = ($supported & 1) === 1;

        $candidates = $preferTarget
            ? [
                HAClimateDefinitions::ATTRIBUTE_TARGET_TEMPERATURE,
                'temperature',
                HAClimateDefinitions::ATTRIBUTE_CURRENT_TEMPERATURE
            ]
            : [
                HAClimateDefinitions::ATTRIBUTE_CURRENT_TEMPERATURE,
                HAClimateDefinitions::ATTRIBUTE_TARGET_TEMPERATURE,
                'temperature'
            ];
        foreach ($candidates as $key) {
            $value = $attributes[$key] ?? null;
            if (is_numeric($value)) {
                return (float)$value;
            }
        }
        return null;
    }

    // Cover-Attribute sind klein genug, um direkt am Hauptwert zu hängen.
    private function maintainCoverAttributeVariables(array $entity): void
    {
        $attributes = $entity['attributes'] ?? [];
        if (!is_array($attributes)) {
            $attributes = [];
        }

        $basePosition = $this->getEntityPosition((string)($entity['entity_id'] ?? ''));
        $this->maintainStandardAttributeVariables(
            $entity,
            $this->getMaintainedCoverAttributeDefinitions($attributes),
            fn(string $attribute, array $meta, array $entityAttributes): bool => $this->shouldCreateCoverAttribute($attribute, $meta, $entityAttributes),
            fn(string $_attribute, array $_entityAttributes, array $meta): array => $this->getCoverAttributePresentation($meta),
            fn(string $attribute, int $positionBase): int => $this->getCoverAttributePosition($attribute, $positionBase),
            function (string $attribute, array $entityAttributes, string $ident) {
                $this->syncAttributeActionState($ident, $this->isWritableCoverAttribute($attribute, $entityAttributes));
            },
            $basePosition,
            null,
            null,
            false
        );
    }

    private function ensureCoverAttributeVariable(string $entityId, string $attribute): bool
    {
        $entity = $this->entities[$entityId] ?? [
            'entity_id'  => $entityId,
            'attributes' => []
        ];
        $attributes = $entity['attributes'] ?? [];
        if (!is_array($attributes)) {
            $attributes = [];
        }

        $basePosition = $this->getEntityPosition($entityId);
        return $this->ensureStandardAttributeVariable(
            $entityId,
            $attribute,
            $this->getMaintainedCoverAttributeDefinitions($attributes),
            fn(string $attributeName, array $meta, array $entityAttributes): bool => $this->shouldCreateCoverAttribute($attributeName, $meta, $entityAttributes),
            fn(string $_attributeName, array $_entityAttributes, array $meta): array => $this->getCoverAttributePresentation($meta),
            fn(string $attributeName, int $positionBase): int => $this->getCoverAttributePosition($attributeName, $positionBase),
            function (string $attributeName, array $entityAttributes, string $ident) {
                $this->syncAttributeActionState($ident, $this->isWritableCoverAttribute($attributeName, $entityAttributes));
            },
            $basePosition,
            ['attributes' => []]
        );
    }

    // Cover spiegelt Positionsattribute auf Zusatzvariablen und Hauptwert.
    private function updateCoverAttributeValues(string $entityId, array $attributes, string $state = ''): void
    {
        $definitions = $this->getMaintainedCoverAttributeDefinitions($attributes);
        $attributeValues = [];
        foreach (array_keys($definitions) as $attribute) {
            $rawValue = $this->getCoverAttributeValue($attributes, $attribute);
            if (is_numeric($rawValue)) {
                $attributeValues[$attribute] = $rawValue;
            }
        }

        $this->updateStandardAttributeValues(
            $entityId,
            $attributeValues,
            $definitions,
            fn(string $_attribute, mixed $value, array $meta): mixed => $this->castVariableValue($value, $meta['type'])
        );

        $mainValue = $this->resolveCoverMainValue($attributes, $state);
        if ($mainValue === null) {
            return;
        }

        $ident = $this->sanitizeIdent($entityId);
        if (@$this->GetIDForIdent($ident) === false) {
            return;
        }
        $this->setEntityMainValue($entityId, $ident, $mainValue, $state);
    }

    // Cover verwendet die Reihenfolge der Attributdefinitionen.
    private function getCoverAttributePosition(string $attribute, int $basePosition): int
    {
        $ordered = $this->getMaintainedCoverAttributes();
        $index   = array_search($attribute, $ordered, true);
        if ($index === false) {
            return $basePosition + 200;
        }
        return $basePosition + 10 + $index;
    }

    // Einige Integrationen liefern alternative Positionsschlüssel.
    private function shouldCreateCoverAttribute(string $attribute, array $meta, array $attributes): bool
    {
        $hasAttribute = is_numeric($this->getCoverAttributeValue($attributes, $attribute));
        $isPrimaryAttribute = in_array($attribute, $this->getMaintainedCoverAttributes($attributes), true);
        if (($meta['writable'] ?? false) === true) {
            if (!$hasAttribute && !$isPrimaryAttribute) {
                return false;
            }
            return $hasAttribute || $this->isWritableCoverAttribute($attribute, $attributes);
        }
        return $hasAttribute;
    }

    private function getMaintainedCoverAttributes(array $attributes = []): array
    {
        if (!$this->shouldShowCoverTiltAttribute($attributes)) {
            return [];
        }

        return [HACoverDefinitions::ATTRIBUTE_TILT_POSITION];
    }

    // Cover pflegt nur die aktuell im Entity-Kontext relevanten Zusatzattribute.
    private function getMaintainedCoverAttributeDefinitions(array $attributes = []): array
    {
        $keys = $this->getMaintainedCoverAttributes($attributes);
        if ($keys === []) {
            return [];
        }

        return array_intersect_key(HACoverDefinitions::ATTRIBUTE_DEFINITIONS, array_flip($keys));
    }

    private function getCoverAttributeValue(array $attributes, string $attribute): mixed
    {
        return match ($attribute) {
            HACoverDefinitions::ATTRIBUTE_TILT_POSITION => $attributes[HACoverDefinitions::ATTRIBUTE_TILT_POSITION]
                ?? $attributes[HACoverDefinitions::ATTRIBUTE_TILT_POSITION_ALT]
                ?? null,
            HACoverDefinitions::ATTRIBUTE_POSITION => $attributes[HACoverDefinitions::ATTRIBUTE_POSITION]
                ?? $attributes[HACoverDefinitions::ATTRIBUTE_POSITION_ALT]
                ?? null,
            default => $attributes[$attribute] ?? null,
        };
    }

    private function shouldShowCoverTiltAttribute(array $attributes): bool
    {
        $deviceClass = strtolower(trim((string)($attributes['device_class'] ?? '')));
        if (in_array($deviceClass, [
            HACoverDefinitions::DEVICE_CLASS_GARAGE,
            HACoverDefinitions::DEVICE_CLASS_GATE,
            HACoverDefinitions::DEVICE_CLASS_DOOR,
            HACoverDefinitions::DEVICE_CLASS_WINDOW,
            HACoverDefinitions::DEVICE_CLASS_DAMPER
        ], true)) {
            return false;
        }

        if (in_array($deviceClass, [
            HACoverDefinitions::DEVICE_CLASS_CURTAIN,
            HACoverDefinitions::DEVICE_CLASS_SHADE,
            HACoverDefinitions::DEVICE_CLASS_AWNING,
            ''
        ], true)) {
            return is_numeric($this->getCoverAttributeValue($attributes, HACoverDefinitions::ATTRIBUTE_TILT_POSITION));
        }

        return true;
    }

    private function extractCoverPosition(array $attributes): ?float
    {
        $candidates = [
            HACoverDefinitions::ATTRIBUTE_POSITION,
            HACoverDefinitions::ATTRIBUTE_POSITION_ALT
        ];
        foreach ($candidates as $key) {
            $value = $attributes[$key] ?? null;
            if (is_numeric($value)) {
                return (float)$value;
            }
        }
        return null;
    }

    private function isCoverPositionEntity(array $attributes, string $state = ''): bool
    {
        if ($this->extractCoverPosition($attributes) !== null) {
            return true;
        }

        if ($this->isCoverPositionSupported($attributes)) {
            return true;
        }

        return is_numeric(trim($state));
    }

    // Cover bevorzugt numerische Positionsattribute vor textuellen Statuswerten.
    private function resolveCoverMainValue(array $attributes, string $state): ?float
    {
        $position = $this->extractCoverPosition($attributes);
        if ($position !== null) {
            return $position;
        }

        return $this->normalizeCoverStateToLevel($state);
    }

    // Textzustände werden auf eine Prozentposition abgebildet, wenn kein Zahlenwert vorliegt.
    private function normalizeCoverStateToLevel(string $state): ?float
    {
        $text = strtolower(trim($state));
        if ($text === '') {
            return null;
        }
        if (is_numeric($text)) {
            return (float)$text;
        }
        return match ($text) {
            'open', 'opened' => 100.0,
            'closed' => 0.0,
            default => null
        };
    }

    private function normalizeCoverState(string $state): string
    {
        $text = strtolower(trim($state));
        return match ($text) {
            'open', 'opened' => 'open',
            'close', 'closed' => 'closed',
            'opening' => 'opening',
            'closing' => 'closing',
            default => $text
        };
    }

    private function updateValveAttributeValues(string $entityId, array $attributes, string $state = ''): void
    {
        if (!$this->isValvePositionEntity($attributes, $state)) {
            return;
        }

        $mainValue = $this->resolveValveMainValue($attributes, $state);
        if ($mainValue === null) {
            return;
        }

        $ident = $this->sanitizeIdent($entityId);
        if (@$this->GetIDForIdent($ident) === false) {
            return;
        }

        $this->setEntityMainValue($entityId, $ident, $mainValue, $state);
    }

    private function extractValvePosition(array $attributes): ?float
    {
        foreach ([HAValveDefinitions::ATTRIBUTE_POSITION, HAValveDefinitions::ATTRIBUTE_POSITION_ALT] as $key) {
            $value = $attributes[$key] ?? null;
            if (!is_numeric($value)) {
                continue;
            }

            return $this->clampFloat((float)$value, 0.0, 100.0);
        }

        return null;
    }

    private function isValvePositionEntity(array $attributes, string $state = ''): bool
    {
        if ($this->extractValvePosition($attributes) !== null) {
            return true;
        }

        if ($this->isValvePositionSupported($attributes)) {
            return true;
        }

        return is_numeric(trim($state));
    }

    private function isValvePositionSupported(array $attributes): bool
    {
        $supported = (int)($attributes[self::KEY_SUPPORTED_FEATURES] ?? 0);
        if (($supported & HAValveDefinitions::FEATURE_SET_POSITION) === HAValveDefinitions::FEATURE_SET_POSITION) {
            return true;
        }

        return $this->boolAttributeIsTrue($attributes[HAValveDefinitions::ATTRIBUTE_REPORTS_POSITION] ?? null);
    }

    private function resolveValveMainValue(array $attributes, string $state): ?float
    {
        $position = $this->extractValvePosition($attributes);
        if ($position !== null) {
            return $position;
        }

        $normalized = $this->normalizeValveState($state);
        return match ($normalized) {
            'open' => 100.0,
            'closed' => 0.0,
            default => null
        };
    }

    private function normalizeValveState(string $state): string
    {
        $text = strtolower(trim($state));
        return match ($text) {
            'open', 'opened' => 'open',
            'close', 'closed' => 'closed',
            'opening' => 'opening',
            'closing' => 'closing',
            'stop', 'stopped' => 'stopped',
            default => $text
        };
    }

    private function boolAttributeIsTrue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value !== 0;
        }
        if (!is_string($value)) {
            return false;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    // Light hat eine bevorzugte Reihenfolge und sortiert den Rest stabil dahinter ein.
    private function getLightAttributePosition(string $attribute, int $basePosition): int
    {
        $ordered = HALightDefinitions::ATTRIBUTE_ORDER;
        $index   = array_search($attribute, $ordered, true);
        if ($index !== false) {
            return $basePosition + 10 + $index;
        }

        $remaining = array_diff(array_keys(HALightDefinitions::ATTRIBUTE_DEFINITIONS), $ordered);
        sort($remaining, SORT_STRING);
        $fallbackIndex = array_search($attribute, $remaining, true);
        if ($fallbackIndex === false) {
            return $basePosition + 200;
        }

        return $basePosition + 100 + $fallbackIndex;
    }

    // Entity-Positionen werden zentral vorab berechnet und hier nur gelesen.
    private function getEntityPosition(string $entityId): int
    {
        return (int)($this->entities[$entityId]['position_base'] ?? 10);
    }

    private function shouldCreateFanAttribute(string $attribute, array $meta, array $attributes): bool
    {
        $hasAttribute = array_key_exists($attribute, $attributes);
        if (!$hasAttribute) {
            if ($attribute === 'preset_mode') {
                $hasAttribute = array_key_exists('preset_modes', $attributes);
            } elseif ($attribute === 'direction' || $attribute === 'current_direction') {
                $hasAttribute = array_key_exists('direction_list', $attributes);
            }
        }

        if (($meta['writable'] ?? false) === true) {
            return $hasAttribute || $this->isWritableFanAttribute($attribute, $attributes);
        }
        return $hasAttribute;
    }

    private function applyFanAttributeActionState(string $attribute, array $attributes, string $ident): void
    {
        $this->syncAttributeActionState($ident, $this->isWritableFanAttribute($attribute, $attributes));
    }

    private function maintainFanAttributeVariables(array $entity): void
    {
        $this->maintainStandardAttributeVariables(
            $entity,
            HAFanDefinitions::ATTRIBUTE_DEFINITIONS,
            fn(string $attribute, array $meta, array $attributes): bool => $this->shouldCreateFanAttribute($attribute, $meta, $attributes),
            fn(string $attribute, array $attributes, array $meta): array => $this->getFanAttributePresentation($attribute, $attributes, $meta),
            fn(string $attribute, int $basePosition): int => $this->getFanAttributePosition($attribute, $basePosition),
            function (string $attribute, array $attributes, string $ident, array $_presentation, array $_meta): void {
                $this->applyFanAttributeActionState($attribute, $attributes, $ident);
            },
            0,
            null,
            null,
            false
        );
    }

    private function ensureFanAttributeVariable(string $entityId, string $attribute): bool
    {
        return $this->ensureStandardAttributeVariable(
            $entityId,
            $attribute,
            HAFanDefinitions::ATTRIBUTE_DEFINITIONS,
            fn(string $attributeName, array $meta, array $attributes): bool => $this->shouldCreateFanAttribute($attributeName, $meta, $attributes),
            fn(string $attributeName, array $attributes, array $meta): array => $this->getFanAttributePresentation($attributeName, $attributes, $meta),
            fn(string $attributeName, int $basePosition): int => $this->getFanAttributePosition($attributeName, $basePosition),
            function (string $attributeName, array $attributes, string $ident, array $_presentation, array $_meta): void {
                $this->applyFanAttributeActionState($attributeName, $attributes, $ident);
            },
            0,
            ['name' => $entityId],
            static function (string $attributeName, array $attributes): array {
                if (!array_key_exists($attributeName, $attributes)) {
                    $attributes[$attributeName] = null;
                }
                return $attributes;
            }
        );
    }

    private function updateFanAttributeValues(string $entityId, array $attributes): void
    {
        $this->updateStandardAttributeValues(
            $entityId,
            $attributes,
            HAFanDefinitions::ATTRIBUTE_DEFINITIONS,
            fn(string $_attribute, mixed $value, array $meta): mixed => $this->castVariableValue($value, $meta['type']),
            null,
            HAFanDefinitions::DOMAIN
        );
    }

    private function buildFanAttributePayload(string $attribute, mixed $value): string
    {
        $meta = HAFanDefinitions::ATTRIBUTE_DEFINITIONS[$attribute] ?? null;
        if (!is_array($meta) || !($meta['writable'] ?? false)) {
            return '';
        }
        $payloadValue = $this->parseFanAttributeValue($attribute, $value);
        return json_encode([$attribute => $payloadValue], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function parseFanAttributeValue(string $attribute, mixed $value): mixed
    {
        return match ($attribute) {
            'percentage' => (int) round($this->clampFloat((float) $value, 0.0, 100.0)),
            'oscillating' => (bool)$value,
            'preset_mode', 'direction' => trim((string)$value),
            default => $value,
        };
    }

    private function isWritableHumidifierAttribute(string $attribute, array $entityAttributes = []): bool
    {
        $meta = HAHumidifierDefinitions::ATTRIBUTE_DEFINITIONS[$attribute] ?? null;
        if (!is_array($meta) || !($meta['writable'] ?? false)) {
            return false;
        }
        if (!empty($entityAttributes) && !$this->checkSupportedFeatures($meta, $entityAttributes)) {
            return false;
        }
        if (!empty($entityAttributes) && !$this->hasHumidifierSelectableValues($attribute, $entityAttributes)) {
            return false;
        }
        return true;
    }

    // Listenbasierte Humidifier-Attribute werden nur mit belastbaren HA-Optionen schreibbar.
    private function hasHumidifierSelectableValues(string $attribute, array $entityAttributes): bool
    {
        return match ($attribute) {
            HAHumidifierDefinitions::ATTRIBUTE_MODE => $this->getHumidifierSelectableValues($entityAttributes, 'available_modes') !== [],
            default => true,
        };
    }

    private function getHumidifierSelectableValues(array $entityAttributes, string $listAttribute): array
    {
        return HASelectDefinitions::normalizeOptions($entityAttributes[$listAttribute] ?? null);
    }

    private function shouldCreateHumidifierAttribute(string $attribute, array $meta, array $attributes): bool
    {
        $hasAttribute = array_key_exists($attribute, $attributes);
        if (!$hasAttribute && $attribute === 'mode') {
            $hasAttribute = array_key_exists('available_modes', $attributes);
        }

        if (($meta['writable'] ?? false) === true) {
            return $hasAttribute || $this->isWritableHumidifierAttribute($attribute, $attributes);
        }
        return $hasAttribute;
    }

    private function applyHumidifierAttributeActionState(string $attribute, array $attributes, string $ident): void
    {
        $this->syncAttributeActionState($ident, $this->isWritableHumidifierAttribute($attribute, $attributes));
    }

    private function maintainHumidifierAttributeVariables(array $entity): void
    {
        $this->maintainStandardAttributeVariables(
            $entity,
            HAHumidifierDefinitions::ATTRIBUTE_DEFINITIONS,
            fn(string $attribute, array $meta, array $attributes): bool => $this->shouldCreateHumidifierAttribute($attribute, $meta, $attributes),
            fn(string $attribute, array $attributes, array $meta): array => $this->getHumidifierAttributePresentation($attribute, $attributes, $meta),
            fn(string $attribute, int $basePosition): int => $this->getHumidifierAttributePosition($attribute, $basePosition),
            function (string $attribute, array $attributes, string $ident, array $_presentation, array $_meta): void {
                $this->applyHumidifierAttributeActionState($attribute, $attributes, $ident);
            },
            0,
            null,
            null,
            false
        );
    }

    private function ensureHumidifierAttributeVariable(string $entityId, string $attribute): bool
    {
        return $this->ensureStandardAttributeVariable(
            $entityId,
            $attribute,
            HAHumidifierDefinitions::ATTRIBUTE_DEFINITIONS,
            fn(string $attributeName, array $meta, array $attributes): bool => $this->shouldCreateHumidifierAttribute($attributeName, $meta, $attributes),
            fn(string $attributeName, array $attributes, array $meta): array => $this->getHumidifierAttributePresentation($attributeName, $attributes, $meta),
            fn(string $attributeName, int $basePosition): int => $this->getHumidifierAttributePosition($attributeName, $basePosition),
            function (string $attributeName, array $attributes, string $ident, array $_presentation, array $_meta): void {
                $this->applyHumidifierAttributeActionState($attributeName, $attributes, $ident);
            },
            0,
            ['name' => $entityId],
            static function (string $attributeName, array $attributes): array {
                if (!array_key_exists($attributeName, $attributes)) {
                    $attributes[$attributeName] = null;
                }
                return $attributes;
            }
        );
    }

    private function updateHumidifierAttributeValues(string $entityId, array $attributes): void
    {
        $this->updateStandardAttributeValues(
            $entityId,
            $attributes,
            HAHumidifierDefinitions::ATTRIBUTE_DEFINITIONS,
            fn(string $_attribute, mixed $value, array $meta): mixed => $this->castVariableValue($value, $meta['type']),
            null,
            HAHumidifierDefinitions::DOMAIN
        );
    }

    private function buildHumidifierAttributePayload(string $attribute, mixed $value): string
    {
        $meta = HAHumidifierDefinitions::ATTRIBUTE_DEFINITIONS[$attribute] ?? null;
        if (!is_array($meta) || !($meta['writable'] ?? false)) {
            return '';
        }
        $payloadValue = $this->parseHumidifierAttributeValue($attribute, $value);
        return json_encode([$attribute => $payloadValue], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function parseHumidifierAttributeValue(string $attribute, mixed $value): mixed
    {
        return match ($attribute) {
            HAHumidifierDefinitions::ATTRIBUTE_TARGET_HUMIDITY => $this->clampFloat((float) $value, 0.0, 100.0),
            HAHumidifierDefinitions::ATTRIBUTE_MODE => trim((string)$value),
            default => $value,
        };
    }

    private function refreshAttributePresentation(
        string $ident,
        string $caption,
        int $type,
        array $presentation,
        int $position
    ): void {
        $this->MaintainVariable($ident, $this->Translate($caption), $type, $presentation, $position, true);
    }

    // Capability-Updates dürfen bestehende Action-Flags nicht nachträglich umschalten.
    private function refreshDomainAttributePresentations(string $domain, string $entityId, array $attributes): void
    {
        $attributeTriggers = $this->getDomainAttributeRefreshTriggers($domain);
        foreach ($attributeTriggers as $attribute => $triggerKeys) {
            if ($this->hasAnyAttributeKey($attributes, $triggerKeys)) {
                $this->refreshDomainAttributePresentationIfExists($domain, $entityId, $attribute, $attributes);
            }
        }

        $actionTriggers = $this->getDomainActionStateRefreshTriggers($domain);
        foreach ($actionTriggers as $attribute => $triggerKeys) {
            if (!$this->hasAnyAttributeKey($attributes, $triggerKeys)) {
                continue;
            }
            $this->ensureDomainActionVariable($domain, $entityId, $attribute, $attributes);
        }
    }

    private function hasAnyAttributeKey(array $attributes, array $keys): bool
    {
        return array_any($keys, static fn($key) => array_key_exists($key, $attributes));
    }

    private function getDomainAttributeRefreshTriggers(string $domain): array
    {
        return match ($domain) {
            HAFanDefinitions::DOMAIN => HAFanDefinitions::ATTRIBUTE_REFRESH_TRIGGERS ?? [],
            HAHumidifierDefinitions::DOMAIN => HAHumidifierDefinitions::ATTRIBUTE_REFRESH_TRIGGERS ?? [],
            HAMediaPlayerDefinitions::DOMAIN => HAMediaPlayerDefinitions::ATTRIBUTE_REFRESH_TRIGGERS ?? [],
            HALightDefinitions::DOMAIN => HALightDefinitions::ATTRIBUTE_REFRESH_TRIGGERS ?? [],
            HAClimateDefinitions::DOMAIN => HAClimateDefinitions::ATTRIBUTE_REFRESH_TRIGGERS ?? [],
            default => []
        };
    }

    private function getDomainActionStateRefreshTriggers(string $domain): array
    {
        return match ($domain) {
            HAFanDefinitions::DOMAIN => HAFanDefinitions::ACTION_STATE_REFRESH_TRIGGERS ?? [],
            HAHumidifierDefinitions::DOMAIN => HAHumidifierDefinitions::ACTION_STATE_REFRESH_TRIGGERS ?? [],
            HAMediaPlayerDefinitions::DOMAIN => HAMediaPlayerDefinitions::ACTION_STATE_REFRESH_TRIGGERS ?? [],
            HALightDefinitions::DOMAIN => HALightDefinitions::ACTION_STATE_REFRESH_TRIGGERS ?? [],
            HAClimateDefinitions::DOMAIN => HAClimateDefinitions::ACTION_STATE_REFRESH_TRIGGERS ?? [],
            default => []
        };
    }

    private function refreshDomainAttributePresentationIfExists(
        string $domain,
        string $entityId,
        string $attribute,
        array $attributes
    ): void {
        $basePosition = $this->getEntityPosition($entityId);

        switch ($domain) {
            case HAFanDefinitions::DOMAIN: {
                $this->refreshStandardAttributePresentationIfExists(
                    $entityId,
                    $attribute,
                    $attributes,
                    HAFanDefinitions::ATTRIBUTE_DEFINITIONS,
                    fn(string $attributeName, array $attributeData, array $meta): array => $this->getFanAttributePresentation($attributeName, $attributeData, $meta),
                    fn(string $attributeName, int $positionBase): int => $this->getFanAttributePosition($attributeName, $positionBase)
                );
                return;
            }
            case HAHumidifierDefinitions::DOMAIN: {
                $this->refreshStandardAttributePresentationIfExists(
                    $entityId,
                    $attribute,
                    $attributes,
                    HAHumidifierDefinitions::ATTRIBUTE_DEFINITIONS,
                    fn(string $attributeName, array $attributeData, array $meta): array => $this->getHumidifierAttributePresentation($attributeName, $attributeData, $meta),
                    fn(string $attributeName, int $positionBase): int => $this->getHumidifierAttributePosition($attributeName, $positionBase)
                );
                return;
            }
            case HAMediaPlayerDefinitions::DOMAIN: {
                $this->refreshStandardAttributePresentationIfExists(
                    $entityId,
                    $attribute,
                    $attributes,
                    HAMediaPlayerDefinitions::ATTRIBUTE_DEFINITIONS,
                    fn(string $attributeName, array $attributeData, array $meta): array => $this->getMediaPlayerAttributePresentation($attributeName, $attributeData, $meta),
                    fn(string $attributeName, int $positionBase): int => $this->getMediaPlayerAttributePosition($attributeName, $positionBase)
                );
                return;
            }
            case HALightDefinitions::DOMAIN: {
                $this->refreshStandardAttributePresentationIfExists(
                    $entityId,
                    $attribute,
                    $attributes,
                    HALightDefinitions::ATTRIBUTE_DEFINITIONS,
                    fn(string $attributeName, array $attributeData, array $meta): array => $this->getLightAttributePresentation($attributeName, $attributeData, $meta),
                    fn(string $attributeName, int $positionBase): int => $this->getLightAttributePosition($attributeName, $positionBase),
                    $basePosition
                );
                return;
            }
            case HAClimateDefinitions::DOMAIN: {
                $this->refreshStandardAttributePresentationIfExists(
                    $entityId,
                    $attribute,
                    $attributes,
                    HAClimateDefinitions::ATTRIBUTE_DEFINITIONS,
                    fn(string $attributeName, array $attributeData, array $_meta): array => $this->getClimateAttributePresentation($attributeName, $attributeData),
                    fn(string $attributeName, int $positionBase): int => $this->getClimateAttributePosition($attributeName, $positionBase),
                    $basePosition
                );
                return;
            }
            default:
        }
    }

    // Late Capability-Topics dürfen fehlende Variablen anlegen, aber bestehende Action-Flags nicht ändern.
    private function ensureDomainActionVariable(
        string $domain,
        string $entityId,
        string $attribute,
        array $attributes
    ): void {
        if ($domain === HALightDefinitions::DOMAIN && $attribute === '*') {
            foreach (HALightDefinitions::ATTRIBUTE_DEFINITIONS as $key => $_meta) {
                $ident = $this->sanitizeIdent($entityId . '_' . $key);
                if ($this->attributeVariableExists($ident) || !$this->shouldCreateLightAttribute($key, $attributes)) {
                    continue;
                }
                $this->ensureLightAttributeVariable($entityId, $key);
            }
            return;
        }

        $ident = $this->sanitizeIdent($entityId . '_' . $attribute);
        if ($this->attributeVariableExists($ident)) {
            return;
        }

        if ($domain === HAHumidifierDefinitions::DOMAIN) {
            $this->ensureHumidifierAttributeVariable($entityId, $attribute);
            return;
        }
        if ($domain === HAFanDefinitions::DOMAIN) {
            $this->ensureFanAttributeVariable($entityId, $attribute);
            return;
        }
        if ($domain === HAClimateDefinitions::DOMAIN) {
            $this->ensureClimateAttributeVariable($entityId, $attribute);
            return;
        }
        if ($domain === HAMediaPlayerDefinitions::DOMAIN) {
            $this->ensureMediaPlayerAttributeVariable($entityId, $attribute);
        }
    }

    private function updateMediaPlayerAttributeValues(string $entityId, array $attributes): void
    {
        $this->updateStandardAttributeValues(
            $entityId,
            $attributes,
            HAMediaPlayerDefinitions::ATTRIBUTE_DEFINITIONS,
            function (string $attribute, mixed $value, array $meta): mixed {
                if ($attribute === 'repeat') {
                    return $this->mapMediaPlayerRepeatToValue($value);
                }
                return $this->castVariableValue($value, $meta['type']);
            },
            function (string $attribute, mixed $value) use ($entityId) {
                if ($attribute === 'media_image_url' && is_string($value)) {
                    $this->updateMediaPlayerCoverMedia($entityId, $value);
                }
            },
            HAMediaPlayerDefinitions::DOMAIN
        );
    }

    private function applyMediaPlayerAttributeActionState(
        string $attribute,
        array $attributes,
        array $presentation,
        string $ident
    ): void {
        $presentationId = $presentation['PRESENTATION'] ?? '';
        if ($attribute === 'media_position') {
            $useAction = $presentationId === VARIABLE_PRESENTATION_SLIDER
                && $this->isWritableMediaPlayerAttribute($attribute, $attributes);
        } else {
            $useAction = $this->isWritableMediaPlayerAttribute($attribute, $attributes);
        }
        $this->syncAttributeActionState($ident, $useAction);
    }
    private function getMediaPlayerAttributePosition(string $attribute, int $basePosition): int
    {
        return $this->getMediaPlayerOrderPosition($basePosition, $attribute);
    }

    private function getLockAttributePosition(string $attribute, int $basePosition): int
    {
        $index = array_search($attribute, HALockDefinitions::ATTRIBUTE_ORDER, true);
        if ($index === false) {
            return $basePosition + 90;
        }
        return $basePosition + 6 + $index;
    }
    private function getMediaPlayerCoverPosition(int $basePosition): int
    {
        return $this->getMediaPlayerOrderPosition($basePosition, 'media_cover');
    }

    private function getMediaPlayerOrderPosition(int $basePosition, string $key): int
    {
        $ordered = HAMediaPlayerDefinitions::ATTRIBUTE_ORDER;
        $index = array_search($key, $ordered, true);
        if ($index === false) {
            return $basePosition + 200;
        }
        return $basePosition + (($index + 1) * 10);
    }

    private function getFanAttributePosition(string $attribute, int $basePosition): int
    {
        return $this->getFanOrderPosition($basePosition, $attribute);
    }

    private function getFanOrderPosition(int $basePosition, string $key): int
    {
        $ordered = HAFanDefinitions::ATTRIBUTE_ORDER;
        $index = array_search($key, $ordered, true);
        if ($index === false) {
            return $basePosition + 200;
        }
        return $basePosition + (($index + 1) * 10);
    }

    private function getHumidifierAttributePosition(string $attribute, int $basePosition): int
    {
        return $this->getHumidifierOrderPosition($basePosition, $attribute);
    }

    private function getHumidifierOrderPosition(int $basePosition, string $key): int
    {
        $ordered = HAHumidifierDefinitions::ATTRIBUTE_ORDER;
        $index = array_search($key, $ordered, true);
        if ($index === false) {
            return $basePosition + 200;
        }
        return $basePosition + (($index + 1) * 10);
    }

    // Featurebits werden domainübergreifend für Schreibbarkeit und Sichtbarkeit verwendet.
    private function checkSupportedFeatures(array $meta, array $attributes): bool
    {
        $required = $meta['requires_features'] ?? [];
        if (!is_array($required) || count($required) === 0) {
            return true;
        }

        $mask = (int)($attributes['supported_features'] ?? 0);
        return array_all($required, static fn($bit) => ($mask & (int)$bit) === (int)$bit);
    }

    // Einige Light-Attribute sind nur in bestimmten Color-Modes verfügbar.
    private function checkSupportedColorModes(array $meta, array $attributes): bool
    {
        $required = $meta['requires_color_modes'] ?? [];
        if (!is_array($required) || count($required) === 0) {
            return true;
        }

        $modes = $attributes['supported_color_modes'] ?? null;
        if (!is_array($modes) || count($modes) === 0) {
            return true;
        }

        return array_any($required, static fn($mode) => in_array($mode, $modes, true));
    }

    private function getMediaPlayerLinkedPosition(string $entityId, string $domain): ?int
    {
        if ($domain === HASwitchDefinitions::DOMAIN) {
            $suffixMap = [
                'cross_fade' => ['cross_fade', 'crossfade'],
                'loudness' => ['loudness']
            ];
        } elseif ($domain === HANumberDefinitions::DOMAIN) {
            $suffixMap = [
                'balance' => ['balance'],
                'bass' => ['bass'],
                'treble' => ['treble']
            ];
        } else {
            return null;
        }

        foreach ($suffixMap as $attribute => $suffixes) {
            foreach ($suffixes as $suffix) {
                if (!str_ends_with($entityId, '_' . $suffix)) {
                    continue;
                }
                $baseEntity = $entityId;
                if (str_contains($entityId, '.')) {
                    [, $baseEntity] = explode('.', $entityId, 2);
                }
                $base = substr($baseEntity, 0, -strlen('_' . $suffix));
                $expectedMediaPlayer = HAMediaPlayerDefinitions::DOMAIN . '.' . $base;
                if (!isset($this->entities[$expectedMediaPlayer])) {
                    $expectedIdent = $this->sanitizeIdent($expectedMediaPlayer);
                    if ($this->findEntityByBaseIdentInConfig($expectedIdent) === null) {
                        return null;
                    }
                }
                return $this->getMediaPlayerOrderPosition(0, $attribute);
            }
        }

        return null;
    }

    private function isMediaPlayerAttributeShadowed(string $attribute): bool
    {
        $shadowMap = [
            'cross_fade' => [
                'domain' => HASwitchDefinitions::DOMAIN,
                'suffixes' => ['cross_fade', 'crossfade']
            ],
            'loudness' => [
                'domain' => HASwitchDefinitions::DOMAIN,
                'suffixes' => ['loudness']
            ],
            'balance' => [
                'domain' => HANumberDefinitions::DOMAIN,
                'suffixes' => ['balance']
            ],
            'bass' => [
                'domain' => HANumberDefinitions::DOMAIN,
                'suffixes' => ['bass']
            ],
            'treble' => [
                'domain' => HANumberDefinitions::DOMAIN,
                'suffixes' => ['treble']
            ]
        ];

        $config = $shadowMap[$attribute] ?? null;
        if ($config === null) {
            return false;
        }

        $matchesSuffix = static function (string $entityId, string $suffix): bool {
            if (str_contains($entityId, '.')) {
                [, $entityId] = explode('.', $entityId, 2);
            }
            return str_ends_with($entityId, '_' . $suffix);
        };

        foreach ($this->entities as $entityId => $entity) {
            $domain = $entity['domain'] ?? $this->getEntityDomain($entityId);
            if ($domain !== $config['domain']) {
                continue;
            }
            foreach ($config['suffixes'] as $suffix) {
                if ($matchesSuffix($entityId, $suffix)) {
                    return true;
                }
            }
        }

        foreach ($this->getConfiguredEntities(__FUNCTION__) as $row) {
            $domain = $row['domain'] ?? $this->getEntityDomain($row['entity_id']);
            if ($domain !== $config['domain']) {
                continue;
            }
            foreach ($config['suffixes'] as $suffix) {
                if ($matchesSuffix($row['entity_id'], $suffix)) {
                    return true;
                }
            }
        }

        return false;
    }
}
