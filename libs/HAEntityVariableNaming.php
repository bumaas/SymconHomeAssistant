<?php

declare(strict_types=1);

trait HAEntityVariableNamingTrait
{
    // Zählschlüssel für den eigenen Namen als letzten Ausweg; das NUL-Zeichen kommt in keinem
    // Anzeigenamen vor, die Schlüssel können also nicht mit einem Basisnamen zusammenfallen.
    private const string SHARED_OWN_NAME_COUNT_PREFIX = "\0own:";
    // Weitere Zählschlüssel (MCP-Regel 14, gleichnamige Variablen): Entitäten je Domäne, Basisname je
    // Domäne, ungekürzter Name der Entitäten ohne eigenen Namen.
    private const string SHARED_DOMAIN_COUNT_PREFIX = "\0domain:";
    private const string SHARED_DOMAIN_NAME_COUNT_PREFIX = "\0dname:";
    private const string SHARED_RAW_NAME_COUNT_PREFIX = "\0raw:";

    // Art-Hinweis, wenn Home Assistant denselben Namen an Entitäten verschiedener Art vergibt
    // (Tesla: „Ladekabel" als Ja/Nein- und als Textsensor). Domänen ohne Eintrag bekommen keinen.
    private const array SHARED_DOMAIN_KIND_CAPTIONS = [
        'binary_sensor' => 'Yes/No',
        'sensor'        => 'Value',
        'select'        => 'Selection',
        'input_select'  => 'Selection',
        'number'        => 'Number',
        'input_number'  => 'Number',
        'switch'        => 'Switch',
        'input_boolean' => 'Switch',
        'button'        => 'Button',
        'input_button'  => 'Button',
        'text'          => 'Text',
        'input_text'    => 'Text',
        'light'         => 'Light',
        'lock'          => 'Lock',
        'event'         => 'Event',
    ];

