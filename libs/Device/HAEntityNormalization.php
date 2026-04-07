<?php

declare(strict_types=1);

trait HAEntityNormalizationTrait
{
    // Führt alle Normalisierungsschritte für Konfigurations- und State-Daten in fester Reihenfolge aus.
    private function normalizeEntity(array $row, string $context): ?array
    {
        if (empty($row['entity_id'])) {
            $this->debugExpert($context, 'Entity ohne entity_id', $row);
            return null;
        }

        $row = $this->normalizeEntityDomain($row);
        $row = $this->normalizeEntityAttributes($row, $context);
        $row = $this->syncDeviceClass($row);
        $row = $this->normalizeDomainSpecificAttributes($row);
        $this->enrichSupportedFeaturesList($row);

        return $this->applyEinLevelDefaults($row);
    }

    // Leitet die Domain aus der Entity-ID ab, wenn sie in der Konfiguration fehlt.
    private function normalizeEntityDomain(array $row): array
    {
        if (!isset($row['domain']) && str_contains($row['entity_id'], '.')) {
            [$row['domain']] = explode('.', $row['entity_id'], 2);
        }

        return $row;
    }

    // Attribute können aus der Konfiguration als JSON-String oder als Array kommen.
    private function normalizeEntityAttributes(array $row, string $context): array
    {
        if (isset($row['attributes']) && is_string($row['attributes'])) {
            $decoded = $this->decodeJsonArray($row['attributes'], $context);
            if ($decoded !== null) {
                $row['attributes'] = $decoded;
            }
        }

        if (!isset($row['attributes']) || !is_array($row['attributes'])) {
            $row['attributes'] = [];
        }

        return $row;
    }

    // Device-Class wird zwischen Haupteintrag und Attributen synchron gehalten.
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

    // Domain-spezifische Alias- und Normalisierungsregeln werden zentral angewendet.
    private function normalizeDomainSpecificAttributes(array $row): array
    {
        $domain = (string) ($row['domain'] ?? '');
        if ($domain !== '' && is_array($row['attributes'])) {
            $row['attributes'] = $this->filterAttributesByDomain($domain, $row['attributes'], __FUNCTION__);
        }

        return $row;
    }

    // Führt Laufzeit-, Konfigurations- und Domain-Attribute zu einem stabilen Zustandsbild zusammen.
    private function resolveEntityStateAttributes(string $entityId, mixed $attributes = null): array
    {
        $resolved = is_array($attributes) ? $attributes : [];

        $existing = $this->entities[$entityId]['attributes'] ?? [];
        if (is_array($existing) && $existing !== []) {
            $resolved = array_merge($existing, $resolved);
        }

        $configured = $this->findConfiguredEntityById($entityId);
        if ($configured !== null) {
            $configuredAttributes = $configured['attributes'] ?? [];
            if (is_array($configuredAttributes) && $configuredAttributes !== []) {
                $resolved = array_merge($configuredAttributes, $resolved);
            }
            $configuredDeviceClass = $configured['device_class'] ?? '';
            if (($resolved['device_class'] ?? '') === '' && is_string($configuredDeviceClass) && trim($configuredDeviceClass) !== '') {
                $resolved['device_class'] = trim($configuredDeviceClass);
            }
        }

        $entityDeviceClass = $this->entities[$entityId]['device_class'] ?? '';
        if (($resolved['device_class'] ?? '') === '' && is_string($entityDeviceClass) && trim($entityDeviceClass) !== '') {
            $resolved['device_class'] = trim($entityDeviceClass);
        }

        $domain = $this->getEntityDomain($entityId);
        if ($domain !== '') {
            $resolved = $this->filterAttributesByDomain($domain, $resolved, __FUNCTION__);
        }

        return $resolved;
    }

    // Sucht die normalisierte Konfigurationszeile für eine Entity-ID.
    private function findConfiguredEntityById(string $entityId): ?array
    {
        $configData = $this->decodeJsonArray($this->ReadPropertyString(self::PROP_DEVICE_CONFIG), __FUNCTION__);
        if ($configData === null) {
            return null;
        }

        foreach ($configData as $row) {
            $row = $this->normalizeEntity($row, __FUNCTION__);
            if ($row === null || (($row['create_var'] ?? true) === false)) {
                continue;
            }
            if (($row['entity_id'] ?? '') === $entityId) {
                return $row;
            }
        }

        return null;
    }

