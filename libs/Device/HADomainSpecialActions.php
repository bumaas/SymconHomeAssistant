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
        $supported = (int)($attributes[self::KEY_SUPPORTED_FEATURES] ?? 0);
        return ($supported & HALockDefinitions::FEATURE_OPEN) === HALockDefinitions::FEATURE_OPEN;
    }

    private function getLockActionIdent(string $entityId): string
    {
        return $this->sanitizeIdent($entityId) . self::LOCK_ACTION_SUFFIX;
    }

    private function getVacuumActionIdent(string $entityId): string
    {
        return $this->sanitizeIdent($entityId) . self::VACUUM_ACTION_SUFFIX;
    }

    private function getVacuumFanSpeedIdent(string $entityId): string
    {
        return $this->sanitizeIdent($entityId) . self::VACUUM_FAN_SPEED_SUFFIX;
    }

    private function getLawnMowerActionIdent(string $entityId): string
    {
        return $this->sanitizeIdent($entityId) . self::LAWN_MOWER_ACTION_SUFFIX;
    }

    private function maintainLockActionVariable(array $entity): void
    {
        $entityId = $entity['entity_id'] ?? '';
        if ($entityId === '') {
            return;
        }

        $attributes = $entity['attributes'] ?? [];
        $options = $this->getLockActionOptions(is_array($attributes) ? $attributes : []);
        if ($options === []) {
            return;
        }

        $ident = $this->getLockActionIdent($entityId);
        $exists = @$this->GetIDForIdent($ident) !== false;
        $position = $this->getEntityPosition($entityId) + 5;
        $presentation = [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'OPTIONS' => json_encode($options, JSON_THROW_ON_ERROR)
        ];

        $this->MaintainVariable($ident, 'Aktion', VARIABLETYPE_INTEGER, $presentation, $position, true);
        $this->initializeVariableDescriptorValue($ident, $this->createTriggerVariableDescriptor(), $exists);
        $this->EnableAction($ident);
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
        if ($options === []) {
            return;
        }

        $ident = $this->getVacuumActionIdent($entityId);
        $exists = @$this->GetIDForIdent($ident) !== false;
        $position = $this->getEntityPosition($entityId) + 5;
        $presentation = [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'OPTIONS'      => json_encode($options, JSON_THROW_ON_ERROR)
        ];

        $this->MaintainVariable($ident, $this->Translate('Aktion'), VARIABLETYPE_INTEGER, $presentation, $position, true);
        $this->initializeVariableDescriptorValue($ident, $this->createTriggerVariableDescriptor(), $exists);
        $this->EnableAction($ident);
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

        $fanSpeedList = $attributes['fan_speed_list'] ?? null;
        if (!is_array($fanSpeedList) || $fanSpeedList === []) {
            return;
        }

        $ident = $this->getVacuumFanSpeedIdent($entityId);
        $position = $this->getEntityPosition($entityId) + 6;
        $presentation = [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'OPTIONS'      => $this->getPresentationOptions($fanSpeedList)
        ];

        $this->MaintainVariable($ident, $this->Translate('LÃƒÂ¼fterstufe'), VARIABLETYPE_STRING, $presentation, $position, true);
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
        if ($options === []) {
            return;
        }

        $ident = $this->getLawnMowerActionIdent($entityId);
        $exists = @$this->GetIDForIdent($ident) !== false;
        $position = $this->getEntityPosition($entityId) + 5;
        $presentation = [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'OPTIONS'      => json_encode($options, JSON_THROW_ON_ERROR)
        ];

        $this->MaintainVariable($ident, $this->Translate('Aktion'), VARIABLETYPE_INTEGER, $presentation, $position, true);
        $this->initializeVariableDescriptorValue($ident, $this->createTriggerVariableDescriptor(), $exists);
        $this->EnableAction($ident);
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
                'Caption' => $this->Translate('Ãƒâ€“ffnen'),
                'IconActive' => false,
                'IconValue' => '',
                'Color' => -1
            ];
        }

        return $options;
    }

    private function handleLockAction(string $ident, mixed $value): bool
    {
        if (!str_ends_with($ident, self::LOCK_ACTION_SUFFIX)) {
            return false;
        }

        $entity = $this->findEntityByIdentSuffix($ident, self::LOCK_ACTION_SUFFIX, HALockDefinitions::DOMAIN);
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

        $topic = $this->getSetTopicForEntity($entityId);
        if ($topic === '') {
            return true;
        }

        $this->debugExpert('RequestAction', 'Lock action', ['EntityID' => $entityId, 'Command' => $command]);
        $this->sendMqttMessage($topic, $command);
        $this->resetVariableByDescriptor($ident, $this->describeVariableByIdent($ident));
        return true;
    }

    private function handleVacuumAction(string $ident, mixed $value): bool
    {
        if (!str_ends_with($ident, self::VACUUM_ACTION_SUFFIX)) {
            return false;
        }

        $entity = $this->findEntityByIdentSuffix($ident, self::VACUUM_ACTION_SUFFIX, HAVacuumDefinitions::DOMAIN);
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

        $topic = $this->getSetTopicForEntity($entityId);
        if ($topic === '') {
            return true;
        }

        $this->debugExpert('RequestAction', 'Vacuum action', ['EntityID' => $entityId, 'Command' => $command]);
        $this->sendMqttMessage($topic, $command);
        $this->resetVariableByDescriptor($ident, $this->describeVariableByIdent($ident));
        return true;
    }

    private function handleVacuumFanSpeedAction(string $ident, mixed $value): bool
    {
        if (!str_ends_with($ident, self::VACUUM_FAN_SPEED_SUFFIX)) {
            return false;
        }

        $entity = $this->findEntityByIdentSuffix($ident, self::VACUUM_FAN_SPEED_SUFFIX, HAVacuumDefinitions::DOMAIN);
        if ($entity === null) {
            return false;
        }

        $entityId = $entity['entity_id'] ?? '';
        if ($entityId === '') {
            return true;
        }

        $fanSpeed = trim((string)$value);
        if ($fanSpeed === '') {
            return true;
        }

        $payload = json_encode(['fan_speed' => $fanSpeed], JSON_THROW_ON_ERROR);
        $topic = $this->getSetTopicForEntity($entityId);
        if ($topic === '') {
            return true;
        }

        $this->debugExpert('RequestAction', 'Vacuum fan_speed', ['EntityID' => $entityId, 'fan_speed' => $fanSpeed]);
        $this->sendMqttMessage($topic, $payload);
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
        if (!str_ends_with($ident, self::LAWN_MOWER_ACTION_SUFFIX)) {
            return false;
        }

        $entity = $this->findEntityByIdentSuffix($ident, self::LAWN_MOWER_ACTION_SUFFIX, HALawnMowerDefinitions::DOMAIN);
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

        $topic = $this->getSetTopicForEntity($entityId);
        if ($topic === '') {
            return true;
        }

        $this->debugExpert('RequestAction', 'Lawn mower action', ['EntityID' => $entityId, 'Command' => $command]);
        $this->sendMqttMessage($topic, $command);
        $this->resetVariableByDescriptor($ident, $this->describeVariableByIdent($ident));
        return true;
    }

    private function handleMediaPlayerAction(string $ident, mixed $value): bool
    {
        if (!str_ends_with($ident, self::MEDIA_PLAYER_ACTION_SUFFIX)) {
            return false;
        }

        $this->debugExpert(__FUNCTION__, 'Action value set', ['Ident' => $ident, 'Value' => $value], true);

        $entity = $this->findEntityByIdentSuffix($ident, self::MEDIA_PLAYER_ACTION_SUFFIX, HAMediaPlayerDefinitions::DOMAIN);
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

        $topic = $this->getSetTopicForEntity($entityId);
        if ($topic === '') {
            return true;
        }

        $this->debugExpert('RequestAction', 'Media player action', ['EntityID' => $entityId, 'Action' => $command], true);
        $this->sendMqttMessage($topic, $command);
        $this->resetVariableByDescriptor($ident, $this->describeVariableByIdent($ident));
        return true;
    }

    private function handleMediaPlayerPowerAction(string $ident, mixed $value): bool
    {
        if (!str_ends_with($ident, self::MEDIA_PLAYER_POWER_SUFFIX)) {
            return false;
        }

        $entity = $this->findEntityByIdentSuffix($ident, self::MEDIA_PLAYER_POWER_SUFFIX, HAMediaPlayerDefinitions::DOMAIN);
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

        $supported = (int)($attributes[self::KEY_SUPPORTED_FEATURES] ?? 0);
        $turnOn = (bool)$value;
        if ($turnOn && ($supported & HAMediaPlayerDefinitions::FEATURE_TURN_ON) !== HAMediaPlayerDefinitions::FEATURE_TURN_ON) {
            return true;
        }
        if (!$turnOn && ($supported & HAMediaPlayerDefinitions::FEATURE_TURN_OFF) !== HAMediaPlayerDefinitions::FEATURE_TURN_OFF) {
            return true;
        }

        $command = $turnOn ? 'turn_on' : 'turn_off';
        if ($this->sendServiceRequestToParent(HAMediaPlayerDefinitions::DOMAIN, $command, ['entity_id' => $entityId])) {
            $this->debugExpert('RequestAction', 'Media player power (REST)', ['EntityID' => $entityId, 'Command' => $command], true);
            return true;
        }

        $topic = $this->getSetTopicForEntity($entityId);
        if ($topic === '') {
            return true;
        }

        $this->debugExpert('RequestAction', 'Media player power', ['EntityID' => $entityId, 'Command' => $command], true);
        $this->sendMqttMessage($topic, $command);
        return true;
    }

    private function handleClimatePowerAction(string $ident, mixed $value): bool
    {
        if (!str_ends_with($ident, self::CLIMATE_POWER_SUFFIX)) {
            return false;
        }

        $entity = $this->findEntityByIdentSuffix($ident, self::CLIMATE_POWER_SUFFIX, HAClimateDefinitions::DOMAIN);
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

        $supported = (int)($attributes[HAClimateDefinitions::ATTRIBUTE_SUPPORTED_FEATURES] ?? 0);
        $turnOn = (bool)$value;
        if ($turnOn && ($supported & HAClimateDefinitions::FEATURE_TURN_ON) !== HAClimateDefinitions::FEATURE_TURN_ON) {
            return true;
        }
        if (!$turnOn && ($supported & HAClimateDefinitions::FEATURE_TURN_OFF) !== HAClimateDefinitions::FEATURE_TURN_OFF) {
            return true;
        }

        $command = $turnOn ? 'turn_on' : 'turn_off';
        if ($this->sendServiceRequestToParent(HAClimateDefinitions::DOMAIN, $command, ['entity_id' => $entityId])) {
            $this->debugExpert('RequestAction', 'Climate power (REST)', ['EntityID' => $entityId, 'Command' => $command], true);
            return true;
        }

        $topic = $this->getSetTopicForEntity($entityId);
        if ($topic === '') {
            return true;
        }

        $this->debugExpert('RequestAction', 'Climate power', ['EntityID' => $entityId, 'Command' => $command], true);
        $this->sendMqttMessage($topic, $command);
        return true;
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
        $this->initializeVariableDescriptorValue($ident, $this->createTriggerVariableDescriptor(), $exists);
        $this->EnableAction($ident);
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
        $supported = (int)($attributes[self::KEY_SUPPORTED_FEATURES] ?? 0);
        $options = [];

        $addAll = $supported === 0;
        if ($addAll || ($supported & HAMediaPlayerDefinitions::FEATURE_PREVIOUS_TRACK) === HAMediaPlayerDefinitions::FEATURE_PREVIOUS_TRACK) {
            $options[] = ['Value' => HAMediaPlayerDefinitions::ACTION_PREVIOUS, 'Caption' => $this->Translate('Previous Track')];
        }
        if ($addAll || ($supported & HAMediaPlayerDefinitions::FEATURE_PLAY) === HAMediaPlayerDefinitions::FEATURE_PLAY) {
            $options[] = ['Value' => HAMediaPlayerDefinitions::ACTION_PLAY, 'Caption' => $this->Translate('Play')];
        }
        if ($addAll || ($supported & HAMediaPlayerDefinitions::FEATURE_PAUSE) === HAMediaPlayerDefinitions::FEATURE_PAUSE) {
            $options[] = ['Value' => HAMediaPlayerDefinitions::ACTION_PAUSE, 'Caption' => $this->Translate('Pause')];
        }
        if ($addAll || ($supported & HAMediaPlayerDefinitions::FEATURE_STOP) === HAMediaPlayerDefinitions::FEATURE_STOP) {
            $options[] = ['Value' => HAMediaPlayerDefinitions::ACTION_STOP, 'Caption' => $this->Translate('Stop')];
        }
        if ($addAll || ($supported & HAMediaPlayerDefinitions::FEATURE_NEXT_TRACK) === HAMediaPlayerDefinitions::FEATURE_NEXT_TRACK) {
            $options[] = ['Value' => HAMediaPlayerDefinitions::ACTION_NEXT, 'Caption' => $this->Translate('Next Track')];
        }

        foreach ($options as &$option) {
            $option['Value'] = (int)($option['Value'] ?? 0);
            $option['IconActive'] = false;
            $option['IconValue'] = '';
            $option['Color'] = -1;
        }
        unset($option);

        $this->debugExpert(__FUNCTION__, 'Result', ['Options' => $options, 'Supported' => $supported, 'AddAll' => $addAll]);
        return $options;
    }

    private function supportsMediaPlayerPower(array $attributes): bool
    {
        $supported = (int)($attributes[self::KEY_SUPPORTED_FEATURES] ?? 0);
        if ($supported === 0) {
            return false;
        }
        return (($supported & HAMediaPlayerDefinitions::FEATURE_TURN_ON) === HAMediaPlayerDefinitions::FEATURE_TURN_ON)
               || (($supported & HAMediaPlayerDefinitions::FEATURE_TURN_OFF) === HAMediaPlayerDefinitions::FEATURE_TURN_OFF);
    }

    private function supportsClimatePower(array $attributes): bool
    {
        $supported = (int)($attributes[HAClimateDefinitions::ATTRIBUTE_SUPPORTED_FEATURES] ?? 0);
        if ($supported === 0) {
            return false;
        }
        return (($supported & HAClimateDefinitions::FEATURE_TURN_ON) === HAClimateDefinitions::FEATURE_TURN_ON)
               || (($supported & HAClimateDefinitions::FEATURE_TURN_OFF) === HAClimateDefinitions::FEATURE_TURN_OFF);
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
        if (!is_array($attributes)) {
            $attributes = [];
        }
        if (!$this->supportsClimatePower($attributes)) {
            $this->MaintainVariable($ident, $this->Translate('Power'), VARIABLETYPE_BOOLEAN, ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH], 1, false);
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
        $supported = (int)($attributes[self::KEY_SUPPORTED_FEATURES] ?? 0);
        $options = [];

        if (($supported & HAVacuumDefinitions::FEATURE_START) === HAVacuumDefinitions::FEATURE_START) {
            $options[] = ['Value' => HAVacuumDefinitions::ACTION_START, 'Caption' => $this->Translate('Start')];
        }
        if (($supported & HAVacuumDefinitions::FEATURE_STOP) === HAVacuumDefinitions::FEATURE_STOP) {
            $options[] = ['Value' => HAVacuumDefinitions::ACTION_STOP, 'Caption' => $this->Translate('Stop')];
        }
        if (($supported & HAVacuumDefinitions::FEATURE_PAUSE) === HAVacuumDefinitions::FEATURE_PAUSE) {
            $options[] = ['Value' => HAVacuumDefinitions::ACTION_PAUSE, 'Caption' => $this->Translate('Pause')];
        }
        if (($supported & HAVacuumDefinitions::FEATURE_RETURN_HOME) === HAVacuumDefinitions::FEATURE_RETURN_HOME) {
            $options[] = ['Value' => HAVacuumDefinitions::ACTION_RETURN_HOME, 'Caption' => $this->Translate('Zur Basis')];
        }
        if (($supported & HAVacuumDefinitions::FEATURE_CLEAN_SPOT) === HAVacuumDefinitions::FEATURE_CLEAN_SPOT) {
            $options[] = ['Value' => HAVacuumDefinitions::ACTION_CLEAN_SPOT, 'Caption' => $this->Translate('Punktreinigung')];
        }
        if (($supported & HAVacuumDefinitions::FEATURE_LOCATE) === HAVacuumDefinitions::FEATURE_LOCATE) {
            $options[] = ['Value' => HAVacuumDefinitions::ACTION_LOCATE, 'Caption' => $this->Translate('Lokalisieren')];
        }

        foreach ($options as &$option) {
            $option['IconActive'] = false;
            $option['IconValue'] = '';
            $option['Color'] = -1;
        }
        unset($option);

        return $options;
    }

    private function getLawnMowerActionOptions(array $attributes): array
    {
        $supported = (int)($attributes[self::KEY_SUPPORTED_FEATURES] ?? 0);
        $options = [];

        if (($supported & HALawnMowerDefinitions::FEATURE_START_MOWING) === HALawnMowerDefinitions::FEATURE_START_MOWING) {
            $options[] = ['Value' => HALawnMowerDefinitions::ACTION_START_MOWING, 'Caption' => $this->Translate('Start')];
        }
        if (($supported & HALawnMowerDefinitions::FEATURE_PAUSE) === HALawnMowerDefinitions::FEATURE_PAUSE) {
            $options[] = ['Value' => HALawnMowerDefinitions::ACTION_PAUSE, 'Caption' => $this->Translate('Pause')];
        }
        if (($supported & HALawnMowerDefinitions::FEATURE_DOCK) === HALawnMowerDefinitions::FEATURE_DOCK) {
            $options[] = ['Value' => HALawnMowerDefinitions::ACTION_DOCK, 'Caption' => $this->Translate('Zur Basis')];
        }

        foreach ($options as &$option) {
            $option['IconActive'] = false;
            $option['IconValue'] = '';
            $option['Color'] = -1;
        }
        unset($option);

        return $options;
    }
}