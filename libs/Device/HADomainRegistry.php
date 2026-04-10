<?php

declare(strict_types=1);

trait HADomainRegistryTrait
{
    private function applyDomainActionState(string $domain, string $ident, array $entity): void
    {
        $handler = $this->getDomainActionHandlerMethod($domain);
        if ($handler !== null) {
            $this->{$handler}($ident, $entity);
            return;
        }

        if ($this->isWriteable($domain)) {
            $this->EnableAction($ident);
        }
    }

    private function getDomainActionHandlerMethod(string $domain): ?string
    {
        $handlers = [
            HALockDefinitions::DOMAIN => 'applyLockActionState',
            HAClimateDefinitions::DOMAIN => 'applyClimateActionState',
            HACoverDefinitions::DOMAIN => 'applyCoverActionState',
            HAFanDefinitions::DOMAIN => 'applyFanActionState',
            HASelectDefinitions::DOMAIN => 'applySelectActionState',
            HAHumidifierDefinitions::DOMAIN => 'applyHumidifierActionState'
        ];

        return $handlers[$domain] ?? null;
    }

    private function applyLockActionState(string $ident, array $entity): void
    {
        $this->DisableAction($ident);
    }

    private function applyClimateActionState(string $ident, array $entity): void
    {
        if ($this->isClimateTargetWritable($entity[self::KEY_ATTRIBUTES] ?? [])) {
            $this->EnableAction($ident);
        }
    }

    private function applyCoverActionState(string $ident, array $entity): void
    {
        if ($this->isCoverMainWritable($entity[self::KEY_ATTRIBUTES] ?? [])) {
            $this->EnableAction($ident);
            return;
        }

        $this->DisableAction($ident);
    }

    private function applyFanActionState(string $ident, array $entity): void
    {
        if ($this->isFanToggleSupported($entity[self::KEY_ATTRIBUTES] ?? [])) {
            $this->EnableAction($ident);
        }
    }

    private function applySelectActionState(string $ident, array $entity): void
    {
        if ($this->isSelectWritable($entity[self::KEY_ATTRIBUTES] ?? [])) {
            $this->EnableAction($ident);
            return;
        }

        $this->DisableAction($ident);
    }

    private function applyHumidifierActionState(string $ident, array $entity): void
    {
        $this->EnableAction($ident);
    }

    private function isSelectWritable(mixed $attributes): bool
    {
        if (!is_array($attributes)) {
            return false;
        }

        return HASelectDefinitions::normalizeOptions($attributes['options'] ?? null) !== [];
    }

    private function applyDomainExtraMaintenance(string $domain, array $entity): void
    {
        foreach ($this->getDomainExtraMaintainerMethods($domain) as $method) {
            $this->{$method}($entity);
        }
    }

    private function getDomainExtraMaintainerMethods(string $domain): array
    {
        $maintainers = [
            HALightDefinitions::DOMAIN => [
                'maintainLightAttributeVariables'
            ],
            HAClimateDefinitions::DOMAIN => [
                'maintainClimateAttributeVariables',
                'maintainClimatePowerVariable'
            ],
            HACoverDefinitions::DOMAIN => [
                'maintainCoverAttributeVariables'
            ],
            HAFanDefinitions::DOMAIN => [
                'maintainFanAttributeVariables'
            ],
            HAHumidifierDefinitions::DOMAIN => [
                'maintainHumidifierAttributeVariables'
            ],
            HALockDefinitions::DOMAIN => [
                'maintainLockActionVariable',
                'maintainLockAttributeVariables'
            ],
            HAVacuumDefinitions::DOMAIN => [
                'maintainVacuumActionVariable',
                'maintainVacuumFanSpeedVariable'
            ],
            HALawnMowerDefinitions::DOMAIN => [
                'maintainLawnMowerActionVariable'
            ],
            HAMediaPlayerDefinitions::DOMAIN => [
                'maintainMediaPlayerActionVariable',
                'maintainMediaPlayerPowerVariable',
                'maintainMediaPlayerAttributeVariables'
            ],
            HACameraDefinitions::DOMAIN => [
                'maintainCameraPowerVariable',
                'maintainCameraAttributeVariables'
            ],
            HAImageDefinitions::DOMAIN => [
                'maintainImageAttributeVariables'
            ],
            HAEventDefinitions::DOMAIN => [
                'maintainEventAttributeVariables'
            ]
        ];

        return $maintainers[$domain] ?? [];
    }

