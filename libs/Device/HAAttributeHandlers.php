<?php /** @noinspection AutoloadingIssuesInspection */

declare(strict_types=1);

trait HAAttributeHandlersTrait
{
    protected function tryHandleAttributeFromTopic(string $topic, string $payload): bool
    {
        // Attribute topics come as .../<domain>/<entity>/<attribute>
        $parts = explode('/', trim($topic, '/'));
        if (count($parts) < 3) {
            $this->debugExpert('AttributeTopic', 'Topic zu kurz', ['Topic' => $topic]);
            return false;
        }

        $attribute = $parts[count($parts) - 1];
        $entity    = $parts[count($parts) - 2];
        $domain    = $parts[count($parts) - 3];
        $entityId  = $domain . '.' . $entity;

        $currentDomain = $this->entities[$entityId]['domain'] ?? $domain;
        if (!HADomainCatalog::supportsAttributeTopics($currentDomain)) {
            $this->debugExpert('AttributeTopic', 'Domain nicht unterstützt', ['EntityID' => $entityId, 'Domain' => $domain]);
            return false;
        }
        if (!$this->isManagedEntityId($entityId)) {
            $this->debugExpert('AttributeTopic', 'Fremde Entity ignoriert', ['EntityID' => $entityId, 'Domain' => $domain]);
            return false;
        }
        if (!isset($this->entities[$entityId])) {
            $this->entities[$entityId] = [
                'entity_id' => $entityId,
                'domain'    => $currentDomain,
                'name'      => $entity
            ];
        }

        $handler = $this->getAttributeTopicDomainHandlerMethod($currentDomain);
        if ($handler === null) {
            $this->debugExpert('AttributeTopic', 'Kein Handler für Domain', ['EntityID' => $entityId, 'Domain' => $currentDomain]);
            return false;
        }

        return $this->{$handler}($entityId, $attribute, $payload);
    }

    private function getAttributeTopicDomainHandlerMethod(string $domain): ?string
    {
        $handlers = [
            HASensorDefinitions::DOMAIN => 'handleGenericAttributeTopic',
            HABinarySensorDefinitions::DOMAIN => 'handleGenericAttributeTopic',
            HANumberDefinitions::DOMAIN => 'handleGenericAttributeTopic',
            HASwitchDefinitions::DOMAIN => 'handleGenericAttributeTopic',
            HAEventDefinitions::DOMAIN => 'handleEventAttributeTopic',
            HACoverDefinitions::DOMAIN => 'handleCoverAttributeTopic',
            HAValveDefinitions::DOMAIN => 'handleValveAttributeTopic',
            HAClimateDefinitions::DOMAIN => 'handleClimateAttributeTopic',
            HAFanDefinitions::DOMAIN => 'handleFanAttributeTopic',
            HAHumidifierDefinitions::DOMAIN => 'handleHumidifierAttributeTopic',
            HALockDefinitions::DOMAIN => 'handleLockAttributeTopic',
            HAVacuumDefinitions::DOMAIN => 'handleVacuumAttributeTopic',
            HALawnMowerDefinitions::DOMAIN => 'handleLawnMowerAttributeTopic',
            HAMediaPlayerDefinitions::DOMAIN => 'handleMediaPlayerAttributeTopic',
            HACameraDefinitions::DOMAIN => 'handleCameraAttributeTopic',
            HAImageDefinitions::DOMAIN => 'handleImageAttributeTopic',
            HASelectDefinitions::DOMAIN => 'handleSelectAttributeTopic',
            HALightDefinitions::DOMAIN => 'handleLightAttributeTopic'
        ];

        return $handlers[$domain] ?? null;
    }

    protected function handleGenericAttributeTopic(string $entityId, string $attribute, string $payload): bool
    {
        $value = $this->parseAttributePayload($payload);
        if ($value === null) {
            return true;
        }

        $this->storeAttributeTopicValue($entityId, $attribute, $value);
        return true;
    }

    protected function handleEventAttributeTopic(string $entityId, string $attribute, string $payload): bool
    {
        if (!in_array($attribute, [HAEventDefinitions::ATTRIBUTE_EVENT_TYPES, HAEventDefinitions::ATTRIBUTE_EVENT_TYPE], true)) {
            return true;
        }

        $value = $this->parseAttributePayload($payload);
        if ($value !== null) {
            $this->storeAttributeTopicValue($entityId, $attribute, $value);
        }

        return true;
    }

