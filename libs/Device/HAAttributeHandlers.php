<?php /** @noinspection AutoloadingIssuesInspection */

declare(strict_types=1);

trait HAAttributeHandlersTrait
{
    protected function tryHandleAttributeFromTopic(string $topic, string $payload): bool
    {
        // Attribute topics come as .../<domain>/<entity>/<attribute>
        $parts = explode('/', trim($topic, '/'));
        if (count($parts) < 3) {
            $this->debugRuntimeIssue('AttributeTopic', 'Topic zu kurz', ['Topic' => $topic]);
            return false;
        }

        $attribute = $parts[count($parts) - 1];
        $entity    = $parts[count($parts) - 2];
        $domain    = $parts[count($parts) - 3];
        $entityId  = $domain . '.' . $entity;

        // Hinweis: Universelle Bookkeeping-Attribute (last_updated/last_changed/attribution/icon, vgl.
        // HADomainCatalog::IGNORABLE_BOOKKEEPING_ATTRIBUTES) werden bereits im Splitter vor dem Broadcast
        // verworfen und erreichen das Device im Normalbetrieb nicht. Eine erneute Pruefung hier waere
        // redundant; unveraenderte Wiederholungen faengt ohnehin der Skip-if-unchanged in
        // storeAttributeTopicValue ab.

        $currentDomain = $this->entities[$entityId]['domain'] ?? $domain;
        if (!HADomainCatalog::supportsAttributeTopics($currentDomain)) {
            $this->debugRuntimeIssue('AttributeTopic', 'Domain nicht unterstützt', ['EntityID' => $entityId, 'Domain' => $domain]);
            return false;
        }
        if (!$this->isManagedEntityId($entityId)) {
            $this->debugRuntimeIssue('AttributeTopic', 'Fremde Entity ignoriert', ['EntityID' => $entityId, 'Domain' => $domain]);
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
            $this->debugRuntimeIssue('AttributeTopic', 'Kein Handler für Domain', ['EntityID' => $entityId, 'Domain' => $currentDomain]);
            return false;
        }

        return $this->{$handler}($entityId, $attribute, $payload);
    }

    private function getAttributeTopicDomainHandlerMethod(string $domain): ?string
    {
        $domain = HADomainCatalog::normalizeDomainAlias($domain);

        $handlers = [
            HASensorDefinitions::DOMAIN => 'handleGenericAttributeTopic',
            HABinarySensorDefinitions::DOMAIN => 'handleGenericAttributeTopic',
            HANumberDefinitions::DOMAIN => 'handleGenericAttributeTopic',
            HASwitchDefinitions::DOMAIN => 'handleGenericAttributeTopic',
            HAInputTextDefinitions::DOMAIN => 'handleGenericAttributeTopic',
            HADateTimeDefinitions::DOMAIN => 'handleGenericAttributeTopic',
            HAInputDateTimeDefinitions::DOMAIN => 'handleGenericAttributeTopic',
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
            HADeviceTrackerDefinitions::DOMAIN => 'handleDeviceTrackerAttributeTopic',
            HAUpdateDefinitions::DOMAIN => 'handleGenericAttributeTopic',
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

    protected function handleDeviceTrackerAttributeTopic(string $entityId, string $attribute, string $payload): bool
    {
        return $this->handleAttributeTopicWithDefinitions(
            $entityId,
            $attribute,
            $payload,
            HADeviceTrackerDefinitions::ATTRIBUTE_DEFINITIONS,
            fn(string $id, string $attr): bool => $this->ensureDeviceTrackerAttributeVariable($id, $attr),
            [
                'store_unknown' => true,
                'store_defined' => true,
                'update_presentation_unknown' => true,
                'update_presentation_defined' => true,
                'post_set' => function (string $id): void {
                    $this->updateDeviceTrackerAttributeValues($id, $this->getStoredAttributeTopicAttributes($id));
                }
            ]
        );
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
            function (string $id, array $attributes, string $state): void {
                $this->updateCoverAttributeValues($id, $attributes, $state);
            }
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
            function (string $id, array $attributes, string $state): void {
                $this->updateValveAttributeValues($id, $attributes, $state);
            }
        );
        return true;
    }

    private function getRequiredAttributeMeta(array $definitions, string $attribute): ?array
    {
        $meta = $definitions[$attribute] ?? null;
        if (!is_array($meta)) {
            $this->debugRuntimeIssue('AttributeTopic', 'Attribut nicht definiert', ['Attribute' => $attribute]);
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

    protected function parseAttributePayload(string $payload): mixed
    {
        $trimmed = trim($payload);
        if ($trimmed === '') {
            return '';
        }

        try {
            $json = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            // HA's mqtt_statestream publishes scalar attribute values as raw strings
            // (e.g. color_mode=color_temp, friendly_name=...), which are not valid JSON and are
            // expected. Only a payload that actually looks like structured JSON ({, [, ") is worth
            // flagging as malformed; plain scalars are silently stored as the string they are.
            if ($this->payloadLooksLikeJson($trimmed)) {
                $this->debugRuntimeIssue('AttributeTopic', 'Invalid JSON payload', ['Error' => $e->getMessage()]);
            } else {
                $this->debugExpert('AttributeTopic', 'Non-JSON payload stored as string', ['Payload' => $payload]);
            }
            return $payload;
        }

        if ($json !== null || $trimmed === 'null') {
            return $json;
        }

        return $payload;
    }

    private function payloadLooksLikeJson(string $trimmed): bool
    {
        if ($trimmed === '') {
            return false;
        }

        $first = $trimmed[0];
        return $first === '{' || $first === '[' || $first === '"';
    }

    private function storeAttributeTopicValue(string $entityId, string $attribute, mixed $value, bool $refreshPresentation = true): void
    {
        // Unveraenderte Attributwerte (haeufig bei wiederkehrenden Sensor-/Metadaten-Topics wie
        // state_class, friendly_name oder event_types) nicht erneut speichern und vor allem keine teure
        // Presentation-Synchronisation ausloesen. Verglichen wird gegen den State-Cache, da dieser den
        // Rohwert haelt (die Entity-Attribute werden domainspezifisch gefiltert und sind kein zuverlaessiger
        // Vergleichswert).
        $cachedAttributes = $this->getCachedEntityAttributes($entityId);
        if (array_key_exists($attribute, $cachedAttributes) && $cachedAttributes[$attribute] === $value) {
            return;
        }

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
