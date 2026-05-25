<?php

declare(strict_types=1);

trait HAEntityNormalizationTrait
{
    // Führt die rein fachliche Normalisierung einer Entität aus (Struktur, Aliase, Fixes).
    private function normalizeEntityStructure(array $entity): ?array
    {
        if (!isset($entity['entity_id']) || !is_string($entity['entity_id']) || trim($entity['entity_id']) === '') {
            return null;
        }
        $entity = $this->normalizeEntityDomain($entity);
        $entity = $this->normalizeEntityAttributes($entity);
        $entity = $this->syncDeviceClass($entity);
        $entity = $this->applyEinLevelDefaults($entity);
        $entity = $this->normalizeDomainSpecificAttributes($entity);
        $this->enrichSupportedFeaturesList($entity);

        return $entity;
    }

    private function normalizeEntityDomain(array $row): array
    {
        if (!isset($row['domain']) && isset($row['entity_id']) && str_contains($row['entity_id'], '.')) {
            [$row['domain']] = explode('.', $row['entity_id'], 2);
        }

        return $row;
    }

    private function normalizeEntityAttributes(array $row): array
    {
        if (isset($row['attributes']) && is_string($row['attributes'])) {
            try {
                $decoded = json_decode($row['attributes'], true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $row['attributes'] = $decoded;
                }
            } catch (JsonException) {
            }
        }

        if (!isset($row['attributes']) || !is_array($row['attributes'])) {
            $row['attributes'] = [];
        }

        return $row;
    }

    private function syncDeviceClass(array $row): array
    {
        $attributes = $row['attributes'] ?? [];
        if (!is_array($attributes)) {
            $attributes = [];
        }

        if (isset($attributes['device_class'])
            && is_string($attributes['device_class'])
            && (!isset($row['device_class']) || trim((string) $row['device_class']) === '')) {
            $row['device_class'] = trim($attributes['device_class']);
        }

        if (isset($row['device_class'])) {
            $deviceClass = $row['device_class'];
            if (!is_string($deviceClass)) {
                $deviceClass = '';
            }
            $deviceClass = trim($deviceClass);
            if (($deviceClass !== '') && ($attributes['device_class'] ?? '') === '') {
                $attributes['device_class'] = $deviceClass;
            }
        }

        $row['attributes'] = $attributes;

        return $row;
    }

    private function applyEinLevelDefaults(array $row): array
    {
        $entityId = (string) ($row['entity_id'] ?? '');
        if (str_ends_with($entityId, '_ein_level') || str_ends_with($entityId, '.ein_level')) {
            if (!isset($row['attributes']) || !is_array($row['attributes'])) {
                $row['attributes'] = [];
            }
            if (!isset($row['attributes']['unit_of_measurement']) || $row['attributes']['unit_of_measurement'] === '') {
                $row['attributes']['unit_of_measurement'] = '%';
            }
        }

        return $row;
    }

    private function normalizeDomainSpecificAttributes(array $row): array
    {
        $domain = (string) ($row['domain'] ?? '');
        if ($domain === '' && isset($row['entity_id'])) {
            $domain = $this->deriveDomainFromEntityId((string) $row['entity_id']);
        }

        if ($domain !== '' && isset($row['attributes']) && is_array($row['attributes'])) {
            $row['attributes'] = $this->filterAttributesByDomain($domain, $row['attributes']);
        }

        return $row;
    }

    private function filterAttributesByDomain(string $domain, array $attributes): array
    {
        if ($domain === HAClimateDefinitions::DOMAIN) {
            return $this->mapClimateAttributeAliases($attributes);
        }
        if ($domain === HAMediaPlayerDefinitions::DOMAIN) {
            return $this->mapMediaPlayerAttributeAliases($attributes);
        }
        if ($domain === HACameraDefinitions::DOMAIN) {
            return $this->normalizeCameraAttributes($attributes);
        }
        if ($domain === HAImageDefinitions::DOMAIN) {
            return $this->normalizeImageAttributes($attributes);
        }
        if ($domain === HADeviceTrackerDefinitions::DOMAIN) {
            return $this->normalizeDeviceTrackerAttributes($attributes);
        }
        if ($domain === HAHumidifierDefinitions::DOMAIN) {
            return $this->mapHumidifierAttributeAliases($attributes);
        }

        return $attributes;
    }

