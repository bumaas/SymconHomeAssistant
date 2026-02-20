<?php

declare(strict_types=1);

trait HAPresentationTrait
{

    private function getEntityPresentation(string $domain, array $entity, int $type): array
    {
        $this->debugExpert(__FUNCTION__, 'Input', ['Domain' => $domain, 'Type' => $type, 'Entity' => $entity], false);
        $attributes = $entity['attributes'] ?? [];
        if (!is_array($attributes)) {
            $attributes = [];
        }

        if ($domain === HABinarySensorDefinitions::DOMAIN) {
            return $this->getBinarySensorPresentation($attributes);
        }

        if ($domain === HALightDefinitions::DOMAIN) {
            return [
                'PRESENTATION' => HALightDefinitions::PRESENTATION
            ];
        }

        if ($domain === HASwitchDefinitions::DOMAIN) {
            return [
                'PRESENTATION' => HASwitchDefinitions::PRESENTATION
            ];
        }

        if ($domain === HANumberDefinitions::DOMAIN) {
            return $this->getNumberPresentation($attributes);
        }

        if ($domain === HAClimateDefinitions::DOMAIN) {
            return $this->getClimatePresentation($attributes);
        }

        if ($domain === HASensorDefinitions::DOMAIN) {
            $deviceClass = $attributes['device_class'] ?? '';
            if (is_string($deviceClass) && trim($deviceClass) === HASensorDefinitions::DEVICE_CLASS_ENUM) {
                $options = $this->getPresentationOptions($attributes['options'] ?? null);
                if ($options !== null) {
                    return $this->filterPresentation([
                                                         'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                                                     'OPTIONS'      => $options
                                                 ]);
                }
            }
            if (is_string($deviceClass) && trim($deviceClass) === HASensorDefinitions::DEVICE_CLASS_TIMESTAMP) {
                return $this->filterPresentation([
                                                     'PRESENTATION'   => VARIABLE_PRESENTATION_DATE_TIME,
                                                     'DATE'           => 2,
                                                     'DAY_OF_THE_WEEK' => false,
                                                     'MONTH_TEXT'     => false,
                                                     'TIME'           => 1
                                                 ]);
            }
            if (is_string($deviceClass) && trim($deviceClass) === HASensorDefinitions::DEVICE_CLASS_DURATION) {
                return $this->filterPresentation([
                                                     'PRESENTATION'   => VARIABLE_PRESENTATION_DURATION,
                                                     'FORMAT'         => 3
                                                 ]);
            }
        }

        if ($domain === HALockDefinitions::DOMAIN) {
            return $this->getLockPresentation($attributes);
        }

        if ($domain === HAVacuumDefinitions::DOMAIN) {
            return $this->getVacuumPresentation();
        }

        if ($domain === HAFanDefinitions::DOMAIN) {
            return $this->getFanPresentation();
        }

        if ($domain === HAHumidifierDefinitions::DOMAIN) {
            return $this->getHumidifierPresentation();
        }

        if ($domain === HAButtonDefinitions::DOMAIN) {
            return $this->getButtonPresentation($entity);
        }

        if ($domain === HAMediaPlayerDefinitions::DOMAIN) {
            return $this->getMediaPlayerPresentation();
        }

        if ($domain === HACoverDefinitions::DOMAIN) {
            return $this->getCoverPresentation($attributes);
        }

        if ($domain === HAEventDefinitions::DOMAIN) {
            return $this->getEventPresentation($attributes);
        }

        if ($type === VARIABLETYPE_BOOLEAN) {
            return [
                'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH
            ];
        }

        $suffix = $this->getPresentationSuffix($attributes);
        if ($domain === HASelectDefinitions::DOMAIN) {
            $options = HASelectDefinitions::normalizeOptions($attributes['options'] ?? null);
            if ($options !== []) {
                return $this->filterPresentation([
                                                     'PRESENTATION' => HASelectDefinitions::PRESENTATION,
                                                     'OPTIONS'      => $this->getPresentationOptions($options)
                                                 ]);
            }
        }

        if ($type === VARIABLETYPE_INTEGER || $type === VARIABLETYPE_FLOAT) {
            if ($this->isWriteable($domain)) {
                $slider = $this->getNumericSliderPresentation($attributes);
                if ($slider !== null) {
                    return $slider;
                }
            }
        }

        return $this->filterPresentation([
                                             'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                                             'DIGITS'       => ($type === VARIABLETYPE_INTEGER || $type === VARIABLETYPE_FLOAT)
                                                 ? $this->getNumericDigits($attributes) : null,
                                             'SUFFIX'       => $this->formatPresentationSuffix($suffix)
                                         ]);
    }

    private function getVacuumPresentation(): array
    {
        $options = [];
        foreach (HAVacuumDefinitions::STATE_OPTIONS as $value => $meta) {
            $caption   = (string)($meta['caption'] ?? $value);
            $icon      = (string)($meta['icon'] ?? '');
            $options[] = [
                'Value'       => $value,
                'Caption'     => $this->Translate($caption),
                'IconActive'  => $icon !== '',
                'IconValue'   => $icon,
                'ColorActive' => false,
                'ColorValue'  => -1
            ];
        }

        return $this->filterPresentation([
                                             'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                                             'OPTIONS'      => json_encode($options, JSON_THROW_ON_ERROR)
                                         ]);
    }