    private function handleDomainUpdateEntityValue(string $domain, string $entityId, string $ident, array $parsed): bool
    {
        $handler = $this->getDomainStateUpdateHandlerMethod($domain);
        if ($handler === null) {
            return false;
        }

        $this->{$handler}($entityId, $ident, $parsed);
        return true;
    }

    private function getDomainStateUpdateHandlerMethod(string $domain): ?string
    {
        $handlers = [
            HAEventDefinitions::DOMAIN => 'updateEventEntityValue',
            HAClimateDefinitions::DOMAIN => 'updateClimateEntityValue',
            HACoverDefinitions::DOMAIN => 'updateCoverEntityValue',
            HALockDefinitions::DOMAIN => 'updateLockEntityValue',
            HAVacuumDefinitions::DOMAIN => 'updateVacuumEntityValue',
            HALawnMowerDefinitions::DOMAIN => 'updateLawnMowerEntityValue',
            HAFanDefinitions::DOMAIN => 'updateFanEntityValue',
            HAHumidifierDefinitions::DOMAIN => 'updateHumidifierEntityValue',
            HAMediaPlayerDefinitions::DOMAIN => 'updateMediaPlayerEntityValue',
            HACameraDefinitions::DOMAIN => 'updateCameraEntityValue',
            HAImageDefinitions::DOMAIN => 'updateImageEntityValue'
        ];

        return $handlers[$domain] ?? null;
    }

    private function updateEventEntityValue(string $entityId, string $ident, array $parsed): void
    {
        $state = $parsed[self::KEY_STATE] ?? '';
        if (is_string($state) && $state !== '') {
            $value = $this->convertValueByDomain(HAEventDefinitions::DOMAIN, $state);
            $this->setEntityMainValue($entityId, $ident, $value, $state);
        }

        if (!empty($parsed[self::KEY_ATTRIBUTES])) {
            $this->storeEntityAttributes($entityId, $parsed[self::KEY_ATTRIBUTES]);
            $this->updateEntityCache($entityId, $state !== '' ? $state : null, $parsed[self::KEY_ATTRIBUTES]);
            $this->refreshEntityPresentation($entityId);
            return;
        }

        if ($state !== '') {
            $this->updateEntityCache($entityId, $state, null);
        }
    }

    private function updateClimateEntityValue(string $entityId, string $ident, array $parsed): void
    {
        $state = $parsed[self::KEY_STATE] ?? null;
        $attributes = $parsed[self::KEY_ATTRIBUTES] ?? [];
        if (is_array($attributes) && $attributes !== []) {
            $attributes = $this->mapClimateAttributeAliases($attributes, __FUNCTION__);
            $hasHvacActionUpdate = array_key_exists(HAClimateDefinitions::ATTRIBUTE_HVAC_ACTION, $attributes);
            if (is_string($state) && $state !== '' && ($hasHvacActionUpdate || !array_key_exists(HAClimateDefinitions::ATTRIBUTE_HVAC_MODE, $attributes))) {
                $attributes[HAClimateDefinitions::ATTRIBUTE_HVAC_MODE] = $state;
            }
            $attributes = $this->storeEntityAttributes($entityId, $attributes);
            $this->maintainClimatePowerVariable($this->entities[$entityId] ?? ['entity_id' => $entityId, self::KEY_ATTRIBUTES => $attributes]);
            $mainValue = $this->extractClimateMainValue($attributes);
            if ($mainValue !== null) {
                $this->setEntityMainValue($entityId, $ident, $mainValue, $state);
            }
            $this->finalizeEntityStateUpdate($entityId, is_string($state) && $state !== '' ? $state : null, $attributes);
            $this->updateClimateAttributeValues($entityId, $attributes);
            if (is_string($state) && $state !== '') {
                $this->updateClimatePowerValue($entityId, $state);
            }
            return;
        }

        if (is_numeric($state)) {
            $value = (float)$state;
            $this->setEntityMainValue($entityId, $ident, $value, $state);
            $this->updateEntityCache($entityId, $value, null);
            return;
        }

        if (!is_string($state) || $state === '') {
            return;
        }

        if ($this->isIndeterminateEntityState($state)) {
            $this->updateEntityCache($entityId, $state, null);
            return;
        }

        $this->storeEntityAttribute($entityId, HAClimateDefinitions::ATTRIBUTE_HVAC_MODE, $state);
        $this->updateEntityCache($entityId, null, [HAClimateDefinitions::ATTRIBUTE_HVAC_MODE => $state]);
        $this->refreshEntityPresentation($entityId);
        $attributes = $this->getStoredEntityAttributes($entityId);
        if ($attributes !== []) {
            $this->updateClimateAttributeValues($entityId, $attributes);
        }
        $this->updateClimatePowerValue($entityId, $state);
    }

