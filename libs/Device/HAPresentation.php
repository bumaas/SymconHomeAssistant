<?php

declare(strict_types=1);

trait HAPresentationTrait
{
    use HAEntityVariableNamingTrait;
    use HASharedPresentationTrait;

    protected function getEntityPresentation(string $domain, array $entity, int $type): array
    {
        $domain = HADomainCatalog::normalizeDomainAlias($domain);
        $this->debugExpert(__FUNCTION__, 'Input', ['Domain' => $domain, 'Type' => $type, 'Entity' => $entity]);
        $attributes = $entity['attributes'] ?? [];
        if (!is_array($attributes)) {
            $attributes = [];
        }

        $presentation = $this->getDomainEntityPresentation($domain, $entity, $attributes, $type);
        if ($presentation !== null) {
            return $presentation;
        }

        return $this->getTypeFallbackEntityPresentation($domain, $attributes, $type)
            ?? $this->getDefaultEntityValuePresentation($attributes, $type);
    }

    // Split the main dispatcher into domain-specific, type fallback and default paths.
    private function getDomainEntityPresentation(string $domain, array $entity, array $attributes, int $type): ?array
    {
        return match ($domain) {
            HABinarySensorDefinitions::DOMAIN => $this->getBinarySensorPresentation($attributes),
            HALightDefinitions::DOMAIN => $this->getStaticPresentation(HALightDefinitions::PRESENTATION),
            HASwitchDefinitions::DOMAIN => $this->getStaticPresentation(HASwitchDefinitions::PRESENTATION),
            HANumberDefinitions::DOMAIN => $this->getNumberPresentation($attributes),
            HAClimateDefinitions::DOMAIN => $this->getClimatePresentation($attributes),
            HASensorDefinitions::DOMAIN => $this->getSensorEntityPresentation($attributes, $type),
            HADateTimeDefinitions::DOMAIN => $this->getTemporalEntityPresentation($attributes, true, true),
            HAInputDateTimeDefinitions::DOMAIN => $this->getTemporalEntityPresentation($attributes, true, true),
            HAImageDefinitions::DOMAIN => $this->getDateTimeValuePresentation(2),
            HAUpdateDefinitions::DOMAIN => $this->getUpdatePresentation(),
            HALockDefinitions::DOMAIN => $this->getLockPresentation($attributes),
            HAVacuumDefinitions::DOMAIN => $this->getVacuumPresentation(),
            HALawnMowerDefinitions::DOMAIN => $this->getLawnMowerPresentation(),
            HAFanDefinitions::DOMAIN => $this->getFanPresentation(),
            HAHumidifierDefinitions::DOMAIN => $this->getHumidifierPresentation(),
            HAButtonDefinitions::DOMAIN => $this->getButtonPresentation($entity),
            HAMediaPlayerDefinitions::DOMAIN => $this->getMediaPlayerPresentation(),
            HACoverDefinitions::DOMAIN => $this->getCoverPresentation($attributes, $type),
            HAValveDefinitions::DOMAIN => $this->getValvePresentation($attributes, $type),
            HAEventDefinitions::DOMAIN => $this->getEventPresentation(),
            default => null
        };
    }

    private function getSensorEntityPresentation(array $attributes, int $type): ?array
    {
        $deviceClass = $attributes['device_class'] ?? '';
        if (is_string($deviceClass) && trim($deviceClass) === HASensorDefinitions::DEVICE_CLASS_DATE) {
            return $this->getDateTimeValuePresentation(0);
        }
        if (is_string($deviceClass) && trim($deviceClass) === HASensorDefinitions::DEVICE_CLASS_TIMESTAMP) {
            return $this->getDateTimeValuePresentation(2);
        }
        if (is_string($deviceClass) && trim($deviceClass) === HASensorDefinitions::DEVICE_CLASS_DURATION) {
            return $this->getDurationValuePresentation(3);
        }

        $sensorOptions = HASelectDefinitions::normalizeOptions($attributes['options'] ?? null);
        if ($type === VARIABLETYPE_STRING && $sensorOptions !== []) {
            return $this->filterPresentation([
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'OPTIONS' => $this->getValuePresentationOptions($sensorOptions)
            ]);
        }

        return null;
    }

    private function getTemporalEntityPresentation(array $attributes, bool $defaultDate, bool $defaultTime): array
    {
        $capabilities = HADateTimeValue::resolveCapabilities($attributes, null, $defaultDate, $defaultTime);

        return $this->filterPresentation([
            'PRESENTATION' => VARIABLE_PRESENTATION_DATE_TIME,
            'DATE' => $capabilities['has_date'] ? 1 : 0,
            'DAY_OF_THE_WEEK' => false,
            'MONTH_TEXT' => false,
            'TIME' => $capabilities['has_time'] ? 2 : 0
        ]);
    }

    private function getTypeFallbackEntityPresentation(string $domain, array $attributes, int $type): ?array
    {
        if ($type === VARIABLETYPE_BOOLEAN) {
            return $this->getStaticPresentation(VARIABLE_PRESENTATION_SWITCH);
        }

        if ($domain === HASelectDefinitions::DOMAIN) {
            $options = HASelectDefinitions::normalizeOptions($attributes['options'] ?? null);
            if ($options !== []) {
                return $this->filterPresentation([
                    'PRESENTATION' => HASelectDefinitions::PRESENTATION,
                    'OPTIONS' => $this->getPresentationOptions($options)
                ]);
            }
        }

        if (($type === VARIABLETYPE_INTEGER || $type === VARIABLETYPE_FLOAT) && $this->isWriteable($domain)) {
            $slider = $this->getNumericSliderPresentation($attributes);
            if ($slider !== null) {
                return $slider;
            }
        }

        return null;
    }

    private function getDefaultEntityValuePresentation(array $attributes, int $type): array
    {
        $suffix = $this->getPresentationSuffix($attributes);
        return $this->filterPresentation([
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'DIGITS' => ($type === VARIABLETYPE_INTEGER || $type === VARIABLETYPE_FLOAT)
                ? $this->getNumericDigits($attributes) : null,
            'SUFFIX' => $this->formatPresentationSuffix($suffix)
        ]);
    }

    private function getStaticPresentation(string|int $presentation): array
    {
        return [
            'PRESENTATION' => $presentation
        ];
    }

    private function getDateTimeValuePresentation(int $time): array
    {
        return $this->filterPresentation([
            'PRESENTATION' => VARIABLE_PRESENTATION_DATE_TIME,
            'DATE' => 1,
            'DAY_OF_THE_WEEK' => false,
            'MONTH_TEXT' => false,
            'TIME' => $time
        ]);
    }

    private function getDurationValuePresentation(int $format): array
    {
        return $this->filterPresentation([
            'PRESENTATION' => VARIABLE_PRESENTATION_DURATION,
            'FORMAT' => $format
        ]);
    }

