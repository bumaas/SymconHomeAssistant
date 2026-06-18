<?php

declare(strict_types=1);

trait HADomainSpecialActionsTrait
{
    protected function resolveLockDisplayState(string $state, ?array $attributes): ?string
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
        return $this->buildSharedSuffixIdent($entityId, self::LOCK_ACTION_SUFFIX);
    }

    private function getVacuumActionIdent(string $entityId): string
    {
        return $this->buildSharedSuffixIdent($entityId, self::VACUUM_ACTION_SUFFIX);
    }

    private function getCoverActionIdent(string $entityId): string
    {
        return $this->buildSharedSuffixIdent($entityId, self::COVER_ACTION_SUFFIX);
    }

    private function getCoverTiltActionIdent(string $entityId): string
    {
        return $this->buildSharedSuffixIdent($entityId, self::COVER_TILT_ACTION_SUFFIX);
    }

    private function getValveActionIdent(string $entityId): string
    {
        return $this->buildSharedSuffixIdent($entityId, self::VALVE_ACTION_SUFFIX);
    }

    private function getVacuumFanSpeedIdent(string $entityId): string
    {
        return $this->buildSharedSuffixIdent($entityId, self::VACUUM_FAN_SPEED_SUFFIX);
    }

    private function supportsVacuumFanSpeed(array $attributes): bool
    {
        return $this->supportsFeatureFlag($this->getSupportedFeatureFlags($attributes), HAVacuumDefinitions::FEATURE_FAN_SPEED);
    }

    private function getLawnMowerActionIdent(string $entityId): string
    {
        return $this->buildSharedSuffixIdent($entityId, self::LAWN_MOWER_ACTION_SUFFIX);
    }

    private function getCameraPowerIdent(string $entityId): string
    {
        return $this->buildSharedSuffixIdent($entityId, '_camera_power');
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
        return array_any($features, fn($feature) => $this->supportsFeatureFlag($supported, (int)$feature));
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

    private function extractEntityActionState(array $entity): ?array
    {
        $entityId = $entity['entity_id'] ?? '';
        if ($entityId === '') {
            return null;
        }

        $attributes = $entity['attributes'] ?? [];
        if (!is_array($attributes)) {
            $attributes = [];
        }

        return [
            'entityId' => $entityId,
            'attributes' => $attributes
        ];
    }

    private function extractEntityActionMaintenanceContext(array $entity, int $positionOffset): ?array
    {
        $context = $this->extractEntityActionState($entity);
        if ($context === null) {
            return null;
        }

        $context['position'] = $this->getEntityPosition($context['entityId']) + $positionOffset;
        return $context;
    }

    private function maintainEntityActionVariable(
        array $entity,
        int $positionOffset,
        callable $optionsResolver,
        callable $identResolver,
        string $caption,
        bool $hideWhenEmpty
    ): void {
        $context = $this->extractEntityActionMaintenanceContext($entity, $positionOffset);
        if ($context === null) {
            return;
        }

        $options = $optionsResolver($context['attributes']);
        $ident = $identResolver($context['entityId']);
        $entityName = $this->getSharedEntityName($entity);
        $this->maintainEnumerationTriggerVariable($ident, $entityName !== '' ? $entityName : $caption, $context['position'], $options, $hideWhenEmpty);
    }

    private function appendEnumerationOptionIfSupported(
        array &$options,
        int $supported,
        int $feature,
        int $action,
        string $caption,
        bool $addAll = false
    ): void {
        if (!$addAll && !$this->supportsFeatureFlag($supported, $feature)) {
            return;
        }

        $options[] = $this->buildEnumerationOption($action, $caption);
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

    private function handleServiceBackedEnumerationAction(
        string $ident,
        mixed $value,
        string $suffix,
        string $domain,
        array $actionMap,
        array $featureMap,
        string $debugMessage
    ): bool {
        $entity = $this->resolveSpecialActionEntity($ident, $suffix, $domain);
        if ($entity === null) {
            return false;
        }

        $context = $this->extractEntityActionState($entity);
        if ($context === null) {
            return true;
        }

        $action = is_numeric($value) ? (int)$value : null;
        $definition = $action !== null ? ($actionMap[$action] ?? null) : null;
        if (!is_array($definition)) {
            return true;
        }

        $supported = $this->getSupportedFeatureFlags($context['attributes']);
        if ($supported !== 0) {
            $requiredFeature = $featureMap[$action] ?? 0;
            if ($requiredFeature === 0 || !$this->supportsFeatureFlag($supported, $requiredFeature)) {
                return true;
            }
        }

        [$service, $payload] = $definition;
        if ($this->sendServiceRequestToParent($domain, $service, ['entity_id' => $context['entityId']])) {
            $this->debugExpert('RequestAction', $debugMessage . ' (REST)', ['EntityID' => $context['entityId'], 'Command' => $service], true);
            $this->resetTriggerActionValue($ident);
            return true;
        }

        if ($this->sendTopicCommandToEntity(
            $context['entityId'],
            $payload,
            $debugMessage,
            ['EntityID' => $context['entityId'], 'Command' => $payload],
            true
        )) {
            $this->resetTriggerActionValue($ident);
        }

        return true;
    }

    private function updateBinaryStateValue(string $ident, string $state, array $offStates): void
    {
        if (@$this->GetIDForIdent($ident) === false) {
            return;
        }

        $normalized = strtolower(trim($state));
        if ($normalized === '' || $normalized === 'unknown' || $normalized === 'unavailable') {
            return;
        }

        $this->setValueWithDebug($ident, !in_array($normalized, $offStates, true));
    }

    private function maintainSupportedPowerVariable(
        string $entityId,
        string $ident,
        bool $supported,
        ?string $state,
        callable $valueUpdater
    ): void {
        $exists = @$this->GetIDForIdent($ident) !== false;
        if (!$supported) {
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

        if (is_string($state) && $state !== '') {
            $valueUpdater($entityId, $state);
        }
    }

    private function maintainEntityPowerVariable(
        array $entity,
        callable $identResolver,
        callable $supportsPower,
        callable $valueUpdater
    ): void {
        $context = $this->extractEntityActionState($entity);
        if ($context === null) {
            return;
        }

        $ident = $identResolver($context['entityId']);
        $state = $entity[self::KEY_STATE] ?? $this->getCachedEntityState($context['entityId']);
        $this->maintainSupportedPowerVariable(
            $context['entityId'],
            $ident,
            $supportsPower($context['attributes']),
            is_string($state) ? $state : null,
            $valueUpdater
        );
    }

    private function handleSupportedPowerAction(
        string $ident,
        mixed $value,
        string $suffix,
        string $domain,
        callable $supportsPower,
        int $turnOnFeature,
        int $turnOffFeature,
        string $debugMessage,
        string $supportedFeatureAttribute = self::KEY_SUPPORTED_FEATURES
    ): bool {
        $entity = $this->resolveSpecialActionEntity($ident, $suffix, $domain);
        if ($entity === null) {
            return false;
        }

        $context = $this->extractEntityActionState($entity);
        if ($context === null) {
            return true;
        }

        if (!$supportsPower($context['attributes'])) {
            return true;
        }

        $supported = $this->getSupportedFeatureFlags($context['attributes'], $supportedFeatureAttribute);
        $turnOn = (bool)$value;
        if ($turnOn && !$this->supportsFeatureFlag($supported, $turnOnFeature)) {
            return true;
        }
        if (!$turnOn && !$this->supportsFeatureFlag($supported, $turnOffFeature)) {
            return true;
        }

        $command = $turnOn ? 'turn_on' : 'turn_off';
        return $this->sendServiceOrTopicCommandToEntity($domain, $context['entityId'], $command, $debugMessage, true);
    }

    private function buildFeatureEnumerationOptions(array $attributes, array $definitions, bool $addAll = false): array
    {
        $supported = $this->getSupportedFeatureFlags($attributes);
        $options = [];

        foreach ($definitions as [$feature, $action, $caption]) {
            $this->appendEnumerationOptionIfSupported($options, $supported, $feature, $action, $caption, $addAll);
        }

        return $options;
    }

    private function resetTriggerActionValue(string $ident): void
    {
        $this->resetVariableByDescriptor($ident, $this->describeVariableByIdent($ident));
    }

    protected function maintainLockActionVariable(array $entity): void
    {
        $this->maintainEntityActionVariable($entity, 5, [$this, 'getLockActionOptions'], [$this, 'getLockActionIdent'], $this->Translate('Select action'), false);
    }

    protected function maintainCoverActionVariable(array $entity): void
    {
        $this->maintainEntityActionVariable($entity, 5, [$this, 'getCoverActionOptions'], [$this, 'getCoverActionIdent'], $this->Translate('Select action'), true);
    }

    protected function maintainCoverTiltActionVariable(array $entity): void
    {
        $this->maintainEntityActionVariable($entity, 6, [$this, 'getCoverTiltActionOptions'], [$this, 'getCoverTiltActionIdent'], $this->Translate('Tilt Action'), true);
    }

    protected function maintainValveActionVariable(array $entity): void
    {
        $this->maintainEntityActionVariable($entity, 5, [$this, 'getValveActionOptions'], [$this, 'getValveActionIdent'], $this->Translate('Select action'), true);
    }

    protected function maintainVacuumActionVariable(array $entity): void
    {
        $this->maintainEntityActionVariable($entity, 5, [$this, 'getVacuumActionOptions'], [$this, 'getVacuumActionIdent'], $this->Translate('Select action'), true);
    }

    protected function maintainVacuumFanSpeedVariable(array $entity): void
    {
        $context = $this->extractEntityActionMaintenanceContext($entity, 6);
        if ($context === null) {
            return;
        }

        $attributes = $context['attributes'];
        $ident = $this->getVacuumFanSpeedIdent($context['entityId']);
        $fanSpeedList = $attributes['fan_speed_list'] ?? null;
        $exists = @$this->GetIDForIdent($ident) !== false;
        if (!is_array($fanSpeedList) || $fanSpeedList === [] || !$this->supportsVacuumFanSpeed($attributes)) {
            if (!$exists) {
                $this->MaintainVariable($ident, $this->Translate('Fan speed'), VARIABLETYPE_STRING, '', 0, false);
            }
            return;
        }

        $position = $context['position'];
        $presentation = [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'OPTIONS'      => $this->getPresentationOptions($fanSpeedList)
        ];

        $this->MaintainVariable($ident, $this->Translate('Fan speed'), VARIABLETYPE_STRING, $presentation, $position, true);
        $this->EnableAction($ident);
    }

    protected function maintainLawnMowerActionVariable(array $entity): void
    {
        $this->maintainEntityActionVariable($entity, 5, [$this, 'getLawnMowerActionOptions'], [$this, 'getLawnMowerActionIdent'], $this->Translate('Select action'), true);
    }

    private function supportsCameraPower(array $attributes): bool
    {
        return $this->supportsFeatureFlag($this->getSupportedFeatureFlags($attributes), HACameraDefinitions::FEATURE_ON_OFF);
    }

    protected function maintainCameraPowerVariable(array $entity): void
    {
        $this->maintainEntityPowerVariable($entity, [$this, 'getCameraPowerIdent'], [$this, 'supportsCameraPower'], [$this, 'updateCameraPowerValue']);
    }

    private function updateCameraPowerValue(string $entityId, string $state): void
    {
        $ident = $this->getCameraPowerIdent($entityId);
        $this->updateBinaryStateValue($ident, $state, ['off']);
    }

    private function handleCameraPowerAction(string $ident, mixed $value): bool
    {
        return $this->handleSupportedPowerAction(
            $ident,
            $value,
            '_camera_power',
            HACameraDefinitions::DOMAIN,
            [$this, 'supportsCameraPower'],
            HACameraDefinitions::FEATURE_ON_OFF,
            HACameraDefinitions::FEATURE_ON_OFF,
            'Camera power'
        );
    }

    private function getLockActionOptions(array $attributes): array
    {
        $options = [
            [
                'Value' => HALockDefinitions::ACTION_LOCK,
                'Caption' => $this->Translate('Lock'),
                'IconActive' => false,
                'IconValue' => '',
                'Color' => -1
            ],
            [
                'Value' => HALockDefinitions::ACTION_UNLOCK,
                'Caption' => $this->Translate('Unlock'),
                'IconActive' => false,
                'IconValue' => '',
                'Color' => -1
            ]
        ];

        if ($this->isLockOpenSupported($attributes)) {
            $options[] = [
                'Value' => HALockDefinitions::ACTION_OPEN,
                'Caption' => $this->Translate('Open'),
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
        return $this->handleServiceBackedEnumerationAction(
            $ident,
            $value,
            self::COVER_ACTION_SUFFIX,
            HACoverDefinitions::DOMAIN,
            [
                HACoverDefinitions::ACTION_OPEN => ['open_cover', 'open'],
                HACoverDefinitions::ACTION_CLOSE => ['close_cover', 'close'],
                HACoverDefinitions::ACTION_STOP => ['stop_cover', 'stop']
            ],
            [
                HACoverDefinitions::ACTION_OPEN => HACoverDefinitions::FEATURE_OPEN,
                HACoverDefinitions::ACTION_CLOSE => HACoverDefinitions::FEATURE_CLOSE,
                HACoverDefinitions::ACTION_STOP => HACoverDefinitions::FEATURE_STOP
            ],
            'Cover action'
        );
    }

    private function handleCoverTiltAction(string $ident, mixed $value): bool
    {
        return $this->handleServiceBackedEnumerationAction(
            $ident,
            $value,
            self::COVER_TILT_ACTION_SUFFIX,
            HACoverDefinitions::DOMAIN,
            [
                HACoverDefinitions::ACTION_OPEN_TILT => ['open_cover_tilt', 'open_tilt'],
                HACoverDefinitions::ACTION_CLOSE_TILT => ['close_cover_tilt', 'close_tilt'],
                HACoverDefinitions::ACTION_STOP_TILT => ['stop_cover_tilt', 'stop_tilt']
            ],
            [
                HACoverDefinitions::ACTION_OPEN_TILT => HACoverDefinitions::FEATURE_OPEN_TILT,
                HACoverDefinitions::ACTION_CLOSE_TILT => HACoverDefinitions::FEATURE_CLOSE_TILT,
                HACoverDefinitions::ACTION_STOP_TILT => HACoverDefinitions::FEATURE_STOP_TILT
            ],
            'Cover tilt action'
        );
    }

    private function handleValveAction(string $ident, mixed $value): bool
    {
        return $this->handleServiceBackedEnumerationAction(
            $ident,
            $value,
            self::VALVE_ACTION_SUFFIX,
            HAValveDefinitions::DOMAIN,
            [
                HAValveDefinitions::ACTION_OPEN => ['open_valve', 'open'],
                HAValveDefinitions::ACTION_CLOSE => ['close_valve', 'close'],
                HAValveDefinitions::ACTION_STOP => ['stop_valve', 'stop']
            ],
            [
                HAValveDefinitions::ACTION_OPEN => HAValveDefinitions::FEATURE_OPEN,
                HAValveDefinitions::ACTION_CLOSE => HAValveDefinitions::FEATURE_CLOSE,
                HAValveDefinitions::ACTION_STOP => HAValveDefinitions::FEATURE_STOP
            ],
            'Valve action'
        );
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

    protected function updateVacuumFanSpeedValue(string $entityId, ?array $attributes): void
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
        return $this->handleSupportedPowerAction(
            $ident,
            $value,
            self::MEDIA_PLAYER_POWER_SUFFIX,
            HAMediaPlayerDefinitions::DOMAIN,
            [$this, 'supportsMediaPlayerPower'],
            HAMediaPlayerDefinitions::FEATURE_TURN_ON,
            HAMediaPlayerDefinitions::FEATURE_TURN_OFF,
            'Media player power'
        );
    }

    private function handleClimatePowerAction(string $ident, mixed $value): bool
    {
        return $this->handleSupportedPowerAction(
            $ident,
            $value,
            self::CLIMATE_POWER_SUFFIX,
            HAClimateDefinitions::DOMAIN,
            [$this, 'supportsClimatePower'],
            HAClimateDefinitions::FEATURE_TURN_ON,
            HAClimateDefinitions::FEATURE_TURN_OFF,
            'Climate power',
            HAClimateDefinitions::ATTRIBUTE_SUPPORTED_FEATURES
        );
    }

    protected function maintainMediaPlayerActionVariable(array $entity): void
    {
        $context = $this->extractEntityActionState($entity);
        if ($context === null) {
            return;
        }

        $options = $this->getMediaPlayerActionOptions($context['attributes']);
        if ($options === []) {
            return;
        }

        $this->debugExpert(__FUNCTION__, 'Options', ['Options' => $options]);

        $ident = $this->getMediaPlayerActionIdent($context['entityId']);
        $exists = @$this->GetIDForIdent($ident) !== false;
        $position = $this->getMediaPlayerOrderPosition(0, 'action');

        $presentation = [
            'PRESENTATION' => VARIABLE_PRESENTATION_LEGACY,
            'PROFILE'      => '~PlaybackPreviousNext'
        ];

        $this->MaintainVariable($ident, $this->Translate('Playback'), VARIABLETYPE_INTEGER, $presentation, $position, true);
        $this->initializeTriggerActionVariable($ident, $exists);
    }

    protected function maintainMediaPlayerPowerVariable(array $entity): void
    {
        $context = $this->extractEntityActionState($entity);
        if ($context === null) {
            return;
        }

        if (!$this->supportsMediaPlayerPower($context['attributes'])) {
            return;
        }

        $ident = $this->getMediaPlayerPowerIdent($context['entityId']);
        $position = $this->getMediaPlayerOrderPosition(0, 'power');
        $presentation = [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH
        ];

        $this->MaintainVariable($ident, $this->Translate('Power'), VARIABLETYPE_BOOLEAN, $presentation, $position, true);
        $this->EnableAction($ident);
        $cachedState = $this->getCachedEntityState($context['entityId']);
        if ($cachedState !== null) {
            $this->updateMediaPlayerPowerValue($context['entityId'], $cachedState);
        }
    }

    private function getMediaPlayerActionIdent(string $entityId): string
    {
        return $this->buildSharedSuffixIdent($entityId, self::MEDIA_PLAYER_ACTION_SUFFIX);
    }

    private function getMediaPlayerPowerIdent(string $entityId): string
    {
        return $this->buildSharedSuffixIdent($entityId, self::MEDIA_PLAYER_POWER_SUFFIX);
    }

    private function getClimatePowerIdent(string $entityId): string
    {
        return $this->buildSharedSuffixIdent($entityId, self::CLIMATE_POWER_SUFFIX);
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
        $this->updateBinaryStateValue($ident, $state, ['off', 'standby']);
    }

    protected function maintainClimatePowerVariable(array $entity): void
    {
        $this->maintainEntityPowerVariable($entity, [$this, 'getClimatePowerIdent'], [$this, 'supportsClimatePower'], [$this, 'updateClimatePowerValue']);
    }

    private function updateClimatePowerValue(string $entityId, string $state): void
    {
        $ident = $this->getClimatePowerIdent($entityId);
        $this->updateBinaryStateValue($ident, $state, ['off']);
    }

    private function getVacuumActionOptions(array $attributes): array
    {
        $supported = $this->getSupportedFeatureFlags($attributes);
        $options = [];

        $this->appendEnumerationOptionIfSupported($options, $supported, HAVacuumDefinitions::FEATURE_START, HAVacuumDefinitions::ACTION_START, $this->Translate('Start'));
        $this->appendEnumerationOptionIfSupported($options, $supported, HAVacuumDefinitions::FEATURE_STOP, HAVacuumDefinitions::ACTION_STOP, $this->Translate('Stop'));
        $this->appendEnumerationOptionIfSupported($options, $supported, HAVacuumDefinitions::FEATURE_PAUSE, HAVacuumDefinitions::ACTION_PAUSE, $this->Translate('Pause'));
        $this->appendEnumerationOptionIfSupported($options, $supported, HAVacuumDefinitions::FEATURE_RETURN_HOME, HAVacuumDefinitions::ACTION_RETURN_HOME, $this->Translate('Return Home'));
        $this->appendEnumerationOptionIfSupported($options, $supported, HAVacuumDefinitions::FEATURE_CLEAN_SPOT, HAVacuumDefinitions::ACTION_CLEAN_SPOT, $this->Translate('Clean Spot'));
        $this->appendEnumerationOptionIfSupported($options, $supported, HAVacuumDefinitions::FEATURE_LOCATE, HAVacuumDefinitions::ACTION_LOCATE, $this->Translate('Locate'));

        return $options;
    }

    private function getCoverActionOptions(array $attributes): array
    {
        return $this->buildFeatureEnumerationOptions($attributes, [
            [HACoverDefinitions::FEATURE_OPEN, HACoverDefinitions::ACTION_OPEN, $this->Translate('Open')],
            [HACoverDefinitions::FEATURE_CLOSE, HACoverDefinitions::ACTION_CLOSE, $this->Translate('Close')],
            [HACoverDefinitions::FEATURE_STOP, HACoverDefinitions::ACTION_STOP, $this->Translate('Stop')]
        ], $this->getSupportedFeatureFlags($attributes) === 0);
    }

    private function getCoverTiltActionOptions(array $attributes): array
    {
        return $this->buildFeatureEnumerationOptions($attributes, [
            [HACoverDefinitions::FEATURE_OPEN_TILT, HACoverDefinitions::ACTION_OPEN_TILT, $this->Translate('Open Tilt')],
            [HACoverDefinitions::FEATURE_CLOSE_TILT, HACoverDefinitions::ACTION_CLOSE_TILT, $this->Translate('Close Tilt')],
            [HACoverDefinitions::FEATURE_STOP_TILT, HACoverDefinitions::ACTION_STOP_TILT, $this->Translate('Stop Tilt')]
        ], $this->getSupportedFeatureFlags($attributes) === 0);
    }

    private function getValveActionOptions(array $attributes): array
    {
        $supported = $this->getSupportedFeatureFlags($attributes);
        $addAll = $supported === 0;
        $options = [];

        $this->appendEnumerationOptionIfSupported($options, $supported, HAValveDefinitions::FEATURE_OPEN, HAValveDefinitions::ACTION_OPEN, $this->Translate('Open'), $addAll);
        $this->appendEnumerationOptionIfSupported($options, $supported, HAValveDefinitions::FEATURE_CLOSE, HAValveDefinitions::ACTION_CLOSE, $this->Translate('Close'), $addAll);
        $this->appendEnumerationOptionIfSupported($options, $supported, HAValveDefinitions::FEATURE_STOP, HAValveDefinitions::ACTION_STOP, $this->Translate('Stop'), $addAll);

        return $options;
    }

    private function getLawnMowerActionOptions(array $attributes): array
    {
        $supported = $this->getSupportedFeatureFlags($attributes);
        $options = [];

        $this->appendEnumerationOptionIfSupported($options, $supported, HALawnMowerDefinitions::FEATURE_START_MOWING, HALawnMowerDefinitions::ACTION_START_MOWING, $this->Translate('Start'));
        $this->appendEnumerationOptionIfSupported($options, $supported, HALawnMowerDefinitions::FEATURE_PAUSE, HALawnMowerDefinitions::ACTION_PAUSE, $this->Translate('Pause'));
        $this->appendEnumerationOptionIfSupported($options, $supported, HALawnMowerDefinitions::FEATURE_DOCK, HALawnMowerDefinitions::ACTION_DOCK, $this->Translate('Return Home'));

        return $options;
    }
}