    private function updateCoverEntityValue(string $entityId, string $ident, array $parsed): void
    {
        $state = (string)($parsed[self::KEY_STATE] ?? '');
        $rawState = $parsed[self::KEY_STATE] ?? null;
        $rawAttributes = $parsed[self::KEY_ATTRIBUTES] ?? [];
        $storedAttributes = null;

        if (is_array($rawAttributes)) {
            if ($rawAttributes !== []) {
                $storedAttributes = $this->storeEntityAttributes($entityId, $rawAttributes);
            }
            $resolvedAttributes = $storedAttributes ?? $rawAttributes;
            $mainValue = $this->resolveCoverMainValue($resolvedAttributes, $state);
            if ($mainValue !== null) {
                $this->setEntityMainValue($entityId, $ident, $mainValue, $rawState);
                $this->updateEntityCache($entityId, $rawState, $resolvedAttributes);
                $this->refreshEntityPresentation($entityId);
                $this->updateCoverAttributeValues($entityId, $resolvedAttributes, $state);
                return;
            }
        }

        $level = $this->resolveCoverMainValue([], $state);
        if ($level !== null) {
            $this->setEntityMainValue($entityId, $ident, $level, $rawState);
            $this->updateEntityCache($entityId, $rawState, is_array($rawAttributes) ? $rawAttributes : null);
        }

        if (empty($parsed[self::KEY_ATTRIBUTES])) {
            return;
        }

        if ($storedAttributes === null) {
            $storedAttributes = $this->storeEntityAttributes($entityId, $parsed[self::KEY_ATTRIBUTES]);
        }
        $this->refreshEntityPresentation($entityId);
        $this->updateCoverAttributeValues($entityId, $storedAttributes, $state);
    }

    private function updateLockEntityValue(string $entityId, string $ident, array $parsed): void
    {
        $attributes = $this->storeUpdatedEntityAttributes($entityId, $parsed[self::KEY_ATTRIBUTES] ?? null);
        $displayState = $this->resolveLockDisplayState((string)($parsed[self::KEY_STATE] ?? ''), $attributes !== [] ? $attributes : null);
        if ($displayState !== null) {
            $this->setEntityMainValue($entityId, $ident, $displayState, $parsed[self::KEY_STATE] ?? null);
        }

        if ($attributes === []) {
            $this->updateEntityCache($entityId, $parsed[self::KEY_STATE] ?? null, null);
            return;
        }

        $this->finalizeEntityStateUpdate($entityId, $parsed[self::KEY_STATE] ?? null, $attributes);
        $this->updateLockAttributeValues($entityId, $attributes);
    }

    private function updateVacuumEntityValue(string $entityId, string $ident, array $parsed): void
    {
        $attributes = $this->storeUpdatedEntityAttributes($entityId, $parsed[self::KEY_ATTRIBUTES] ?? null);
        $state = $parsed[self::KEY_STATE] ?? null;
        if (is_string($state) && $state !== '') {
            $this->setEntityMainValue($entityId, $ident, $state, $state);
        }

        $this->updateVacuumFanSpeedValue($entityId, $attributes);
        $this->finalizeEntityStateUpdate($entityId, $state, $attributes);
    }

    private function updateLawnMowerEntityValue(string $entityId, string $ident, array $parsed): void
    {
        $attributes = $this->storeUpdatedEntityAttributes($entityId, $parsed[self::KEY_ATTRIBUTES] ?? null);
        $state = $parsed[self::KEY_STATE] ?? null;
        if (is_string($state) && $state !== '') {
            $this->setEntityMainValue($entityId, $ident, $state, $state);
        }

        $this->finalizeEntityStateUpdate($entityId, $state, $attributes);
    }

    private function updateFanEntityValue(string $entityId, string $ident, array $parsed): void
    {
        $attributes = $this->storeUpdatedEntityAttributes($entityId, $parsed[self::KEY_ATTRIBUTES] ?? null);
        $state = $parsed[self::KEY_STATE] ?? null;
        if (is_string($state) && $state !== '') {
            $this->setEntityMainValue(
                $entityId,
                $ident,
                $this->convertValueByDomain(HAFanDefinitions::DOMAIN, $state, $attributes),
                $state
            );
        }

        $this->updateFanAttributeValues($entityId, $attributes);
        $this->finalizeEntityStateUpdate($entityId, $state, $attributes);
    }