    protected function handleClimateAttributeTopic(string $entityId, string $attribute, string $payload): bool
    {
        $climateAliases = [
            'temperature' => HAClimateDefinitions::ATTRIBUTE_TARGET_TEMPERATURE,
            'target_temp_low' => HAClimateDefinitions::ATTRIBUTE_TARGET_TEMPERATURE_LOW,
            'target_temp_high' => HAClimateDefinitions::ATTRIBUTE_TARGET_TEMPERATURE_HIGH,
            'target_temp_step' => HAClimateDefinitions::ATTRIBUTE_TARGET_TEMPERATURE_STEP
        ];
        $mappedAttribute = $climateAliases[$attribute] ?? $attribute;
        $handled = $this->handleAttributeTopicWithDefinitions(
            $entityId,
            $attribute,
            $payload,
            HAClimateDefinitions::ATTRIBUTE_DEFINITIONS,
            fn(string $id, string $attr): bool => $this->ensureClimateAttributeVariable($id, $attr),
            [
                'attribute_alias' => $climateAliases,
                'store_unknown' => true,
                'store_defined' => true,
                'update_presentation_unknown' => true,
                'update_presentation_defined' => true
            ]
        );
        if ($handled) {
            $this->updateClimateMainValueFromAttributes($entityId, $mappedAttribute);
        }

        return $handled;
    }

    protected function handleFanAttributeTopic(string $entityId, string $attribute, string $payload): bool
    {
        return $this->handleAttributeTopicWithDefinitions(
            $entityId,
            $attribute,
            $payload,
            HAFanDefinitions::ATTRIBUTE_DEFINITIONS,
            fn(string $id, string $attr): bool => $this->ensureFanAttributeVariable($id, $attr),
            [
                'store_unknown' => true,
                'update_presentation_unknown' => true
            ]
        );
    }

    protected function handleHumidifierAttributeTopic(string $entityId, string $attribute, string $payload): bool
    {
        return $this->handleAttributeTopicWithDefinitions(
            $entityId,
            $attribute,
            $payload,
            HAHumidifierDefinitions::ATTRIBUTE_DEFINITIONS,
            fn(string $id, string $attr): bool => $this->ensureHumidifierAttributeVariable($id, $attr),
            [
                'attribute_alias' => ['humidity' => HAHumidifierDefinitions::ATTRIBUTE_TARGET_HUMIDITY],
                'store_unknown' => true,
                'store_defined' => true,
                'update_presentation_unknown' => true,
                'update_presentation_defined' => true
            ]
        );
    }

    protected function handleLockAttributeTopic(string $entityId, string $attribute, string $payload): bool
    {
        return $this->handleAttributeTopicWithDefinitions(
            $entityId,
            $attribute,
            $payload,
            HALockDefinitions::ATTRIBUTE_DEFINITIONS,
            fn(string $id, string $attr): bool => $this->ensureLockAttributeVariable($id, $attr),
            [
                'store_unknown' => true,
                'store_defined' => true,
                'update_presentation_unknown' => true,
                'update_presentation_defined' => true
            ]
        );
    }

    protected function handleVacuumAttributeTopic(string $entityId, string $attribute, string $payload): bool
    {
        $value = $this->parseAttributePayload($payload);
        if ($value === null) {
            return true;
        }

        $this->storeAttributeTopicValue($entityId, $attribute, $value, false);
        $this->refreshAttributeTopicPresentation($entityId);
        $this->updateVacuumFanSpeedValue($entityId, $this->getStoredAttributeTopicAttributes($entityId));
        return true;
    }

    protected function handleLawnMowerAttributeTopic(string $entityId, string $attribute, string $payload): bool
    {
        $value = $this->parseAttributePayload($payload);
        if ($value === null) {
            return true;
        }

        $this->storeAttributeTopicValue($entityId, $attribute, $value, false);
        $this->refreshAttributeTopicPresentation($entityId);
        return true;
    }

