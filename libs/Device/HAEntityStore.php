<?php

declare(strict_types=1);

trait HAEntityStoreTrait
{
    private function isManagedEntityId(string $entityId): bool
    {
        if (isset($this->entities[$entityId]) && (($this->entities[$entityId]['create_var'] ?? true) !== false)) {
            return true;
        }

        foreach ($this->getConfiguredEntities(__FUNCTION__) as $row) {
            if (($row['entity_id'] ?? '') === $entityId) {
                return true;
            }
        }

        return false;
    }

    private function getEntityDomain(string $entityId): string
    {
        $domain = $this->entities[$entityId]['domain'] ?? null;
        if ($domain === null && str_contains($entityId, '.')) {
            [$domain] = explode('.', $entityId, 2);
        }
        return $domain ?? '';
    }

    private function getEntityIdByIdent(string $ident): ?string
    {
        return array_find_key($this->entities, fn($_data, $id) => $this->sanitizeIdent($id) === $ident);
    }

    private function findEntityByIdent(string $ident): ?array
    {
        $entityId = $this->getEntityIdByIdent($ident);
        if ($entityId !== null && isset($this->entities[$entityId])) {
            $entity              = $this->entities[$entityId];
            $entity['entity_id'] ??= $entityId;
            return $entity;
        }

        foreach ($this->getConfiguredEntities(__FUNCTION__) as $row) {
            if ($this->sanitizeIdent($row['entity_id']) !== $ident) {
                continue;
            }
            return $row;
        }
        return null;
    }

    private function findEntityByIdentSuffix(string $ident, string $suffix, string $domain): ?array
    {
        foreach ($this->entities as $entityId => $entity) {
            if (($entity['domain'] ?? '') !== $domain) {
                continue;
            }
            if ($this->sanitizeIdent($entityId) . $suffix === $ident) {
                $entity['entity_id'] ??= $entityId;
                return $entity;
            }
        }

        if (!str_ends_with($ident, $suffix)) {
            return null;
        }
        $baseIdent = substr($ident, 0, -strlen($suffix));
        if ($baseIdent === '') {
            return null;
        }

        $entity = $this->findEntityByIdent($baseIdent);
        if ($entity !== null && ($entity['domain'] ?? '') === $domain) {
            return $entity;
        }

        return null;
    }

    // Runtime-Entities erhalten bei Bedarf nur Minimalmetadaten.
    private function ensureStoredEntity(string $entityId): void
    {
        if (isset($this->entities[$entityId])) {
            return;
        }

        $this->entities[$entityId] = [
            'entity_id' => $entityId,
            'domain'    => $this->getEntityDomain($entityId),
            'name'      => $entityId
        ];
    }

    private function getStoredEntityAttributes(string $entityId): array
    {
        $attributes = $this->entities[$entityId]['attributes'] ?? [];
        return is_array($attributes) ? $attributes : [];
    }

    private function storeEntityAttributes(string $entityId, array $attributes): array
    {
        $this->ensureStoredEntity($entityId);

        $domain = $this->getEntityDomain($entityId);
        if ($domain !== '') {
            $attributes = $this->filterAttributesByDomain($domain, $attributes, __FUNCTION__);
        }

        $existing = $this->getStoredEntityAttributes($entityId);
        $this->entities[$entityId]['attributes'] = array_merge($existing, $attributes);
        return $attributes;
    }

    private function storeEntityAttribute(string $entityId, string $attribute, mixed $value): void
    {
        $this->ensureStoredEntity($entityId);
        $existing = $this->getStoredEntityAttributes($entityId);
        $merged = $existing;
        $merged[$attribute] = $value;
        $this->storeEntityAttributes($entityId, $merged);
    }