    private function updateHumidifierEntityValue(string $entityId, string $ident, array $parsed): void
    {
        $attributes = $this->storeUpdatedEntityAttributes($entityId, $parsed[self::KEY_ATTRIBUTES] ?? null);
        $state = $parsed[self::KEY_STATE] ?? null;
        if (is_string($state) && $state !== '') {
            $this->setEntityMainValue(
                $entityId,
                $ident,
                $this->convertValueByDomain(HAHumidifierDefinitions::DOMAIN, $state, $attributes),
                $state
            );
        }

        $this->updateHumidifierAttributeValues($entityId, $attributes);
        $this->finalizeEntityStateUpdate($entityId, $state, $attributes);
    }

    private function updateMediaPlayerEntityValue(string $entityId, string $ident, array $parsed): void
    {
        $attributes = $this->storeUpdatedEntityAttributes($entityId, $parsed[self::KEY_ATTRIBUTES] ?? null);
        $state = $parsed[self::KEY_STATE] ?? null;
        if (is_string($state) && $state !== '') {
            $this->setEntityMainValue($entityId, $ident, $state, $state);
            $this->updateMediaPlayerPowerValue($entityId, $state);
        }

        $this->updateMediaPlayerAttributeValues($entityId, $attributes);
        $this->finalizeEntityStateUpdate($entityId, $state, $attributes);
    }

    private function updateCameraEntityValue(string $entityId, string $ident, array $parsed): void
    {
        $attributes = $this->storeUpdatedEntityAttributes($entityId, $parsed[self::KEY_ATTRIBUTES] ?? null);
        $state = $parsed[self::KEY_STATE] ?? null;
        if (is_string($state) && $state !== '') {
            $this->setEntityMainValue($entityId, $ident, $state, $state);
            $this->updateCameraPowerValue($entityId, $state);
        }

        $this->maintainCameraPowerVariable($this->entities[$entityId] ?? ['entity_id' => $entityId, self::KEY_ATTRIBUTES => $attributes]);
        $this->updateCameraAttributeValues($entityId, $attributes);
        $this->finalizeEntityStateUpdate($entityId, $state, $attributes);
    }

    private function updateImageEntityValue(string $entityId, string $ident, array $parsed): void
    {
        $attributes = $this->storeUpdatedEntityAttributes($entityId, $parsed[self::KEY_ATTRIBUTES] ?? null);
        $state = $parsed[self::KEY_STATE] ?? null;
        if (is_string($state) && $state !== '') {
            $this->updateImageStateValue($ident, $state);
        }

        $this->updateImageAttributeValues($entityId, $attributes);
        $this->finalizeEntityStateUpdate($entityId, $state, $attributes);
    }

    private function storeUpdatedEntityAttributes(string $entityId, mixed $attributes): array
    {
        if (!is_array($attributes) || $attributes === []) {
            return [];
        }

        return $this->storeEntityAttributes($entityId, $attributes);
    }

    private function finalizeEntityStateUpdate(string $entityId, mixed $state, array $attributes): void
    {
        if ($attributes === []) {
            $this->updateEntityCache($entityId, $state, null);
            return;
        }

        $this->updateEntityCache($entityId, $state, $attributes);
        $this->refreshEntityPresentation($entityId);
    }

    private function refreshEntityPresentation(string $entityId): void
    {
        $this->updateEntityPresentation($entityId, $this->getStoredEntityAttributes($entityId));
    }

    private function getStoredEntityAttributes(string $entityId): array
    {
        $attributes = $this->entities[$entityId][self::KEY_ATTRIBUTES] ?? [];
        return is_array($attributes) ? $attributes : [];
    }

    private function buildDomainAttributePayload(string $domain, string $attribute, mixed $value): string
    {
        $builder = $this->getDomainAttributePayloadBuilderMethod($domain);
        if ($builder === null) {
            return '';
        }

        return (string)$this->{$builder}($attribute, $value);
    }

    private function getDomainAttributePayloadBuilderMethod(string $domain): ?string
    {
        $builders = [
            HALightDefinitions::DOMAIN => 'buildLightAttributePayload',
            HACoverDefinitions::DOMAIN => 'buildCoverAttributePayload',
            HAClimateDefinitions::DOMAIN => 'buildClimateAttributePayload',
            HAFanDefinitions::DOMAIN => 'buildFanAttributePayload',
            HAHumidifierDefinitions::DOMAIN => 'buildHumidifierAttributePayload',
            HAMediaPlayerDefinitions::DOMAIN => 'buildMediaPlayerAttributePayload'
        ];

        return $builders[$domain] ?? null;
    }
}
