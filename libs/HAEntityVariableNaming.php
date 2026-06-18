<?php

declare(strict_types=1);

trait HAEntityVariableNamingTrait
{
    private function buildSharedEntityVariableName(string $domain, array $entity, bool $hasMultipleStatusEntities): string
    {
        $domain = HADomainCatalog::normalizeDomainAlias($domain);
        $name = $this->getSharedDomainEntityVariableName($domain, $entity, $hasMultipleStatusEntities);
        return $name ?? $this->getSharedDefaultEntityVariableName($domain, $entity);
    }

    private function getSharedDomainEntityVariableName(string $domain, array $entity, bool $hasMultipleStatusEntities): ?string
    {
        return match ($domain) {
            HAClimateDefinitions::DOMAIN => $this->getSharedClimateEntityVariableName($entity),
            HAImageDefinitions::DOMAIN => $this->getSharedImageEntityVariableName($entity),
            HADeviceTrackerDefinitions::DOMAIN => $this->getSharedDeviceTrackerEntityVariableName($entity),
            HACoverDefinitions::DOMAIN => $this->getSharedCoverVariableName($entity, $hasMultipleStatusEntities),
            HAValveDefinitions::DOMAIN => $this->getSharedValveVariableName($entity, $hasMultipleStatusEntities),
            HAButtonDefinitions::DOMAIN => $this->getSharedButtonVariableName($entity),
            HAEventDefinitions::DOMAIN => $this->formatSharedEntityNameWithSuffix($entity, 'Last Event'),
            HABinarySensorDefinitions::DOMAIN => $this->getSharedBinarySensorEntityVariableName($entity),
            default => HADomainCatalog::isStatusDomain($domain) ? $this->getSharedStatusEntityVariableName($domain, $hasMultipleStatusEntities) : null,
        };
    }

    private function getSharedClimateEntityVariableName(array $entity): ?string
    {
        $attributes = $this->getSharedEntityAttributesArray($entity);
        if ($attributes === []) {
            return null;
        }

        if (array_key_exists(HAClimateDefinitions::ATTRIBUTE_TARGET_TEMPERATURE, $attributes)
            || $this->supportsSharedClimateTargetTemperature($attributes)) {
            return $this->Translate('Target Temperature');
        }

        if (array_key_exists(HAClimateDefinitions::ATTRIBUTE_CURRENT_TEMPERATURE, $attributes)) {
            return $this->Translate('Current Temperature');
        }

        return null;
    }

    private function getSharedImageEntityVariableName(array $entity): string
    {
        if ($this->isSharedEntityBoundToDevice($entity)) {
            $baseName = $this->getSharedImageEntityBaseName($entity);
            if ($baseName === '') {
                return $this->Translate('Last Update');
            }

            return $baseName . ' (' . $this->Translate('Last Update') . ')';
        }

        return $this->Translate('Last Update');
    }

    private function getSharedDeviceTrackerEntityVariableName(array $entity): string
    {
        $name = $this->getSharedEntityName($entity);
        return $name !== '' ? $name : $this->Translate('Location');
    }

    private function getSharedStatusEntityVariableName(string $domain, bool $hasMultipleStatusEntities): string
    {
        if (!$hasMultipleStatusEntities) {
            return $this->Translate('Status');
        }

        return $this->Translate('Status') . ' (' . strtoupper($domain) . ')';
    }

    private function getSharedButtonVariableName(array $entity): string
    {
        $name = $this->getSharedEntityName($entity);
        if ($name !== '') {
            return $name;
        }

        $caption = match ($this->getSharedEntityDeviceClass($entity)) {
            'identify' => 'Identify',
            'restart' => 'Restart',
            'update' => 'Update',
            default => null,
        };
        if ($caption !== null) {
            return $this->Translate($caption);
        }

        $entityId = $this->getSharedEntityId($entity);
        return $entityId !== '' ? $entityId : 'Press';
    }

    private function getSharedBinarySensorEntityVariableName(array $entity): string
    {
        $name = $this->getSharedEntityName($entity);
        if ($name !== '') {
            return $name;
        }

        return $this->getSharedDeviceClassFallbackName($entity) ?? $this->Translate('Status');
    }

    private function getSharedDefaultEntityVariableName(string $domain, array $entity): string
    {
        $name = $this->getSharedEntityName($entity);
        if ($name !== '') {
            return $name;
        }

        if (HADomainCatalog::supportsDeviceClassNameFallback($domain)) {
            $fallback = $this->getSharedDeviceClassFallbackName($entity);
            if ($fallback !== null) {
                return $fallback;
            }
        }

        return $this->getSharedEntityId($entity);
    }

