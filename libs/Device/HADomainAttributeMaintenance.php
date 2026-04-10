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

    // Der State-Cache dient als Fallback für Attribute mit unvollständigen MQTT-Updates.
    private function getCachedEntityState(string $entityId): ?string
    {
        $cache = $this->decodeJsonArray($this->ReadAttributeString('EntityStateCache'), __FUNCTION__);
        if ($cache === null || !isset($cache[$entityId]) || !is_array($cache[$entityId])) {
            return null;
        }
        $state = $cache[$entityId][self::KEY_STATE] ?? null;
        if (!is_string($state) || trim($state) === '') {
            return null;
        }
        return $state;
    }

    // Media-Player bündelt viele abgeleitete Attribute und zusätzliche Medienobjekte.
    private function maintainMediaPlayerAttributeVariables(array $entity): void
    {
        $attributes = $entity['attributes'] ?? [];
        if (!is_array($attributes)) {
            $attributes = [];
        }

        $baseIdent = $this->sanitizeIdent($entity['entity_id']);
        foreach (HAMediaPlayerDefinitions::ATTRIBUTE_DEFINITIONS as $key => $meta) {
            if ($this->isMediaPlayerAttributeShadowed($key)) {
                continue;
            }
            if (!$this->shouldCreateMediaPlayerAttribute($key, $meta, $attributes)) {
                continue;
            }
            $ident        = $baseIdent . '_' . $key;
            $name         = $this->Translate((string)$meta['caption']);
            $basePosition = 0;
            $position     = $this->getMediaPlayerAttributePosition($key, $basePosition);
            $presentation = $this->getMediaPlayerAttributePresentation($key, $attributes, $meta);
            $exists = @$this->GetIDForIdent($ident) !== false;
            $this->MaintainVariable($ident, $name, $meta['type'], $presentation, $position, true);
            if (!$exists) {
                $this->applyMediaPlayerAttributeActionState($key, $attributes, $presentation, $ident);
            }
            if ($key === 'media_image_url') {
                $this->maintainMediaPlayerCoverMedia($entity['entity_id'], $basePosition);
            }
        }
    }

    // Erstellt fehlende Media-Player-Attribute lazily bei Bedarf.
    private function ensureMediaPlayerAttributeVariable(string $entityId, string $attribute): bool
    {
        $meta = HAMediaPlayerDefinitions::ATTRIBUTE_DEFINITIONS[$attribute] ?? null;
        if ($meta === null) {
            return false;
        }
        if ($this->isMediaPlayerAttributeShadowed($attribute)) {
            return false;
        }
        $ident = $this->sanitizeIdent($entityId . '_' . $attribute);
        if (@$this->GetIDForIdent($ident) !== false) {
            return true;
        }

        $entity = $this->entities[$entityId] ?? [
            'entity_id' => $entityId,
            'name'      => $entityId
        ];

        $attributes   = $entity['attributes'] ?? [];
        if (!$this->shouldCreateMediaPlayerAttribute($attribute, $meta, is_array($attributes) ? $attributes : [])) {
            return false;
        }
        $name         = $this->Translate((string)$meta['caption']);
        $basePosition = 0;
        $position     = $this->getMediaPlayerAttributePosition($attribute, $basePosition);
        $presentation = $this->getMediaPlayerAttributePresentation($attribute, is_array($attributes) ? $attributes : [], $meta);
        $this->MaintainVariable($ident, $name, $meta['type'], $presentation, $position, true);
        $this->applyMediaPlayerAttributeActionState($attribute, is_array($attributes) ? $attributes : [], $presentation, $ident);
        if ($attribute === 'media_image_url') {
            $this->maintainMediaPlayerCoverMedia($entityId, $basePosition);
        }
        return true;
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

        // Modus-Attribute sind nur sinnvoll, wenn HA die zugeh�rigen Optionen liefert.
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

    // Light-Attribute h�ngen stark von Features und Color-Modes ab.
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

        // Light-F�higkeiten kommen oft nur �ber Features und Color-Modes, nicht �ber den aktuellen State.
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

        $baseIdent = $this->sanitizeIdent($entity['entity_id']);
        foreach (HALightDefinitions::ATTRIBUTE_DEFINITIONS as $key => $meta) {
            if (!$this->shouldCreateLightAttribute($key, $attributes)) {
                continue;
            }
            if ($key !== 'color_mode' && !$this->isWritableLightAttribute($key, $attributes)) {
                continue;
            }

            $ident        = $baseIdent . '_' . $key;
            $name         = $this->Translate((string)$meta['caption']);
            $basePosition = $this->getEntityPosition($entity['entity_id']);
            $position     = $this->getLightAttributePosition($key, $basePosition);
            $presentation = $this->getLightAttributePresentation($key, $attributes, $meta);
            $this->MaintainVariable($ident, $name, $meta['type'], $presentation, $position, true);
            $this->debugExpert('LightVars', 'Variable angelegt', ['Ident' => $ident, 'Name' => $name, 'Presentation' => $presentation]);
            if ($this->isWritableLightAttribute($key, $attributes)) {
                $this->EnableAction($ident);
            } else {
                $this->DisableAction($ident);
            }
        }
    }

    // Erstellt Light-Attribute bei nachgelieferten Attributen aus Laufzeitdaten.
    private function ensureLightAttributeVariable(string $entityId, string $attribute): bool
    {
        $meta = HALightDefinitions::ATTRIBUTE_DEFINITIONS[$attribute] ?? null;
        if ($meta === null) {
            return false;
        }

        $entity = $this->entities[$entityId] ?? [
            'entity_id' => $entityId,
            'name'      => $entityId
        ];

        $ident = $this->sanitizeIdent($entityId . '_' . $attribute);
        if (@$this->GetIDForIdent($ident) !== false) {
            return true;
        }

        $attributes   = $entity['attributes'] ?? null;
        $attributesArray = is_array($attributes) ? $attributes : [];
        if (!$this->shouldCreateLightAttribute($attribute, $attributesArray)) {
            return false;
        }
        $name         = $this->Translate((string)$meta['caption']);
        $basePosition = 0;
        $position     = $this->getLightAttributePosition($attribute, $basePosition);
        $presentation = $this->getLightAttributePresentation($attribute, $attributesArray, $meta);
        $this->MaintainVariable($ident, $name, $meta['type'], $presentation, $position, true);
        $this->debugExpert('LightVars', 'Variable nachtr�glich angelegt', ['Ident' => $ident, 'Name' => $name, 'Presentation' => $presentation]);
        if ($this->isWritableLightAttribute($attribute, $attributesArray)) {
            $this->EnableAction($ident);
        } else {
            $this->DisableAction($ident);
        }
        return true;
    }
    private function updateLightAttributeValues(string $entityId, array $attributes): void
    {
        foreach (HALightDefinitions::ATTRIBUTE_DEFINITIONS as $key => $meta) {
            if (!array_key_exists($key, $attributes)) {
                continue;
            }

            $ident = $this->sanitizeIdent($entityId . '_' . $key);
            $varId = @$this->GetIDForIdent($ident);
            if ($varId === false) {
                continue;
            }

            $value = $attributes[$key];
            if ($key === 'rgb_color') {
                $value = $this->formatRgbColorStorageValue($value);
            } elseif (is_array($value)) {
                $value = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $value = $this->castVariableValue($value, $meta['type']);
            $this->setValueWithDebug($ident, $value);
        }
        $this->refreshDomainAttributePresentations(HALightDefinitions::DOMAIN, $entityId, $attributes);
    }

    // Climate erzeugt Attribute auch dann, wenn nur die Optionslisten vorhanden sind.
    private function maintainClimateAttributeVariables(array $entity): void
    {
        $attributes = $entity['attributes'] ?? [];
        if (!is_array($attributes)) {
            $this->debugExpert('ClimateVars', 'Attributes kein Array', ['EntityID' => $entity['entity_id'] ?? null]);
            return;
        }

        $baseIdent = $this->sanitizeIdent($entity['entity_id']);

        foreach (HAClimateDefinitions::ATTRIBUTE_DEFINITIONS as $key => $meta) {
            if (!$this->shouldCreateClimateAttribute($key, $meta, $attributes)) {
                continue;
            }

            $ident        = $baseIdent . '_' . $key;
            $name         = $this->Translate((string)$meta['caption']);
            $basePosition = $this->getEntityPosition($entity['entity_id']);
            $position     = $this->getClimateAttributePosition($key, $basePosition);
            $presentation = $this->getClimateAttributePresentation($key, $attributes);
            $this->MaintainVariable($ident, $name, $meta['type'], $presentation, $position, true);
            if ($this->isWritableClimateAttribute($key, $attributes)) {
                $this->EnableAction($ident);
            } else {
                $this->DisableAction($ident);
            }
            $this->debugExpert('ClimateVars', 'Variable angelegt', ['Ident' => $ident, 'Name' => $name, 'Presentation' => $presentation]);
        }
    }

    // Climate-Attribute können erst nach dem ersten State vollständig beurteilbar sein.
    private function ensureClimateAttributeVariable(string $entityId, string $attribute): bool
    {
        $meta = HAClimateDefinitions::ATTRIBUTE_DEFINITIONS[$attribute] ?? null;
        if ($meta === null) {
            return false;
        }

        $ident = $this->sanitizeIdent($entityId . '_' . $attribute);
        if (@$this->GetIDForIdent($ident) !== false) {
            return true;
        }

        $entity = $this->entities[$entityId] ?? [
            'entity_id' => $entityId,
            'name'      => $entityId
        ];

        $attributes   = $entity['attributes'] ?? [];
        $name         = $this->Translate((string)$meta['caption']);
        $basePosition = $this->getEntityPosition($entityId);
        $position     = $this->getClimateAttributePosition($attribute, $basePosition);
        $presentation = $this->getClimateAttributePresentation($attribute, is_array($attributes) ? $attributes : []);
        $this->MaintainVariable($ident, $name, $meta['type'], $presentation, $position, true);
        if ($this->isWritableClimateAttribute($attribute, is_array($attributes) ? $attributes : [])) {
            $this->EnableAction($ident);
        } else {
            $this->DisableAction($ident);
        }
        $this->debugExpert('ClimateVars', 'Variable nachträglich angelegt', ['Ident' => $ident, 'Name' => $name, 'Presentation' => $presentation]);
        return true;
    }

    // Für HVAC-Mode wird notfalls der Hauptzustand als Fallback verwendet.
    private function updateClimateAttributeValues(string $entityId, array $attributes): void
    {
        foreach (HAClimateDefinitions::ATTRIBUTE_DEFINITIONS as $key => $meta) {
            $hasAttributeValue = array_key_exists($key, $attributes);
            if (!$hasAttributeValue && $key !== HAClimateDefinitions::ATTRIBUTE_HVAC_MODE) {
                continue;
            }

            $ident = $this->sanitizeIdent($entityId . '_' . $key);
            $varId = @$this->GetIDForIdent($ident);
            if ($varId === false) {
                continue;
            }

            if ($hasAttributeValue) {
                $value = $this->castVariableValue($attributes[$key], $meta['type']);
            } else {
                $state = $this->entities[$entityId][self::KEY_STATE] ?? null;
                if (is_string($state) && $state !== '') {
                    $value = $this->castVariableValue($state, $meta['type']);
                } else {
                    continue;
                }
            }
            $this->setValueWithDebug($ident, $value);
        }
        $this->refreshDomainAttributePresentations(HAClimateDefinitions::DOMAIN, $entityId, $attributes);
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

        foreach ($this->getMaintainedCoverAttributes($attributes) as $key) {
            $meta = HACoverDefinitions::ATTRIBUTE_DEFINITIONS[$key] ?? null;
            if (!is_array($meta)) {
                continue;
            }
            if (!$this->shouldCreateCoverAttribute($key, $meta, $attributes)) {
                continue;
            }
            $this->ensureCoverAttributeVariable($entity['entity_id'], $key);
        }
    }

    private function ensureCoverAttributeVariable(string $entityId, string $attribute): bool
    {
        $meta = HACoverDefinitions::ATTRIBUTE_DEFINITIONS[$attribute] ?? null;
        if ($meta === null) {
            return false;
        }

        $entity = $this->entities[$entityId] ?? [
            'entity_id' => $entityId,
            'attributes' => []
        ];
        $attributes = $entity['attributes'] ?? [];
        if (!is_array($attributes)) {
            $attributes = [];
        }
        if (!$this->shouldCreateCoverAttribute($attribute, $meta, $attributes)) {
            return false;
        }

        $ident = $this->sanitizeIdent($entityId . '_' . $attribute);
        if (@$this->GetIDForIdent($ident) !== false) {
            return true;
        }

        $name         = $meta['caption'];
        $basePosition = $this->getEntityPosition($entityId);
        $position     = $this->getCoverAttributePosition($attribute, $basePosition);
        $presentation = $this->getCoverAttributePresentation($meta);
        $this->MaintainVariable($ident, $name, $meta['type'], $presentation, $position, true);
        $this->debugExpert('CoverVars', 'Variable nachträglich angelegt', ['Ident' => $ident, 'Name' => $name, 'Presentation' => $presentation]);
        if ($this->isWritableCoverAttribute($attribute, $attributes)) {
            $this->EnableAction($ident);
        } else {
            $this->DisableAction($ident);
        }
        return true;
    }

    // Cover spiegelt Positionsattribute auf Zusatzvariablen und Hauptwert.
    private function updateCoverAttributeValues(string $entityId, array $attributes, string $state = ''): void
    {
        foreach ($this->getMaintainedCoverAttributes($attributes) as $key) {
            $meta = HACoverDefinitions::ATTRIBUTE_DEFINITIONS[$key] ?? null;
            if (!is_array($meta)) {
                continue;
            }

            $ident = $this->sanitizeIdent($entityId . '_' . $key);
            if (@$this->GetIDForIdent($ident) === false) {
                continue;
            }

            $rawValue = $this->getCoverAttributeValue($attributes, $key);
            if (!is_numeric($rawValue)) {
                continue;
            }

            $value = $this->castVariableValue($rawValue, $meta['type']);
            $this->setValueWithDebug($ident, $value);
            if ($this->isWritableCoverAttribute($key, $attributes)) {
                $this->EnableAction($ident);
            } else {
                $this->DisableAction($ident);
            }
        }

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
        if ($this->isWritableFanAttribute($attribute, $attributes)) {
            $this->EnableAction($ident);
        } else {
            $this->DisableAction($ident);
        }
    }

    private function maintainFanAttributeVariables(array $entity): void
    {
        $attributes = $entity['attributes'] ?? [];
        if (!is_array($attributes)) {
            $attributes = [];
        }

        $baseIdent = $this->sanitizeIdent($entity['entity_id']);
        foreach (HAFanDefinitions::ATTRIBUTE_DEFINITIONS as $key => $meta) {
            if (!$this->shouldCreateFanAttribute($key, $meta, $attributes)) {
                continue;
            }
            $ident        = $baseIdent . '_' . $key;
            $name         = $this->Translate((string)$meta['caption']);
            $basePosition = 0;
            $position     = $this->getFanAttributePosition($key, $basePosition);
            $presentation = $this->getFanAttributePresentation($key, $attributes, $meta);
            $this->MaintainVariable($ident, $name, $meta['type'], $presentation, $position, true);
            $this->applyFanAttributeActionState($key, $attributes, $ident);
        }
    }

    private function ensureFanAttributeVariable(string $entityId, string $attribute): bool
    {
        $meta = HAFanDefinitions::ATTRIBUTE_DEFINITIONS[$attribute] ?? null;
        if ($meta === null) {
            return false;
        }
        $ident = $this->sanitizeIdent($entityId . '_' . $attribute);
        if (@$this->GetIDForIdent($ident) !== false) {
            return true;
        }

        $entity = $this->entities[$entityId] ?? [
            'entity_id' => $entityId,
            'name'      => $entityId
        ];

        $attributes = $entity['attributes'] ?? [];
        $attributesWith = is_array($attributes) ? $attributes : [];
        if (!array_key_exists($attribute, $attributesWith)) {
            $attributesWith[$attribute] = null;
        }
        if (!$this->shouldCreateFanAttribute($attribute, $meta, $attributesWith)) {
            return false;
        }
        $name         = $this->Translate((string)$meta['caption']);
        $basePosition = 0;
        $position     = $this->getFanAttributePosition($attribute, $basePosition);
        $presentation = $this->getFanAttributePresentation($attribute, is_array($attributes) ? $attributes : [], $meta);
        $this->MaintainVariable($ident, $name, $meta['type'], $presentation, $position, true);
        $this->applyFanAttributeActionState($attribute, is_array($attributes) ? $attributes : [], $ident);
        return true;
    }

    private function updateFanAttributeValues(string $entityId, array $attributes): void
    {
        foreach (HAFanDefinitions::ATTRIBUTE_DEFINITIONS as $key => $meta) {
            if (!array_key_exists($key, $attributes)) {
                continue;
            }

            $ident = $this->sanitizeIdent($entityId . '_' . $key);
            $varId = @$this->GetIDForIdent($ident);
            if ($varId === false) {
                continue;
            }

            $value = $attributes[$key];
            $value = $this->castVariableValue($value, $meta['type']);
            $this->setValueWithDebug($ident, $value);
        }
        $this->refreshDomainAttributePresentations(HAFanDefinitions::DOMAIN, $entityId, $attributes);
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
        if ($this->isWritableHumidifierAttribute($attribute, $attributes)) {
            $this->EnableAction($ident);
        } else {
            $this->DisableAction($ident);
        }
    }

    private function maintainHumidifierAttributeVariables(array $entity): void
    {
        $attributes = $entity['attributes'] ?? [];
        if (!is_array($attributes)) {
            $attributes = [];
        }

        $baseIdent = $this->sanitizeIdent($entity['entity_id']);
        foreach (HAHumidifierDefinitions::ATTRIBUTE_DEFINITIONS as $key => $meta) {
            if (!$this->shouldCreateHumidifierAttribute($key, $meta, $attributes)) {
                continue;
            }
            $ident        = $baseIdent . '_' . $key;
            $name         = $this->Translate((string)$meta['caption']);
            $basePosition = 0;
            $position     = $this->getHumidifierAttributePosition($key, $basePosition);
            $presentation = $this->getHumidifierAttributePresentation($key, $attributes, $meta);
            $this->MaintainVariable($ident, $name, $meta['type'], $presentation, $position, true);
            $this->applyHumidifierAttributeActionState($key, $attributes, $ident);
        }
    }

    private function ensureHumidifierAttributeVariable(string $entityId, string $attribute): bool
    {
        $meta = HAHumidifierDefinitions::ATTRIBUTE_DEFINITIONS[$attribute] ?? null;
        if ($meta === null) {
            return false;
        }
        $ident = $this->sanitizeIdent($entityId . '_' . $attribute);
        if (@$this->GetIDForIdent($ident) !== false) {
            return true;
        }

        $entity = $this->entities[$entityId] ?? [
            'entity_id' => $entityId,
            'name'      => $entityId
        ];

        $attributes = $entity['attributes'] ?? [];
        $attributesWith = is_array($attributes) ? $attributes : [];
        if (!array_key_exists($attribute, $attributesWith)) {
            $attributesWith[$attribute] = null;
        }
        if (!$this->shouldCreateHumidifierAttribute($attribute, $meta, $attributesWith)) {
            return false;
        }
        $name         = $this->Translate((string)$meta['caption']);
        $basePosition = 0;
        $position     = $this->getHumidifierAttributePosition($attribute, $basePosition);
        $presentation = $this->getHumidifierAttributePresentation($attribute, $attributesWith, $meta);
        $this->MaintainVariable($ident, $name, $meta['type'], $presentation, $position, true);
        $this->applyHumidifierAttributeActionState($attribute, $attributesWith, $ident);
        return true;
    }

    private function updateHumidifierAttributeValues(string $entityId, array $attributes): void
    {
        foreach (HAHumidifierDefinitions::ATTRIBUTE_DEFINITIONS as $key => $meta) {
            if (!array_key_exists($key, $attributes)) {
                continue;
            }

            $ident = $this->sanitizeIdent($entityId . '_' . $key);
            $varId = @$this->GetIDForIdent($ident);
            if ($varId === false) {
                continue;
            }

            $value = $attributes[$key];
            $value = $this->castVariableValue($value, $meta['type']);
            $this->setValueWithDebug($ident, $value);
        }
        $this->refreshDomainAttributePresentations(HAHumidifierDefinitions::DOMAIN, $entityId, $attributes);
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

    // Präsentationen und Actions werden nur bei relevanten Attributänderungen nachgezogen.
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
            $this->refreshDomainActionState($domain, $entityId, $attribute, $attributes);
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
        $ident = $this->sanitizeIdent($entityId . '_' . $attribute);
        if (@$this->GetIDForIdent($ident) === false) {
            return;
        }
        $basePosition = $this->getEntityPosition($entityId);

        switch ($domain) {
            case HAFanDefinitions::DOMAIN: {
                $meta = HAFanDefinitions::ATTRIBUTE_DEFINITIONS[$attribute] ?? null;
                if (!is_array($meta)) {
                    return;
                }
                $presentation = $this->getFanAttributePresentation($attribute, $attributes, $meta);
                $position = $this->getFanAttributePosition($attribute, 0);
                $this->refreshAttributePresentation($ident, (string)$meta['caption'], $meta['type'], $presentation, $position);
                return;
            }
            case HAHumidifierDefinitions::DOMAIN: {
                $meta = HAHumidifierDefinitions::ATTRIBUTE_DEFINITIONS[$attribute] ?? null;
                if (!is_array($meta)) {
                    return;
                }
                $presentation = $this->getHumidifierAttributePresentation($attribute, $attributes, $meta);
                $position = $this->getHumidifierAttributePosition($attribute, 0);
                $this->refreshAttributePresentation($ident, (string)$meta['caption'], $meta['type'], $presentation, $position);
                return;
            }
            case HAMediaPlayerDefinitions::DOMAIN: {
                $meta = HAMediaPlayerDefinitions::ATTRIBUTE_DEFINITIONS[$attribute] ?? null;
                if (!is_array($meta)) {
                    return;
                }
                $presentation = $this->getMediaPlayerAttributePresentation($attribute, $attributes, $meta);
                $position = $this->getMediaPlayerAttributePosition($attribute, 0);
                $this->refreshAttributePresentation($ident, (string)$meta['caption'], $meta['type'], $presentation, $position);
                return;
            }
            case HALightDefinitions::DOMAIN: {
                $meta = HALightDefinitions::ATTRIBUTE_DEFINITIONS[$attribute] ?? null;
                if (!is_array($meta)) {
                    return;
                }
                $presentation = $this->getLightAttributePresentation($attribute, $attributes, $meta);
                $position = $this->getLightAttributePosition($attribute, $basePosition);
                $this->refreshAttributePresentation($ident, (string)$meta['caption'], $meta['type'], $presentation, $position);
                return;
            }
            case HAClimateDefinitions::DOMAIN: {
                $meta = HAClimateDefinitions::ATTRIBUTE_DEFINITIONS[$attribute] ?? null;
                if (!is_array($meta)) {
                    return;
                }
                $presentation = $this->getClimateAttributePresentation($attribute, $attributes);
                $position = $this->getClimateAttributePosition($attribute, $basePosition);
                $this->refreshAttributePresentation($ident, (string)$meta['caption'], $meta['type'], $presentation, $position);
                return;
            }
            default:
        }
    }

    // Die Refresh-Logik hält Profil, Darstellung und EnableAction synchron.
    private function refreshDomainActionState(
        string $domain,
        string $entityId,
        string $attribute,
        array $attributes
    ): void {
        if ($domain === HALightDefinitions::DOMAIN && $attribute === '*') {
            foreach (HALightDefinitions::ATTRIBUTE_DEFINITIONS as $key => $_meta) {
                $ident = $this->sanitizeIdent($entityId . '_' . $key);
                if (!$this->shouldCreateLightAttribute($key, $attributes)) {
                    continue;
                }
                if (@$this->GetIDForIdent($ident) === false && !$this->ensureLightAttributeVariable($entityId, $key)) {
                    continue;
                }
                if ($this->isWritableLightAttribute($key, $attributes)) {
                    $this->EnableAction($ident);
                } else {
                    $this->DisableAction($ident);
                }
            }
            return;
        }

        $ident = $this->sanitizeIdent($entityId . '_' . $attribute);
        if (@$this->GetIDForIdent($ident) === false) {
            return;
        }

        if ($domain === HAHumidifierDefinitions::DOMAIN && $attribute === 'mode') {
            $this->applyHumidifierAttributeActionState('mode', $attributes, $ident);
            return;
        }
        if ($domain === HAFanDefinitions::DOMAIN) {
            $this->applyFanAttributeActionState($attribute, $attributes, $ident);
            return;
        }
        if ($domain === HAClimateDefinitions::DOMAIN) {
            if ($this->isWritableClimateAttribute($attribute, $attributes)) {
                $this->EnableAction($ident);
            } else {
                $this->DisableAction($ident);
            }
            return;
        }
        if ($domain === HAMediaPlayerDefinitions::DOMAIN && $attribute === 'media_position') {
            $meta = HAMediaPlayerDefinitions::ATTRIBUTE_DEFINITIONS['media_position'] ?? null;
            if (!is_array($meta)) {
                return;
            }
            $presentation = $this->getMediaPlayerAttributePresentation('media_position', $attributes, $meta);
            $this->applyMediaPlayerAttributeActionState('media_position', $attributes, $presentation, $ident);
        }
    }

    private function updateMediaPlayerAttributeValues(string $entityId, array $attributes): void
    {
        foreach (HAMediaPlayerDefinitions::ATTRIBUTE_DEFINITIONS as $key => $meta) {
            if (!array_key_exists($key, $attributes)) {
                continue;
            }

            $ident = $this->sanitizeIdent($entityId . '_' . $key);
            $varId = @$this->GetIDForIdent($ident);
            if ($varId === false) {
                continue;
            }

            $value = $attributes[$key];
            if ($key === 'repeat') {
                $value = $this->mapMediaPlayerRepeatToValue($value);
            } else {
                $value = $this->castVariableValue($value, $meta['type']);
            }
            $this->setValueWithDebug($ident, $value);
            if ($key === 'media_image_url' && is_string($value)) {
                $this->updateMediaPlayerCoverMedia($entityId, $value);
            }
        }
        $this->refreshDomainAttributePresentations(HAMediaPlayerDefinitions::DOMAIN, $entityId, $attributes);
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
        if ($useAction) {
            $this->EnableAction($ident);
        } else {
            $this->DisableAction($ident);
        }
    }
    private function getMediaPlayerAttributePosition(string $attribute, int $basePosition): int
    {
        return $this->getMediaPlayerOrderPosition($basePosition, $attribute);
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

        $configData = $this->decodeJsonArray($this->ReadPropertyString(self::PROP_DEVICE_CONFIG), __FUNCTION__);
        if (is_array($configData)) {
            foreach ($configData as $row) {
                $row = $this->normalizeEntity($row, __FUNCTION__);
                if ($row === null) {
                    continue;
                }
                if (($row['create_var'] ?? true) === false) {
                    continue;
                }
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
        }

        return false;
    }
}