    private function getButtonPresentation(array $entity): array
    {
        $caption = $entity['name'] ?? $entity['entity_id'] ?? 'Press';
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
                is_array($attributes['source_list'] ?? null) ? $attributes['source_list'] : null
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
                is_array($attributes['sound_mode_list'] ?? null) ? $attributes['sound_mode_list'] : null
            );
            if ($options !== null) {
                return $this->filterPresentation([
                                                     'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                                                     'OPTIONS'      => $options
                                                 ]);
            }
        }

        if ($attribute === 'shuffle' || $attribute === 'is_volume_muted' || $attribute === 'cross_fade') {
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
                is_array($attributes['preset_modes'] ?? null) ? $attributes['preset_modes'] : null
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
                is_array($attributes['direction_list'] ?? null) ? $attributes['direction_list'] : null
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
        if ($attribute === 'target_humidity') {
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

        if ($attribute === 'current_humidity') {
            return $this->filterPresentation([
                                                 'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                                                 'DIGITS'       => 0,
                                                 'SUFFIX'       => $this->formatPresentationSuffix('%')
                                             ]);
        }

        if ($attribute === 'mode') {
            $options = $this->getPresentationOptions(
                is_array($attributes['available_modes'] ?? null) ? $attributes['available_modes'] : null
            );
            if ($options !== null) {
                return $this->filterPresentation([
                                                     'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                                                     'OPTIONS'      => $options
                                                 ]);
            }
        }

        if ($attribute === 'action') {
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

        $hasPosition = $this->extractCoverPosition($attributes) !== null;
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
        $eventTypes = $attributes[HAEventDefinitions::ATTRIBUTE_EVENT_TYPES] ?? null;
        if (!is_array($eventTypes)) {
            $eventTypes = [];
        }

        $options = [];
        foreach ($eventTypes as $eventType) {
            if (!is_scalar($eventType)) {
                continue;
            }
            $eventType  = (string)$eventType;
            $captionKey = HAEventDefinitions::EVENT_TYPE_TRANSLATION_KEYS[$eventType] ?? $eventType;
            $options[]  = [
                'Value'               => $eventType,
                'Caption'             => $this->Translate($captionKey),
                'IconActive'          => false,
                'IconValue'           => '',
                'ColorActive'         => false,
                'ColorValue'          => -1,
                'ContentColorActive'  => false,
                'ContentColorValue'   => -1,
                'ColorDisplay'        => -1,
                'ContentColorDisplay' => -1
            ];
        }

        return $this->filterPresentation([
                                             'PRESENTATION' => HAEventDefinitions::PRESENTATION,
                                             'OPTIONS'      => json_encode($options, JSON_THROW_ON_ERROR)
                                         ]);
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

        return $this->filterPresentation([
                                             'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                                             'MIN'          => (float)$min,
                                             'MAX'          => (float)$max,
                                             'STEP_SIZE'    => (float)$step,
                                             'DIGITS'       => $this->getNumericDigits($attributes, $step),
                                             'SUFFIX'       => $presentationSuffix
                                         ]);
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

    private function getPresentationOptions(?array $options): ?string
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

    private function getEntityVariableName(string $domain, array $entity): string
    {
        if ($domain === HALockDefinitions::DOMAIN) {
            return $this->Translate('Lock');
        }
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
        if ($this->isStatusDomain($domain)) {
            if (!$this->hasMultipleStatusEntities) {
                return $this->Translate('Status');
            }
            $domainLabel = strtoupper($domain);
            return $this->Translate('Status') . ' (' . $domainLabel . ')';
        }
        return $entity['name'] ?? $entity['entity_id'];
    }

    private function isStatusDomain(string $domain): bool
    {
        return in_array($domain, [
            HAMediaPlayerDefinitions::DOMAIN,
            HAVacuumDefinitions::DOMAIN,
            HAFanDefinitions::DOMAIN,
            HAHumidifierDefinitions::DOMAIN
        ], true);
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
                    return $this->filterPresentation([
                                                         'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                                                         'MIN'          => (float)$min,
                                                         'MAX'          => (float)$max,
                                                         'STEP_SIZE'    => 1,
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
            $options = $this->getPresentationOptions($attributes[HAClimateDefinitions::ATTRIBUTE_HVAC_MODES] ?? null);
            if ($options !== null) {
                return $this->filterPresentation([
                                                     'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                                                     'OPTIONS'      => $options
                                                 ]);
            }
        }
        if ($attribute === HAClimateDefinitions::ATTRIBUTE_PRESET_MODE) {
            $options = $this->getPresentationOptions($attributes[HAClimateDefinitions::ATTRIBUTE_PRESET_MODES] ?? null);
            if ($options !== null) {
                return $this->filterPresentation([
                                                     'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                                                     'OPTIONS'      => $options
                                                 ]);
            }
        }
        if ($attribute === HAClimateDefinitions::ATTRIBUTE_FAN_MODE) {
            $options = $this->getPresentationOptions($attributes[HAClimateDefinitions::ATTRIBUTE_FAN_MODES] ?? null);
            if ($options !== null) {
                return $this->filterPresentation([
                                                     'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                                                     'OPTIONS'      => $options
                                                 ]);
            }
        }
        if ($attribute === HAClimateDefinitions::ATTRIBUTE_SWING_MODE) {
            $options = $this->getPresentationOptions($attributes[HAClimateDefinitions::ATTRIBUTE_SWING_MODES] ?? null);
            if ($options !== null) {
                return $this->filterPresentation([
                                                     'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                                                     'OPTIONS'      => $options
                                                 ]);
            }
        }
        if ($attribute === HAClimateDefinitions::ATTRIBUTE_SWING_HORIZONTAL_MODE) {
            $options = $this->getPresentationOptions($attributes[HAClimateDefinitions::ATTRIBUTE_SWING_HORIZONTAL_MODES] ?? null);
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