    private function getVacuumPresentation(): array
    {
        return $this->getMetaStateValuePresentation(HAVacuumDefinitions::STATE_OPTIONS);
    }

    private function getLawnMowerPresentation(): array
    {
        return $this->getMetaStateValuePresentation(HALawnMowerDefinitions::STATE_OPTIONS);
    }

    private function getMetaStateValuePresentation(array $stateOptions): array
    {
        $options = [];
        foreach ($stateOptions as $value => $meta) {
            $caption = (string)($meta['caption'] ?? $value);
            $icon = (string)($meta['icon'] ?? '');
            $options[] = [
                'Value' => $value,
                'Caption' => $this->Translate($caption),
                'IconActive' => $icon !== '',
                'IconValue' => $icon,
                'ColorActive' => false,
                'ColorValue' => -1
            ];
        }

        return $this->filterPresentation([
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'OPTIONS' => json_encode($options, JSON_THROW_ON_ERROR)
        ]);
    }

    private function getButtonPresentation(array $entity): array
    {
        $caption = $this->getButtonVariableName($entity);
        $options = [[
                        'Value'      => HAButtonDefinitions::ACTION_PRESS,
                        'Caption'    => $this->Translate($caption),
                        'IconActive' => false,
                        'IconValue'  => '',
                        'Color'      => -1
        ]];

        return $this->filterPresentation([
                                             'PRESENTATION' => HAButtonDefinitions::PRESENTATION,
                                             'OPTIONS'      => json_encode($options, JSON_THROW_ON_ERROR)
                                         ]);
    }

    private function getMediaPlayerPresentation(): array
    {
        $options = [];
        foreach (HAMediaPlayerDefinitions::STATE_OPTIONS as $value => $caption) {
            $options[] = [
                'Value'      => $value,
                'Caption'    => $this->Translate((string)$caption),
                'IconActive' => false,
                'IconValue'  => '',
                'ColorActive'      => false,
                'ColorValue'      => -1
            ];
        }

        return $this->filterPresentation([
                                             'PRESENTATION' => HAMediaPlayerDefinitions::PRESENTATION,
                                             'OPTIONS'      => json_encode($options, JSON_THROW_ON_ERROR)
                                         ]);
    }

    private function getFanPresentation(): array
    {
        return $this->filterPresentation([
                                             'PRESENTATION' => HAFanDefinitions::PRESENTATION
                                         ]);
    }

    private function getHumidifierPresentation(): array
    {
        return $this->filterPresentation([
                                             'PRESENTATION' => HAHumidifierDefinitions::PRESENTATION
                                         ]);
    }

    protected function getMediaPlayerAttributePresentation(string $attribute, array $attributes, array $meta): array
    {
        if ($attribute === 'volume_level') {
            $min          = (float)($meta['min'] ?? null);
            $max          = (float)($meta['max'] ?? null);
            $step         = (float)($meta['step'] ?? null);
            $percentage   = (boolean)($meta['percentage'] ?? null);
            $usageType    = (int)($meta['usage_type'] ?? null);
            $intervals    = $meta['intervals'] ?? null;
            $presentation = [
                'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                'MIN'          => $min,
                'MAX'          => $max,
                'STEP_SIZE'    => $step,
                'USAGE_TYPE'   => $usageType,
                'PERCENTAGE'   => $percentage
            ];
            if (is_array($intervals) && $intervals !== []) {
                $presentation['INTERVALS']        = json_encode($intervals, JSON_THROW_ON_ERROR);
                $presentation['INTERVALS_ACTIVE'] = true;
            }
            return $this->filterPresentation($presentation);
        }

        if ($attribute === 'media_position') {
            if (!$this->isWritableMediaPlayerAttribute($attribute, $attributes)) {
                return $this->filterPresentation([
                                                     'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                                                     'DIGITS'       => 0,
                                                     'SUFFIX'       => $this->formatPresentationSuffix('s')
                                                 ]);
            }
            $min       = (float)($meta['min'] ?? 0);
            $maxValue  = $attributes['media_duration'] ?? null;
            $max       = (is_numeric($maxValue) && ($maxValue > 0)) ? (float)$maxValue : 100;
            $stepSize  = (int)($meta['step_size'] ?? null);
            $usageType = (int)($meta['usage_type'] ?? null);
            return $this->filterPresentation([
                                                 'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                                                 'MIN'          => $min,
                                                 'MAX'          => $max,
                                                 'STEP_SIZE'    => $stepSize,
                                                 'USAGE_TYPE'   => $usageType,
                                                 'SUFFIX'       => $this->formatPresentationSuffix('s')
                                             ]);
        }

        if ($attribute === 'media_duration') {
            return $this->filterPresentation([
                                                 'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                                                 'DIGITS'       => 0,
                                                 'SUFFIX'       => $this->formatPresentationSuffix('s')
                                             ]);
        }

        if ($attribute === 'repeat') {
            $options = [];
            foreach (HAMediaPlayerDefinitions::REPEAT_OPTIONS as $value => $captionKey) {
                $options[] = [
                    'Value'       => (int)$value,
                    'Caption'     => $this->Translate((string)$captionKey),
                    'IconActive'  => false,
                    'IconValue'   => '',
                    'Color'  => -1
                ];
            }
            return $this->filterPresentation([
                                                 'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                                                 'OPTIONS'      => json_encode($options, JSON_THROW_ON_ERROR)
                                             ]);
        }

        if ($attribute === 'source') {
            $presentation = $this->buildOptionPresentation(
                $attributes['source_list'] ?? null,
                $this->isWritableMediaPlayerAttribute($attribute, $attributes)
            );
            if ($presentation !== null) {
                return $presentation;
            }
        }

        if ($attribute === 'sound_mode') {
            $presentation = $this->buildOptionPresentation(
                $attributes['sound_mode_list'] ?? null,
                $this->isWritableMediaPlayerAttribute($attribute, $attributes)
            );
            if ($presentation !== null) {
                return $presentation;
            }
        }

        if ($attribute === 'shuffle') {
            $options = [
                [
                    'Value'      => false,
                    'Caption'    => $this->Translate('Off'),
                    'IconActive' => true,
                    'IconValue'  => 'angles-right',
                    'Color'      => -1
                ],
                [
                    'Value'      => true,
                    'Caption'    => $this->Translate('On'),
                    'IconActive' => true,
                    'IconValue'  => 'shuffle',
                    'Color'      => -1
                ]
            ];
            return $this->filterPresentation([
                                                 'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                                                 'OPTIONS'      => json_encode($options, JSON_THROW_ON_ERROR)
                                             ]);
        }

        if ($attribute === 'is_volume_muted' || $attribute === 'cross_fade') {
            $usageType    = (int)($meta['usage_type'] ?? 0);
            $iconFalse    = (string)($meta['icon_false'] ?? null);
            $iconTrue     = (string)($meta['icon_true'] ?? null);
            $useIconFalse = (bool)($meta['use_icon_false'] ?? null);
            return $this->filterPresentation([
                                                 'PRESENTATION'   => VARIABLE_PRESENTATION_SWITCH,
                                                 'USAGE_TYPE'     => $usageType,
                                                 'ICON_FALSE'     => $iconFalse,
                                                 'ICON_TRUE'      => $iconTrue,
                                                 'USE_ICON_FALSE' => $useIconFalse
                                             ]);
        }

        $profile = (string)($meta['profile'] ?? '');
        if ($profile !== '') {
            return $this->filterPresentation([
                                                 'PRESENTATION' => VARIABLE_PRESENTATION_LEGACY,
                                                 'PROFILE'      => $profile
                                             ]);
        }

        $suffix = $meta['suffix'] ?? '';
        return $this->filterPresentation([
                                             'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                                             'SUFFIX'       => $this->formatPresentationSuffix((string)$suffix)
                                         ]);
    }