    private function getSharedDeviceClassFallbackName(array $entity): ?string
    {
        $deviceClass = $this->getSharedEntityDeviceClass($entity);
        if ($deviceClass === '') {
            return null;
        }

        $caption = [
            'co' => 'CO',
            'co2' => 'CO2',
            'pm1' => 'PM1',
            'pm10' => 'PM10',
            'pm25' => 'PM2.5',
            'aqi' => 'AQI',
            'uv_index' => 'UV Index',
        ][$deviceClass] ?? null;

        if ($caption !== null) {
            return $this->Translate($caption);
        }

        $caption = str_replace('_', ' ', $deviceClass);
        $caption = ucwords($caption);
        return $this->Translate($caption);
    }

    private function getSharedCoverVariableName(array $entity, bool $hasMultipleStatusEntities): string
    {
        $attributes = $this->getSharedEntityAttributesArray($entity);
        if (!$this->isSharedCoverPositionEntity($attributes)) {
            return $this->getSharedStatusEntityVariableName(HACoverDefinitions::DOMAIN, $hasMultipleStatusEntities);
        }

        return match ($this->getSharedEntityDeviceClass($entity)) {
            HACoverDefinitions::DEVICE_CLASS_GARAGE,
            HACoverDefinitions::DEVICE_CLASS_GATE,
            HACoverDefinitions::DEVICE_CLASS_DOOR,
            HACoverDefinitions::DEVICE_CLASS_WINDOW => $this->Translate('Opening'),
            HACoverDefinitions::DEVICE_CLASS_DAMPER => $this->Translate('Positioning'),
            default => $this->Translate('Position'),
        };
    }

    private function getSharedValveVariableName(array $entity, bool $hasMultipleStatusEntities): string
    {
        $attributes = $this->getSharedEntityAttributesArray($entity);
        if ($this->isSharedValvePositionEntity($attributes)) {
            return $this->Translate('Position');
        }

        return $this->getSharedStatusEntityVariableName(HAValveDefinitions::DOMAIN, $hasMultipleStatusEntities);
    }

    private function formatSharedEntityNameWithSuffix(array $entity, string $suffix): string
    {
        $baseName = $this->getSharedEntityName($entity);
        if ($baseName === '') {
            return $this->Translate($suffix);
        }

        return $baseName . ' (' . $this->Translate($suffix) . ')';
    }

    private function getSharedEntityName(array $entity): string
    {
        $name = trim((string)($entity['name'] ?? ''));
        $stripped = $this->stripSharedCurrentInstanceNamePrefix($name);
        if ($stripped !== $name) {
            return $this->appendHaDeduplicationSuffix($stripped, $entity);
        }

        $deviceName = trim((string)($entity['device_name'] ?? ''));
        if ($deviceName !== '' && str_starts_with($name, $deviceName . ' ')) {
            $stripped = trim(substr($name, strlen($deviceName) + 1));
            return $this->appendHaDeduplicationSuffix($stripped, $entity);
        }

        return $name;
    }

    // HA appends _2, _3, ... to entity_ids when multiple entities share the same name.
    // The entity name field is not updated. We detect this and append the number to the variable name.
    private function appendHaDeduplicationSuffix(string $name, array $entity): string
    {
        $entityId = trim((string)($entity['entity_id'] ?? ''));
        if ($entityId === '' || !preg_match('/_(\d+)$/', $entityId, $matches)) {
            return $name;
        }
        $num = (int)$matches[1];
        // HA deduplication suffixes start at _2; _1 suffixes are intentional (e.g. ch1)
        if ($num < 2) {
            return $name;
        }
        // Only append if the name does not already contain this number
        if (preg_match('/\b' . $num . '\b/', $name)) {
            return $name;
        }
        return $name . ' ' . $num;
    }

    private function getSharedImageEntityBaseName(array $entity): string
    {
        $attributes = $this->getSharedEntityAttributesArray($entity);
        $friendlyName = $this->stripSharedCurrentInstanceNamePrefix(trim((string)($attributes['friendly_name'] ?? '')));
        if ($friendlyName !== '') {
            return $friendlyName;
        }

        return $this->getSharedEntityName($entity);
    }

    private function getSharedEntityId(array $entity): string
    {
        $entityId = trim((string)($entity['entity_id'] ?? $entity['entity_key'] ?? ''));
        if ($entityId !== '') {
            return $entityId;
        }

        $domain = trim((string)($entity['domain'] ?? $entity['component'] ?? ''));
        $objectId = trim((string)($entity['object_id'] ?? ''));
        if ($domain !== '' && $objectId !== '') {
            return $domain . '.' . $objectId;
        }

        return '';
    }

    private function getSharedEntityAttributesArray(array $entity): array
    {
        $attributes = $entity['attributes'] ?? [];
        if (!is_array($attributes)) {
            $attributes = [];
        }

        $metadata = $entity['metadata'] ?? [];
        if (is_array($metadata) && $metadata !== []) {
            $attributes = array_merge($metadata, $attributes);
        }

        return $attributes;
    }