    protected function handleMediaPlayerAttributeTopic(string $entityId, string $attribute, string $payload): bool
    {
        if ($attribute === 'entity_picture') {
            $attribute = 'media_image_url';
        }

        if ($attribute === 'media_image_url') {
            return $this->handleMediaPlayerImageAttributeTopic($entityId, $attribute, $payload);
        }

        if ($attribute === 'repeat') {
            return $this->handleMediaPlayerRepeatAttributeTopic($entityId, $attribute, $payload);
        }

        return $this->handleAttributeTopicWithDefinitions(
            $entityId,
            $attribute,
            $payload,
            HAMediaPlayerDefinitions::ATTRIBUTE_DEFINITIONS,
            fn(string $id, string $attr): bool => $this->ensureMediaPlayerAttributeVariable($id, $attr),
            [
                'store_unknown' => true,
                'update_presentation_unknown' => true
            ]
        );
    }

    private function handleMediaPlayerImageAttributeTopic(string $entityId, string $attribute, string $payload): bool
    {
        $value = $this->parseAttributePayload($payload);
        if ($value === null) {
            $this->debugExpert('AttributeTopic', 'Payload null', ['EntityID' => $entityId, 'Attribute' => $attribute]);
            return true;
        }

        $original = (string)$value;
        $absolute = $this->makeMediaImageUrlAbsolute($original);
        if (!$this->ensureAttributeTopicVariable($entityId, $attribute, [$this, 'ensureMediaPlayerAttributeVariable'])) {
            return false;
        }
        $meta = $this->getRequiredAttributeMeta(HAMediaPlayerDefinitions::ATTRIBUTE_DEFINITIONS, $attribute);
        if ($meta === null) {
            return false;
        }

        $this->applyAttributeVariableValue($entityId, $attribute, $original, $meta, true);
        if ($absolute !== '') {
            $this->updateMediaPlayerCoverMedia($entityId, $absolute);
        }

        return true;
    }

    private function handleMediaPlayerRepeatAttributeTopic(string $entityId, string $attribute, string $payload): bool
    {
        if (!$this->ensureAttributeTopicVariable($entityId, $attribute, [$this, 'ensureMediaPlayerAttributeVariable'])) {
            return false;
        }

        $value = $this->parseAttributePayload($payload);
        if ($value === null) {
            $this->debugExpert('AttributeTopic', 'Payload null', ['EntityID' => $entityId, 'Attribute' => $attribute]);
            return true;
        }

        $value = $this->mapMediaPlayerRepeatToValue($value);
        $ident = $this->buildSharedAttributeIdent($entityId, $attribute);
        $this->setValueWithDebug($ident, $value);
        $this->debugExpert('AttributeTopic', 'SetValue', ['Ident' => $ident, 'Value' => $value]);
        $this->updateEntityCache($entityId, null, [$attribute => $value]);
        return true;
    }

    protected function handleCameraAttributeTopic(string $entityId, string $attribute, string $payload): bool
    {
        $value = $this->parseAttributePayload($payload);
        if ($value === null) {
            return true;
        }

        $this->storeAttributeTopicValue($entityId, $attribute, $value);
        return true;
    }

    protected function handleImageAttributeTopic(string $entityId, string $attribute, string $payload): bool
    {
        $value = $this->parseAttributePayload($payload);
        if ($value === null) {
            return true;
        }

        $this->storeAttributeTopicValue($entityId, $attribute, $value);
        return true;
    }

    protected function handleSelectAttributeTopic(string $entityId, string $attribute, string $payload): bool
    {
        $value = $this->parseAttributePayload($payload);
        if ($value !== null) {
            $this->storeAttributeTopicValue($entityId, $attribute, $value);
        }

        return true;
    }

