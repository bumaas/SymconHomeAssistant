<?php

declare(strict_types=1);

trait HADomainSpecialActionsTrait
{
    private function resolveLockDisplayState(string $state, ?array $attributes): ?string
    {
        $state = strtolower(trim($state));
        if ($state !== '' && array_key_exists($state, HALockDefinitions::STATE_OPTIONS)) {
            return $state;
        }

        if (is_array($attributes)) {
            $flags = [
                'is_jammed' => 'jammed',
                'is_opening' => 'opening',
                'is_open' => 'open',
                'is_locking' => 'locking',
                'is_locked' => 'locked',
                'is_unlocking' => 'unlocking',
                'is_unlocked' => 'unlocked'
            ];
            foreach ($flags as $key => $value) {
                if (array_key_exists($key, $attributes) && (bool)$attributes[$key] === true) {
                    return $value;
                }
            }
        }

        return null;
    }


    private function isLockOpenSupported(array $attributes): bool
    {
        return $this->supportsFeatureFlag($this->getSupportedFeatureFlags($attributes), HALockDefinitions::FEATURE_OPEN);
    }

    private function getLockActionIdent(string $entityId): string
    {
        return $this->sanitizeIdent($entityId) . self::LOCK_ACTION_SUFFIX;
    }

    private function getVacuumActionIdent(string $entityId): string
    {
        return $this->sanitizeIdent($entityId) . self::VACUUM_ACTION_SUFFIX;
    }

    private function getCoverActionIdent(string $entityId): string
    {
        return $this->sanitizeIdent($entityId) . self::COVER_ACTION_SUFFIX;
    }

    private function getCoverTiltActionIdent(string $entityId): string
    {
        return $this->sanitizeIdent($entityId) . self::COVER_TILT_ACTION_SUFFIX;
    }

    private function getValveActionIdent(string $entityId): string
    {
        return $this->sanitizeIdent($entityId) . self::VALVE_ACTION_SUFFIX;
    }

    private function getVacuumFanSpeedIdent(string $entityId): string
    {
        return $this->sanitizeIdent($entityId) . self::VACUUM_FAN_SPEED_SUFFIX;
    }

    private function supportsVacuumFanSpeed(array $attributes): bool
    {
        return $this->supportsFeatureFlag($this->getSupportedFeatureFlags($attributes), HAVacuumDefinitions::FEATURE_FAN_SPEED);
    }

    private function getLawnMowerActionIdent(string $entityId): string
    {
        return $this->sanitizeIdent($entityId) . self::LAWN_MOWER_ACTION_SUFFIX;
    }

    private function getCameraPowerIdent(string $entityId): string
    {
        return $this->sanitizeIdent($entityId) . '_camera_power';
    }

    private function getSupportedFeatureFlags(array $attributes, string $attribute = self::KEY_SUPPORTED_FEATURES): int
    {
        return (int)($attributes[$attribute] ?? 0);
    }

    // Shared supported_features helpers keep bit checks readable and consistent.
    private function supportsFeatureFlag(int $supported, int $feature): bool
    {
        return ($supported & $feature) === $feature;
    }

    private function supportsAnyFeatureFlag(int $supported, array $features): bool
    {
        foreach ($features as $feature) {
            if ($this->supportsFeatureFlag($supported, (int)$feature)) {
                return true;
            }
        }

        return false;
    }

    private function buildEnumerationOption(int $value, string $caption): array
    {
        return [
            'Value' => $value,
            'Caption' => $caption,
            'IconActive' => false,
            'IconValue' => '',
            'Color' => -1
        ];
    }

    private function initializeTriggerActionVariable(string $ident, bool $exists): void
    {
        $this->initializeVariableDescriptorValue($ident, $this->createTriggerVariableDescriptor(), $exists);
        $this->EnableAction($ident);
    }

    // Shared helper for integer trigger variables with enum presentation.
    private function maintainEnumerationTriggerVariable(string $ident, string $caption, int $position, array $options, bool $hideWhenEmpty): void
    {
        $exists = @$this->GetIDForIdent($ident) !== false;
        if ($options === []) {
            if ($hideWhenEmpty && !$exists) {
                $this->MaintainVariable($ident, $caption, VARIABLETYPE_INTEGER, '', 0, false);
            }
            return;
        }

        $presentation = [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'OPTIONS' => json_encode($options, JSON_THROW_ON_ERROR)
        ];

        $this->MaintainVariable($ident, $caption, VARIABLETYPE_INTEGER, $presentation, $position, true);
        $this->initializeTriggerActionVariable($ident, $exists);
    }

    private function resolveSpecialActionEntity(string $ident, string $suffix, string $domain): ?array
    {
        if ($ident === '' || !str_ends_with($ident, $suffix)) {
            return null;
        }

        return $this->findEntityByIdentSuffix($ident, $suffix, $domain);
    }

    private function sendTopicCommandToEntity(string $entityId, string $payload, string $debugMessage, array $context, bool $expert = false): bool
    {
        $topic = $this->getSetTopicForEntity($entityId);
        if ($topic === '') {
            return false;
        }

        $this->debugExpert('RequestAction', $debugMessage, $context, $expert);
        $this->sendMqttMessage($topic, $payload);
        return true;
    }

