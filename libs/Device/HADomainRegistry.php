<?php

declare(strict_types=1);

trait HADomainRegistryTrait
{
    private function applyDomainActionState(string $domain, string $ident, array $entity): void
    {
        $handlers = $this->getDomainActionHandlers();
        if (isset($handlers[$domain])) {
            $handlers[$domain]($ident, $entity);
            return;
        }

        if ($this->isWriteable($domain)) {
            $this->EnableAction($ident);
        }
    }

    private function applyDomainExtraMaintenance(string $domain, array $entity): void
    {
        $handlers = $this->getDomainExtraMaintainers();
        if (!isset($handlers[$domain])) {
            return;
        }
        foreach ($handlers[$domain] as $handler) {
            $handler($entity);
        }
    }

    private function getDomainActionHandlers(): array
    {
        return [
            HALockDefinitions::DOMAIN => fn(string $ident, array $entity) => $this->DisableAction($ident),
            HAClimateDefinitions::DOMAIN => function (string $ident, array $entity): void {
                if ($this->isClimateTargetWritable($entity['attributes'] ?? [])) {
                    $this->EnableAction($ident);
                }
            },
            HAFanDefinitions::DOMAIN => function (string $ident, array $entity): void {
                if ($this->isFanToggleSupported($entity['attributes'] ?? [])) {
                    $this->EnableAction($ident);
                }
            },
            HAHumidifierDefinitions::DOMAIN => fn(string $ident, array $entity) => $this->EnableAction($ident)
        ];
    }

    private function getDomainExtraMaintainers(): array
    {
        return [
            HALightDefinitions::DOMAIN => [
                fn(array $entity) => $this->maintainLightAttributeVariables($entity)
            ],
            HAClimateDefinitions::DOMAIN => [
                fn(array $entity) => $this->maintainClimateAttributeVariables($entity),
                fn(array $entity) => $this->maintainClimatePowerVariable($entity)
            ],
            HAFanDefinitions::DOMAIN => [
                fn(array $entity) => $this->maintainFanAttributeVariables($entity)
            ],
            HAHumidifierDefinitions::DOMAIN => [
                fn(array $entity) => $this->maintainHumidifierAttributeVariables($entity)
            ],
            HALockDefinitions::DOMAIN => [
                fn(array $entity) => $this->maintainLockActionVariable($entity)
            ],
            HAVacuumDefinitions::DOMAIN => [
                fn(array $entity) => $this->maintainVacuumActionVariable($entity),
                fn(array $entity) => $this->maintainVacuumFanSpeedVariable($entity)
            ],
            HALawnMowerDefinitions::DOMAIN => [
                fn(array $entity) => $this->maintainLawnMowerActionVariable($entity)
            ],
            HAMediaPlayerDefinitions::DOMAIN => [
                fn(array $entity) => $this->maintainMediaPlayerActionVariable($entity),
                fn(array $entity) => $this->maintainMediaPlayerPowerVariable($entity),
                fn(array $entity) => $this->maintainMediaPlayerAttributeVariables($entity)
            ],
            HACameraDefinitions::DOMAIN => [
                fn(array $entity) => $this->maintainCameraAttributeVariables($entity)
            ],
            HAImageDefinitions::DOMAIN => [
                fn(array $entity) => $this->maintainImageAttributeVariables($entity)
            ],
            HAEventDefinitions::DOMAIN => [
                fn(array $entity) => $this->maintainEventAttributeVariables($entity)
            ]
        ];
    }

    private function handleDomainUpdateEntityValue(string $domain, string $entityId, string $ident, array $parsed): bool
    {
        $handlers = $this->getDomainStateUpdateHandlers();
        if (!isset($handlers[$domain])) {
            return false;
        }
        $handlers[$domain]($entityId, $ident, $parsed);
        return true;
    }