    protected function handleLightAttributeTopic(string $entityId, string $attribute, string $payload): bool
    {
        if (!array_key_exists($attribute, HALightDefinitions::ATTRIBUTE_DEFINITIONS)) {
            $value = $this->parseAttributePayload($payload);
            if ($value !== null) {
                $this->storeAttributeTopicValue($entityId, $attribute, $value);
                // Capability updates can add light variables after initial creation.
                if (in_array($attribute, ['supported_features', 'supported_color_modes', 'effect_list'], true)) {
                    $this->updateLightAttributeValues($entityId, $this->getStoredAttributeTopicAttributes($entityId));
                }
            }
            return true;
        }

        if (!$this->ensureAttributeTopicVariable($entityId, $attribute, [$this, 'ensureLightAttributeVariable'])) {
            return false;
        }

        $value = $this->parseAttributePayload($payload);
        if ($value === null) {
            $this->debugExpert('AttributeTopic', 'Payload null', ['EntityID' => $entityId, 'Attribute' => $attribute]);
            return true;
        }

        $meta = $this->getRequiredAttributeMeta(HALightDefinitions::ATTRIBUTE_DEFINITIONS, $attribute);
        if ($meta === null) {
            return false;
        }

        if ($attribute === 'rgb_color') {
            $value = $this->formatRgbColorStorageValue($value);
        } elseif (is_array($value)) {
            $value = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $this->applyAttributeVariableValue($entityId, $attribute, $value, $meta);

        return true;
    }

    private function handleAttributeTopicWithDefinitions(
        string $entityId,
        string $attribute,
        string $payload,
        array $definitions,
        callable $ensureVariable,
        array $options = []
    ): bool {
        $aliases = $options['attribute_alias'] ?? [];
        if (is_array($aliases) && array_key_exists($attribute, $aliases)) {
            $attribute = (string)$aliases[$attribute];
        }

        $storeUnknown = (bool)($options['store_unknown'] ?? true);
        $updatePresentationUnknown = (bool)($options['update_presentation_unknown'] ?? true);
        $storeDefined = (bool)($options['store_defined'] ?? false);
        $updatePresentationDefined = (bool)($options['update_presentation_defined'] ?? false);
        $postSet = $options['post_set'] ?? null;

        if (!array_key_exists($attribute, $definitions)) {
            if (!$storeUnknown) {
                return true;
            }
            $value = $this->parseAttributePayload($payload);
            if ($value !== null) {
                $this->storeEntityAttribute($entityId, $attribute, $value);
                $this->updateEntityCache($entityId, null, [$attribute => $value]);
                if ($updatePresentationUnknown) {
                    $this->refreshAttributeTopicPresentation($entityId);
                }
            }
            return true;
        }

        if (!$this->ensureAttributeTopicVariable($entityId, $attribute, $ensureVariable)) {
            return false;
        }

        $value = $this->parseAttributePayload($payload);
        if ($value === null) {
            $this->debugExpert('AttributeTopic', 'Payload null', ['EntityID' => $entityId, 'Attribute' => $attribute]);
            return true;
        }

        $meta = $this->getRequiredAttributeMeta($definitions, $attribute);
        if ($meta === null) {
            return false;
        }

        $value = $this->applyAttributeVariableValue($entityId, $attribute, $value, $meta, $storeDefined);
        if ($updatePresentationDefined) {
            $this->refreshAttributeTopicPresentation($entityId);
        }
        if (is_callable($postSet)) {
            $postSet($entityId, $attribute, $value);
        }
        return true;
    }

    protected function handleCoverAttributeTopic(string $entityId, string $attribute, string $payload): bool
    {
        $value = $this->parseAttributePayload($payload);
        if ($value === null) {
            if (array_key_exists($attribute, HACoverDefinitions::ATTRIBUTE_DEFINITIONS)) {
                $this->debugExpert('AttributeTopic', 'Payload null', ['EntityID' => $entityId, 'Attribute' => $attribute]);
            }
            return true;
        }

        if (!array_key_exists($attribute, HACoverDefinitions::ATTRIBUTE_DEFINITIONS)) {
            $this->storeAttributeTopicValue($entityId, $attribute, $value);
            return true;
        }

        $meta = $this->getRequiredAttributeMeta(HACoverDefinitions::ATTRIBUTE_DEFINITIONS, $attribute);
        if ($meta === null) {
            return false;
        }

        $casted = $this->castVariableValue($value, $meta['type']);
        $this->storeEntityAttribute($entityId, $attribute, $casted);
        $this->updateEntityCache($entityId, null, [$attribute => $casted]);
        $this->refreshAttributeTopicPresentation($entityId);
        $this->refreshStatefulAttributeValues(
            $entityId,
            static fn(array $attributes): bool => $attributes !== [],
            fn(string $id, array $attributes, string $state): bool => $this->updateCoverAttributeValues($id, $attributes, $state)
        );

        return true;
    }

    protected function handleValveAttributeTopic(string $entityId, string $attribute, string $payload): bool
    {
        $value = $this->parseAttributePayload($payload);
        if ($value === null) {
            return true;
        }

        $this->storeAttributeTopicValue($entityId, $attribute, $value);
        $this->refreshStatefulAttributeValues(
            $entityId,
            static fn(array $attributes): bool => $attributes !== [],
            fn(string $id, array $attributes, string $state): bool => $this->updateValveAttributeValues($id, $attributes, $state)
        );
        return true;
    }

    private function getRequiredAttributeMeta(array $definitions, string $attribute): ?array
    {
        $meta = $definitions[$attribute] ?? null;
        if (!is_array($meta)) {
            $this->debugExpert('AttributeTopic', 'Attribut nicht definiert', ['Attribute' => $attribute]);
            return null;
        }

        return $meta;
    }

    private function ensureAttributeTopicVariable(string $entityId, string $attribute, callable $ensureVariable): bool
    {
        if ($ensureVariable($entityId, $attribute)) {
            return true;
        }

        return false;
    }

    private function applyAttributeVariableValue(
        string $entityId,
        string $attribute,
        mixed $value,
        array $meta,
        bool $storeEntityAttribute = false
    ): string|int|bool|float {
        $casted = $this->castVariableValue($value, $meta['type']);
        $ident = $this->buildSharedAttributeIdent($entityId, $attribute);
        $this->setValueWithDebug($ident, $casted);
        $this->debugExpert('AttributeTopic', 'SetValue', ['Ident' => $ident, 'Value' => $casted]);
        if ($storeEntityAttribute) {
            $this->storeEntityAttribute($entityId, $attribute, $casted);
        }
        $this->updateEntityCache($entityId, null, [$attribute => $casted]);

        return $casted;
    }

    private function refreshStatefulAttributeValues(
        string $entityId,
        callable $shouldUpdate,
        callable $updater
    ): void {
        $attributes = $this->getStoredAttributeTopicAttributes($entityId);
        if (!$shouldUpdate($attributes)) {
            return;
        }

        $state = $this->getCachedEntityRawState($entityId) ?? $this->getCachedEntityState($entityId) ?? '';
        $updater($entityId, $attributes, $state);
    }

    private function storeAttributeTopicValue(string $entityId, string $attribute, mixed $value, bool $refreshPresentation = true): void
    {
        $this->storeEntityAttribute($entityId, $attribute, $value);
        $this->updateEntityCache($entityId, null, [$attribute => $value]);
        if ($refreshPresentation) {
            $this->refreshAttributeTopicPresentation($entityId);
        }
    }

    private function refreshAttributeTopicPresentation(string $entityId): void
    {
        $this->updateEntityPresentation($entityId, $this->getStoredAttributeTopicAttributes($entityId));
    }

    private function getStoredAttributeTopicAttributes(string $entityId): array
    {
        $attributes = $this->entities[$entityId][self::KEY_ATTRIBUTES] ?? [];
        return is_array($attributes) ? $attributes : [];
    }

    private function updateClimateMainValueFromAttributes(string $entityId, string $attribute): void
    {
        if (!in_array($attribute, [
            HAClimateDefinitions::ATTRIBUTE_TARGET_TEMPERATURE,
            HAClimateDefinitions::ATTRIBUTE_CURRENT_TEMPERATURE
        ], true)) {
            return;
        }
        $attributes = $this->entities[$entityId][self::KEY_ATTRIBUTES] ?? [];
        if (!is_array($attributes)) {
            return;
        }
        $mainValue = $this->extractClimateMainValue($attributes);
        if ($mainValue === null) {
            return;
        }
        $mainIdent = $this->getSharedEntityMainIdent($entityId);
        if (@$this->GetIDForIdent($mainIdent) === false) {
            return;
        }
        $this->setEntityMainValue($entityId, $mainIdent, $mainValue);
    }
}