    private function sendServiceOrTopicCommandToEntity(string $domain, string $entityId, string $command, string $debugMessage, bool $expert = false): bool
    {
        if ($this->sendServiceRequestToParent($domain, $command, ['entity_id' => $entityId])) {
            $this->debugExpert('RequestAction', $debugMessage . ' (REST)', ['EntityID' => $entityId, 'Command' => $command], $expert);
            return true;
        }

        $this->sendTopicCommandToEntity(
            $entityId,
            $command,
            $debugMessage,
            ['EntityID' => $entityId, 'Command' => $command],
            $expert
        );

        return true;
    }

    private function resetTriggerActionValue(string $ident): void
    {
        $this->resetVariableByDescriptor($ident, $this->describeVariableByIdent($ident));
    }

    private function maintainLockActionVariable(array $entity): void
    {
        $entityId = $entity['entity_id'] ?? '';
        if ($entityId === '') {
            return;
        }

        $attributes = $entity['attributes'] ?? [];
        $options = $this->getLockActionOptions(is_array($attributes) ? $attributes : []);
        $ident = $this->getLockActionIdent($entityId);
        $position = $this->getEntityPosition($entityId) + 5;
        $this->maintainEnumerationTriggerVariable($ident, 'Aktion', $position, $options, false);
    }

    private function maintainCoverActionVariable(array $entity): void
    {
        $entityId = $entity['entity_id'] ?? '';
        if ($entityId === '') {
            return;
        }

        $attributes = $entity['attributes'] ?? [];
        if (!is_array($attributes)) {
            $attributes = [];
        }

        $options = $this->getCoverActionOptions($attributes);
        $ident = $this->getCoverActionIdent($entityId);
        $position = $this->getEntityPosition($entityId) + 5;
        $this->maintainEnumerationTriggerVariable($ident, 'Aktion', $position, $options, true);
    }

    private function maintainCoverTiltActionVariable(array $entity): void
    {
        $entityId = $entity['entity_id'] ?? '';
        if ($entityId === '') {
            return;
        }

        $attributes = $entity['attributes'] ?? [];
        if (!is_array($attributes)) {
            $attributes = [];
        }

        $options = $this->getCoverTiltActionOptions($attributes);
        $ident = $this->getCoverTiltActionIdent($entityId);
        $position = $this->getEntityPosition($entityId) + 6;
        $this->maintainEnumerationTriggerVariable($ident, $this->Translate('Tilt Action'), $position, $options, true);
    }

    private function maintainValveActionVariable(array $entity): void
    {
        $entityId = $entity['entity_id'] ?? '';
        if ($entityId === '') {
            return;
        }

        $attributes = $entity['attributes'] ?? [];
        if (!is_array($attributes)) {
            $attributes = [];
        }

        $options = $this->getValveActionOptions($attributes);
        $ident = $this->getValveActionIdent($entityId);
        $position = $this->getEntityPosition($entityId) + 5;
        $this->maintainEnumerationTriggerVariable($ident, 'Aktion', $position, $options, true);
    }

    private function maintainVacuumActionVariable(array $entity): void
    {
        $entityId = $entity['entity_id'] ?? '';
        if ($entityId === '') {
            return;
        }

        $attributes = $entity['attributes'] ?? [];
        if (!is_array($attributes)) {
            $attributes = [];
        }

        $options = $this->getVacuumActionOptions($attributes);
        $ident = $this->getVacuumActionIdent($entityId);
        $position = $this->getEntityPosition($entityId) + 5;
        $this->maintainEnumerationTriggerVariable($ident, $this->Translate('Aktion'), $position, $options, true);
    }

    private function maintainVacuumFanSpeedVariable(array $entity): void
    {
        $entityId = $entity['entity_id'] ?? '';
        if ($entityId === '') {
            return;
        }

        $attributes = $entity['attributes'] ?? [];
        if (!is_array($attributes)) {
            return;
        }

        $ident = $this->getVacuumFanSpeedIdent($entityId);
        $fanSpeedList = $attributes['fan_speed_list'] ?? null;
        $exists = @$this->GetIDForIdent($ident) !== false;
        if (!$this->supportsVacuumFanSpeed($attributes) || !is_array($fanSpeedList) || $fanSpeedList === []) {
            if (!$exists) {
                $this->MaintainVariable($ident, $this->Translate('Lüfterstufe'), VARIABLETYPE_STRING, '', 0, false);
            }
            return;
        }

        $position = $this->getEntityPosition($entityId) + 6;
        $presentation = [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'OPTIONS'      => $this->getPresentationOptions($fanSpeedList)
        ];

        $this->MaintainVariable($ident, $this->Translate('Lüfterstufe'), VARIABLETYPE_STRING, $presentation, $position, true);
        $this->EnableAction($ident);
    }

    private function maintainLawnMowerActionVariable(array $entity): void
    {
        $entityId = $entity['entity_id'] ?? '';
        if ($entityId === '') {
            return;
        }

        $attributes = $entity['attributes'] ?? [];
        if (!is_array($attributes)) {
            $attributes = [];
        }

        $options = $this->getLawnMowerActionOptions($attributes);
        $ident = $this->getLawnMowerActionIdent($entityId);
        $position = $this->getEntityPosition($entityId) + 5;
        $this->maintainEnumerationTriggerVariable($ident, $this->Translate('Aktion'), $position, $options, true);
    }

    private function supportsCameraPower(array $attributes): bool
    {
        return $this->supportsFeatureFlag($this->getSupportedFeatureFlags($attributes), HACameraDefinitions::FEATURE_ON_OFF);
    }

