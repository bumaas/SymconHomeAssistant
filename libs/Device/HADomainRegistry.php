<?php

declare(strict_types=1);

trait HADomainRegistryTrait
{
    protected function applyDomainActionState(string $domain, string $ident, array $entity): void
    {
        $handler = $this->getDomainActionHandlerMethod($domain);
        if ($handler !== null) {
            $this->invokeDomainActionHandler($handler, $ident, $entity);
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
            HAValveDefinitions::DOMAIN => 'applyValveActionState',
            HAFanDefinitions::DOMAIN => 'applyFanActionState',
            HASelectDefinitions::DOMAIN => 'applySelectActionState',
            HAHumidifierDefinitions::DOMAIN => 'applyHumidifierActionState'
        ];

        return $handlers[$domain] ?? null;
    }

    protected function applyLockActionState(string $ident, array $entity): void
    {
        $this->DisableAction($ident);
    }

    protected function applyValveActionState(string $ident, array $entity): void
    {
        if ($this->isValveMainWritable($entity[self::KEY_ATTRIBUTES] ?? [])) {
            $this->EnableAction($ident);
            return;
        }

        $this->DisableAction($ident);
    }

    protected function applyClimateActionState(string $ident, array $entity): void
    {
        if ($this->isClimateTargetWritable($entity[self::KEY_ATTRIBUTES] ?? [])) {
            $this->EnableAction($ident);
            return;
        }

        $this->DisableAction($ident);
    }

    protected function applyCoverActionState(string $ident, array $entity): void
    {
        if ($this->isCoverMainWritable($entity[self::KEY_ATTRIBUTES] ?? [])) {
            $this->EnableAction($ident);
            return;
        }

        $this->DisableAction($ident);
    }

    protected function applyFanActionState(string $ident, array $entity): void
    {
        if ($this->isFanToggleSupported($entity[self::KEY_ATTRIBUTES] ?? [])) {
            $this->EnableAction($ident);
            return;
        }

        $this->DisableAction($ident);
    }

    protected function applySelectActionState(string $ident, array $entity): void
    {
        if ($this->isSelectWritable($entity[self::KEY_ATTRIBUTES] ?? [])) {
            $this->EnableAction($ident);
            return;
        }

        $this->DisableAction($ident);
    }

    protected function applyHumidifierActionState(string $ident, array $entity): void
    {
        $this->EnableAction($ident);
    }

    private function invokeDomainActionHandler(string $handler, string $ident, array $entity): void
    {
        $this->{$handler}($ident, $entity);
    }

    private function isSelectWritable(mixed $attributes): bool
    {
        if (!is_array($attributes)) {
            return false;
        }

        return HASelectDefinitions::normalizeOptions($attributes['options'] ?? null) !== [];
    }

    protected function applyDomainExtraMaintenance(string $domain, array $entity): void
    {
        foreach ($this->getDomainExtraMaintainerMethods($domain) as $method) {
            $this->{$method}($entity);
        }
    }

    protected function shouldApplyDomainActionStateOnExisting(string $domain): bool
    {
        return $domain === HASelectDefinitions::DOMAIN;
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
                'maintainCoverActionVariable',
                'maintainCoverTiltActionVariable',
                'maintainCoverAttributeVariables'
            ],
            HAValveDefinitions::DOMAIN => [
                'maintainValveActionVariable'
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
            HADeviceTrackerDefinitions::DOMAIN => [
                'maintainDeviceTrackerAttributeVariables'
            ],
            HAUpdateDefinitions::DOMAIN => [
                'maintainUpdateAttributeVariables'
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
            HAValveDefinitions::DOMAIN => 'updateValveEntityValue',
            HALockDefinitions::DOMAIN => 'updateLockEntityValue',
            HAVacuumDefinitions::DOMAIN => 'updateVacuumEntityValue',
            HALawnMowerDefinitions::DOMAIN => 'updateLawnMowerEntityValue',
            HAFanDefinitions::DOMAIN => 'updateFanEntityValue',
            HAHumidifierDefinitions::DOMAIN => 'updateHumidifierEntityValue',
            HAMediaPlayerDefinitions::DOMAIN => 'updateMediaPlayerEntityValue',
            HACameraDefinitions::DOMAIN => 'updateCameraEntityValue',
            HAImageDefinitions::DOMAIN => 'updateImageEntityValue',
            HADeviceTrackerDefinitions::DOMAIN => 'updateDeviceTrackerEntityValue'
        ];

        return $handlers[$domain] ?? null;
    }

