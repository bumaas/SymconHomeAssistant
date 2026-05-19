<?php

declare(strict_types=1);

trait HADeviceEntityNormalizationTrait
{
    // Führt Laufzeit-, Konfigurations- und Domain-Attribute zu einem stabilen Zustandsbild zusammen.
    private function resolveEntityStateAttributes(string $entityId, mixed $attributes = null): array
    {
        $resolved = is_array($attributes) ? $attributes : [];

        // 1. Aktuelle Attribute aus dem Runtime-Speicher (Laufzeitdaten)
        $existing = $this->entities[$entityId]['attributes'] ?? [];
        if (is_array($existing) && $existing !== []) {
            $resolved = array_merge($existing, $resolved);
        }

        // 2. Attribute aus der Konfiguration (Strukturdaten)
        $configured = $this->findConfiguredEntityById($entityId);
        if ($configured !== null) {
            $configuredAttributes = $configured['attributes'] ?? [];
            if (is_string($configuredAttributes)) {
                $configuredAttributes = json_decode($configuredAttributes, true, 512, JSON_THROW_ON_ERROR) ?? [];
            }
            if (is_array($configuredAttributes) && $configuredAttributes !== []) {
                $resolved = array_merge($configuredAttributes, $resolved);
            }
            $configuredDeviceClass = $configured['device_class'] ?? '';
            if (($resolved['device_class'] ?? '') === '' && is_string($configuredDeviceClass) && trim($configuredDeviceClass) !== '') {
                $resolved['device_class'] = trim($configuredDeviceClass);
            }
        }

        // 3. Fallback auf Device-Class aus dem Runtime-Speicher
        $entityDeviceClass = $this->entities[$entityId]['device_class'] ?? '';
        if (($resolved['device_class'] ?? '') === '' && is_string($entityDeviceClass) && trim($entityDeviceClass) !== '') {
            $resolved['device_class'] = trim($entityDeviceClass);
        }

        // 4. Fachliche Normalisierung (Aliase etc.)
        $domain = $this->getEntityDomain($entityId);
        if ($domain !== '') {
            $resolved = $this->filterAttributesByDomain($domain, $resolved);
        }

        return $resolved;
    }

    // Konfigurations-Entities werden einmal zentral normalisiert und gefiltert.
    private function getConfiguredEntities(string $context): array
    {
        $configData = $this->decodeJsonArray($this->ReadPropertyString(HADeviceConstants::PROP_DEVICE_CONFIG), $context);
        if ($configData === null) {
            return [];
        }

        $configuredEntities = [];
        foreach ($configData as $row) {
            $row = $this->normalizeActiveConfiguredEntity($row);
            if ($row === null) {
                continue;
            }

            $configuredEntities[] = $row;
        }

        return $this->applySharedEntityIdents($configuredEntities);
    }

    // Sucht die passende Konfigurationszeile zu einer Entity-ID.
    private function findConfiguredEntityById(string $entityId): ?array
    {
        return array_find(
            $this->getConfiguredEntities(__FUNCTION__),
            static fn(array $row): bool => ($row['entity_id'] ?? '') === $entityId
        );
    }
}
