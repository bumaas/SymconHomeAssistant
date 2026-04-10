<?php

declare(strict_types=1);

trait HAPresentationTrait
{

    private function getEntityPresentation(string $domain, array $entity, int $type): array
    {
        $domain = HADomainCatalog::normalizeDomainAlias($domain);
        $this->debugExpert(__FUNCTION__, 'Input', ['Domain' => $domain, 'Type' => $type, 'Entity' => $entity], false);
        $attributes = $entity['attributes'] ?? [];
        if (!is_array($attributes)) {
            $attributes = [];
        }

        $presentation = $this->getDomainEntityPresentation($domain, $entity, $attributes, $type);
        if ($presentation !== null) {
            return $presentation;
        }

        $presentation = $this->getTypeFallbackEntityPresentation($domain, $attributes, $type);
        if ($presentation !== null) {
            return $presentation;
        }

        return $this->getDefaultEntityValuePresentation($attributes, $type);
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
            HAImageDefinitions::DOMAIN => $this->getDateTimeValuePresentation(2),
            HALockDefinitions::DOMAIN => $this->getLockPresentation($attributes),
            HAVacuumDefinitions::DOMAIN => $this->getVacuumPresentation(),
            HALawnMowerDefinitions::DOMAIN => $this->getLawnMowerPresentation(),
            HAFanDefinitions::DOMAIN => $this->getFanPresentation(),
            HAHumidifierDefinitions::DOMAIN => $this->getHumidifierPresentation(),
            HAButtonDefinitions::DOMAIN => $this->getButtonPresentation($entity),
            HAMediaPlayerDefinitions::DOMAIN => $this->getMediaPlayerPresentation(),
            HACoverDefinitions::DOMAIN => $this->getCoverPresentation($attributes),
            HAEventDefinitions::DOMAIN => $this->getEventPresentation($attributes),
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

    private function getStaticPresentation(int $presentation): array
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
            'Caption'    => $this->Translate((string)$caption),
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

    private function getMediaPlayerAttributePresentation(string $attribute, array $attributes, array $meta): array
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
            $options = $this->getPresentationOptions(
                HASelectDefinitions::normalizeOptions($attributes['source_list'] ?? null)
            );
            if ($options !== null) {
                return $this->filterPresentation([
                                                     'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                                                     'OPTIONS'      => $options
                                                 ]);
            }
        }