    protected function updateEventEntityValue(string $entityId, string $ident, array $parsed): void
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

    protected function updateClimateEntityValue(string $entityId, string $ident, array $parsed): void
    {
        $state = $parsed[self::KEY_STATE] ?? null;
        $attributes = $parsed[self::KEY_ATTRIBUTES] ?? [];
        if (is_array($attributes) && $attributes !== []) {
            $attributes = $this->mapClimateAttributeAliases($attributes);
            $hasHvacActionUpdate = array_key_exists(HAClimateDefinitions::ATTRIBUTE_HVAC_ACTION, $attributes);
            if (is_string($state) && $state !== '' && ($hasHvacActionUpdate || !array_key_exists(HAClimateDefinitions::ATTRIBUTE_HVAC_MODE, $attributes))) {
                $attributes[HAClimateDefinitions::ATTRIBUTE_HVAC_MODE] = $state;
            }
            $attributes = $this->storeEntityAttributes($entityId, $attributes);
            $entityWithState = $this->entities[$entityId] ?? ['entity_id' => $entityId, self::KEY_ATTRIBUTES => $attributes];
            if (is_string($state) && $state !== '') {
                $entityWithState[self::KEY_STATE] = $state;
            }
            $this->maintainClimatePowerVariable($entityWithState);
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
        $this->updateEntityCache($entityId, $state, [HAClimateDefinitions::ATTRIBUTE_HVAC_MODE => $state]);
        $this->refreshEntityPresentation($entityId);
        $attributes = $this->getStoredEntityAttributes($entityId);
        if ($attributes !== []) {
            $this->updateClimateAttributeValues($entityId, $attributes);
        }
        $this->updateClimatePowerValue($entityId, $state);
    }

    protected function updateCoverEntityValue(string $entityId, string $ident, array $parsed): void
    {
        [$state, $rawState, $attributes] = $this->extractPositionEntityUpdateContext($entityId, $parsed);

        if ($this->isCoverPositionEntity($attributes, $state)) {
            $mainValue = $this->resolveCoverMainValue($attributes, $state);
            if ($mainValue !== null) {
                $this->setEntityMainValue($entityId, $ident, $mainValue, $rawState);
            }
        } elseif ($state !== '') {
            $mainValue = $this->convertValueByDomain(HACoverDefinitions::DOMAIN, $state, $attributes);
            if ($mainValue !== null) {
                $this->setEntityMainValue($entityId, $ident, $mainValue, $rawState);
            }
        }

        $this->finalizeEntityStateUpdate($entityId, $rawState, $attributes);
        $this->updateCoverAttributeValues($entityId, $attributes, $state);
    }

    protected function updateValveEntityValue(string $entityId, string $ident, array $parsed): void
    {
        [$state, $rawState, $attributes] = $this->extractPositionEntityUpdateContext($entityId, $parsed);

        if ($this->isValvePositionEntity($attributes, $state)) {
            $mainValue = $this->resolveValveMainValue($attributes, $state);
            if ($mainValue !== null) {
                $this->setEntityMainValue($entityId, $ident, $mainValue, $rawState);
            }
        } elseif ($state !== '') {
            $mainValue = $this->convertValueByDomain(HAValveDefinitions::DOMAIN, $state, $attributes);
            if ($mainValue !== null) {
                $this->setEntityMainValue($entityId, $ident, $mainValue, $rawState);
            }
        }

        $this->finalizeEntityStateUpdate($entityId, $rawState, $attributes);
    }

    protected function updateLockEntityValue(string $entityId, string $ident, array $parsed): void
    {
        $attributes = $this->storeUpdatedEntityAttributes($entityId, $parsed[self::KEY_ATTRIBUTES] ?? null);
        $displayState = $this->resolveLockDisplayState((string)($parsed[self::KEY_STATE] ?? ''), $attributes !== [] ? $attributes : null);
        if ($displayState !== null) {
            $this->setEntityMainValue($entityId, $ident, $displayState, $parsed[self::KEY_STATE] ?? null);
        }

        $this->updateLockAttributeValues($entityId, $attributes);
        $this->finalizeEntityStateUpdate($entityId, $parsed[self::KEY_STATE] ?? null, $attributes);
    }

    protected function updateVacuumEntityValue(string $entityId, string $ident, array $parsed): void
    {
        $attributes = $this->storeUpdatedEntityAttributes($entityId, $parsed[self::KEY_ATTRIBUTES] ?? null);
        $state = $parsed[self::KEY_STATE] ?? null;
        if (is_string($state) && $state !== '') {
            $this->setEntityMainValue($entityId, $ident, $state, $state);
        }

        $this->updateVacuumFanSpeedValue($entityId, $attributes);
        $this->finalizeEntityStateUpdate($entityId, $state, $attributes);
    }

    protected function updateLawnMowerEntityValue(string $entityId, string $ident, array $parsed): void
    {
        $attributes = $this->storeUpdatedEntityAttributes($entityId, $parsed[self::KEY_ATTRIBUTES] ?? null);
        $state = $parsed[self::KEY_STATE] ?? null;
        if (is_string($state) && $state !== '') {
            $this->setEntityMainValue($entityId, $ident, $state, $state);
        }

        $this->finalizeEntityStateUpdate($entityId, $state, $attributes);
    }

    protected function updateFanEntityValue(string $entityId, string $ident, array $parsed): void
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

    protected function updateHumidifierEntityValue(string $entityId, string $ident, array $parsed): void
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

    protected function updateMediaPlayerEntityValue(string $entityId, string $ident, array $parsed): void
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

    protected function updateCameraEntityValue(string $entityId, string $ident, array $parsed): void
    {
        $attributes = $this->storeUpdatedEntityAttributes($entityId, $parsed[self::KEY_ATTRIBUTES] ?? null);
        $state = $parsed[self::KEY_STATE] ?? null;
        if (is_string($state) && $state !== '') {
            $this->setEntityMainValue($entityId, $ident, $state, $state);
            $this->updateCameraPowerValue($entityId, $state);
        }

        $this->updateCameraAttributeValues($entityId, $attributes);
        $this->finalizeEntityStateUpdate($entityId, $state, $attributes);
    }

    protected function updateImageEntityValue(string $entityId, string $ident, array $parsed): void
    {
        $attributes = $this->storeUpdatedEntityAttributes($entityId, $parsed[self::KEY_ATTRIBUTES] ?? null);
        $state = $parsed[self::KEY_STATE] ?? null;
        if (is_string($state) && $state !== '') {
            $this->updateImageStateValue($ident, $state);
        }

        $this->updateImageAttributeValues($entityId, $attributes);
        $this->finalizeEntityStateUpdate($entityId, $state, $attributes);
    }

    protected function updateDeviceTrackerEntityValue(string $entityId, string $ident, array $parsed): void
    {
        $attributes = $this->storeUpdatedEntityAttributes($entityId, $parsed[self::KEY_ATTRIBUTES] ?? null);
        $state = $parsed[self::KEY_STATE] ?? null;
        if (is_string($state) && $state !== '') {
            $this->setEntityMainValue(
                $entityId,
                $ident,
                $this->convertValueByDomain(HADeviceTrackerDefinitions::DOMAIN, $state, $attributes),
                $state
            );
        }

        $this->updateDeviceTrackerAttributeValues($entityId, $attributes);
        $this->finalizeEntityStateUpdate($entityId, $state, $attributes);
    }

    private function storeUpdatedEntityAttributes(string $entityId, mixed $attributes): array
    {
        if (!is_array($attributes) || $attributes === []) {
            return [];
        }

        return $this->storeEntityAttributes($entityId, $attributes);
    }

    private function extractPositionEntityUpdateContext(string $entityId, array $parsed): array
    {
        $state = (string)($parsed[self::KEY_STATE] ?? '');
        $rawState = $parsed[self::KEY_STATE] ?? null;
        $attributes = $this->getStoredEntityAttributes($entityId);
        $rawAttributes = $parsed[self::KEY_ATTRIBUTES] ?? null;
        if (is_array($rawAttributes) && $rawAttributes !== []) {
            $attributes = $this->storeEntityAttributes($entityId, $rawAttributes);
        }

        return [$state, $rawState, $attributes];
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
