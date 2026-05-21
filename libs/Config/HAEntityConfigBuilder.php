<?php

declare(strict_types=1);

trait HAEntityConfigBuilderTrait
{
    private const array STABLE_GENERIC_ATTRIBUTE_KEYS = [
        'device_class',
        'supported_features',
        'supported_features_list',
        'options',
        'unit_of_measurement',
        'native_unit_of_measurement',
        'display_unit',
        'unit',
        'min',
        'max',
        'step',
        'native_min_value',
        'native_max_value',
        'native_step',
        'state_class',
        'icon',
        'entity_picture',
        'entity_picture_local',
        'url'
    ];

    private function buildResolvedEntityConfig(array $rawEntities, bool $autoCreateVariables = true, bool $allowUnsupportedDomains = false): array
    {
        $resolved = [];
        foreach ($rawEntities as $rawEntity) {
            $row = $this->buildResolvedEntityRow($rawEntity, $autoCreateVariables, $allowUnsupportedDomains);
            if ($row === null) {
                continue;
            }

            $resolved[] = $row;
        }

        return $resolved;
    }

    private function buildResolvedEntityRow(array $rawEntity, bool $autoCreateVariables = true, bool $allowUnsupportedDomains = false): ?array
    {
        $entityId = (string)($rawEntity['entity_id'] ?? '');
        if ($entityId === '') {
            return null;
        }

        $domain = trim((string)($rawEntity['domain'] ?? $this->deriveDomainFromEntityId($entityId)));

        if (!$allowUnsupportedDomains && !HADomainCatalog::isDomainSupported($domain)) {
            return null;
        }

        $attributes = $rawEntity['attributes'] ?? [];
        if (!is_array($attributes)) {
            $attributes = [];
        }

        if (!isset($attributes['supported_features']) || !is_numeric($attributes['supported_features'])) {
            if (isset($rawEntity['supported_features']) && is_numeric($rawEntity['supported_features'])) {
                $attributes['supported_features'] = (int)$rawEntity['supported_features'];
            }
        }

        $resolved = [
            'entity_id' => $entityId,
            'domain' => $domain,
            'name' => (string)($rawEntity['name'] ?? $entityId),
            'attributes' => $attributes,
            'device_name' => (string)($rawEntity['device_name'] ?? ''),
            'device_manufacturer' => (string)($rawEntity['device_manufacturer'] ?? ''),
            'device_model' => (string)($rawEntity['device_model'] ?? ''),
            'device_id' => (string)($rawEntity['device_id'] ?? HAConfigDefaults::DEVICE_NONE),
            'area' => (string)($rawEntity['area'] ?? HAConfigDefaults::AREA_NONE),
            'create_var' => $autoCreateVariables
        ];

        if (($resolved['device_name'] === '' || $resolved['device_name'] === HAConfigDefaults::NAME_UNKNOWN) || $resolved['device_id'] === HAConfigDefaults::DEVICE_NONE) {
            $resolved['device'] = ucfirst($domain) . ' (' . HAConfigDefaults::DEVICE_WITHOUT_DEVICE_SUFFIX . ')';
        } else {
            $resolved['device'] = $resolved['device_name'];
        }

        return $this->normalizeResolvedEntityRow($resolved);
    }

    private function normalizeResolvedEntityRow(array $entity): ?array
    {
        return $this->normalizeEntityStructure($entity);
    }