    private function getSharedEntityDeviceClass(array $entity): string
    {
        $attributes = $this->getSharedEntityAttributesArray($entity);
        return strtolower(trim((string)($attributes['device_class'] ?? '')));
    }

    private function isSharedEntityBoundToDevice(array $entity): bool
    {
        return $this->isSharedCurrentInstanceDeviceBoundToEntity($this->getSharedEntityId($entity));
    }

    private function isSharedCurrentInstanceDeviceBoundToEntity(string $entityId): bool
    {
        $deviceId = $this->getSharedCurrentInstanceDeviceId();
        if ($deviceId === '' || strtolower($deviceId) === 'none') {
            return false;
        }

        if ($entityId !== '' && strcasecmp($deviceId, $entityId) === 0) {
            return false;
        }

        return !str_contains($deviceId, '.');
    }

    private function stripSharedCurrentInstanceNamePrefix(string $name): string
    {
        $instanceName = $this->getSharedCurrentInstanceDeviceName();
        if ($instanceName === '') {
            return $name;
        }

        if (strcasecmp($name, $instanceName) === 0) {
            return '';
        }

        $prefix = $instanceName . ' ';
        if (!str_starts_with($name, $prefix)) {
            return $name;
        }

        $name = substr($name, strlen($prefix));
        return trim($name);
    }

    private function getSharedCurrentInstanceDeviceId(): string
    {
        if (defined(static::class . '::PROP_DEVICE_ID')) {
            $prop = constant(static::class . '::PROP_DEVICE_ID');
            if (is_string($prop) && $prop !== '') {
                return trim($this->ReadPropertyString($prop));
            }
        }

        if (method_exists($this, 'getConfiguredDeviceId')) {
            $deviceId = $this->getConfiguredDeviceId();
            return trim($deviceId);
        }

        return '';
    }

    private function getSharedCurrentInstanceDeviceName(): string
    {
        if (defined(static::class . '::PROP_DEVICE_NAME')) {
            $prop = constant(static::class . '::PROP_DEVICE_NAME');
            if (is_string($prop) && $prop !== '') {
                $name = trim($this->ReadPropertyString($prop));
                if ($name !== '') {
                    return $name;
                }
            }
        }

        if (property_exists($this, 'InstanceID')) {
            $instanceId = $this->InstanceID ?? 0;
            if ($instanceId > 0) {
                $object = @IPS_GetObject($instanceId);
                $name = trim((string)($object['ObjectName'] ?? ''));
                if ($name !== '') {
                    return $name;
                }
            }
        }

        if (method_exists($this, 'readResolvedDeviceDefinition')) {
            $definition = $this->readResolvedDeviceDefinition();
            $name = trim((string)($definition['device_name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        return '';
    }

    private function supportsSharedClimateTargetTemperature(array $attributes): bool
    {
        $supported = (int)($attributes[HAClimateDefinitions::ATTRIBUTE_SUPPORTED_FEATURES] ?? 0);
        return ($supported & 1) === 1;
    }

    private function isSharedCoverPositionEntity(array $attributes): bool
    {
        if (array_any(
            [HACoverDefinitions::ATTRIBUTE_POSITION, HACoverDefinitions::ATTRIBUTE_POSITION_ALT],
            static fn(string $key): bool => is_numeric($attributes[$key] ?? null)
        )) {
            return true;
        }

        $supported = (int)($attributes['supported_features'] ?? 0);
        return ($supported & HACoverDefinitions::FEATURE_SET_POSITION) === HACoverDefinitions::FEATURE_SET_POSITION;
    }

    private function isSharedValvePositionEntity(array $attributes): bool
    {
        if (array_any(
            [HAValveDefinitions::ATTRIBUTE_POSITION, HAValveDefinitions::ATTRIBUTE_POSITION_ALT],
            static fn(string $key): bool => is_numeric($attributes[$key] ?? null)
        )) {
            return true;
        }

        $supported = (int)($attributes['supported_features'] ?? 0);
        if (($supported & HAValveDefinitions::FEATURE_SET_POSITION) === HAValveDefinitions::FEATURE_SET_POSITION) {
            return true;
        }

        $reportsPosition = $attributes[HAValveDefinitions::ATTRIBUTE_REPORTS_POSITION] ?? null;
        if (is_bool($reportsPosition)) {
            return $reportsPosition;
        }
        if (is_numeric($reportsPosition)) {
            return (int)$reportsPosition !== 0;
        }
        if (is_string($reportsPosition)) {
            $reportsPosition = strtolower(trim($reportsPosition));
            return in_array($reportsPosition, ['1', 'true', 'on', 'yes'], true);
        }

        return false;
    }
}