    // Der State-Cache wird im Store zentral gelesen und geschrieben.
    private function readEntityStateCache(): array
    {
        $raw = $this->ReadAttributeString('EntityStateCache');
        if ($raw === '') {
            return [];
        }

        try {
            $cache = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($cache) ? $cache : [];
    }

    private function writeEntityStateCache(array $cache): void
    {
        $this->WriteAttributeString('EntityStateCache', json_encode($cache, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->updateDiagnosticsLabels();
    }

    private function getEntityStateCacheEntry(string $entityId, ?array $cache = null): array
    {
        $cache ??= $this->readEntityStateCache();
        $entry = $cache[$entityId] ?? [];
        return is_array($entry) ? $entry : [];
    }

    private function getCachedEntityStringValue(string $entityId, string $key): ?string
    {
        $entry = $this->getEntityStateCacheEntry($entityId);
        $value = $entry[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }

    private function updateEntityCache(string $entityId, mixed $state, ?array $attributes): void
    {
        $cache = $this->readEntityStateCache();
        $entry = $this->getEntityStateCacheEntry($entityId, $cache);
        if ($state !== null) {
            $entry[self::KEY_STATE] = $state;
        }
        if (is_array($attributes)) {
            $existing = isset($entry[self::KEY_ATTRIBUTES]) && is_array($entry[self::KEY_ATTRIBUTES]) ? $entry[self::KEY_ATTRIBUTES] : [];
            $entry[self::KEY_ATTRIBUTES] = array_merge($existing, $attributes);
        }
        $entry['ts'] = time();
        $cache[$entityId] = $entry;

        $this->writeEntityStateCache($cache);
    }

    private function updateEntityRawStateCache(string $entityId, mixed $rawState): void
    {
        $cache = $this->readEntityStateCache();
        $entry = $this->getEntityStateCacheEntry($entityId, $cache);
        if (is_string($rawState) && trim($rawState) !== '') {
            $entry['raw_state'] = $rawState;
        }
        $entry['ts'] = time();
        $cache[$entityId] = $entry;

        $this->writeEntityStateCache($cache);
    }

    private function getEntityMainVariablePosition(array $entity, string $domain): int
    {
        $entityId = (string)($entity['entity_id'] ?? '');
        $position = $this->getEntityPosition($entityId);
        if ($domain === HAMediaPlayerDefinitions::DOMAIN) {
            return $this->getMediaPlayerOrderPosition(0, 'status');
        }

        $linkedPosition = $this->getMediaPlayerLinkedPosition($entityId, $domain);
        if ($linkedPosition !== null) {
            return $linkedPosition;
        }

        return $position;
    }

    // Initiale Anlage und Refresh teilen sich einen Pfad für Hauptvariable und Domain-Extras.
    private function syncEntityPresentation(array $entity, bool $initializeDescriptorValue = false): void
    {
        $entityId = (string)($entity['entity_id'] ?? '');
        if ($entityId === '') {
            return;
        }

        $domain = (string)($entity['domain'] ?? $this->getEntityDomain($entityId));
        if ($domain === '') {
            return;
        }

        $ident = $this->sanitizeIdent($entityId);
        $exists = @$this->GetIDForIdent($ident) !== false;
        $type = $this->getVariableType($domain, $entity['attributes'] ?? []);
        $presentation = $this->getEntityPresentation($domain, $entity, $type);
        $position = $this->getEntityMainVariablePosition($entity, $domain);
        $name = $this->getEntityVariableName($domain, $entity);

        $this->MaintainVariable($ident, $name, $type, $presentation, $position, true);
        if ($initializeDescriptorValue) {
            $descriptor = $this->describeEntityMainVariable($entity);
            $this->initializeVariableDescriptorValue($ident, $descriptor, $exists);
        }

        if (!$exists || $this->shouldApplyDomainActionStateOnExisting($domain)) {
            $this->applyDomainActionState($domain, $ident, $entity);
        }
        $this->applyDomainExtraMaintenance($domain, $entity);
    }

    private function updateEntityPresentation(string $entityId, array $attributes): void
    {
        if (!isset($this->entities[$entityId])) {
            return;
        }

        $domain = $this->entities[$entityId]['domain'] ?? $this->getEntityDomain($entityId);
        if ($domain === '') {
            return;
        }

        $ident = $this->sanitizeIdent($entityId);
        if (@$this->GetIDForIdent($ident) === false) {
            return;
        }

        $existing = $this->getStoredEntityAttributes($entityId);
        $mergedAttributes = array_merge($existing, $attributes);
        $cachedAttributes = $this->getCachedEntityAttributes($entityId);
        if ($cachedAttributes !== []) {
            $mergedAttributes = array_merge($mergedAttributes, $cachedAttributes);
        }
        $this->entities[$entityId]['attributes'] = $mergedAttributes;
        $entity = $this->entities[$entityId];
        $entity['attributes'] = $mergedAttributes;
        $this->syncEntityPresentation($entity);
    }

    private function getCachedEntityAttributes(string $entityId): array
    {
        $entry = $this->getEntityStateCacheEntry($entityId);
        $attrs = $entry[self::KEY_ATTRIBUTES] ?? null;
        return is_array($attrs) ? $attrs : [];
    }

    // Der State-Cache dient als Fallback bei partiellen MQTT-Updates.
    private function getCachedEntityState(string $entityId): ?string
    {
        return $this->getCachedEntityStringValue($entityId, self::KEY_STATE);
    }

    private function getCachedEntityRawState(string $entityId): ?string
    {
        return $this->getCachedEntityStringValue($entityId, 'raw_state');
    }

    private function updateAvailabilityValue(string $entityId, mixed $rawState): void
    {
        if (!is_string($rawState) || trim($rawState) === '') {
            return;
        }

        $this->updateUnavailableEntitiesJsonVariable();
    }

    private function shouldShowUnavailableEntitiesJson(): bool
    {
        return @$this->ReadPropertyBoolean(self::PROP_SHOW_UNAVAILABLE_ENTITIES_JSON);
    }

    private function getUnavailableEntitiesJsonIdent(): string
    {
        return self::UNAVAILABLE_ENTITIES_JSON_IDENT;
    }

    private function maintainUnavailableEntitiesJsonVariable(): void
    {
        $ident = $this->getUnavailableEntitiesJsonIdent();
        if (!$this->shouldShowUnavailableEntitiesJson()) {
            $this->MaintainVariable(
                $ident,
                $this->Translate('Unavailable entities JSON'),
                VARIABLETYPE_STRING,
                ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION],
                10000,
                false
            );
            return;
        }

        $this->MaintainVariable(
            $ident,
            $this->Translate('Unavailable entities JSON'),
            VARIABLETYPE_STRING,
            ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION],
            10000,
            true
        );
    }

    private function updateUnavailableEntitiesJsonVariable(): void
    {
        if (!$this->shouldShowUnavailableEntitiesJson()) {
            return;
        }

        $jsonIdent = $this->getUnavailableEntitiesJsonIdent();
        if (@$this->GetIDForIdent($jsonIdent) === false) {
            return;
        }

        $entries = [];
        foreach ($this->entities as $entityId => $entity) {
            if (($entity['create_var'] ?? true) === false) {
                continue;
            }

            $rawState = $this->getCachedEntityRawState($entityId) ?? $this->getCachedEntityState($entityId);
            // Die Expertenliste zeigt nur auffällige Zustände statt aller Entities.
            if (!is_string($rawState) || !$this->isIndeterminateEntityState($rawState)) {
                continue;
            }

            $entityIdent = $this->sanitizeIdent($entityId);
            $objectId = @$this->GetIDForIdent($entityIdent);
            if ($objectId === false) {
                continue;
            }

            $entries[$entityId] = [
                'entity_id'    => (int)$objectId,
                'state'        => $rawState,
                'available'    => !$this->isUnavailableEntityState($rawState)
            ];
        }

        $this->setValueWithDebug(
            $jsonIdent,
            json_encode($entries, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    private function updateDiagnosticsLabels(): void
    {
        $this->updateLastMqttLabel();
        $this->updateLastRestFetchLabel();

        $count = count($this->entities);
        $this->updateFormFieldSafe('DiagEntityCount', 'caption', 'Entitäten (aktiv): ' . $count);
    }
}