    protected function getFanAttributePresentation(string $attribute, array $attributes, array $meta): array
    {
        if ($attribute === 'percentage') {
            $min  = (float)($meta['min'] ?? 0);
            $max  = (float)($meta['max'] ?? 100);
            $step = (float)($meta['step_size'] ?? 1);
            return $this->filterPresentation([
                                                 'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                                                 'MIN'          => $min,
                                                 'MAX'          => $max,
                                                 'STEP_SIZE'    => $step
                                             ]);
        }

        if ($attribute === 'preset_mode') {
            // current_direction ist read-only -> Wertanzeige; nur beschreibbare Attribute werden Aufzaehlung.
            $presentation = $this->buildOptionPresentation(
                $attributes['preset_modes'] ?? null,
                $this->isWritableFanAttribute($attribute, $attributes)
            );
            if ($presentation !== null) {
                return $presentation;
            }
        }

        if ($attribute === 'direction' || $attribute === 'current_direction') {
            $presentation = $this->buildOptionPresentation(
                $attributes['direction_list'] ?? null,
                $this->isWritableFanAttribute($attribute, $attributes)
            );
            if ($presentation !== null) {
                return $presentation;
            }
        }

        if ($attribute === 'oscillating') {
            return $this->filterPresentation([
                                                 'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH
                                             ]);
        }

        $suffix = $meta['suffix'] ?? '';
        return $this->filterPresentation([
                                             'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                                             'SUFFIX'       => $this->formatPresentationSuffix((string)$suffix)
                                         ]);
    }

    protected function getHumidifierAttributePresentation(string $attribute, array $attributes, array $meta): array
    {
        if ($attribute === HAHumidifierDefinitions::ATTRIBUTE_TARGET_HUMIDITY) {
            $min  = is_numeric($attributes['min_humidity'] ?? null) ? (float)$attributes['min_humidity'] : 0;
            $max  = is_numeric($attributes['max_humidity'] ?? null) ? (float)$attributes['max_humidity'] : 100;
            $step = is_numeric($attributes['target_humidity_step'] ?? null) ? (float)$attributes['target_humidity_step'] : 1;
            return $this->filterPresentation([
                                                 'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                                                 'MIN'          => $min,
                                                 'MAX'          => $max,
                                                 'STEP_SIZE'    => $step,
                                                 'SUFFIX'       => $this->formatPresentationSuffix('%')
                                             ]);
        }

        if ($attribute === HAHumidifierDefinitions::ATTRIBUTE_CURRENT_HUMIDITY) {
            return $this->filterPresentation([
                                                 'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                                                 'DIGITS'       => 0,
                                                 'SUFFIX'       => $this->formatPresentationSuffix('%')
                                             ]);
        }

        if ($attribute === 'mode') {
            $presentation = $this->buildOptionPresentation(
                $attributes['available_modes'] ?? null,
                $this->isWritableHumidifierAttribute($attribute, $attributes)
            );
            if ($presentation !== null) {
                return $presentation;
            }
        }

        if ($attribute === 'action') {
            $options = $this->getHumidifierActionValueOptions($attributes);
            if ($options !== null) {
                return $this->filterPresentation([
                                                     'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                                                     'OPTIONS'      => $options
                                                 ]);
            }
            return $this->filterPresentation([
                                                 'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION
                                             ]);
        }

        $suffix = $meta['suffix'] ?? '';
        return $this->filterPresentation([
                                             'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                                             'SUFFIX'       => $this->formatPresentationSuffix((string)$suffix)
                                         ]);
    }

    private function getHumidifierActionValueOptions(array $attributes): ?string
    {
        $options = ['off', 'idle', 'humidifying', 'drying', 'tank_full'];
        $current = $attributes[HAHumidifierDefinitions::ATTRIBUTE_ACTION] ?? null;
        if (is_string($current) && trim($current) !== '') {
            $options[] = trim($current);
        }

        $normalized = HASelectDefinitions::normalizeOptions($options);
        return $this->getValuePresentationOptions($normalized);
    }

    private function getLockPresentation(array $attributes): array
    {
        $supported = (int)($attributes[self::KEY_SUPPORTED_FEATURES] ?? 0);
        $allowOpen = ($supported & HALockDefinitions::FEATURE_OPEN) === HALockDefinitions::FEATURE_OPEN;

        $values = [];
        foreach (HALockDefinitions::STATE_OPTIONS as $value => $_meta) {
            if ($value === 'open' && !$allowOpen) {
                continue;
            }
            $values[] = $value;
        }

        return $this->filterPresentation([
                                             'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                                             'OPTIONS'      => $this->getValuePresentationOptions($values)
                                         ]);
    }

    private function getBinarySensorPresentation(array $attributes): array
    {
        $deviceClass = $attributes['device_class'] ?? '';
        if (!is_string($deviceClass)) {
            $deviceClass = '';
        }
        $deviceClass = trim($deviceClass);

        [$trueCaption, $falseCaption, $icon] = HABinarySensorDefinitions::getPresentationMeta($deviceClass);

        return $this->buildSharedBinarySensorPresentation(
            $this->Translate($trueCaption),
            $this->Translate($falseCaption),
            $icon
        );
    }

    private function getUpdatePresentation(): array
    {
        $options = [
            [
                'Value' => false,
                'Caption' => $this->Translate(HAUpdateDefinitions::FALSE_CAPTION),
                'IconActive' => false,
                'IconValue' => '',
                'ColorActive' => false,
                'ColorValue' => -1
            ],
            [
                'Value' => true,
                'Caption' => $this->Translate(HAUpdateDefinitions::TRUE_CAPTION),
                'IconActive' => false,
                'IconValue' => '',
                'ColorActive' => false,
                'ColorValue' => -1
            ]
        ];

        return $this->filterPresentation([
            'PRESENTATION' => HAUpdateDefinitions::PRESENTATION,
            'OPTIONS' => json_encode($options, JSON_THROW_ON_ERROR),
            'ICON' => HAUpdateDefinitions::ICON
        ]);
    }