    // Historischer Sonderfall: *_ein_level wird als Prozentwert behandelt.
    private function applyEinLevelDefaults(array $row): array
    {
        $entityId = (string) $row['entity_id'];
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

    // Verzweigt auf Domain-Regeln, ohne die Aufrufer mit Domainwissen zu belasten.
    private function filterAttributesByDomain(string $domain, array $attributes, string $context): array
    {
        if ($domain === HAClimateDefinitions::DOMAIN) {
            return $this->mapClimateAttributeAliases($attributes, $context);
        }
        if ($domain === HAMediaPlayerDefinitions::DOMAIN) {
            return $this->mapMediaPlayerAttributeAliases($attributes, $context);
        }
        if ($domain === HACameraDefinitions::DOMAIN) {
            return $this->normalizeCameraAttributes($attributes, $context);
        }
        if ($domain === HAImageDefinitions::DOMAIN) {
            return $this->normalizeImageAttributes($attributes, $context);
        }
        if ($domain === HAHumidifierDefinitions::DOMAIN) {
            return $this->mapHumidifierAttributeAliases($attributes, $context);
        }

        return $attributes;
    }

    // Legacy-Klimaschlüssel werden auf die offiziellen HA-Attribute gemappt.
    private function mapClimateAttributeAliases(array $attributes, string $context): array
    {
        $aliases = [
            'temperature' => HAClimateDefinitions::ATTRIBUTE_TARGET_TEMPERATURE,
            'target_temp_low' => HAClimateDefinitions::ATTRIBUTE_TARGET_TEMPERATURE_LOW,
            'target_temp_high' => HAClimateDefinitions::ATTRIBUTE_TARGET_TEMPERATURE_HIGH,
            'target_temp_step' => HAClimateDefinitions::ATTRIBUTE_TARGET_TEMPERATURE_STEP
        ];

        $mapped = [];
        foreach ($aliases as $alias => $target) {
            if (!array_key_exists($alias, $attributes) || array_key_exists($target, $attributes)) {
                continue;
            }
            $attributes[$target] = $attributes[$alias];
            unset($attributes[$alias]);
            $mapped[$alias] = $target;
        }

        if ($mapped !== []) {
            $this->debugExpert($context, 'Klima-Attribute umbenannt', ['Mapped' => $mapped]);
        }

        return $attributes;
    }

    // Media-Player deckt mehrere Bildquellen ab, intern wird auf media_image_url vereinheitlicht.
    private function mapMediaPlayerAttributeAliases(array $attributes, string $context): array
    {
        $mapped = [];
        if (!array_key_exists('media_image_url', $attributes)) {
            if (array_key_exists('entity_picture', $attributes)) {
                $attributes['media_image_url'] = $attributes['entity_picture'];
                $mapped['entity_picture'] = 'media_image_url';
            } elseif (array_key_exists('entity_picture_local', $attributes)) {
                $attributes['media_image_url'] = $attributes['entity_picture_local'];
                $mapped['entity_picture_local'] = 'media_image_url';
            }
        }
        if ($mapped !== []) {
            $this->debugExpert($context, 'Media-Attribute umbenannt', ['Mapped' => $mapped]);
        }

        return $attributes;
    }

    // Einige Integrationen liefern humidity statt target_humidity.
    private function mapHumidifierAttributeAliases(array $attributes, string $context): array
    {
        if (!array_key_exists(HAHumidifierDefinitions::ATTRIBUTE_TARGET_HUMIDITY, $attributes)
            && array_key_exists('humidity', $attributes)) {
            $attributes[HAHumidifierDefinitions::ATTRIBUTE_TARGET_HUMIDITY] = $attributes['humidity'];
            unset($attributes['humidity']);
            $this->debugExpert($context, 'Humidifier-Attribute umbenannt', ['Mapped' => ['humidity' => 'target_humidity']]);
        }

        return $attributes;
    }

    // Kameras liefern URLs teils mit Leerzeichen am Rand.
    private function normalizeCameraAttributes(array $attributes, string $context): array
    {
        $normalized = [];
        foreach (['stream_source', 'rtsp_url'] as $key) {
            if (!isset($attributes[$key]) || !is_string($attributes[$key])) {
                continue;
            }
            $trimmed = trim($attributes[$key]);
            if ($trimmed !== $attributes[$key]) {
                $attributes[$key] = $trimmed;
                $normalized[] = $key;
            }
        }
        if ($normalized !== []) {
            $this->debugExpert($context, 'Camera-Attribute normalisiert', ['Attributes' => $normalized]);
        }

        return $attributes;
    }

    // Image vereinheitlicht Vorschauquellen auf entity_picture.
    private function normalizeImageAttributes(array $attributes, string $context): array
    {
        $normalized = [];
        foreach (['entity_picture', 'entity_picture_local', 'url'] as $key) {
            if (!isset($attributes[$key]) || !is_string($attributes[$key])) {
                continue;
            }
            $trimmed = trim($attributes[$key]);
            if ($trimmed !== $attributes[$key]) {
                $attributes[$key] = $trimmed;
                $normalized[] = $key;
            }
        }

        $mapped = [];
        if (!array_key_exists('entity_picture', $attributes) || trim((string) $attributes['entity_picture']) === '') {
            if (array_key_exists('entity_picture_local', $attributes) && trim((string) $attributes['entity_picture_local']) !== '') {
                $attributes['entity_picture'] = $attributes['entity_picture_local'];
                $mapped['entity_picture_local'] = 'entity_picture';
            } elseif (array_key_exists('url', $attributes) && trim((string) $attributes['url']) !== '') {
                $attributes['entity_picture'] = $attributes['url'];
                $mapped['url'] = 'entity_picture';
            }
        }

        if ($normalized !== []) {
            $this->debugExpert($context, 'Image-Attribute normalisiert', ['Attributes' => $normalized]);
        }
        if ($mapped !== []) {
            $this->debugExpert($context, 'Image-Attribute umbenannt', ['Mapped' => $mapped]);
        }

        return $attributes;
    }

    // Lesbare Feature-Namen werden einmalig aus der Bitmaske ergänzt.
    private function enrichSupportedFeaturesList(array &$row): void
    {
        if (!isset($row['attributes']) || !is_array($row['attributes'])) {
            return;
        }
        if (isset($row['attributes']['supported_features_list'])) {
            return;
        }
        if (!isset($row['attributes']['supported_features']) || !is_numeric($row['attributes']['supported_features'])) {
            return;
        }

        $domain = (string) ($row['domain'] ?? '');
        if ($domain === '' && isset($row['entity_id']) && str_contains($row['entity_id'], '.')) {
            [$domain] = explode('.', (string) $row['entity_id'], 2);
        }

        $list = $this->mapSupportedFeaturesByDomain($domain, (int) $row['attributes']['supported_features'], true);
        if ($list !== []) {
            $row['attributes']['supported_features_list'] = $list;
        }
    }
}