    // base name (without dedup suffix) => number of entities in this instance sharing it
    private array $sharedEntityBaseNameCounts = [];

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
            default => HADomainCatalog::isStatusDomain($domain) ? ($this->getSharedEntityName($entity) ?: $this->getSharedStatusEntityVariableName($domain, $hasMultipleStatusEntities, $entity)) : null,
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
            $caption = $this->Translate('Target Temperature');
        } elseif (array_key_exists(HAClimateDefinitions::ATTRIBUTE_CURRENT_TEMPERATURE, $attributes)) {
            $caption = $this->Translate('Current Temperature');
        } else {
            return null;
        }

        // Mehrere Klima-Entitäten (Tesla: „Klima" und „Überhitzungsschutz der Kabine"): ohne
        // Entitätsnamen hießen beide Hauptvariablen nur nach der Temperatur. Zeigt die Hauptvariable
        // die Isttemperatur, heißt sie nach der Entität allein — die Zusatzvariable der Isttemperatur
        // trüge sonst denselben Namen.
        $name = $this->getSharedEntityName($entity);
        if ($name === '' || $this->getSharedDomainEntityCount(HAClimateDefinitions::DOMAIN) < 2) {
            return $caption;
        }
        return $caption === $this->Translate('Target Temperature') ? $name . ' ' . $caption : $name;
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

    private function getSharedStatusEntityVariableName(string $domain, bool $hasMultipleStatusEntities, array $entity = []): string
    {
        if (!$hasMultipleStatusEntities) {
            return $this->Translate('Status');
        }

        // Mehrere Hauptteile (Luftentfeuchter mit Lüfter): Der ungekürzte Name aus Home Assistant
        // sagt mehr als die Domäne in Großbuchstaben — sofern ihn keine zweite Entität ebenso trägt.
        $rawName = trim((string)($entity['name'] ?? ''));
        if ($rawName !== '' && ($this->sharedEntityBaseNameCounts[self::SHARED_RAW_NAME_COUNT_PREFIX . $rawName] ?? 0) < 2) {
            return $rawName;
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

        // Ungekürzter HA-Name vor der Entity-ID (wie getSharedDefaultEntityVariableName seit build 171):
        // Ein Button, dessen Name nur den Instanznamen wiederholt, hieß sonst „input_button.test_button"
        // (Blindtest 04.10.2026) — sofern keine zweite Entität der Instanz ebenso darauf ausweicht.
        $rawName = trim((string)($entity['name'] ?? ''));
        if ($rawName !== '' && ($this->sharedEntityBaseNameCounts[self::SHARED_RAW_NAME_COUNT_PREFIX . $rawName] ?? 0) < 2) {
            return $rawName;
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

        // Der Name galt nur als Präfix des Instanz- oder Gerätenamens als leer (z. B. Entity-Instanz
        // „Test Zahl" für input_number.test_zahl) — er ist trotzdem besser als die Entity-ID. Fallen
        // mehrere Entitäten der Instanz auf denselben Namen zurück, unterscheidet er nichts mehr;
        // dann bleibt es bei der eindeutigen Entity-ID.
        $ownName = $this->getSharedOwnNameFallback($entity);
        if ($ownName !== null
            && ($this->sharedEntityBaseNameCounts[$ownName] ?? 0)
            + ($this->sharedEntityBaseNameCounts[self::SHARED_OWN_NAME_COUNT_PREFIX . $ownName] ?? 0) < 2) {
            return $ownName;
        }
        return $this->getSharedEntityId($entity);
    }

    // Eigener Name einer Entität, deren Name nur aus dem Instanz- oder Gerätenamen besteht — sofern
    // sie bis zum letzten Ausweg von getSharedDefaultEntityVariableName kommt (kein Domänen- und kein
    // Geräteklassen-Ersatz). null = trifft nicht zu.
    private function getSharedOwnNameFallback(array $entity): ?string
    {
        $ownName = trim((string)($entity['name'] ?? ''));
        if ($ownName === '' || $this->getSharedEntityBaseName($entity) !== '') {
            return null;
        }
        $domain = HADomainCatalog::normalizeDomainAlias((string)($entity['domain'] ?? $entity['component'] ?? ''));
        if ($this->getSharedDomainEntityVariableName($domain, $entity, false) !== null) {
            return null;
        }
        if (HADomainCatalog::supportsDeviceClassNameFallback($domain) && $this->getSharedDeviceClassFallbackName($entity) !== null) {
            return null;
        }
        return $ownName;
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
        $name = $this->getSharedEntityName($entity);
        if ($name !== '') {
            return $name;
        }

        $attributes = $this->getSharedEntityAttributesArray($entity);
        if (!$this->isSharedCoverPositionEntity($attributes)) {
            return $this->getSharedStatusEntityVariableName(HACoverDefinitions::DOMAIN, $hasMultipleStatusEntities, $entity);
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
        $name = $this->getSharedEntityName($entity);
        if ($name !== '') {
            return $name;
        }

        $attributes = $this->getSharedEntityAttributesArray($entity);
        if ($this->isSharedValvePositionEntity($attributes)) {
            return $this->Translate('Position');
        }

        return $this->getSharedStatusEntityVariableName(HAValveDefinitions::DOMAIN, $hasMultipleStatusEntities, $entity);
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
        $baseName = $this->getSharedEntityBaseName($entity);
        if ($baseName === '') {
            return '';
        }

        return $this->disambiguateSharedEntityName($baseName, $entity);
    }

    // Returns the display name stripped of the current instance/device prefix, WITHOUT any
    // deduplication suffix. An empty string means "entity has no own name" → the caller falls
    // back to a domain-specific name (e.g. "Status (LIGHT)").
    private function getSharedEntityBaseName(array $entity): string
    {
        $name = trim((string)($entity['name'] ?? ''));
        $stripped = $this->stripSharedCurrentInstanceNamePrefix($name);
        if ($stripped !== $name) {
            return $stripped;
        }

        $deviceName = trim((string)($entity['device_name'] ?? ''));
        if ($deviceName !== '' && str_starts_with($name, $deviceName . ' ')) {
            return trim(substr($name, strlen($deviceName) + 1));
        }

        // zigbee2mqtt & Co.: der Name der primären Entität entspricht dem Gerätenamen, ist aber
        // anders formatiert (z. B. "Buero/Beleuchtung/Test" → "Buero beleuchtung test"). Ein
        // slug-identischer Name gilt als "kein eigener Name" → Domänen-Fallback (z. B. "Status").
        if ($deviceName !== '' && $this->normalizeSharedIdentFragment($name) === $this->normalizeSharedIdentFragment($deviceName)) {
            return '';
        }

        return $name;
    }

    // Append HA's entity_id dedup number ONLY when another entity in the same instance actually
    // shares this base name. A trailing _2/_3 on the entity_id alone is just HA's global
    // slug-uniqueness (e.g. identical devices) and must not leak into the variable name.
    private function disambiguateSharedEntityName(string $baseName, array $entity): string
    {
        $count = $this->sharedEntityBaseNameCounts[$baseName] ?? 0;
        if ($count < 2) {
            return $baseName;
        }

        $numbered = $this->appendHaDeduplicationSuffix($baseName, $entity);
        if ($numbered !== $baseName) {
            return $numbered;
        }

        // Gleicher Name bei Entitäten verschiedener Art: Die HA-Nummer fehlt (andere Domäne, anderer
        // Slug), unterscheiden kann nur die Art. Gleichartige Entitäten bleiben wie bisher.
        $domain = $this->getSharedEntityDomainForNaming($entity);
        $sameDomain = $this->sharedEntityBaseNameCounts[self::SHARED_DOMAIN_NAME_COUNT_PREFIX . $domain . "\0" . $baseName] ?? 0;
        $kind = self::SHARED_DOMAIN_KIND_CAPTIONS[$domain] ?? null;
        if ($sameDomain >= $count || $kind === null) {
            return $baseName;
        }
        return $baseName . ' (' . $this->Translate($kind) . ')';
    }

    // Counts how often each (non-empty) base name occurs across the instance's entities.
    // Consumed by disambiguateSharedEntityName to detect genuine name collisions. Recomputed on
    // every applySharedEntityIdents() pass, i.e. whenever variables are (re)created.
    private function rebuildSharedEntityBaseNameCounts(array $entities): void
    {
        $counts = [];
        foreach ($entities as $entity) {
            if (!is_array($entity)) {
                continue;
            }
            $domain = $this->getSharedEntityDomainForNaming($entity);
            $this->incrementSharedCount($counts, self::SHARED_DOMAIN_COUNT_PREFIX . $domain);
            $baseName = $this->getSharedEntityBaseName($entity);
            if ($baseName === '') {
                $ownName = $this->getSharedOwnNameFallback($entity);
                if ($ownName !== null) {
                    $this->incrementSharedCount($counts, self::SHARED_OWN_NAME_COUNT_PREFIX . $ownName);
                }
                $rawName = trim((string)($entity['name'] ?? ''));
                if ($rawName !== '') {
                    $this->incrementSharedCount($counts, self::SHARED_RAW_NAME_COUNT_PREFIX . $rawName);
                }
                continue;
            }
            $this->incrementSharedCount($counts, $baseName);
            $this->incrementSharedCount($counts, self::SHARED_DOMAIN_NAME_COUNT_PREFIX . $domain . "\0" . $baseName);
        }

        $this->sharedEntityBaseNameCounts = $counts;
    }

    /**
     * Eine eben angelegte Zusatzvariable (Attribut, Aktion, Ein/Aus …) bekommt den Entitätsnamen
     * vorangestellt, wenn ihr Name allein nicht eindeutig ist: Die Instanz hat mehrere Entitäten
     * derselben Art (Tesla: zwei Klima-Entitäten, je „Isttemperatur"), oder eine andere Variable der
     * Instanz trägt den Namen schon („Titel" von Mediaplayer und Update). Hauptvariablen benennt
     * buildSharedEntityVariableName; Variablen ohne zugehörige Entität bleiben unberührt.
     */
    protected function scopeCreatedEntityVariableName(string $ident, string $name): void
    {
        if (!property_exists($this, 'entities') || !is_array($this->entities)) {
            return;
        }
        $owner = null;
        $ownerPrefixLength = -1;
        foreach ($this->entities as $entity) {
            if (!is_array($entity) || $ident === (string)($entity['ident'] ?? '')) {
                continue;
            }
            $prefix = (string)($entity['ident_prefix'] ?? '');
            if ($prefix !== '' && str_starts_with($ident, $prefix . '_') && strlen($prefix) > $ownerPrefixLength) {
                $owner = $entity;
                $ownerPrefixLength = strlen($prefix);
            }
        }
        if ($owner === null) {
            return;
        }

        $entityName = $this->getSharedEntityName($owner);
        if ($entityName === '' || str_starts_with($name, $entityName)) {
            return;
        }

        $variableId = @$this->GetIDForIdent($ident);
        if ($variableId === false) {
            return;
        }
        $ambiguous = $this->getSharedDomainEntityCount($this->getSharedEntityDomainForNaming($owner)) > 1
            || array_any(
                IPS_GetChildrenIDs($this->InstanceID),
                static fn(int $childId): bool => $childId !== $variableId && IPS_GetName($childId) === $name
            );
        if ($ambiguous) {
            IPS_SetName($variableId, $entityName . ' ' . $name);
        }
    }

    private function incrementSharedCount(array &$counts, string $key): void
    {
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }

    private function getSharedEntityDomainForNaming(array $entity): string
    {
        return HADomainCatalog::normalizeDomainAlias((string)($entity['domain'] ?? $entity['component'] ?? ''));
    }

    // Anzahl der Entitäten dieser Domäne in der Instanz (Stand des letzten applySharedEntityIdents).
    private function getSharedDomainEntityCount(string $domain): int
    {
        return $this->sharedEntityBaseNameCounts[self::SHARED_DOMAIN_COUNT_PREFIX . HADomainCatalog::normalizeDomainAlias($domain)] ?? 0;
    }

    // HA appends _2, _3, ... to entity_ids when multiple entities share the same name.
    // The entity name field is not updated. We detect this and append the number to the variable name.
    private function appendHaDeduplicationSuffix(string $name, array $entity): string
    {
        if ($name === '') {
            return $name;
        }
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

        // Slug-tolerant: nur anders formatierter Instanzname (z. B. Slashes vs. Spaces) zählt
        // ebenfalls als "kein eigener Name" → Domänen-Fallback greift.
        if ($this->normalizeSharedIdentFragment($name) === $this->normalizeSharedIdentFragment($instanceName)) {
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