    private function getDomainStateUpdateHandlers(): array
    {
        return [
            HAEventDefinitions::DOMAIN => function (string $entityId, string $ident, array $parsed): void {
                $state = $parsed[self::KEY_STATE] ?? '';
                $value = null;
                if (is_string($state) && $state !== '') {
                    $value = $this->convertValueByDomain(HAEventDefinitions::DOMAIN, $state);
                    $this->setEntityMainValue($entityId, $ident, $value, $state);
                }
                if (!empty($parsed[self::KEY_ATTRIBUTES])) {
                    $this->storeEntityAttributes($entityId, $parsed[self::KEY_ATTRIBUTES]);
                    $this->updateEntityCache($entityId, $state !== '' ? $state : null, $parsed[self::KEY_ATTRIBUTES]);
                    $this->updateEntityPresentation($entityId, $this->entities[$entityId][self::KEY_ATTRIBUTES] ?? []);
                    return;
                }

                if ($state !== '') {
                    $this->updateEntityCache($entityId, $state, null);
                }
            },
            HAClimateDefinitions::DOMAIN => function (string $entityId, string $ident, array $parsed): void {
                $attributes = $parsed[self::KEY_ATTRIBUTES] ?? [];
                if (is_array($attributes) && $attributes !== []) {
                    // Normalize HA climate aliases (e.g. "temperature" -> "target_temperature") for consistent downstream updates.
                    $attributes = $this->mapClimateAttributeAliases($attributes, __FUNCTION__);
                    $state = $parsed[self::KEY_STATE] ?? null;
                    $hasHvacActionUpdate = array_key_exists(HAClimateDefinitions::ATTRIBUTE_HVAC_ACTION, $attributes);
                    if (is_string($state) && $state !== '' && ($hasHvacActionUpdate || !array_key_exists(HAClimateDefinitions::ATTRIBUTE_HVAC_MODE, $attributes))) {
                        $attributes[HAClimateDefinitions::ATTRIBUTE_HVAC_MODE] = $state;
                    }
                    $attributes = $this->storeEntityAttributes($entityId, $attributes);
                    $this->maintainClimatePowerVariable($this->entities[$entityId] ?? ['entity_id' => $entityId, 'attributes' => $attributes]);
                    $mainValue = $this->extractClimateMainValue($attributes);
                    if ($mainValue !== null) {
                        $this->setEntityMainValue($entityId, $ident, $mainValue, $state);
                    }
                    $this->updateEntityCache($entityId, $mainValue, $attributes);
                    $this->updateEntityPresentation($entityId, $this->entities[$entityId][self::KEY_ATTRIBUTES] ?? []);
                    $this->updateClimateAttributeValues($entityId, $attributes);
                    if (is_string($state) && $state !== '') {
                        $this->updateClimatePowerValue($entityId, $state);
                    }
                    return;
                }

                if (is_numeric($parsed[self::KEY_STATE])) {
                    $value = (float)$parsed[self::KEY_STATE];
                    $this->setEntityMainValue($entityId, $ident, $value, $parsed[self::KEY_STATE]);
                    $this->updateEntityCache($entityId, $value, null);
                } elseif (is_string($parsed[self::KEY_STATE]) && $parsed[self::KEY_STATE] !== '') {
                    if ($this->isIndeterminateEntityState($parsed[self::KEY_STATE])) {
                        $this->updateEntityCache($entityId, $parsed[self::KEY_STATE], null);
                        return;
                    }
                    $this->storeEntityAttribute($entityId, HAClimateDefinitions::ATTRIBUTE_HVAC_MODE, $parsed[self::KEY_STATE]);
                    $this->updateEntityCache($entityId, null, [HAClimateDefinitions::ATTRIBUTE_HVAC_MODE => $parsed[self::KEY_STATE]]);
                    $this->updateEntityPresentation($entityId, $this->entities[$entityId][self::KEY_ATTRIBUTES] ?? []);
                    $attributes = $this->entities[$entityId][self::KEY_ATTRIBUTES] ?? [];
                    if (is_array($attributes)) {
                        $this->updateClimateAttributeValues($entityId, $attributes);
                    }
                    $this->updateClimatePowerValue($entityId, (string)$parsed[self::KEY_STATE]);
                }
            },
            HACoverDefinitions::DOMAIN => function (string $entityId, string $ident, array $parsed): void {
                $attributes = $parsed[self::KEY_ATTRIBUTES] ?? [];
                $storedAttributes = null;
                if (is_array($attributes)) {
                    if ($attributes !== []) {
                        $storedAttributes = $this->storeEntityAttributes($entityId, $attributes);
                    }
                    $position = $this->extractCoverPosition($storedAttributes ?? $attributes);
                    if ($position !== null) {
                        $this->setEntityMainValue($entityId, $ident, $position, $parsed[self::KEY_STATE]);
                        $this->updateEntityCache($entityId, $position, $storedAttributes ?? $attributes);
                        $this->updateEntityPresentation($entityId, $this->entities[$entityId][self::KEY_ATTRIBUTES] ?? []);
                        $this->updateCoverAttributeValues($entityId, $storedAttributes ?? $attributes);
                        return;
                    }
                }

                $level = $this->normalizeCoverStateToLevel((string)$parsed[self::KEY_STATE]);
                if ($level !== null) {
                    $this->setEntityMainValue($entityId, $ident, $level, $parsed[self::KEY_STATE]);
                    $this->updateEntityCache($entityId, $level, is_array($attributes ?? null) ? $attributes : null);
                }
                if (!empty($parsed[self::KEY_ATTRIBUTES])) {
                    if ($storedAttributes === null) {
                        $storedAttributes = $this->storeEntityAttributes($entityId, $parsed[self::KEY_ATTRIBUTES]);
                    }
                    $this->updateEntityPresentation($entityId, $this->entities[$entityId][self::KEY_ATTRIBUTES] ?? []);
                    $this->updateCoverAttributeValues($entityId, $storedAttributes);
                }
            },
            HALockDefinitions::DOMAIN => function (string $entityId, string $ident, array $parsed): void {
                $attributes = $parsed[self::KEY_ATTRIBUTES] ?? [];
                if (is_array($attributes) && $attributes !== []) {
                    $attributes = $this->storeEntityAttributes($entityId, $attributes);
                }
                $displayState = $this->resolveLockDisplayState((string)$parsed[self::KEY_STATE], is_array($attributes) ? $attributes : null);
                if ($displayState !== null) {
                    $this->setEntityMainValue($entityId, $ident, $displayState, $parsed[self::KEY_STATE]);
                }
                if (is_array($attributes) && $attributes !== []) {
                    $this->updateEntityCache($entityId, $parsed[self::KEY_STATE], $attributes);
                    $this->updateEntityPresentation($entityId, $this->entities[$entityId][self::KEY_ATTRIBUTES] ?? []);
                } else {
                    $this->updateEntityCache($entityId, $parsed[self::KEY_STATE], null);
                }
            },
            HAVacuumDefinitions::DOMAIN => function (string $entityId, string $ident, array $parsed): void {
                $attributes = $parsed[self::KEY_ATTRIBUTES] ?? [];
                if (is_array($attributes) && $attributes !== []) {
                    $attributes = $this->storeEntityAttributes($entityId, $attributes);
                }
                if (is_string($parsed[self::KEY_STATE]) && $parsed[self::KEY_STATE] !== '') {
                    $this->setEntityMainValue($entityId, $ident, $parsed[self::KEY_STATE], $parsed[self::KEY_STATE]);
                }
                $this->updateVacuumFanSpeedValue($entityId, $attributes);
                if (is_array($attributes) && $attributes !== []) {
                    $this->updateEntityCache($entityId, $parsed[self::KEY_STATE], $attributes);
                    $this->updateEntityPresentation($entityId, $this->entities[$entityId][self::KEY_ATTRIBUTES] ?? []);
                } else {
                    $this->updateEntityCache($entityId, $parsed[self::KEY_STATE], null);
                }
            },
            HALawnMowerDefinitions::DOMAIN => function (string $entityId, string $ident, array $parsed): void {
                $attributes = $parsed[self::KEY_ATTRIBUTES] ?? [];
                if (is_array($attributes) && $attributes !== []) {
                    $attributes = $this->storeEntityAttributes($entityId, $attributes);
                }
                if (is_string($parsed[self::KEY_STATE]) && $parsed[self::KEY_STATE] !== '') {
                    $this->setEntityMainValue($entityId, $ident, $parsed[self::KEY_STATE], $parsed[self::KEY_STATE]);
                }
                if (is_array($attributes) && $attributes !== []) {
                    $this->updateEntityCache($entityId, $parsed[self::KEY_STATE], $attributes);
                    $this->updateEntityPresentation($entityId, $this->entities[$entityId][self::KEY_ATTRIBUTES] ?? []);
                } else {
                    $this->updateEntityCache($entityId, $parsed[self::KEY_STATE], null);
                }
            },
            HAFanDefinitions::DOMAIN => function (string $entityId, string $ident, array $parsed): void {
                $attributes = $parsed[self::KEY_ATTRIBUTES] ?? [];
                if (is_array($attributes) && $attributes !== []) {
                    $attributes = $this->storeEntityAttributes($entityId, $attributes);
                }
                if (is_string($parsed[self::KEY_STATE]) && $parsed[self::KEY_STATE] !== '') {
                    $this->setEntityMainValue(
                        $entityId,
                        $ident,
                        $this->convertValueByDomain(HAFanDefinitions::DOMAIN, $parsed[self::KEY_STATE], is_array($attributes) ? $attributes : []),
                        $parsed[self::KEY_STATE]
                    );
                }
                $this->updateFanAttributeValues($entityId, is_array($attributes) ? $attributes : []);
                if (is_array($attributes) && $attributes !== []) {
                    $this->updateEntityCache($entityId, $parsed[self::KEY_STATE], $attributes);
                    $this->updateEntityPresentation($entityId, $this->entities[$entityId][self::KEY_ATTRIBUTES] ?? []);
                } else {
                    $this->updateEntityCache($entityId, $parsed[self::KEY_STATE], null);
                }
            },
            HAHumidifierDefinitions::DOMAIN => function (string $entityId, string $ident, array $parsed): void {
                $attributes = $parsed[self::KEY_ATTRIBUTES] ?? [];
                if (is_array($attributes) && $attributes !== []) {
                    $attributes = $this->storeEntityAttributes($entityId, $attributes);
                }
                if (is_string($parsed[self::KEY_STATE]) && $parsed[self::KEY_STATE] !== '') {
                    $this->setEntityMainValue(
                        $entityId,
                        $ident,
                        $this->convertValueByDomain(HAHumidifierDefinitions::DOMAIN, $parsed[self::KEY_STATE], is_array($attributes) ? $attributes : []),
                        $parsed[self::KEY_STATE]
                    );
                }
                $this->updateHumidifierAttributeValues($entityId, is_array($attributes) ? $attributes : []);
                if (is_array($attributes) && $attributes !== []) {
                    $this->updateEntityCache($entityId, $parsed[self::KEY_STATE], $attributes);
                    $this->updateEntityPresentation($entityId, $this->entities[$entityId][self::KEY_ATTRIBUTES] ?? []);
                } else {
                    $this->updateEntityCache($entityId, $parsed[self::KEY_STATE], null);
                }
            },
            HAMediaPlayerDefinitions::DOMAIN => function (string $entityId, string $ident, array $parsed): void {
                $attributes = $parsed[self::KEY_ATTRIBUTES] ?? [];
                if (is_array($attributes) && $attributes !== []) {
                    $attributes = $this->storeEntityAttributes($entityId, $attributes);
                }
                if (is_string($parsed[self::KEY_STATE]) && $parsed[self::KEY_STATE] !== '') {
                    $state = $parsed[self::KEY_STATE];
                    $this->setEntityMainValue($entityId, $ident, $state, $state);
                    $this->updateMediaPlayerPowerValue($entityId, $state);
                }
                $this->updateMediaPlayerAttributeValues($entityId, is_array($attributes) ? $attributes : []);
                if (is_array($attributes) && $attributes !== []) {
                    $this->updateEntityCache($entityId, $parsed[self::KEY_STATE], $attributes);
                    $this->updateEntityPresentation($entityId, $this->entities[$entityId][self::KEY_ATTRIBUTES] ?? []);
                } else {
                    $this->updateEntityCache($entityId, $parsed[self::KEY_STATE], null);
                }
            },
            HACameraDefinitions::DOMAIN => function (string $entityId, string $ident, array $parsed): void {
                $attributes = $parsed[self::KEY_ATTRIBUTES] ?? [];
                if (is_array($attributes) && $attributes !== []) {
                    $attributes = $this->storeEntityAttributes($entityId, $attributes);
                }
                if (is_string($parsed[self::KEY_STATE]) && $parsed[self::KEY_STATE] !== '') {
                    $this->setEntityMainValue($entityId, $ident, $parsed[self::KEY_STATE], $parsed[self::KEY_STATE]);
                }
                $this->updateCameraAttributeValues($entityId, is_array($attributes) ? $attributes : []);
                if (is_array($attributes) && $attributes !== []) {
                    $this->updateEntityCache($entityId, $parsed[self::KEY_STATE], $attributes);
                    $this->updateEntityPresentation($entityId, $this->entities[$entityId][self::KEY_ATTRIBUTES] ?? []);
                } else {
                    $this->updateEntityCache($entityId, $parsed[self::KEY_STATE], null);
                }
            },
            HAImageDefinitions::DOMAIN => function (string $entityId, string $ident, array $parsed): void {
                $attributes = $parsed[self::KEY_ATTRIBUTES] ?? [];
                if (is_array($attributes) && $attributes !== []) {
                    $attributes = $this->storeEntityAttributes($entityId, $attributes);
                }
                if (is_string($parsed[self::KEY_STATE]) && $parsed[self::KEY_STATE] !== '') {
                    $this->updateImageStateValue($ident, $parsed[self::KEY_STATE]);
                }
                $this->updateImageAttributeValues($entityId, is_array($attributes) ? $attributes : []);
                if (is_array($attributes) && $attributes !== []) {
                    $this->updateEntityCache($entityId, $parsed[self::KEY_STATE], $attributes);
                    $this->updateEntityPresentation($entityId, $this->entities[$entityId][self::KEY_ATTRIBUTES] ?? []);
                } else {
                    $this->updateEntityCache($entityId, $parsed[self::KEY_STATE], null);
                }
            }
        ];
    }

    private function buildDomainAttributePayload(string $domain, string $attribute, mixed $value): string
    {
        $builders = $this->getDomainAttributePayloadBuilders();
        if (!isset($builders[$domain])) {
            return '';
        }
        return (string)$builders[$domain]($attribute, $value);
    }

    private function getDomainAttributePayloadBuilders(): array
    {
        return [
            HALightDefinitions::DOMAIN => fn(string $attribute, mixed $value) => $this->buildLightAttributePayload($attribute, $value),
            HACoverDefinitions::DOMAIN => fn(string $attribute, mixed $value) => $this->buildCoverAttributePayload($attribute, $value),
            HAClimateDefinitions::DOMAIN => fn(string $attribute, mixed $value) => $this->buildClimateAttributePayload($attribute, $value),
            HAFanDefinitions::DOMAIN => fn(string $attribute, mixed $value) => $this->buildFanAttributePayload($attribute, $value),
            HAHumidifierDefinitions::DOMAIN => fn(string $attribute, mixed $value) => $this->buildHumidifierAttributePayload($attribute, $value),
            HAMediaPlayerDefinitions::DOMAIN => fn(string $attribute, mixed $value) => $this->buildMediaPlayerAttributePayload($attribute, $value)
        ];
    }
}