    private function getCoverPresentation(array $attributes, int $type): array
    {
        $deviceClass = $attributes['device_class'] ?? '';
        if (!is_string($deviceClass)) {
            $deviceClass = '';
        }
        $deviceClass = trim($deviceClass);

        $hasPosition = ($type === VARIABLETYPE_FLOAT);
        if ($hasPosition && HACoverDefinitions::usesShutterPresentation($deviceClass)) {
            return $this->filterPresentation([
                                                 'CLOSE_INSIDE_VALUE' => 0,
                                                 'USAGE_TYPE'         => 0,
                                                 'OPEN_OUTSIDE_VALUE' => 100,
                                                 'PRESENTATION'       => VARIABLE_PRESENTATION_SHUTTER
                                             ]);
        }

        if ($hasPosition) {
            return $this->filterPresentation([
                                                 'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                                                 'MIN'          => 0,
                                                 'MAX'          => 100,
                                                 'STEP_SIZE'    => 1,
                                                 'DIGITS'       => 1,
                                                 'SUFFIX'       => $this->formatPresentationSuffix('%')
                                             ]);
        }

        $options = [];
        foreach (HACoverDefinitions::STATE_OPTIONS as $value => $captionKey) {
            $options[] = [
                'Value'               => $value,
                'Caption'             => $this->Translate($captionKey),
                'IconActive'          => false,
                'IconValue'           => '',
                'Color'               => -1,
                'ContentColorActive'  => false,
                'ContentColorValue'   => -1,
                'ColorDisplay'        => -1,
                'ContentColorDisplay' => -1
            ];
        }

        return $this->filterPresentation([
                                             'PRESENTATION' => HACoverDefinitions::PRESENTATION,
                                             'OPTIONS'      => json_encode($options, JSON_THROW_ON_ERROR)
                                         ]);
    }

    protected function isCoverPositionSupported(array $attributes): bool
    {
        $supported = (int)($attributes[self::KEY_SUPPORTED_FEATURES] ?? 0);
        return ($supported & HACoverDefinitions::FEATURE_SET_POSITION) === HACoverDefinitions::FEATURE_SET_POSITION;
    }

    private function getValvePresentation(array $attributes, int $type): array
    {
        if ($type === VARIABLETYPE_FLOAT) {
            return $this->filterPresentation([
                'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                'MIN' => 0,
                'MAX' => 100,
                'STEP_SIZE' => 1,
                'DIGITS' => 1,
                'SUFFIX' => $this->formatPresentationSuffix('%')
            ]);
        }

        $options = [];
        foreach (HAValveDefinitions::STATE_OPTIONS as $value => $captionKey) {
            $options[] = [
                'Value' => $value,
                'Caption' => $this->Translate($captionKey),
                'IconActive' => false,
                'IconValue' => '',
                'Color' => -1,
                'ContentColorActive' => false,
                'ContentColorValue' => -1,
                'ColorDisplay' => -1,
                'ContentColorDisplay' => -1
            ];
        }

        return $this->filterPresentation([
            'PRESENTATION' => HAValveDefinitions::PRESENTATION,
            'OPTIONS' => json_encode($options, JSON_THROW_ON_ERROR)
        ]);
    }

    private function getClimatePresentation(array $attributes): array
    {
        $supportsTarget     = $this->supportsClimateTargetTemperature($attributes);
        $hasTargetAttribute = array_key_exists(HAClimateDefinitions::ATTRIBUTE_TARGET_TEMPERATURE, $attributes)
                              || array_key_exists('temperature', $attributes);
        if ($supportsTarget || $hasTargetAttribute) {
            $slider = $this->getClimateSliderPresentation($attributes);
            if ($slider !== null) {
                return $slider;
            }
        }

        $suffix = $this->getClimateTemperatureSuffix($attributes);
        return $this->filterPresentation([
                                             'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                                             'USAGE_TYPE'   => 1,
                                             'SUFFIX'       => $this->formatPresentationSuffix($suffix)
                                         ]);
    }

    // HA climate uses feature bit 1 to signal writable target temperature support.
    private function supportsClimateTargetTemperature(array $attributes): bool
    {
        $supported = (int)($attributes[HAClimateDefinitions::ATTRIBUTE_SUPPORTED_FEATURES] ?? 0);
        return ($supported & 1) === 1;
    }

    private function getEventPresentation(): array
    {
        return $this->getDateTimeValuePresentation(2);
    }

    protected function getLightAttributePresentation(string $attribute, array $attributes, array $meta): array
    {
        $suffix = $meta['suffix'] ?? '';
        if (!is_string($suffix)) {
            $suffix = '';
        }
        $suffix             = trim($suffix);
        $isPercent          = $suffix === '%';
        $presentationSuffix = $this->formatPresentationSuffix($suffix);
        $digitsOverride     = $this->getMetaDigitsOverride($meta);

        if ($attribute === 'rgb_color') {
            return $this->buildSharedLightRgbColorPresentation();
        }
        if ($attribute === 'color_mode') {
            $modes = $attributes['supported_color_modes'] ?? [];
            if (!is_array($modes)) {
                $modes = [];
            }
            $currentMode = $attributes['color_mode'] ?? null;
            if (is_string($currentMode) && trim($currentMode) !== '') {
                $modes[] = trim($currentMode);
            }

            return $this->filterPresentation([
                                                 'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                                                 'SUFFIX'       => $presentationSuffix,
                                                 'OPTIONS'      => $this->getLightColorModeValueOptions($modes)
                                             ]);
        }

        if (!$this->isWritableLightAttribute($attribute, $attributes)) {
            return $this->filterPresentation([
                                                 'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                                                 'SUFFIX'       => $presentationSuffix
                                             ]);
        }

        if ($attribute === 'brightness') {
            return $this->buildSharedLightBrightnessPresentation($isPercent, $digitsOverride, $presentationSuffix);
        }
        if ($attribute === 'color_temp') {
            $min = $attributes['min_mireds'] ?? null;
            $max = $attributes['max_mireds'] ?? null;
            $slider = $this->getLightBoundedSliderPresentation($min, $max, $isPercent, $digitsOverride, $presentationSuffix);
            if ($slider !== null) {
                return $slider;
            }
        }
        if ($attribute === 'color_temp_kelvin') {
            $min = $attributes['min_color_temp_kelvin'] ?? null;
            $max = $attributes['max_color_temp_kelvin'] ?? null;
            $slider = $this->getLightBoundedSliderPresentation($min, $max, $isPercent, $digitsOverride, $presentationSuffix);
            if ($slider !== null) {
                return $slider;
            }
        }
        if ($attribute === 'effect') {
            $presentation = $this->buildOptionPresentation(
                $attributes['effect_list'] ?? null,
                $this->isWritableLightAttribute($attribute, $attributes)
            );
            if ($presentation !== null) {
                return $presentation;
            }
        }
        if ($attribute === 'flash') {
            return $this->filterPresentation([
                                                 'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                                                 'OPTIONS'      => $this->getPresentationOptions(['short', 'long'])
                                             ]);
        }

        return $this->filterPresentation([
                                             'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                                             'SUFFIX'       => $presentationSuffix
                                         ]);
    }