    private function buildStableCreateConfig(array $entitiesForConfig): array
    {
        $stableConfig = [];

        foreach ($entitiesForConfig as $entity) {
            if (!is_array($entity)) {
                continue;
            }

            $normalizedEntity = $this->normalizeEntityStructure($entity);
            if ($normalizedEntity === null) {
                continue;
            }
            $entity = $normalizedEntity;

            $stableEntity = [
                'entity_id' => (string)($entity['entity_id'] ?? ''),
                'domain' => (string)($entity['domain'] ?? ''),
                'name' => (string)($entity['name'] ?? ''),
                'device_class' => isset($entity['device_class']) ? trim((string)$entity['device_class']) : '',
                'create_var' => (bool)($entity['create_var'] ?? true)
            ];

            $attributes = $entity['attributes'] ?? [];
            if (is_string($attributes)) {
                try {
                    $attributes = json_decode($attributes, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    $attributes = [];
                }
            }
            if (!is_array($attributes)) {
                $attributes = [];
            }

            $stableAttributes = $this->filterStableEntityAttributes(
                (string)($stableEntity['domain'] ?: $this->deriveDomainFromEntityId($stableEntity['entity_id'])),
                $attributes
            );
            if ($stableAttributes !== []) {
                $stableEntity['attributes'] = $stableAttributes;
            }

            if ($stableEntity['device_class'] === '' && isset($stableAttributes['device_class']) && is_string($stableAttributes['device_class'])) {
                $stableEntity['device_class'] = trim($stableAttributes['device_class']);
            }
            if ($stableEntity['device_class'] === '') {
                unset($stableEntity['device_class']);
            }

            $stableConfig[] = $stableEntity;
        }

        usort($stableConfig, static function (array $left, array $right): int {
            return strcmp((string)($left['entity_id'] ?? ''), (string)($right['entity_id'] ?? ''));
        });

        return $stableConfig;
    }

    private function filterStableEntityAttributes(string $domain, array $attributes): array
    {
        $allowed = array_fill_keys(self::STABLE_GENERIC_ATTRIBUTE_KEYS, true);
        foreach ($this->getStableEntityAttributeKeys($domain) as $key) {
            $allowed[$key] = true;
        }

        $filtered = [];
        foreach ($attributes as $key => $value) {
            if (!is_string($key) || !isset($allowed[$key])) {
                continue;
            }
            if ($this->isEmptyStableAttributeValue($value)) {
                continue;
            }
            $filtered[$key] = $value;
        }

        ksort($filtered);
        return $filtered;
    }

    private function getStableEntityAttributeKeys(string $domain): array
    {
        return match ($domain) {
            HALightDefinitions::DOMAIN => ['effect_list', 'supported_color_modes', 'min_mireds', 'max_mireds', 'min_color_temp_kelvin', 'max_color_temp_kelvin'],
            HAClimateDefinitions::DOMAIN => [
                HAClimateDefinitions::ATTRIBUTE_SUPPORTED_FEATURES,
                HAClimateDefinitions::ATTRIBUTE_HVAC_MODES,
                HAClimateDefinitions::ATTRIBUTE_PRESET_MODES,
                HAClimateDefinitions::ATTRIBUTE_FAN_MODES,
                HAClimateDefinitions::ATTRIBUTE_SWING_MODES,
                HAClimateDefinitions::ATTRIBUTE_SWING_HORIZONTAL_MODES,
                HAClimateDefinitions::ATTRIBUTE_MIN_TEMP,
                HAClimateDefinitions::ATTRIBUTE_MAX_TEMP,
                HAClimateDefinitions::ATTRIBUTE_TARGET_TEMPERATURE_STEP,
                HAClimateDefinitions::ATTRIBUTE_MIN_HUMIDITY,
                HAClimateDefinitions::ATTRIBUTE_MAX_HUMIDITY,
                'target_humidity_step'
            ],
            HASelectDefinitions::DOMAIN => ['options'],
            HANumberDefinitions::DOMAIN => ['mode'],
            HADateTimeDefinitions::DOMAIN,
            HAInputDateTimeDefinitions::DOMAIN => ['has_date', 'has_time'],
            HAFanDefinitions::DOMAIN => ['preset_modes', 'direction_list'],
            HAHumidifierDefinitions::DOMAIN => ['available_modes', 'min_humidity', 'max_humidity', 'target_humidity_step'],
            HAMediaPlayerDefinitions::DOMAIN => ['source_list', 'sound_mode_list', 'volume_step'],
            HACameraDefinitions::DOMAIN => ['stream_source', 'rtsp_url', 'frontend_stream_type'],
            HAImageDefinitions::DOMAIN => ['entity_picture', 'entity_picture_local', 'url'],
            default => []
        };
    }


    private function isEmptyStableAttributeValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value)) {
            return trim($value) === '';
        }
        if (is_array($value)) {
            return $value === [];
        }

        return false;
    }
}