        if ($attribute === 'sound_mode') {
            $options = $this->getPresentationOptions(
                HASelectDefinitions::normalizeOptions($attributes['sound_mode_list'] ?? null)
            );
            if ($options !== null) {
                return $this->filterPresentation([
                                                     'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                                                     'OPTIONS'      => $options
                                                 ]);
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

    private function getFanAttributePresentation(string $attribute, array $attributes, array $meta): array
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
            $options = $this->getPresentationOptions(
                HASelectDefinitions::normalizeOptions($attributes['preset_modes'] ?? null)
            );
            if ($options !== null) {
                return $this->filterPresentation([
                                                     'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                                                     'OPTIONS'      => $options
                                                 ]);
            }
        }

        if ($attribute === 'direction' || $attribute === 'current_direction') {
            $options = $this->getPresentationOptions(
                HASelectDefinitions::normalizeOptions($attributes['direction_list'] ?? null)
            );
            if ($options !== null) {
                return $this->filterPresentation([
                                                     'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                                                     'OPTIONS'      => $options
                                                 ]);
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

    private function getHumidifierAttributePresentation(string $attribute, array $attributes, array $meta): array
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
            $options = $this->getPresentationOptions(
                HASelectDefinitions::normalizeOptions($attributes['available_modes'] ?? null)
            );
            if ($options !== null) {
                return $this->filterPresentation([
                                                     'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                                                     'OPTIONS'      => $options
                                                 ]);
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

        $options = [
            [
                'Value'       => false,
                'Caption'     => $falseCaption,
                'IconActive'  => false,
                'IconValue'   => '',
                'ColorActive' => false,
                'ColorValue'  => -1
            ],
            [
                'Value'       => true,
                'Caption'     => $trueCaption,
                'IconActive'  => false,
                'IconValue'   => '',
                'ColorActive' => false,
                'ColorValue'  => -1
            ]
        ];

        return $this->filterPresentation([
                                             'PRESENTATION' => HABinarySensorDefinitions::PRESENTATION,
                                             'OPTIONS'      => json_encode($options, JSON_THROW_ON_ERROR),
                                             'ICON'         => $icon !== '' ? $icon : null
                                         ]);
    }

    private function getCoverPresentation(array $attributes): array
    {
        $deviceClass = $attributes['device_class'] ?? '';
        if (!is_string($deviceClass)) {
            $deviceClass = '';
        }
        $deviceClass = trim($deviceClass);

        $hasPosition = $this->extractCoverPosition($attributes) !== null || $this->isCoverPositionSupported($attributes);
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

    private function isCoverPositionSupported(array $attributes): bool
    {
        $supported = (int)($attributes[self::KEY_SUPPORTED_FEATURES] ?? 0);
        return ($supported & HACoverDefinitions::FEATURE_SET_POSITION) === HACoverDefinitions::FEATURE_SET_POSITION;
    }

    private function getClimatePresentation(array $attributes): array
    {
        $supported          = (int)($attributes[HAClimateDefinitions::ATTRIBUTE_SUPPORTED_FEATURES] ?? 0);
        $supportsTarget     = ($supported & 1) === 1;
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

    private function getEventPresentation(array $attributes): array
    {
        return $this->getDateTimeValuePresentation(2);
    }

    private function getLightAttributePresentation(string $attribute, array $attributes, array $meta): array
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
            return $this->filterPresentation([
                                                 'PRESENTATION' => VARIABLE_PRESENTATION_COLOR,
                                                 'ENCODING'     => 0 // RGB
                                             ]);
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
            return $this->filterPresentation([
                                                 'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                                                 'MIN'          => 0,
                                                 'MAX'          => 255,
                                                 'STEP_SIZE'    => 1,
                                                 'PERCENTAGE'   => $isPercent,
                                                 'DIGITS'       => $digitsOverride ?? 0,
                                                 'SUFFIX'       => $presentationSuffix
                                             ]);
        }
        if ($attribute === 'color_temp') {
            $min = $attributes['min_mireds'] ?? null;
            $max = $attributes['max_mireds'] ?? null;
            if (is_numeric($min) && is_numeric($max)) {
                return $this->filterPresentation([
                                                     'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                                                     'MIN'          => (float)$min,
                                                     'MAX'          => (float)$max,
                                                     'STEP_SIZE'    => 1,
                                                     'PERCENTAGE'   => $isPercent,
                                                     'DIGITS'       => $digitsOverride ?? 0,
                                                     'SUFFIX'       => $presentationSuffix
                                                 ]);
            }
        }
        if ($attribute === 'color_temp_kelvin') {
            $min = $attributes['min_color_temp_kelvin'] ?? null;
            $max = $attributes['max_color_temp_kelvin'] ?? null;
            if (is_numeric($min) && is_numeric($max)) {
                return $this->filterPresentation([
                                                     'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                                                     'MIN'          => (float)$min,
                                                     'MAX'          => (float)$max,
                                                     'STEP_SIZE'    => 1,
                                                     'PERCENTAGE'   => $isPercent,
                                                     'DIGITS'       => $digitsOverride ?? 0,
                                                     'SUFFIX'       => $presentationSuffix
                                                 ]);
            }
        }
        if ($attribute === 'effect') {
            $options = $this->getPresentationOptions($attributes['effect_list'] ?? null);
            if ($options !== null) {
                return $this->filterPresentation([
                                                     'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                                                     'OPTIONS'      => $options
                                                 ]);
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
        if ($this->isIntensitySliderRange((float)$min, (float)$max) && trim($suffix) === '' && $digits === 0) {
            $usageType = 2; // Intensitaet
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
            'onoff'      => 'On/Off',
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

    private function getEntityVariableName(string $domain, array $entity): string
    {
        $domain = HADomainCatalog::normalizeDomainAlias($domain);
        if ($domain === HAClimateDefinitions::DOMAIN) {
            $attributes = $entity['attributes'] ?? [];
            if (is_array($attributes)) {
                $supported        = (int)($attributes[HAClimateDefinitions::ATTRIBUTE_SUPPORTED_FEATURES] ?? 0);
                $hasTargetFeature = ($supported & 1) === 1;
                if ($hasTargetFeature) {
                    return $this->Translate('Target Temperature');
                }
                if (array_key_exists(HAClimateDefinitions::ATTRIBUTE_TARGET_TEMPERATURE, $attributes)) {
                    return $this->Translate('Target Temperature');
                }
                if (array_key_exists(HAClimateDefinitions::ATTRIBUTE_CURRENT_TEMPERATURE, $attributes)) {
                    return $this->Translate('Current Temperature');
                }
            }
        }
        if ($domain === HAImageDefinitions::DOMAIN) {
            if ($this->isEntityBoundToDevice($entity)) {
                $name = trim((string)($entity['name'] ?? ''));
                if ($name !== '') {
                    return $name . ' (' . $this->Translate('Last Update') . ')';
                }
            }
            return $this->Translate('Last Update');
        }
        if ($domain === HACoverDefinitions::DOMAIN) {
            return $this->getCoverVariableName($entity);
        }
        if ($domain === HAButtonDefinitions::DOMAIN) {
            return $this->getButtonVariableName($entity);
        }
        if ($domain === HAEventDefinitions::DOMAIN) {
            return $this->getEventStateVariableName($entity);
        }
        if ($this->isStatusDomain($domain)) {
            if (!$this->hasMultipleStatusEntities) {
                return $this->Translate('Status');
            }
            $domainLabel = strtoupper($domain);
            return $this->Translate('Status') . ' (' . $domainLabel . ')';
        }
        return $this->getDefaultEntityVariableName($domain, $entity);
    }

    private function getButtonVariableName(array $entity): string
    {
        $name = trim((string)($entity['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        $attributes = $entity['attributes'] ?? [];
        if (is_array($attributes)) {
            $deviceClass = strtolower(trim((string)($attributes['device_class'] ?? '')));
            $caption = match ($deviceClass) {
                'identify' => 'Identify',
                'restart' => 'Restart',
                'update' => 'Update',
                default => '',
            };
            if ($caption !== '') {
                return $this->Translate($caption);
            }
        }

        return $entity['entity_id'] ?? 'Press';
    }

    private function getDefaultEntityVariableName(string $domain, array $entity): string
    {
        $domain = HADomainCatalog::normalizeDomainAlias($domain);
        $name = trim((string)($entity['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        if (HADomainCatalog::supportsDeviceClassNameFallback($domain)) {
            $fallback = $this->getDeviceClassFallbackName($entity);
            if ($fallback !== null) {
                return $fallback;
            }
        }

        return $entity['entity_id'] ?? '';
    }

    // HA derives unnamed entities of several domains from the device_class.
    private function getDeviceClassFallbackName(array $entity): ?string
    {
        $attributes = $entity['attributes'] ?? [];
        if (!is_array($attributes)) {
            return null;
        }

        $deviceClass = strtolower(trim((string)($attributes['device_class'] ?? '')));
        if ($deviceClass === '') {
            return null;
        }

        $specialCaptions = [
            'co' => 'CO',
            'co2' => 'CO2',
            'pm1' => 'PM1',
            'pm10' => 'PM10',
            'pm25' => 'PM2.5',
            'aqi' => 'AQI',
            'uv_index' => 'UV Index',
        ];
        if (isset($specialCaptions[$deviceClass])) {
            return $this->Translate($specialCaptions[$deviceClass]);
        }

        $caption = str_replace('_', ' ', $deviceClass);
        $caption = ucwords($caption);
        return $this->Translate($caption);
    }

    private function getCoverVariableName(array $entity): string
    {
        $attributes = $entity['attributes'] ?? [];
        if (!is_array($attributes)) {
            $attributes = [];
        }

        $deviceClass = strtolower(trim((string)($attributes['device_class'] ?? '')));
        return match ($deviceClass) {
            HACoverDefinitions::DEVICE_CLASS_GARAGE,
            HACoverDefinitions::DEVICE_CLASS_GATE,
            HACoverDefinitions::DEVICE_CLASS_DOOR,
            HACoverDefinitions::DEVICE_CLASS_WINDOW => $this->Translate('Opening'),
            HACoverDefinitions::DEVICE_CLASS_DAMPER => $this->Translate('Positioning'),
            default => $this->Translate('Position'),
        };
    }

    private function isStatusDomain(string $domain): bool
    {
        return HADomainCatalog::isStatusDomain($domain);
    }

    private function getEventStateVariableName(array $entity): string
    {
        $baseName = trim((string)($entity['name'] ?? ''));
        if ($baseName === '') {
            return $this->Translate('Last Event');
        }

        return $baseName . ' (' . $this->Translate('Last Event') . ')';
    }

    private function getEventTypeVariableName(array $entity): string
    {
        $baseName = trim((string)($entity['name'] ?? ''));
        if ($baseName === '') {
            return $this->Translate('Event Type');
        }

        return $baseName . ' (' . $this->Translate('Event Type') . ')';
    }

    private function getEventTypePresentation(array $attributes): array
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
            $captionKey = HAEventDefinitions::EVENT_TYPE_TRANSLATION_KEYS[$value] ?? $value;
            $options[] = [
                'Value'       => $value,
                'Caption'     => $this->Translate($captionKey),
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
        $deviceId = trim((string)($entity['device_id'] ?? ''));
        return $deviceId !== '' && strtolower($deviceId) !== 'none';
    }

    private function getClimateAttributePresentation(string $attribute, array $attributes): array
    {
        $meta = HAClimateDefinitions::ATTRIBUTE_DEFINITIONS[$attribute] ?? null;
        if ($meta === null) {
            return [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION
            ];
        }
        $isWritable     = (bool)($meta['writable'] ?? false);
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

        if ($attribute === HAClimateDefinitions::ATTRIBUTE_HVAC_MODE) {
            $options = $this->getClimatePresentationOptions($attributes[HAClimateDefinitions::ATTRIBUTE_HVAC_MODES] ?? null);
            if ($options !== null) {
                return $this->filterPresentation([
                                                     'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                                                     'OPTIONS'      => $options
                                                 ]);
            }
        }
        if ($attribute === HAClimateDefinitions::ATTRIBUTE_PRESET_MODE) {
            $options = $this->getClimatePresentationOptions($attributes[HAClimateDefinitions::ATTRIBUTE_PRESET_MODES] ?? null);
            if ($options !== null) {
                return $this->filterPresentation([
                                                     'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                                                     'OPTIONS'      => $options
                                                 ]);
            }
        }
        if ($attribute === HAClimateDefinitions::ATTRIBUTE_FAN_MODE) {
            $options = $this->getClimatePresentationOptions($attributes[HAClimateDefinitions::ATTRIBUTE_FAN_MODES] ?? null);
            if ($options !== null) {
                return $this->filterPresentation([
                                                     'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                                                     'OPTIONS'      => $options
                                                 ]);
            }
        }
        if ($attribute === HAClimateDefinitions::ATTRIBUTE_SWING_MODE) {
            $options = $this->getClimatePresentationOptions($attributes[HAClimateDefinitions::ATTRIBUTE_SWING_MODES] ?? null);
            if ($options !== null) {
                return $this->filterPresentation([
                                                     'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                                                     'OPTIONS'      => $options
                                                 ]);
            }
        }
        if ($attribute === HAClimateDefinitions::ATTRIBUTE_SWING_HORIZONTAL_MODE) {
            $options = $this->getClimatePresentationOptions($attributes[HAClimateDefinitions::ATTRIBUTE_SWING_HORIZONTAL_MODES] ?? null);
            if ($options !== null) {
                return $this->filterPresentation([
                                                     'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                                                     'OPTIONS'      => $options
                ]);
            }
        }
        if ($attribute === HAClimateDefinitions::ATTRIBUTE_HVAC_ACTION) {
            $optionsRaw = ['off', 'idle', 'heating', 'cooling', 'drying', 'fan', 'preheating', 'defrosting'];
            $current = $attributes[HAClimateDefinitions::ATTRIBUTE_HVAC_ACTION] ?? null;
            if (is_string($current) && trim($current) !== '') {
                $optionsRaw[] = trim($current);
            }
            $options = $this->getClimatePresentationOptions($optionsRaw);
            if ($options !== null) {
                return $this->filterPresentation([
                                                     'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                                                     'OPTIONS'      => $options
                                                 ]);
            }
        }

        $suffix = $meta['suffix'] ?? '';
        return $this->filterPresentation([
                                             'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                                             'SUFFIX'       => $this->formatPresentationSuffix((string)$suffix)
        ]);
    }

    private function getClimatePresentationOptions(array|string|null $options): ?string
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
            $formatted[] = [
                'Value'       => $text,
                'Caption'     => $this->translate($this->getClimateOptionCaption($text)),
                'IconActive'  => false,
                'IconValue'   => '',
                'Color'       => -1
            ];
        }

        if ($formatted === []) {
            return null;
        }
        return json_encode($formatted, JSON_THROW_ON_ERROR);
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
            'dry' => 'Dry',
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
            'lowmid' => 'Low-Middle',
            'highmid' => 'Middle-High',
            'high' => 'High',
            'up' => 'Up',
            'down' => 'Down',
            'left' => 'Left',
            'right' => 'Right',
            'mid' => 'Middle',
            'middle' => 'Middle',
            'leftmid' => 'Middle-Left',
            'rightmid' => 'Middle-Right',
            'upmid' => 'Upper-Middle',
            'downmid' => 'Lower-Middle',
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

        return $this->filterPresentation([
                                             'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                                             'MIN'          => (float)$min,
                                             'MAX'          => (float)$max,
                                             'STEP_SIZE'    => (float)$step,
                                             'DIGITS'       => $this->getNumericDigits(
                                                 $attributes,
                                                 $step,
                                                 $attributes[HAClimateDefinitions::ATTRIBUTE_TARGET_TEMPERATURE] ??
                                                 $attributes[HAClimateDefinitions::ATTRIBUTE_CURRENT_TEMPERATURE] ??
                                                 $attributes['temperature'] ?? null
                                             ),
                                             'USAGE_TYPE'   => 1,
                                             'SUFFIX'       => $this->formatPresentationSuffix($suffix)
                                         ]);
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
        $string = rtrim(rtrim(sprintf('%.4f', $value), '0'), '.');
        $pos    = strpos($string, '.');
        if ($pos === false) {
            return 0;
        }
        $digits = strlen($string) - $pos - 1;
        return max($digits, 0);
    }

    private function getCoverAttributePresentation(array $meta): array
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