    private function getNumericSliderPresentation(array $attributes): ?array
    {
        $min = $attributes['min'] ?? $attributes['native_min_value'] ?? null;
        $max = $attributes['max'] ?? $attributes['native_max_value'] ?? null;
        if (!is_numeric($min) || !is_numeric($max)) {
            return null;
        }

        $step               = $attributes['step'] ?? $attributes['native_step'] ?? 1;
        $suffix             = $this->getPresentationSuffix($attributes);
        $presentationSuffix = $this->formatPresentationSuffix($suffix);
        $digits             = $this->getNumericDigits($attributes, $step);

        $usageType = null;
        $isPercentage = false;
        $displaySuffix = $presentationSuffix;
        if ($digits === 0 && trim($suffix) === '' && $this->isIntensitySliderRange((float)$min, (float)$max)) {
            $usageType = 2; // Intensität
            $isPercentage = true;
            $displaySuffix = $this->formatPresentationSuffix('%');
        }

        return $this->filterPresentation([
                                             'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                                             'MIN'          => (float)$min,
                                             'MAX'          => (float)$max,
                                             'STEP_SIZE'    => (float)$step,
                                             'DIGITS'       => $digits,
                                             'PERCENTAGE'   => $isPercentage,
                                             'USAGE_TYPE'   => $usageType,
                                             'SUFFIX'       => $displaySuffix
                                         ]);
    }

    private function isIntensitySliderRange(float $min, float $max): bool
    {
        $isZeroMin = abs($min - 0.0) < 0.0000001;
        if (!$isZeroMin) {
            return false;
        }

        return abs($max - 100.0) < 0.0000001 || abs($max - 255.0) < 0.0000001;
    }

    private function getPresentationSuffix(array $attributes): string
    {
        $rawUnit = $attributes['unit_of_measurement'] ?? '';
        if (!is_string($rawUnit)) {
            $rawUnit = '';
        }

        $unit = trim($rawUnit);
        if ($unit === '') {
            $fallback = '';
            $altUnit  = $attributes['unit'] ?? '';
            if (is_string($altUnit)) {
                $fallback = trim($altUnit);
            }
            if ($fallback === '') {
                $altUnit = $attributes['display_unit'] ?? '';
                if (is_string($altUnit)) {
                    $fallback = trim($altUnit);
                }
            }
            if ($fallback === '') {
                $altUnit = $attributes['native_unit_of_measurement'] ?? '';
                if (is_string($altUnit)) {
                    $fallback = trim($altUnit);
                }
            }
            $unit = $fallback;
        }

        if ($unit === '') {
            $deviceClass = $attributes['device_class'] ?? '';
            if (is_string($deviceClass)) {
                $deviceClass = trim($deviceClass);
            } else {
                $deviceClass = '';
            }
            if ($deviceClass !== '' && isset(HANumberDefinitions::DEVICE_CLASS_SUFFIX[$deviceClass])) {
                $unit = HANumberDefinitions::DEVICE_CLASS_SUFFIX[$deviceClass];
            } elseif ($deviceClass !== '' && isset(HASensorDefinitions::DEVICE_CLASS_SUFFIX[$deviceClass])) {
                $unit = HASensorDefinitions::DEVICE_CLASS_SUFFIX[$deviceClass];
            }
        }

        $suffix       = '';
        $suffixSource = '';
        if ($unit !== '') {
            $suffix = $unit;
            if ($rawUnit !== '') {
                $suffixSource = 'unit_of_measurement';
            } elseif (isset($attributes['unit']) && is_string($attributes['unit']) && trim($attributes['unit']) !== '') {
                $suffixSource = 'unit';
            } elseif (isset($attributes['display_unit']) && is_string($attributes['display_unit']) && trim($attributes['display_unit']) !== '') {
                $suffixSource = 'display_unit';
            } elseif (isset($attributes['native_unit_of_measurement']) && is_string($attributes['native_unit_of_measurement'])
                      && trim(
                             $attributes['native_unit_of_measurement']
                         ) !== '') {
                $suffixSource = 'native_unit_of_measurement';
            } elseif (isset($attributes['device_class'])) {
                $suffixSource = 'device_class';
            }
        }

        $this->debugExpert('Presentation', 'Suffix berechnet', [
            'unit_of_measurement' => $rawUnit,
            'unit'                => $attributes['unit'] ?? null,
            'display_unit'        => $attributes['display_unit'] ?? null,
            'suffix'              => $suffix,
            'suffix_source'       => $suffixSource
        ]);

        return $suffix;
    }

    private function formatPresentationSuffix(string $suffix): ?string
    {
        $suffix = trim($suffix);
        if ($suffix === '') {
            return null;
        }
        return ' ' . $suffix;
    }

    private function getNumberPresentation(array $attributes): array
    {
        $slider = $this->getNumericSliderPresentation($attributes);
        if ($slider !== null) {
            return $slider;
        }

        $suffix = $this->getPresentationSuffix($attributes);
        return $this->filterPresentation([
                                             'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                                             'DIGITS'       => $this->getNumericDigits($attributes),
                                             'SUFFIX'       => $this->formatPresentationSuffix($suffix)
                                         ]);
    }

    private function filterPresentation(array $presentation): array
    {
        return array_filter(
            $presentation,
            static fn($value) => $value !== null
        );
    }

    private function getPresentationOptions(array|string|null $options): ?string
    {
        if (is_string($options)) {
            $trimmed = trim($options);
            if ($trimmed !== '') {
                $options = [$trimmed];
            }
        }
        if (!is_array($options) || count($options) === 0) {
            return null;
        }

        $formatted = [];
        foreach ($options as $value) {
            $formatted[] = [
                'Value'       => $value,
                'Caption'     => $this->translate((string)$value),
                'IconActive'  => false,
                'IconValue'   => '',
                'Color'  => -1
            ];
        }

        return json_encode($formatted, JSON_THROW_ON_ERROR);
    }

    private function getValuePresentationOptions(?array $options): ?string
    {
        if (!is_array($options) || count($options) === 0) {
            return null;
        }

        $formatted = [];
        foreach ($options as $value) {
            $formatted[] = [
                'Value'       => $value,
                'Caption'     => $this->translate((string)$value),
                'IconActive'  => false,
                'IconValue'   => '',
                'ColorActive' => false,
                'ColorValue'  => -1
            ];
        }

        return json_encode($formatted, JSON_THROW_ON_ERROR);
    }