    private function mapClimateAttributeAliases(array $attributes): array
    {
        $aliases = [
            'temperature' => HAClimateDefinitions::ATTRIBUTE_TARGET_TEMPERATURE,
            'target_temp_low' => HAClimateDefinitions::ATTRIBUTE_TARGET_TEMPERATURE_LOW,
            'target_temp_high' => HAClimateDefinitions::ATTRIBUTE_TARGET_TEMPERATURE_HIGH,
            'target_temp_step' => HAClimateDefinitions::ATTRIBUTE_TARGET_TEMPERATURE_STEP
        ];

        foreach ($aliases as $alias => $target) {
            if (array_key_exists($alias, $attributes) && !array_key_exists($target, $attributes)) {
                $attributes[$target] = $attributes[$alias];
                unset($attributes[$alias]);
            }
        }

        return $attributes;
    }

    private function mapMediaPlayerAttributeAliases(array $attributes): array
    {
        if (!array_key_exists('media_image_url', $attributes)) {
            if (array_key_exists('entity_picture', $attributes)) {
                $attributes['media_image_url'] = $attributes['entity_picture'];
            } elseif (array_key_exists('entity_picture_local', $attributes)) {
                $attributes['media_image_url'] = $attributes['entity_picture_local'];
            }
        }

        return $attributes;
    }

    private function mapHumidifierAttributeAliases(array $attributes): array
    {
        if (!array_key_exists(HAHumidifierDefinitions::ATTRIBUTE_TARGET_HUMIDITY, $attributes)
            && array_key_exists('humidity', $attributes)) {
            $attributes[HAHumidifierDefinitions::ATTRIBUTE_TARGET_HUMIDITY] = $attributes['humidity'];
            unset($attributes['humidity']);
        }

        return $attributes;
    }

    protected function normalizeCameraAttributes(array $attributes, ?string $context = null): array
    {
        unset($context);
        foreach (['stream_source', 'rtsp_url'] as $key) {
            if (isset($attributes[$key]) && is_string($attributes[$key])) {
                $attributes[$key] = trim($attributes[$key]);
            }
        }

        return $attributes;
    }

    protected function normalizeImageAttributes(array $attributes, ?string $context = null): array
    {
        unset($context);
        foreach (['entity_picture', 'entity_picture_local', 'url'] as $key) {
            if (isset($attributes[$key]) && is_string($attributes[$key])) {
                $attributes[$key] = trim($attributes[$key]);
            }
        }

        if (!array_key_exists('entity_picture', $attributes) || trim((string) $attributes['entity_picture']) === '') {
            if (array_key_exists('entity_picture_local', $attributes) && trim((string) $attributes['entity_picture_local']) !== '') {
                $attributes['entity_picture'] = $attributes['entity_picture_local'];
            } elseif (array_key_exists('url', $attributes) && trim((string) $attributes['url']) !== '') {
                $attributes['entity_picture'] = $attributes['url'];
            }
        }

        return $attributes;
    }

    protected function normalizeDeviceTrackerAttributes(array $attributes, ?string $context = null): array
    {
        unset($context);

        if (isset($attributes['source_type']) && is_string($attributes['source_type'])) {
            $attributes['source_type'] = trim($attributes['source_type']);
        }

        foreach (['latitude', 'longitude', 'altitude'] as $key) {
            if (isset($attributes[$key]) && is_numeric($attributes[$key])) {
                $attributes[$key] = (float)$attributes[$key];
            }
        }

        if (isset($attributes['gps_accuracy']) && is_numeric($attributes['gps_accuracy'])) {
            $attributes['gps_accuracy'] = (int)$attributes['gps_accuracy'];
        }

        return $attributes;
    }

    private function enrichSupportedFeaturesList(array &$row): void
    {
        $list = $this->buildSupportedFeaturesList($row, true);
        if ($list !== []) {
            $row['attributes']['supported_features_list'] = $list;
        }
    }

    private function buildSupportedFeaturesList(array $row, bool $stripPrefix): array
    {
        if (!isset($row['attributes']) || !is_array($row['attributes'])) {
            return [];
        }
        if (isset($row['attributes']['supported_features_list'])) {
            return [];
        }
        if (!isset($row['attributes']['supported_features']) || !is_numeric($row['attributes']['supported_features'])) {
            return [];
        }

        $domain = (string)($row['domain'] ?? '');
        if ($domain === '' && isset($row['entity_id'])) {
            $domain = $this->deriveDomainFromEntityId((string)$row['entity_id']);
        }

        return $this->mapSupportedFeaturesByDomain($domain, (int)$row['attributes']['supported_features'], $stripPrefix);
    }

    private function deriveDomainFromEntityId(string $entityId): string
    {
        if ($entityId !== '' && str_contains($entityId, '.')) {
            [$domain] = explode('.', $entityId, 2);
            return $domain;
        }

        return '';
    }
}
