<?php

declare(strict_types=1);

trait HAEntityStoreTrait
{
    protected function isManagedEntityId(string $entityId): bool
    {
        if (isset($this->entities[$entityId]) && (($this->entities[$entityId]['create_var'] ?? true) !== false)) {
            return true;
        }

        return array_any(
            $this->getConfiguredEntities(__FUNCTION__),
            static fn(array $row): bool => ($row['entity_id'] ?? '') === $entityId
        );
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
        return $this->getSharedEntityIdByMainIdent($ident);
    }

    private function findEntityByIdent(string $ident): ?array
    {
        $entityId = $this->getEntityIdByIdent($ident);
        if ($entityId !== null && isset($this->entities[$entityId])) {
            $entity              = $this->entities[$entityId];
            $entity['entity_id'] ??= $entityId;
            return $entity;
        }

        return $this->findSharedConfiguredEntityByMainIdent($ident);
    }

    protected function findEntityByIdentSuffix(string $ident, string $suffix, string $domain): ?array
    {
        foreach ($this->entities as $entityId => $entity) {
            if (($entity['domain'] ?? '') !== $domain) {
                continue;
            }
            if ($this->buildSharedSuffixIdent($entityId, $suffix) === $ident) {
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

        $entityId = $this->getSharedEntityIdByPrefix($baseIdent);
        $entity = $entityId !== null && isset($this->entities[$entityId]) ? $this->entities[$entityId] : $this->findSharedConfiguredEntityByPrefix($baseIdent);
        if ($entity !== null && ($entity['domain'] ?? '') === $domain) {
            $entity['entity_id'] ??= $entityId;
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
            $attributes = $this->filterAttributesByDomain($domain, $attributes);
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
        return $linkedPosition ?? $position;
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

        $ident = $this->getSharedEntityMainIdent($entityId);
        $type = $this->getVariableType($domain, $entity['attributes'] ?? []);
        $existingId = @$this->GetIDForIdent($ident);
        $exists = $existingId !== false;
        $wasLegacy = $exists && str_ends_with(
            trim((string)(IPS_GetObject((int)$existingId)['ObjectName'] ?? '')),
            $this->getLegacyNameSuffix()
        );
        $presentation = $this->getEntityPresentation($domain, $entity, $type);
        $position = $this->getEntityMainVariablePosition($entity, $domain);
        $name = $this->getEntityVariableName($domain, $entity);

        $this->MaintainVariable($ident, $name, $type, $presentation, $position, true);
        if ($wasLegacy) {
            IPS_SetName($this->GetIDForIdent($ident), $name);
        }
        if ($initializeDescriptorValue) {
            $descriptor = $this->describeEntityMainVariable($entity);
            $this->initializeVariableDescriptorValue($ident, $descriptor, $exists);
        }

        if (!$exists || $wasLegacy || $this->shouldApplyDomainActionStateOnExisting($domain)) {
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

        $ident = $this->getSharedEntityMainIdent($entityId);
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
        $this->refreshEntityMainValueFromCache($entityId, $entity, $mergedAttributes);
    }

    private function refreshEntityMainValueFromCache(string $entityId, array $entity, array $attributes): void
    {
        $rawState = $this->getCachedEntityRawState($entityId) ?? $this->getCachedEntityState($entityId);
        if ($rawState === null || trim($rawState) === '') {
            return;
        }

        $this->replayEntityMainValue($entityId, $entity, $attributes, $rawState);
    }

    protected function replayEntityMainValue(string $entityId, array $entity, array $attributes, string $rawState): void
    {
        $domain = (string)($entity['domain'] ?? $this->getEntityDomain($entityId));
        if ($domain === '') {
            return;
        }

        $ident = $this->getSharedEntityMainIdent($entityId);
        if (@$this->GetIDForIdent($ident) === false) {
            return;
        }

        $descriptor = $this->describeVariableByIdent($ident, $domain);
        if ($this->isTriggerVariableDescriptor($descriptor)) {
            return;
        }

        $finalValue = $this->convertValueByDomain($domain, $rawState, $attributes);
        $this->setEntityMainValue($entityId, $ident, $finalValue, $rawState);
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

    private function updateAvailabilityValue(mixed $rawState): void
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

            $entityIdent = $this->getSharedEntityMainIdent($entityId);
            $objectId = @$this->GetIDForIdent($entityIdent);
            if ($objectId === false) {
                continue;
            }

            $entries[$entityId] = [
                'entity_id'    => $objectId,
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
        $this->updateFormFieldSafe('DiagEntityCount', 'caption', sprintf($this->Translate('Entities (active): %d'), $count));
    }
}