    private function getLightColorModeValueOptions(array|string|null $modes): ?string
    {
        if (is_string($modes)) {
            $modes = [trim($modes)];
        }
        if (!is_array($modes) || count($modes) === 0) {
            return null;
        }

        $captions = [
            'unknown'    => 'Unknown',
            'on' . 'off' => 'On/Off',
            'brightness' => 'Brightness',
            'color_temp' => 'Color Temperature',
            'hs'         => 'HS',
            'rgb'        => 'RGB',
            'rgbw'       => 'RGBW',
            'rgbww'      => 'RGBWW',
            'white'      => 'White',
            'xy'         => 'XY'
        ];

        $unique = [];
        foreach ($modes as $mode) {
            if (!is_string($mode)) {
                continue;
            }
            $value = strtolower(trim($mode));
            if ($value === '' || isset($unique[$value])) {
                continue;
            }
            $unique[$value] = true;
        }
        if ($unique === []) {
            return null;
        }

        $formatted = [];
        foreach (array_keys($unique) as $value) {
            $caption = $captions[$value] ?? strtoupper($value);
            $formatted[] = [
                'Value'       => $value,
                'Caption'     => $this->translate($caption),
                'IconActive'  => false,
                'IconValue'   => '',
                'ColorActive' => false,
                'ColorValue'  => -1
            ];
        }

        return json_encode($formatted, JSON_THROW_ON_ERROR);
    }

    protected function getEntityVariableName(string $domain, array $entity): string
    {
        return $this->buildSharedEntityVariableName($domain, $entity, $this->hasMultipleStatusEntities);
    }


    private function getButtonVariableName(array $entity): string
    {
        $name = $this->getSharedEntityName($entity);
        if ($name !== '') {
            return $name;
        }

        $caption = $this->getButtonDeviceClassCaption($this->getEntityDeviceClass($entity));
        if ($caption !== null) {
            return $this->Translate($caption);
        }

        $entityId = $this->getEntityId($entity);
        return $entityId !== '' ? $entityId : 'Press';
    }

    private function getButtonDeviceClassCaption(string $deviceClass): ?string
    {
        return match ($deviceClass) {
            'identify' => 'Identify',
            'restart' => 'Restart',
            'update' => 'Update',
            default => null,
        };
    }


    private function isStatusDomain(string $domain): bool
    {
        return HADomainCatalog::isStatusDomain($domain);
    }

    protected function getEventTypeVariableName(array $entity): string
    {
        return $this->formatSharedEntityNameWithSuffix($entity, 'Event Type');
    }

    private function getEntityId(array $entity): string
    {
        return trim((string)($entity['entity_id'] ?? ''));
    }

    private function getEntityAttributesArray(array $entity): array
    {
        $attributes = $entity['attributes'] ?? [];
        return is_array($attributes) ? $attributes : [];
    }

    private function getEntityDeviceClass(array $entity): string
    {
        $attributes = $this->getEntityAttributesArray($entity);
        return strtolower(trim((string)($attributes['device_class'] ?? '')));
    }

    protected function getEventTypePresentation(array $attributes): array
    {
        $eventTypes = $attributes[HAEventDefinitions::ATTRIBUTE_EVENT_TYPES] ?? null;
        if (!is_array($eventTypes) || $eventTypes === []) {
            return [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION
            ];
        }

        $options = [];
        foreach ($eventTypes as $eventType) {
            if (!is_scalar($eventType)) {
                continue;
            }
            $value = (string)$eventType;
            if (isset(HAEventDefinitions::EVENT_TYPE_TRANSLATION_KEYS[$value])) {
                $caption = $this->Translate(HAEventDefinitions::EVENT_TYPE_TRANSLATION_KEYS[$value]);
            } else {
                $caption = ucwords(str_replace('_', ' ', $value));
            }
            $options[] = [
                'Value'       => $value,
                'Caption'     => $caption,
                'IconActive'  => false,
                'IconValue'   => '',
                'ColorActive' => false,
                'ColorValue'  => -1
            ];
        }

        if ($options === []) {
            return [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION
            ];
        }

        return $this->filterPresentation([
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'OPTIONS'      => json_encode($options, JSON_THROW_ON_ERROR)
        ]);
    }

    private function isEntityBoundToDevice(array $entity): bool
    {
        return $this->isCurrentInstanceDeviceBoundToEntity($this->getEntityId($entity));
    }

    // Helper-Instanzen tragen ihre Entity-ID als DeviceID, echte Geräte eine HA-Geräte-ID.
    private function isCurrentInstanceDeviceBoundToEntity(string $entityId): bool
    {
        $deviceId = trim($this->ReadPropertyString(self::PROP_DEVICE_ID));
        if ($deviceId === '' || strtolower($deviceId) === 'none') {
            return false;
        }

        if ($entityId !== '' && strcasecmp($deviceId, $entityId) === 0) {
            return false;
        }

        return !str_contains($deviceId, '.');
    }

    private function stripCurrentInstanceNamePrefix(string $name): string
    {
        return $this->stripSharedCurrentInstanceNamePrefix($name);
    }