    private function maintainCameraPowerVariable(array $entity): void
    {
        $entityId = $entity['entity_id'] ?? '';
        if ($entityId === '') {
            return;
        }

        $ident = $this->getCameraPowerIdent($entityId);
        $attributes = $entity['attributes'] ?? [];
        $exists = @$this->GetIDForIdent($ident) !== false;
        if (!is_array($attributes) || !$this->supportsCameraPower($attributes)) {
            if (!$exists) {
                $this->MaintainVariable($ident, $this->Translate('Power'), VARIABLETYPE_BOOLEAN, ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH], 1, false);
            }
            return;
        }

        $position = $this->getEntityPosition($entityId) + 1;
        $this->MaintainVariable(
            $ident,
            $this->Translate('Power'),
            VARIABLETYPE_BOOLEAN,
            ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH],
            $position,
            true
        );
        $this->EnableAction($ident);

        $state = $entity[self::KEY_STATE] ?? $this->getCachedEntityState($entityId);
        if (is_string($state) && $state !== '') {
            $this->updateCameraPowerValue($entityId, $state);
        }
    }

    private function updateCameraPowerValue(string $entityId, string $state): void
    {
        $ident = $this->getCameraPowerIdent($entityId);
        if (@$this->GetIDForIdent($ident) === false) {
            return;
        }

        $normalized = strtolower(trim($state));
        if ($normalized === '' || $normalized === 'unknown' || $normalized === 'unavailable') {
            return;
        }

        $this->setValueWithDebug($ident, $normalized !== 'off');
    }

    private function handleCameraPowerAction(string $ident, mixed $value): bool
    {
        $entity = $this->resolveSpecialActionEntity($ident, '_camera_power', HACameraDefinitions::DOMAIN);
        if ($entity === null) {
            return false;
        }

        $entityId = $entity['entity_id'] ?? '';
        if ($entityId === '') {
            return true;
        }

        $attributes = $entity['attributes'] ?? [];
        if (!is_array($attributes) || !$this->supportsCameraPower($attributes)) {
            return true;
        }

        $command = (bool)$value ? 'turn_on' : 'turn_off';
        return $this->sendServiceOrTopicCommandToEntity(HACameraDefinitions::DOMAIN, $entityId, $command, 'Camera power', true);
    }

    private function getLockActionOptions(array $attributes): array
    {
        $options = [
            [
                'Value' => HALockDefinitions::ACTION_LOCK,
                'Caption' => $this->Translate('Abgeschlossen'),
                'IconActive' => false,
                'IconValue' => '',
                'Color' => -1
            ],
            [
                'Value' => HALockDefinitions::ACTION_UNLOCK,
                'Caption' => $this->Translate('Aufgeschlossen'),
                'IconActive' => false,
                'IconValue' => '',
                'Color' => -1
            ]
        ];

        if ($this->isLockOpenSupported($attributes)) {
            $options[] = [
                'Value' => HALockDefinitions::ACTION_OPEN,
                'Caption' => $this->Translate('Öffnen'),
                'IconActive' => false,
                'IconValue' => '',
                'Color' => -1
            ];
        }

        return $options;
    }

    private function handleLockAction(string $ident, mixed $value): bool
    {
        $entity = $this->resolveSpecialActionEntity($ident, self::LOCK_ACTION_SUFFIX, HALockDefinitions::DOMAIN);
        if ($entity === null) {
            return false;
        }

        $entityId = $entity['entity_id'] ?? '';
        if ($entityId === '') {
            return true;
        }

        $attributes = $entity['attributes'] ?? [];
        $allowOpen = is_array($attributes) && $this->isLockOpenSupported($attributes);

        $action = is_numeric($value) ? (int)$value : null;
        $command = match ($action) {
            HALockDefinitions::ACTION_LOCK => 'lock',
            HALockDefinitions::ACTION_UNLOCK => 'unlock',
            HALockDefinitions::ACTION_OPEN => $allowOpen ? 'open' : '',
            default => ''
        };
        if ($command === '') {
            return true;
        }

        if ($this->sendTopicCommandToEntity($entityId, $command, 'Lock action', ['EntityID' => $entityId, 'Command' => $command])) {
            $this->resetTriggerActionValue($ident);
        }
        return true;
    }

    private function handleCoverAction(string $ident, mixed $value): bool
    {
        $entity = $this->resolveSpecialActionEntity($ident, self::COVER_ACTION_SUFFIX, HACoverDefinitions::DOMAIN);
        if ($entity === null) {
            return false;
        }

        $entityId = $entity['entity_id'] ?? '';
        if ($entityId === '') {
            return true;
        }

        $attributes = $entity['attributes'] ?? [];
        if (!is_array($attributes)) {
            $attributes = [];
        }

        $action = is_numeric($value) ? (int)$value : null;
        [$service, $payload] = match ($action) {
            HACoverDefinitions::ACTION_OPEN => ['open_cover', 'open'],
            HACoverDefinitions::ACTION_CLOSE => ['close_cover', 'close'],
            HACoverDefinitions::ACTION_STOP => ['stop_cover', 'stop'],
            default => ['', '']
        };
        if ($service === '' || $payload === '') {
            return true;
        }

        $supported = $this->getSupportedFeatureFlags($attributes);
        $addAll = $supported === 0;
        if (!$addAll) {
            $requiredFeature = match ($action) {
                HACoverDefinitions::ACTION_OPEN => HACoverDefinitions::FEATURE_OPEN,
                HACoverDefinitions::ACTION_CLOSE => HACoverDefinitions::FEATURE_CLOSE,
                HACoverDefinitions::ACTION_STOP => HACoverDefinitions::FEATURE_STOP,
                default => 0
            };
            if ($requiredFeature === 0 || !$this->supportsFeatureFlag($supported, $requiredFeature)) {
                return true;
            }
        }

        if ($this->sendServiceRequestToParent(HACoverDefinitions::DOMAIN, $service, ['entity_id' => $entityId])) {
            $this->debugExpert('RequestAction', 'Cover action (REST)', ['EntityID' => $entityId, 'Command' => $service], true);
            $this->resetTriggerActionValue($ident);
            return true;
        }

        if ($this->sendTopicCommandToEntity($entityId, $payload, 'Cover action', ['EntityID' => $entityId, 'Command' => $payload], true)) {
            $this->resetTriggerActionValue($ident);
        }
        return true;
    }

    private function handleCoverTiltAction(string $ident, mixed $value): bool
    {
        $entity = $this->resolveSpecialActionEntity($ident, self::COVER_TILT_ACTION_SUFFIX, HACoverDefinitions::DOMAIN);
        if ($entity === null) {
            return false;
        }

        $entityId = $entity['entity_id'] ?? '';
        if ($entityId === '') {
            return true;
        }

        $attributes = $entity['attributes'] ?? [];
        if (!is_array($attributes)) {
            $attributes = [];
        }

        $action = is_numeric($value) ? (int)$value : null;
        [$service, $payload] = match ($action) {
            HACoverDefinitions::ACTION_OPEN_TILT => ['open_cover_tilt', 'open_tilt'],
            HACoverDefinitions::ACTION_CLOSE_TILT => ['close_cover_tilt', 'close_tilt'],
            HACoverDefinitions::ACTION_STOP_TILT => ['stop_cover_tilt', 'stop_tilt'],
            default => ['', '']
        };
        if ($service === '' || $payload === '') {
            return true;
        }

        $supported = $this->getSupportedFeatureFlags($attributes);
        $addAll = $supported === 0;
        if (!$addAll) {
            $requiredFeature = match ($action) {
                HACoverDefinitions::ACTION_OPEN_TILT => HACoverDefinitions::FEATURE_OPEN_TILT,
                HACoverDefinitions::ACTION_CLOSE_TILT => HACoverDefinitions::FEATURE_CLOSE_TILT,
                HACoverDefinitions::ACTION_STOP_TILT => HACoverDefinitions::FEATURE_STOP_TILT,
                default => 0
            };
            if ($requiredFeature === 0 || !$this->supportsFeatureFlag($supported, $requiredFeature)) {
                return true;
            }
        }

        if ($this->sendServiceRequestToParent(HACoverDefinitions::DOMAIN, $service, ['entity_id' => $entityId])) {
            $this->debugExpert('RequestAction', 'Cover tilt action (REST)', ['EntityID' => $entityId, 'Command' => $service], true);
            $this->resetTriggerActionValue($ident);
            return true;
        }

        if ($this->sendTopicCommandToEntity($entityId, $payload, 'Cover tilt action', ['EntityID' => $entityId, 'Command' => $payload], true)) {
            $this->resetTriggerActionValue($ident);
        }
        return true;
    }

    private function handleValveAction(string $ident, mixed $value): bool
    {
        $entity = $this->resolveSpecialActionEntity($ident, self::VALVE_ACTION_SUFFIX, HAValveDefinitions::DOMAIN);
        if ($entity === null) {
            return false;
        }

        $entityId = $entity['entity_id'] ?? '';
        if ($entityId === '') {
            return true;
        }

        $attributes = $entity['attributes'] ?? [];
        if (!is_array($attributes)) {
            $attributes = [];
        }

        $action = is_numeric($value) ? (int)$value : null;
        [$service, $payload] = match ($action) {
            HAValveDefinitions::ACTION_OPEN => ['open_valve', 'open'],
            HAValveDefinitions::ACTION_CLOSE => ['close_valve', 'close'],
            HAValveDefinitions::ACTION_STOP => ['stop_valve', 'stop'],
            default => ['', '']
        };
        if ($service === '' || $payload === '') {
            return true;
        }

        $supported = $this->getSupportedFeatureFlags($attributes);
        $addAll = $supported === 0;
        if (!$addAll) {
            $requiredFeature = match ($action) {
                HAValveDefinitions::ACTION_OPEN => HAValveDefinitions::FEATURE_OPEN,
                HAValveDefinitions::ACTION_CLOSE => HAValveDefinitions::FEATURE_CLOSE,
                HAValveDefinitions::ACTION_STOP => HAValveDefinitions::FEATURE_STOP,
                default => 0
            };
            if ($requiredFeature === 0 || !$this->supportsFeatureFlag($supported, $requiredFeature)) {
                return true;
            }
        }

        if ($this->sendServiceRequestToParent(HAValveDefinitions::DOMAIN, $service, ['entity_id' => $entityId])) {
            $this->debugExpert('RequestAction', 'Valve action (REST)', ['EntityID' => $entityId, 'Command' => $service], true);
            $this->resetTriggerActionValue($ident);
            return true;
        }

        if ($this->sendTopicCommandToEntity($entityId, $payload, 'Valve action', ['EntityID' => $entityId, 'Command' => $payload], true)) {
            $this->resetTriggerActionValue($ident);
        }
        return true;
    }

    private function handleVacuumAction(string $ident, mixed $value): bool
    {
        $entity = $this->resolveSpecialActionEntity($ident, self::VACUUM_ACTION_SUFFIX, HAVacuumDefinitions::DOMAIN);
        if ($entity === null) {
            return false;
        }

        $entityId = $entity['entity_id'] ?? '';
        if ($entityId === '') {
            return true;
        }

        $action = is_numeric($value) ? (int)$value : null;
        $command = match ($action) {
            HAVacuumDefinitions::ACTION_START => 'start',
            HAVacuumDefinitions::ACTION_STOP => 'stop',
            HAVacuumDefinitions::ACTION_PAUSE => 'pause',
            HAVacuumDefinitions::ACTION_RETURN_HOME => 'return_to_base',
            HAVacuumDefinitions::ACTION_CLEAN_SPOT => 'clean_spot',
            HAVacuumDefinitions::ACTION_LOCATE => 'locate',
            default => ''
        };
        if ($command === '') {
            return true;
        }

        if ($this->sendTopicCommandToEntity($entityId, $command, 'Vacuum action', ['EntityID' => $entityId, 'Command' => $command])) {
            $this->resetTriggerActionValue($ident);
        }
        return true;
    }

    private function handleVacuumFanSpeedAction(string $ident, mixed $value): bool
    {
        $entity = $this->resolveSpecialActionEntity($ident, self::VACUUM_FAN_SPEED_SUFFIX, HAVacuumDefinitions::DOMAIN);
        if ($entity === null) {
            return false;
        }

        $entityId = $entity['entity_id'] ?? '';
        if ($entityId === '') {
            return true;
        }

        $attributes = $entity['attributes'] ?? [];
        if (!is_array($attributes) || !$this->supportsVacuumFanSpeed($attributes)) {
            return true;
        }

        $fanSpeed = trim((string)$value);
        if ($fanSpeed === '') {
            return true;
        }

        $payload = json_encode(['fan_speed' => $fanSpeed], JSON_THROW_ON_ERROR);
        $this->sendTopicCommandToEntity(
            $entityId,
            $payload,
            'Vacuum fan_speed',
            ['EntityID' => $entityId, 'fan_speed' => $fanSpeed]
        );

        return true;
    }

    private function updateVacuumFanSpeedValue(string $entityId, ?array $attributes): void
    {
        if (!is_array($attributes)) {
            return;
        }
        $fanSpeed = $attributes['fan_speed'] ?? null;
        if (!is_string($fanSpeed) || trim($fanSpeed) === '') {
            return;
        }
        $ident = $this->getVacuumFanSpeedIdent($entityId);
        if (@$this->GetIDForIdent($ident) !== false) {
            $this->setValueWithDebug($ident, $fanSpeed);
        }
    }

    private function handleLawnMowerAction(string $ident, mixed $value): bool
    {
        $entity = $this->resolveSpecialActionEntity($ident, self::LAWN_MOWER_ACTION_SUFFIX, HALawnMowerDefinitions::DOMAIN);
        if ($entity === null) {
            return false;
        }

        $entityId = $entity['entity_id'] ?? '';
        if ($entityId === '') {
            return true;
        }

        $action = is_numeric($value) ? (int)$value : null;
        $command = match ($action) {
            HALawnMowerDefinitions::ACTION_START_MOWING => 'start_mowing',
            HALawnMowerDefinitions::ACTION_PAUSE => 'pause',
            HALawnMowerDefinitions::ACTION_DOCK => 'dock',
            default => ''
        };
        if ($command === '') {
            return true;
        }

        if ($this->sendTopicCommandToEntity($entityId, $command, 'Lawn mower action', ['EntityID' => $entityId, 'Command' => $command])) {
            $this->resetTriggerActionValue($ident);
        }
        return true;
    }

    private function handleMediaPlayerAction(string $ident, mixed $value): bool
    {
        if (!str_ends_with($ident, self::MEDIA_PLAYER_ACTION_SUFFIX)) {
            return false;
        }

        $this->debugExpert(__FUNCTION__, 'Action value set', ['Ident' => $ident, 'Value' => $value], true);

        $entity = $this->resolveSpecialActionEntity($ident, self::MEDIA_PLAYER_ACTION_SUFFIX, HAMediaPlayerDefinitions::DOMAIN);
        if ($entity === null) {
            return false;
        }

        $entityId = $entity['entity_id'] ?? '';
        if ($entityId === '') {
            return true;
        }

        $action = is_numeric($value) ? (int)$value : null;
        $command = match ($action) {
            HAMediaPlayerDefinitions::ACTION_PLAY => 'play',
            HAMediaPlayerDefinitions::ACTION_PAUSE => 'pause',
            HAMediaPlayerDefinitions::ACTION_STOP => 'stop',
            HAMediaPlayerDefinitions::ACTION_NEXT => 'next_track',
            HAMediaPlayerDefinitions::ACTION_PREVIOUS => 'previous_track',
            default => ''
        };
        if ($command === '') {
            return true;
        }

        if ($this->sendTopicCommandToEntity($entityId, $command, 'Media player action', ['EntityID' => $entityId, 'Action' => $command], true)) {
            $this->resetTriggerActionValue($ident);
        }
        return true;
    }

    private function handleMediaPlayerPowerAction(string $ident, mixed $value): bool
    {
        $entity = $this->resolveSpecialActionEntity($ident, self::MEDIA_PLAYER_POWER_SUFFIX, HAMediaPlayerDefinitions::DOMAIN);
        if ($entity === null) {
            return false;
        }

        $entityId = $entity['entity_id'] ?? '';
        if ($entityId === '') {
            return true;
        }

        $attributes = $entity['attributes'] ?? [];
        if (!is_array($attributes)) {
            $attributes = [];
        }
        if (!$this->supportsMediaPlayerPower($attributes)) {
            return true;
        }

        $supported = $this->getSupportedFeatureFlags($attributes);
        $turnOn = (bool)$value;
        if ($turnOn && !$this->supportsFeatureFlag($supported, HAMediaPlayerDefinitions::FEATURE_TURN_ON)) {
            return true;
        }
        if (!$turnOn && !$this->supportsFeatureFlag($supported, HAMediaPlayerDefinitions::FEATURE_TURN_OFF)) {
            return true;
        }

        $command = $turnOn ? 'turn_on' : 'turn_off';
        return $this->sendServiceOrTopicCommandToEntity(HAMediaPlayerDefinitions::DOMAIN, $entityId, $command, 'Media player power', true);
    }

    private function handleClimatePowerAction(string $ident, mixed $value): bool
    {
        $entity = $this->resolveSpecialActionEntity($ident, self::CLIMATE_POWER_SUFFIX, HAClimateDefinitions::DOMAIN);
        if ($entity === null) {
            return false;
        }

        $entityId = $entity['entity_id'] ?? '';
        if ($entityId === '') {
            return true;
        }

        $attributes = $entity['attributes'] ?? [];
        if (!is_array($attributes)) {
            $attributes = [];
        }
        if (!$this->supportsClimatePower($attributes)) {
            return true;
        }

        $supported = $this->getSupportedFeatureFlags($attributes, HAClimateDefinitions::ATTRIBUTE_SUPPORTED_FEATURES);
        $turnOn = (bool)$value;
        if ($turnOn && !$this->supportsFeatureFlag($supported, HAClimateDefinitions::FEATURE_TURN_ON)) {
            return true;
        }
        if (!$turnOn && !$this->supportsFeatureFlag($supported, HAClimateDefinitions::FEATURE_TURN_OFF)) {
            return true;
        }

        $command = $turnOn ? 'turn_on' : 'turn_off';
        return $this->sendServiceOrTopicCommandToEntity(HAClimateDefinitions::DOMAIN, $entityId, $command, 'Climate power', true);
    }

    private function maintainMediaPlayerActionVariable(array $entity): void
    {
        $entityId = $entity['entity_id'] ?? '';
        if ($entityId === '') {
            return;
        }

        $attributes = $entity['attributes'] ?? [];
        if (!is_array($attributes)) {
            $attributes = [];
        }

        $options = $this->getMediaPlayerActionOptions($attributes);
        if ($options === []) {
            return;
        }

        $this->debugExpert(__FUNCTION__, 'Options', ['Options' => $options]);

        $ident = $this->getMediaPlayerActionIdent($entityId);
        $exists = @$this->GetIDForIdent($ident) !== false;
        $position = $this->getMediaPlayerOrderPosition(0, 'action');

        $presentation = [
            'PRESENTATION' => VARIABLE_PRESENTATION_LEGACY,
            'PROFILE'      => '~PlaybackPreviousNext'
        ];

        $this->MaintainVariable($ident, $this->Translate('Playback'), VARIABLETYPE_INTEGER, $presentation, $position, true);
        $this->initializeTriggerActionVariable($ident, $exists);
    }

    private function maintainMediaPlayerPowerVariable(array $entity): void
    {
        $entityId = $entity['entity_id'] ?? '';
        if ($entityId === '') {
            return;
        }

        $attributes = $entity['attributes'] ?? [];
        if (!is_array($attributes)) {
            $attributes = [];
        }
        if (!$this->supportsMediaPlayerPower($attributes)) {
            return;
        }

        $ident = $this->getMediaPlayerPowerIdent($entityId);
        $position = $this->getMediaPlayerOrderPosition(0, 'power');
        $presentation = [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH
        ];

        $this->MaintainVariable($ident, $this->Translate('Power'), VARIABLETYPE_BOOLEAN, $presentation, $position, true);
        $this->EnableAction($ident);
        $cachedState = $this->getCachedEntityState($entityId);
        if ($cachedState !== null) {
            $this->updateMediaPlayerPowerValue($entityId, $cachedState);
        }
    }

    private function getMediaPlayerActionIdent(string $entityId): string
    {
        return $this->sanitizeIdent($entityId) . self::MEDIA_PLAYER_ACTION_SUFFIX;
    }

    private function getMediaPlayerPowerIdent(string $entityId): string
    {
        return $this->sanitizeIdent($entityId) . self::MEDIA_PLAYER_POWER_SUFFIX;
    }

    private function getClimatePowerIdent(string $entityId): string
    {
        return $this->sanitizeIdent($entityId) . self::CLIMATE_POWER_SUFFIX;
    }

    private function getMediaPlayerActionOptions(array $attributes): array
    {
        $debugContext = ['Attributes' => $attributes];
        if (array_key_exists(self::KEY_SUPPORTED_FEATURES, $attributes)) {
            $featuresList = $this->mapSupportedFeaturesByDomain(
                HAMediaPlayerDefinitions::DOMAIN,
                (int)$attributes[self::KEY_SUPPORTED_FEATURES],
                true
            );
            $debugContext['SupportedFeaturesList'] = $featuresList;
        }
        $this->debugExpert(__FUNCTION__, 'Input', $debugContext);
        $supported = $this->getSupportedFeatureFlags($attributes);
        $options = [];

        $addAll = $supported === 0;
        if ($addAll || $this->supportsFeatureFlag($supported, HAMediaPlayerDefinitions::FEATURE_PREVIOUS_TRACK)) {
            $options[] = $this->buildEnumerationOption(HAMediaPlayerDefinitions::ACTION_PREVIOUS, $this->Translate('Previous Track'));
        }
        if ($addAll || $this->supportsFeatureFlag($supported, HAMediaPlayerDefinitions::FEATURE_PLAY)) {
            $options[] = $this->buildEnumerationOption(HAMediaPlayerDefinitions::ACTION_PLAY, $this->Translate('Play'));
        }
        if ($addAll || $this->supportsFeatureFlag($supported, HAMediaPlayerDefinitions::FEATURE_PAUSE)) {
            $options[] = $this->buildEnumerationOption(HAMediaPlayerDefinitions::ACTION_PAUSE, $this->Translate('Pause'));
        }
        if ($addAll || $this->supportsFeatureFlag($supported, HAMediaPlayerDefinitions::FEATURE_STOP)) {
            $options[] = $this->buildEnumerationOption(HAMediaPlayerDefinitions::ACTION_STOP, $this->Translate('Stop'));
        }
        if ($addAll || $this->supportsFeatureFlag($supported, HAMediaPlayerDefinitions::FEATURE_NEXT_TRACK)) {
            $options[] = $this->buildEnumerationOption(HAMediaPlayerDefinitions::ACTION_NEXT, $this->Translate('Next Track'));
        }

        $this->debugExpert(__FUNCTION__, 'Result', ['Options' => $options, 'Supported' => $supported, 'AddAll' => $addAll]);
        return $options;
    }

    private function supportsMediaPlayerPower(array $attributes): bool
    {
        $supported = $this->getSupportedFeatureFlags($attributes);
        if ($supported === 0) {
            return false;
        }
        return $this->supportsAnyFeatureFlag($supported, [
            HAMediaPlayerDefinitions::FEATURE_TURN_ON,
            HAMediaPlayerDefinitions::FEATURE_TURN_OFF
        ]);
    }

    private function supportsClimatePower(array $attributes): bool
    {
        $supported = $this->getSupportedFeatureFlags($attributes, HAClimateDefinitions::ATTRIBUTE_SUPPORTED_FEATURES);
        if ($supported === 0) {
            return false;
        }
        return $this->supportsAnyFeatureFlag($supported, [
            HAClimateDefinitions::FEATURE_TURN_ON,
            HAClimateDefinitions::FEATURE_TURN_OFF
        ]);
    }

    private function updateMediaPlayerPowerValue(string $entityId, string $state): void
    {
        $ident = $this->getMediaPlayerPowerIdent($entityId);
        if (@$this->GetIDForIdent($ident) === false) {
            return;
        }

        $normalized = strtolower(trim($state));
        if ($normalized === '' || $normalized === 'unknown' || $normalized === 'unavailable') {
            return;
        }

        $isOn = !in_array($normalized, ['off', 'standby'], true);
        $this->setValueWithDebug($ident, $isOn);
    }

    private function maintainClimatePowerVariable(array $entity): void
    {
        $entityId = $entity['entity_id'] ?? '';
        if ($entityId === '') {
            return;
        }

        $ident = $this->getClimatePowerIdent($entityId);
        $attributes = $entity['attributes'] ?? [];
        $exists = @$this->GetIDForIdent($ident) !== false;
        if (!is_array($attributes)) {
            $attributes = [];
        }
        if (!$this->supportsClimatePower($attributes)) {
            if (!$exists) {
                $this->MaintainVariable($ident, $this->Translate('Power'), VARIABLETYPE_BOOLEAN, ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH], 1, false);
            }
            return;
        }

        $position = $this->getEntityPosition($entityId) + 1;
        $this->MaintainVariable(
            $ident,
            $this->Translate('Power'),
            VARIABLETYPE_BOOLEAN,
            ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH],
            $position,
            true
        );
        $this->EnableAction($ident);

        $state = $entity[self::KEY_STATE] ?? $this->getCachedEntityState($entityId);
        if (is_string($state) && $state !== '') {
            $this->updateClimatePowerValue($entityId, $state);
        }
    }

    private function updateClimatePowerValue(string $entityId, string $state): void
    {
        $ident = $this->getClimatePowerIdent($entityId);
        if (@$this->GetIDForIdent($ident) === false) {
            return;
        }

        $normalized = strtolower(trim($state));
        if ($normalized === '' || $normalized === 'unknown' || $normalized === 'unavailable') {
            return;
        }

        $isOn = $normalized !== 'off';
        $this->setValueWithDebug($ident, $isOn);
    }

    private function getVacuumActionOptions(array $attributes): array
    {
        $supported = $this->getSupportedFeatureFlags($attributes);
        $options = [];

        if ($this->supportsFeatureFlag($supported, HAVacuumDefinitions::FEATURE_START)) {
            $options[] = $this->buildEnumerationOption(HAVacuumDefinitions::ACTION_START, $this->Translate('Start'));
        }
        if ($this->supportsFeatureFlag($supported, HAVacuumDefinitions::FEATURE_STOP)) {
            $options[] = $this->buildEnumerationOption(HAVacuumDefinitions::ACTION_STOP, $this->Translate('Stop'));
        }
        if ($this->supportsFeatureFlag($supported, HAVacuumDefinitions::FEATURE_PAUSE)) {
            $options[] = $this->buildEnumerationOption(HAVacuumDefinitions::ACTION_PAUSE, $this->Translate('Pause'));
        }
        if ($this->supportsFeatureFlag($supported, HAVacuumDefinitions::FEATURE_RETURN_HOME)) {
            $options[] = $this->buildEnumerationOption(HAVacuumDefinitions::ACTION_RETURN_HOME, $this->Translate('Zur Basis'));
        }
        if ($this->supportsFeatureFlag($supported, HAVacuumDefinitions::FEATURE_CLEAN_SPOT)) {
            $options[] = $this->buildEnumerationOption(HAVacuumDefinitions::ACTION_CLEAN_SPOT, $this->Translate('Punktreinigung'));
        }
        if ($this->supportsFeatureFlag($supported, HAVacuumDefinitions::FEATURE_LOCATE)) {
            $options[] = $this->buildEnumerationOption(HAVacuumDefinitions::ACTION_LOCATE, $this->Translate('Lokalisieren'));
        }

        return $options;
    }

    private function getCoverActionOptions(array $attributes): array
    {
        $supported = $this->getSupportedFeatureFlags($attributes);
        $addAll = $supported === 0;
        $options = [];

        if ($addAll || $this->supportsFeatureFlag($supported, HACoverDefinitions::FEATURE_OPEN)) {
            $options[] = $this->buildEnumerationOption(HACoverDefinitions::ACTION_OPEN, $this->Translate('Open'));
        }
        if ($addAll || $this->supportsFeatureFlag($supported, HACoverDefinitions::FEATURE_CLOSE)) {
            $options[] = $this->buildEnumerationOption(HACoverDefinitions::ACTION_CLOSE, $this->Translate('Close'));
        }
        if ($addAll || $this->supportsFeatureFlag($supported, HACoverDefinitions::FEATURE_STOP)) {
            $options[] = $this->buildEnumerationOption(HACoverDefinitions::ACTION_STOP, $this->Translate('Stop'));
        }

        return $options;
    }

    private function getCoverTiltActionOptions(array $attributes): array
    {
        $supported = $this->getSupportedFeatureFlags($attributes);
        $addAll = $supported === 0;
        $options = [];

        if ($addAll || $this->supportsFeatureFlag($supported, HACoverDefinitions::FEATURE_OPEN_TILT)) {
            $options[] = $this->buildEnumerationOption(HACoverDefinitions::ACTION_OPEN_TILT, $this->Translate('Open Tilt'));
        }
        if ($addAll || $this->supportsFeatureFlag($supported, HACoverDefinitions::FEATURE_CLOSE_TILT)) {
            $options[] = $this->buildEnumerationOption(HACoverDefinitions::ACTION_CLOSE_TILT, $this->Translate('Close Tilt'));
        }
        if ($addAll || $this->supportsFeatureFlag($supported, HACoverDefinitions::FEATURE_STOP_TILT)) {
            $options[] = $this->buildEnumerationOption(HACoverDefinitions::ACTION_STOP_TILT, $this->Translate('Stop Tilt'));
        }

        return $options;
    }

    private function getValveActionOptions(array $attributes): array
    {
        $supported = $this->getSupportedFeatureFlags($attributes);
        $addAll = $supported === 0;
        $options = [];

        if ($addAll || $this->supportsFeatureFlag($supported, HAValveDefinitions::FEATURE_OPEN)) {
            $options[] = $this->buildEnumerationOption(HAValveDefinitions::ACTION_OPEN, $this->Translate('Open'));
        }
        if ($addAll || $this->supportsFeatureFlag($supported, HAValveDefinitions::FEATURE_CLOSE)) {
            $options[] = $this->buildEnumerationOption(HAValveDefinitions::ACTION_CLOSE, $this->Translate('Close'));
        }
        if ($addAll || $this->supportsFeatureFlag($supported, HAValveDefinitions::FEATURE_STOP)) {
            $options[] = $this->buildEnumerationOption(HAValveDefinitions::ACTION_STOP, $this->Translate('Stop'));
        }

        return $options;
    }

    private function getLawnMowerActionOptions(array $attributes): array
    {
        $supported = $this->getSupportedFeatureFlags($attributes);
        $options = [];

        if ($this->supportsFeatureFlag($supported, HALawnMowerDefinitions::FEATURE_START_MOWING)) {
            $options[] = $this->buildEnumerationOption(HALawnMowerDefinitions::ACTION_START_MOWING, $this->Translate('Start'));
        }
        if ($this->supportsFeatureFlag($supported, HALawnMowerDefinitions::FEATURE_PAUSE)) {
            $options[] = $this->buildEnumerationOption(HALawnMowerDefinitions::ACTION_PAUSE, $this->Translate('Pause'));
        }
        if ($this->supportsFeatureFlag($supported, HALawnMowerDefinitions::FEATURE_DOCK)) {
            $options[] = $this->buildEnumerationOption(HALawnMowerDefinitions::ACTION_DOCK, $this->Translate('Zur Basis'));
        }

        return $options;
    }
}