    protected function getClimateAttributePresentation(string $attribute, array $attributes): array
    {
        $meta = HAClimateDefinitions::ATTRIBUTE_DEFINITIONS[$attribute] ?? null;
        if ($meta === null) {
            return [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION
            ];
        }
        // Writability feature-bewusst bestimmen, damit Darstellung (Slider) und Aktion
        // konsistent sind. Sonst entsteht ein Schieberegler ohne Variablenaktion, was Symcon
        // als inkompatible Darstellung meldet (z. B. target_temperature_low ohne Feature-Bit 2).
        $isWritable     = $this->isWritableClimateAttribute($attribute, $attributes);
        $digitsOverride = $this->getMetaDigitsOverride($meta);

        if (in_array($attribute, [
            HAClimateDefinitions::ATTRIBUTE_TARGET_TEMPERATURE,
            HAClimateDefinitions::ATTRIBUTE_TARGET_TEMPERATURE_LOW,
            HAClimateDefinitions::ATTRIBUTE_TARGET_TEMPERATURE_HIGH,
            HAClimateDefinitions::ATTRIBUTE_CURRENT_TEMPERATURE
        ],           true)) {
            if ($isWritable) {
                $slider = $this->getClimateSliderPresentation($attributes);
                if ($slider !== null) {
                    if ($digitsOverride !== null) {
                        $slider['DIGITS'] = $digitsOverride;
                    }
                    return $slider;
                }
            }
            return $this->filterPresentation([
                                                 'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                                                 'USAGE_TYPE'   => 1,
                                                 'DIGITS'       => $digitsOverride ?? $this->getNumericDigits(
                                                         $attributes,
                                                         $attributes[HAClimateDefinitions::ATTRIBUTE_TARGET_TEMPERATURE_STEP] ??
                                                         $attributes['target_temp_step'] ?? null,
                                                         $attributes[HAClimateDefinitions::ATTRIBUTE_TARGET_TEMPERATURE] ??
                                                         $attributes[HAClimateDefinitions::ATTRIBUTE_CURRENT_TEMPERATURE] ??
                                                         $attributes['temperature'] ?? null
                                                     ),
                                                 'SUFFIX'       => $this->formatPresentationSuffix($this->getClimateTemperatureSuffix($attributes))
                                             ]);
        }

        if (in_array($attribute, [HAClimateDefinitions::ATTRIBUTE_CURRENT_HUMIDITY, HAClimateDefinitions::ATTRIBUTE_TARGET_HUMIDITY], true)) {
            if ($isWritable) {
                $min = $attributes[HAClimateDefinitions::ATTRIBUTE_MIN_HUMIDITY] ?? 0;
                $max = $attributes[HAClimateDefinitions::ATTRIBUTE_MAX_HUMIDITY] ?? 100;
                if (is_numeric($min) && is_numeric($max)) {
                    $step = $attributes['target_humidity_step'] ?? 1;
                    return $this->filterPresentation([
                                                         'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                                                         'MIN'          => (float)$min,
                                                         'MAX'          => (float)$max,
                                                         'STEP_SIZE'    => is_numeric($step) ? (float)$step : 1,
                                                         'DIGITS'       => $digitsOverride ?? $this->getNumericDigits($attributes, $step, $attributes[$attribute] ?? null),
                                                         'SUFFIX'       => $this->formatPresentationSuffix('%')
                                                     ]);
                }
            }
            return $this->filterPresentation([
                                                 'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                                                 'DIGITS'       => $digitsOverride ??
                                                                   $this->getNumericDigits($attributes, null, $attributes[$attribute] ?? null),
                                                 'SUFFIX'       => $this->formatPresentationSuffix('%')
                                             ]);
        }

        $optionAttribute = [
            HAClimateDefinitions::ATTRIBUTE_HVAC_MODE => HAClimateDefinitions::ATTRIBUTE_HVAC_MODES,
            HAClimateDefinitions::ATTRIBUTE_PRESET_MODE => HAClimateDefinitions::ATTRIBUTE_PRESET_MODES,
            HAClimateDefinitions::ATTRIBUTE_FAN_MODE => HAClimateDefinitions::ATTRIBUTE_FAN_MODES,
            HAClimateDefinitions::ATTRIBUTE_SWING_MODE => HAClimateDefinitions::ATTRIBUTE_SWING_MODES,
            HAClimateDefinitions::ATTRIBUTE_SWING_HORIZONTAL_MODE => HAClimateDefinitions::ATTRIBUTE_SWING_HORIZONTAL_MODES
        ][$attribute] ?? null;
        if (is_string($optionAttribute)) {
            $presentation = $this->getClimateAttributeOptionPresentation($attributes[$optionAttribute] ?? null, $isWritable);
            if ($presentation !== null) {
                return $presentation;
            }
        }
        if ($attribute === HAClimateDefinitions::ATTRIBUTE_HVAC_ACTION) {
            $optionsRaw = ['off', 'idle', 'heating', 'cooling', 'drying', 'fan', 'preheating', 'defrosting'];
            $current = $attributes[HAClimateDefinitions::ATTRIBUTE_HVAC_ACTION] ?? null;
            if (is_string($current) && trim($current) !== '') {
                $optionsRaw[] = trim($current);
            }
            // hvac_action ist immer read-only ($isWritable === false) -> Wertanzeige mit Optionen,
            // niemals Aufzaehlung (die braucht eine Variablenaktion).
            $presentation = $this->getClimateAttributeOptionPresentation($optionsRaw, $isWritable);
            if ($presentation !== null) {
                return $presentation;
            }
        }

        $suffix = $meta['suffix'] ?? '';
        return $this->filterPresentation([
                                             'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                                             'SUFFIX'       => $this->formatPresentationSuffix((string)$suffix)
        ]);
    }

    private function getClimateAttributeOptionPresentation(array|string|null $options, bool $writable = true): ?array
    {
        return $this->buildOptionPresentation($options, $writable, fn(string $value): string => $this->getClimateOptionCaption($value));
    }

    private function getLightBoundedSliderPresentation(
        mixed $min,
        mixed $max,
        bool $isPercent,
        ?int $digitsOverride,
        ?string $presentationSuffix
    ): ?array {
        return $this->buildSharedLightColorTempSliderPresentation(
            $min,
            $max,
            $isPercent,
            $digitsOverride,
            $presentationSuffix
        );
    }

    // Zentrale Optionsdarstellung fuer Auswahl-Attribute (alle Domains). Beschreibbare
    // (aktionsfaehige) Variablen erhalten eine Aufzaehlung, read-only Variablen eine Wertanzeige
    // mit Optionen. Andernfalls meldet Symcon "Diese Darstellung ist nur fuer Variablen mit einer
    // Variablenaktion verfuegbar", weil Praesentation und fehlende Aktion nicht zusammenpassen.
    // Die Optionsschemata unterscheiden sich: Aufzaehlung nutzt "Color", die Wertanzeige
    // "ColorActive"/"ColorValue". Der optionale captionResolver erlaubt domaenenspezifische
    // Beschriftungen (z. B. climate hvac_action -> "Heizen"/"Kuehlen").
    protected function buildOptionPresentation(array|string|null $options, bool $writable, ?callable $captionResolver = null): ?array
    {
        $normalized = HASelectDefinitions::normalizeOptions($options);
        if ($normalized === []) {
            return null;
        }

        $formatted = [];
        foreach ($normalized as $value) {
            $text = is_scalar($value) ? (string)$value : '';
            if ($text === '') {
                continue;
            }
            $caption = $captionResolver !== null ? (string)$captionResolver($text) : $text;
            $option = [
                'Value'      => $text,
                'Caption'    => $this->translate($caption),
                'IconActive' => false,
                'IconValue'  => ''
            ];
            if ($writable) {
                $option['Color'] = -1;
            } else {
                $option['ColorActive'] = false;
                $option['ColorValue'] = -1;
            }
            $formatted[] = $option;
        }

        if ($formatted === []) {
            return null;
        }

        return $this->filterPresentation([
            'PRESENTATION' => $writable ? VARIABLE_PRESENTATION_ENUMERATION : VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'OPTIONS'      => json_encode($formatted, JSON_THROW_ON_ERROR)
        ]);
    }

    private function getClimateOptionCaption(string $value): string
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return $value;
        }

        $map = [
            'heat' => 'Heating',
            'cool' => 'Cooling',
            'heat_cool' => 'Heat/Cool',
            'dry' => 'Dry (mode)',
            'fan_only' => 'Fan Only',
            'auto' => 'Auto',
            'off' => 'Off',
            'on' => 'On',
            'none' => 'None',
            'eco' => 'Eco',
            'boost' => 'Boost',
            'comfort' => 'Comfort',
            'away' => 'Away',
            'home' => 'Home',
            'sleep' => 'Sleep',
            'activity' => 'Activity',
            'idle' => 'Idle',
            'heating' => 'Heating',
            'cooling' => 'Cooling',
            'drying' => 'Drying',
            'fan' => 'Fan',
            'preheating' => 'Preheating',
            'defrosting' => 'Defrosting',
            'low' => 'Low',
            'low' . 'mid' => 'Low-Middle',
            'high' . 'mid' => 'Middle-High',
            'high' => 'High',
            'up' => 'Up',
            'down' => 'Down',
            'left' => 'Left',
            'right' => 'Right',
            'mid' => 'Middle',
            'middle' => 'Middle',
            'left' . 'mid' => 'Middle-Left',
            'right' . 'mid' => 'Middle-Right',
            'up' . 'mid' => 'Upper-Middle',
            'down' . 'mid' => 'Lower-Middle',
            'swing' => 'Swing'
        ];

        return $map[$normalized] ?? $value;
    }

    private function getClimateSliderPresentation(array $attributes): ?array
    {
        $min = $attributes[HAClimateDefinitions::ATTRIBUTE_MIN_TEMP] ?? null;
        $max = $attributes[HAClimateDefinitions::ATTRIBUTE_MAX_TEMP] ?? null;
        if (!is_numeric($min) || !is_numeric($max)) {
            return null;
        }
        $step   = $attributes[HAClimateDefinitions::ATTRIBUTE_TARGET_TEMPERATURE_STEP] ?? $attributes['target_temp_step'] ?? 1;
        $suffix = $this->getClimateTemperatureSuffix($attributes);
        $digits = $this->getNumericDigits(
            $attributes,
            $step,
            $attributes[HAClimateDefinitions::ATTRIBUTE_TARGET_TEMPERATURE] ??
            $attributes[HAClimateDefinitions::ATTRIBUTE_CURRENT_TEMPERATURE] ??
            $attributes['temperature'] ?? null
        );

        return $this->buildSharedTemperatureSliderPresentation(
            $min,
            $max,
            $step,
            $digits,
            $this->formatPresentationSuffix($suffix)
        );
    }

    private function getClimateTemperatureSuffix(array $attributes): string
    {
        $unit = $attributes[HAClimateDefinitions::ATTRIBUTE_TEMPERATURE_UNIT] ?? null;
        if (is_string($unit) && trim($unit) !== '') {
            return trim($unit);
        }
        $fallback = $this->getPresentationSuffix($attributes);
        return $fallback !== '' ? $fallback : '°C';
    }


    private function getNumericDigits(array $attributes, mixed $step = null, mixed $value = null): int
    {
        //        $this->debugExpert('getNumericDigits', 'Attribute', ['Attributes' => $attributes, 'Step' => $step, 'Value' => $value]);
        $digits = null;

        $stepValue = $step;
        if (is_string($stepValue)) {
            $stepValue = str_replace(',', '.', $stepValue);
        }
        if (is_numeric($stepValue)) {
            $digits = $this->getDigitsFromNumber((float)$stepValue);
        }
        //        $this->debugExpert('getNumericDigits1', 'Digits', ['Digits' => $digits]);

        if ($digits === null && isset($attributes['step']) && is_numeric($attributes['step'])) {
            $digits = $this->getDigitsFromNumber((float)$attributes['step']);
        }
        //        $this->debugExpert('getNumericDigits2', 'Digits', ['Digits' => $digits]);

        if ($digits === null && isset($attributes['native_step']) && is_numeric($attributes['native_step'])) {
            $digits = $this->getDigitsFromNumber((float)$attributes['native_step']);
        }
        //        $this->debugExpert('getNumericDigits3', 'Digits', ['Digits' => $digits]);

        if ($digits === null && isset($attributes['precision']) && is_numeric($attributes['precision'])) {
            $digits = $this->getDigitsFromNumber((float)$attributes['precision']);
        }
        //        $this->debugExpert('getNumericDigits4', 'Digits', ['Digits' => $digits]);

        if ($digits === null && isset($attributes['suggested_display_precision'])
            && is_numeric($attributes['suggested_display_precision'])) {
            $digits = (int)$attributes['suggested_display_precision'];
        }
        //        $this->debugExpert('getNumericDigits5', 'Digits', ['Digits' => $digits]);

        $valueValue = $value;
        if (is_string($valueValue)) {
            $valueValue = str_replace(',', '.', $valueValue);
        }
        if ($digits === null && is_numeric($valueValue)) {
            $digits = $this->getDigitsFromNumber((float)$valueValue);
        }
        //        $this->debugExpert('getNumericDigits6', 'Digits', ['Digits' => $digits]);

        if ($digits === null) {
            $digits = 0;
        }

        if ($digits === 0 && is_numeric($stepValue)) {
            $stepFloat = (float)$stepValue;
            if ($stepFloat > 0 && $stepFloat < 1) {
                $digits = 1;
            }
        }

        //        $this->debugExpert('getNumericDigits', 'Digits', ['Digits' => $digits]);
        return min(3, max(0, $digits));
    }

    private function getMetaDigitsOverride(array $meta): ?int
    {
        if (!array_key_exists('digits', $meta) || !is_numeric($meta['digits'])) {
            return null;
        }
        return min(3, max(0, (int)$meta['digits']));
    }

    private function getDigitsFromNumber(float $value): int
    {
        $string = sprintf('%.4f', $value);
        $string = rtrim($string, '0');
        $string = rtrim($string, '.');
        $pos    = strpos($string, '.');
        if ($pos === false) {
            return 0;
        }
        $digits = strlen($string) - $pos - 1;
        return max($digits, 0);
    }

    protected function getCoverAttributePresentation(array $meta): array
    {
        $suffix = $meta['suffix'] ?? '';
        if (!is_string($suffix)) {
            $suffix = '';
        }
        $suffix             = trim($suffix);
        $isPercent          = $suffix === '%';
        $presentationSuffix = $this->formatPresentationSuffix($suffix);

        return $this->filterPresentation([
                                             'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                                             'MIN'          => 0,
                                             'MAX'          => 100,
                                             'STEP_SIZE'    => 1,
                                             'PERCENTAGE'   => $isPercent,
                                             'DIGITS'       => 1,
                                             'SUFFIX'       => $presentationSuffix
                                         ]);
    }
}
