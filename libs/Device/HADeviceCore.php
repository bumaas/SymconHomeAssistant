<?php

declare(strict_types=1);

/**
 * Trait for shared device logic between HomeAssistantDevice and HomeAssistantEntity
 */
trait HADeviceCoreTrait
{
    private array $topicMapping = [];
    private array $entities = [];
    private bool $hasMultipleStatusEntities = false;

    protected function hasMediaPlayerEntities(): bool
    {
        foreach ($this->entities as $entityId => $entity) {
            $domain = $entity['domain'] ?? $this->getEntityDomain($entityId);
            if ($domain !== HAMediaPlayerDefinitions::DOMAIN) {
                continue;
            }
            if (($entity['create_var'] ?? true) === false) {
                continue;
            }
            return true;
        }
        return false;
    }

    public function UpdateMediaPlayerProgress(): void
    {
        $cache = $this->readEntityStateCache();
        if ($cache === []) {
            return;
        }

        $now = time();
        foreach ($cache as $entityId => $entry) {
            if ($this->getEntityDomain($entityId) !== HAMediaPlayerDefinitions::DOMAIN) {
                continue;
            }

            if (!$this->isMediaPlayerPlaying($entry)) {
                continue;
            }

            $attributes = $this->getMediaPlayerProgressAttributes($entityId, $entry);
            if ($attributes === null) {
                continue;
            }

            $base = $this->getMediaPlayerProgressBase($attributes, $entry);
            if ($base === null) {
                continue;
            }

            $position = $this->computeMediaPlayerProgressPosition($base['base_position'], $base['updated_at_ts'], $attributes, $now);
            $ident = $this->buildSharedSuffixIdent($entityId, '_media_position');
            if (!$this->shouldUpdateMediaPlayerPosition($ident, $position)) {
                continue;
            }
            $this->setValueWithDebug($ident, $position);
        }
    }

    protected function isMediaPlayerPlaying(array $entry): bool
    {
        $state = strtolower((string)($entry[self::KEY_STATE] ?? ''));
        return $state === 'playing';
    }

    protected function getMediaPlayerProgressAttributes(string $entityId, array $entry): ?array
    {
        $attributes = [];
        $stored = $this->entities[$entityId]['attributes'] ?? null;
        if (is_array($stored)) {
            $attributes = $stored;
        }
        if (isset($entry[self::KEY_ATTRIBUTES]) && is_array($entry[self::KEY_ATTRIBUTES])) {
            $attributes = array_merge($attributes, $entry[self::KEY_ATTRIBUTES]);
        }

        $basePosition = $attributes['media_position'] ?? null;
        if (!is_numeric($basePosition)) {
            return null;
        }

        return $attributes;
    }

    protected function getMediaPlayerProgressBase(array $attributes, array $entry): ?array
    {
        $updatedAt = $attributes['media_position_updated_at'] ?? null;
        $updatedAtTs = $this->parseMediaPositionUpdatedAt($updatedAt);
        if ($updatedAtTs === null) {
            $updatedAtTs = is_numeric($entry['ts'] ?? null) ? (int)$entry['ts'] : null;
        }
        if ($updatedAtTs === null) {
            return null;
        }

        return [
            'base_position' => (float)$attributes['media_position'],
            'updated_at_ts' => $updatedAtTs
        ];
    }

    protected function computeMediaPlayerProgressPosition(
        float $basePosition,
        int $updatedAtTs,
        array $attributes,
        int $now
    ): int {
        $elapsed = max(0, $now - $updatedAtTs);
        $position = (int)max(0, $basePosition + $elapsed);
        $duration = $attributes['media_duration'] ?? null;
        if (is_numeric($duration)) {
            $position = min($position, (int)$duration);
        }
        return $position;
    }

    protected function shouldUpdateMediaPlayerPosition(string $ident, int $position): bool
    {
        if (@$this->GetIDForIdent($ident) === false) {
            return false;
        }

        $current = $this->GetValue($ident);
        return !(is_int($current) && $current === $position);
    }

    protected function parseMediaPositionUpdatedAt(mixed $value): ?int
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $dt = new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }

        return $dt->getTimestamp();
    }

    protected function initializeStatesFromHa(array $configData): void
    {
        if (!$this->hasActiveParent()) {
            return;
        }

        foreach ($configData as $row) {
            $entity = $this->normalizeEntityStructure($row);
            if ($entity === null || !($entity['create_var'] ?? true)) {
                continue;
            }

            $entityId = $entity['entity_id'] ?? '';
            if ($entityId === '') {
                continue;
            }

            $state = $this->requestHaState($entityId);
            if (is_array($state)) {
                $this->applyInitialState($entityId, $state);
            }
        }
    }

    protected function applyInitialStatesFromMap(array $configData, array $stateMap): void
    {
        foreach ($configData as $entity) {
            $entityId = $entity['entity_id'];
            if (isset($stateMap[$entityId])) {
                $this->applyParsedEntityState($entityId, $stateMap[$entityId], 'Initialisierung (Map)');
            }
        }
    }

    public function ReceiveData($JSONString): string
    {
        $this->debugExpert(__FUNCTION__, 'MQTT Payload empfangen', ['Payload' => $JSONString]);
        $this->WriteAttributeString('LastMQTTMessage', $JSONString);

        $data = json_decode($JSONString, true);
        if (!is_array($data)) {
            return '';
        }

        $topic = trim((string)($data['Topic'] ?? ''));
        if ($topic === '') {
            return '';
        }

        $payload = $this->decodeIncomingMqttPayload((string)($data['Payload'] ?? ''));
        $this->debugExpert(__FUNCTION__, 'Eingangsdaten', ['Topic' => $topic, 'Payload' => $payload]);

        if ($this->tryHandleStateFromTopic($topic, $payload)) {
            return '';
        }

        $entityId = $this->topicMapping[$topic] ?? null;
        if ($entityId === null) {
            return '';
        }

        $this->applyParsedEntityState($entityId, $this->parseEntityPayload($payload), 'MQTT Update');

        return '';
    }

    protected function decodeIncomingMqttPayload(string $payload): string
    {
        $trimmed = trim($payload);
        if ($trimmed === '') {
            return '';
        }

        $firstChar = $trimmed[0];
        if ($firstChar === '{' || $firstChar === '[' || $firstChar === '"') {
            return $payload;
        }

        if ((strlen($payload) % 2) === 0 && ctype_xdigit($payload)) {
            $decoded = hex2bin($payload);
            if ($decoded !== false) {
                return $decoded;
            }
        }

        return $payload;
    }
    public function RequestAction($Ident, $Value): void
    {
        $this->debugExpert(__FUNCTION__, 'Input', ['Ident' => $Ident, 'Value' => $Value], true);

        if ($this->handleLockAction($Ident, $Value)) {
            return;
        }
        if ($this->handleCoverAction($Ident, $Value)) {
            return;
        }
        if ($this->handleCoverTiltAction($Ident, $Value)) {
            return;
        }
        if ($this->handleValveAction($Ident, $Value)) {
            return;
        }
        if ($this->handleVacuumAction($Ident, $Value)) {
            return;
        }
        if ($this->handleVacuumFanSpeedAction($Ident, $Value)) {
            return;
        }
        if ($this->handleLawnMowerAction($Ident, $Value)) {
            return;
        }
        if ($this->handleCameraPowerAction($Ident, $Value)) {
            return;
        }
        if ($this->handleMediaPlayerPowerAction($Ident, $Value)) {
            return;
        }
        if ($this->handleMediaPlayerAction($Ident, $Value)) {
            return;
        }
        if ($this->handleClimatePowerAction($Ident, $Value)) {
            return;
        }

        $entity = $this->findEntityByIdent($Ident);
        if ($entity !== null && !empty($entity['entity_id'])) {
            $entityId = $entity['entity_id'];
            $domain = $entity['domain'] ?? null;
            if ($domain === null && str_contains($entityId, '.')) {
                [$domain] = explode('.', $entityId, 2);
            }
            $this->debugExpert('RequestAction', 'Entity aufgelöst', ['EntityID' => $entityId, 'Domain' => $domain]);

            if (!$this->isEntityWritable($domain ?? '', $entity['attributes'] ?? [])) {
                $this->debugExpert('RequestAction', 'Variable ist nicht schreibbar', ['EntityID' => $entityId], true);
                return;
            }

            $mqttPayload = $this->formatPayloadForMqtt($domain ?? '', $Value, $entity['attributes'] ?? []);
            if ($mqttPayload === '') {
                $this->debugExpert('RequestAction', 'Payload leer', ['Domain' => $domain, 'Value' => $Value], true);
                return;
            }
            $this->debugExpert('RequestAction', 'Payload formatiert', ['Payload' => $mqttPayload]);

            if ($this->trySendMainEntityValueViaRest($entityId, (string)($domain ?? ''), $mqttPayload, $Ident, $entity['attributes'] ?? [])) {
                return;
            }

            $topic = $this->getSetTopicForEntity($entityId);
            if ($topic === '') {
                return;
            }
            $this->debugExpert(__FUNCTION__, 'MQTT publish | Topic=' . $topic . ' | Payload=' . $mqttPayload, [], true);

            $this->sendMqttMessage($topic, $mqttPayload);
            $optimisticValue = $this->convertValueByDomain($domain ?? '', (string)$mqttPayload, $entity['attributes'] ?? []);
            $this->setEntityMainValue($entityId, $Ident, $optimisticValue, (string)$mqttPayload);
            $this->updateEntityRawStateCache($entityId, (string)$mqttPayload);
            $this->updateEntityCache($entityId, (string)$mqttPayload, null);
            $this->resetVariableByDescriptor($Ident, $this->describeVariableByIdent($Ident, $domain));
            return;
        }

        $attributeInfo = $this->resolveAttributeByIdent($Ident);
        if ($attributeInfo === null) {
            $this->debugExpert(__FUNCTION__, 'Entity/Attribut nicht gefunden', ['Ident' => $Ident], true);
            return;
        }

        $entityId = $attributeInfo['entity_id'];
        $attribute = $attributeInfo['attribute'];

        $payload = $this->buildDomainAttributePayload($attributeInfo['domain'], $attribute, $Value);
        if ($payload === '' && !HADomainCatalog::supportsAttributePayload($attributeInfo['domain'])) {
            $this->debugExpert(__FUNCTION__, 'Attribut-Domain nicht unterstützt', ['Attribute' => $attribute, 'Domain' => $attributeInfo['domain']], true);
            return;
        }
        if ($payload === '') {
            $this->debugExpert(__FUNCTION__, 'Attribut Payload leer', ['Attribute' => $attribute], true);
            return;
        }
        $topic = $this->getSetTopicForEntity($entityId);
        if ($topic === '') {
            $this->debugExpert('Action', 'Kein Set-Topic für Entity | EntityID=' . $entityId, [], true);
            return;
        }
        $this->debugExpert(__FUNCTION__, 'MQTT publish | Topic=' . $topic . ' | Payload=' . $payload, [], true);
        $this->sendMqttMessage($topic, $payload);
        if ($attributeInfo['domain'] === HAClimateDefinitions::DOMAIN && $attribute === HAClimateDefinitions::ATTRIBUTE_HVAC_MODE) {
            $hvacMode = trim((string)$Value);
            if ($hvacMode !== '') {
                $this->storeEntityAttribute($entityId, HAClimateDefinitions::ATTRIBUTE_HVAC_MODE, $hvacMode);
                $this->updateEntityCache($entityId, null, [HAClimateDefinitions::ATTRIBUTE_HVAC_MODE => $hvacMode]);
                $this->setValueWithDebug($Ident, $hvacMode);
            }
        }
    }

    protected function trySendMainEntityValueViaRest(
        string $entityId,
        string $domain,
        string $formattedPayload,
        string $ident,
        mixed $attributes
    ): bool {
        $normalizedDomain = $this->normalizeDomainAlias($domain);
        if ($normalizedDomain !== HANumberDefinitions::DOMAIN) {
            return false;
        }

        [$service, $data] = HANumberDefinitions::buildRestServicePayload($formattedPayload);
        if ($service === '') {
            $this->debugExpert(__FUNCTION__, 'REST-Payload leer', [
                'EntityID' => $entityId,
                'Domain' => $domain,
                'Payload' => $formattedPayload
            ], true);
            return true;
        }

        $serviceDomain = $domain !== '' ? $domain : $normalizedDomain;
        $requestData = array_merge(['entity_id' => $entityId], $data);
        if (!$this->sendServiceRequestToParent($serviceDomain, $service, $requestData)) {
            $this->debugExpert(__FUNCTION__, 'REST-Service fehlgeschlagen', [
                'EntityID' => $entityId,
                'Domain' => $serviceDomain,
                'Service' => $service,
                'Data' => $requestData
            ], true);
            return true;
        }

        $this->debugExpert(__FUNCTION__, 'REST-Service gesendet', [
            'EntityID' => $entityId,
            'Domain' => $serviceDomain,
            'Service' => $service,
            'Data' => $requestData
        ], true);

        $optimisticValue = $this->convertValueByDomain($domain, $formattedPayload, is_array($attributes) ? $attributes : []);
        $this->setEntityMainValue($entityId, $ident, $optimisticValue, $formattedPayload);
        $this->updateEntityRawStateCache($entityId, $formattedPayload);
        $this->updateEntityCache($entityId, $formattedPayload, null);
        $this->resetVariableByDescriptor($ident, $this->describeVariableByIdent($ident, $domain));
        return true;
    }

    protected function processEntities(array $configData, string $baseTopic): array
    {
        $previousEntities      = $this->entities;
        $this->entities        = [];
        $this->topicMapping    = [];
        $this->rebuildSharedEntityIdentIndexes();
        $filterTopics          = [];
        $positionIndex         = 0;
        $this->hasMultipleStatusEntities = $this->countStatusEntities($configData) > 1;
        $entities = [];
        $renamedEntityIds = [];

        foreach ($configData as $row) {
            $entity = $this->normalizeEntityStructure($row);
            if ($entity === null || !($entity['create_var'] ?? true)) {
                continue;
            }
            if (($entity['domain'] ?? '') === '') {
                $this->debugExpert('processEntities', 'Entity ohne Domain', $entity);
                continue;
            }

            $entities[] = $entity;
        }

        $entities = $this->applySharedEntityIdents($entities);
        foreach ($entities as $entity) {
            $positionIndex++;
            $basePosition                                = $positionIndex * 10;
            $entity['position_base']                     = $basePosition;
            if (isset($previousEntities[$entity['entity_id']]['attributes'])
                && is_array($previousEntities[$entity['entity_id']]['attributes'])) {
                $existingAttributes = $previousEntities[$entity['entity_id']]['attributes'];
                if (!isset($entity['attributes']) || !is_array($entity['attributes'])) {
                    $entity['attributes'] = $existingAttributes;
                } else {
                    $entity['attributes'] = array_merge($existingAttributes, $entity['attributes']);
                }
            }
            $this->entities[$entity['entity_id']]        = $entity;
            if ($this->hasSharedManagedIdentChanged($previousEntities[$entity['entity_id']] ?? null, $entity)) {
                $renamedEntityIds[] = $entity['entity_id'];
            }
            $this->debugExpert('processEntities', 'Entity registriert', ['EntityID' => $entity['entity_id'], 'Domain' => $entity['domain'] ?? null]);

            $this->maintainEntityVariable($entity);

            if ($baseTopic !== '') {
                $stateTopic                      = $this->deriveStateTopic($baseTopic, $entity['entity_id']);
                $this->topicMapping[$stateTopic] = $entity['entity_id'];
                $entityPrefix                    = $this->deriveEntityTopicPrefix($baseTopic, $entity['entity_id']);
                $filterTopics[]                  = $entityPrefix;
                $this->debugExpert('processEntities', 'Topic Mapping', ['StateTopic' => $stateTopic, 'Prefix' => $entityPrefix]);
            }
        }
        $this->rebuildSharedEntityIdentIndexes();
        $this->cleanupRenamedSharedEntityObjects($renamedEntityIds, array_keys($this->entities), $previousEntities);
        return $filterTopics;
    }

    private function cleanupRenamedSharedEntityObjects(array $entityIds, array $activeEntityIds, array $previousEntities): void
    {
        if ($entityIds === []) {
            return;
        }

        $entityIds = array_values(array_unique(array_filter(
            $entityIds,
            static fn(mixed $entityId): bool => is_string($entityId) && trim($entityId) !== ''
        )));
        if ($entityIds === []) {
            return;
        }

        $baseIdents = [];
        foreach ($entityIds as $entityId) {
            $baseIdents[] = $this->sanitizeIdent($entityId);
            $previousIdent = trim((string)($previousEntities[$entityId]['ident'] ?? ''));
            if ($previousIdent !== '') {
                $baseIdents[] = $previousIdent;
            }
            $previousPrefix = trim((string)($previousEntities[$entityId]['ident_prefix'] ?? ''));
            if ($previousPrefix !== '') {
                $baseIdents[] = $previousPrefix;
            }
        }

        $activeBaseIdents = [];
        foreach ($activeEntityIds as $entityId) {
            $activeBaseIdents[] = $this->sanitizeIdent($entityId);
            $activeBaseIdents[] = $this->getSharedEntityIdentPrefix($entityId);
        }

        $baseIdents = array_values(array_unique(array_filter($baseIdents, static fn(string $ident): bool => $ident !== '')));
        $activeBaseIdents = array_values(array_unique(array_filter($activeBaseIdents, static fn(string $ident): bool => $ident !== '')));
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childId) {
            $object = IPS_GetObject($childId);
            $ident = (string)($object['ObjectIdent'] ?? '');
            if ($ident === ''
                || !$this->isSharedManagedEntityIdent($ident, $baseIdents)
                || $this->isSharedManagedEntityIdent($ident, $activeBaseIdents)) {
                continue;
            }

            $objectType = (int)($object['ObjectType'] ?? -1);
            if ($objectType === OBJECTTYPE_VARIABLE) {
                IPS_DeleteVariable($childId);
            } elseif ($objectType === 5) {
                IPS_DeleteMedia($childId);
            }
        }
    }

    private function isSharedManagedEntityIdent(string $ident, array $baseIdents): bool
    {
        foreach ($baseIdents as $baseIdent) {
            if ($ident === $baseIdent || str_starts_with($ident, $baseIdent . '_')) {
                return true;
            }
        }

        return false;
    }

    private function hasSharedManagedIdentChanged(?array $previousEntity, array $currentEntity): bool
    {
        if (!is_array($previousEntity)) {
            return false;
        }

        $previousIdent = trim((string)($previousEntity['ident'] ?? ''));
        $previousPrefix = trim((string)($previousEntity['ident_prefix'] ?? ''));
        $currentIdent = trim((string)($currentEntity['ident'] ?? ''));
        $currentPrefix = trim((string)($currentEntity['ident_prefix'] ?? ''));

        return ($previousIdent !== '' && $previousIdent !== $currentIdent)
            || ($previousPrefix !== '' && $previousPrefix !== $currentPrefix);
    }

    protected function countStatusEntities(array $configData): int
    {
        $count = 0;
        foreach ($configData as $row) {
            $entity = $this->normalizeEntityStructure($row);
            if ($entity === null || !($entity['create_var'] ?? true)) {
                continue;
            }
            $domain = (string)($entity['domain'] ?? '');
            if ($domain === '') {
                continue;
            }
            if ($this->isStatusDomain($domain)) {
                $count++;
                if ($count > 1) {
                    return $count;
                }
            }
        }
        return $count;
    }

    protected function updateReceiveFilter(array $topics): void
    {
        if (count($topics) === 0) {
            $filter = '.*ThisShouldNotMatchAnything.*';
            $this->SetReceiveDataFilter($filter);
            $this->WriteAttributeString('CurrentFilter', $filter);
            $this->updateFormFieldSafe('CURRENT_FILTER', 'caption', 'Aktueller Filter (Regex): ' . $filter);
            return;
        }

        $regexParts = [];
        foreach ($topics as $t) {
            $quoted = preg_quote($t, '/');
            $quoted       = str_replace('\/', '\\\\?\/', $quoted);
            $regexParts[] = $quoted . '(\\\\?\/[^"]*)?';
        }

        $filter = '.*"Topic":"(' . implode('|', $regexParts) . ')".*';
        $this->debugExpert('Filter', 'Setze Filter', ['Regex' => $filter]);
        $this->SetReceiveDataFilter($filter);
        $this->WriteAttributeString('CurrentFilter', $filter);
        $this->updateFormFieldSafe('CURRENT_FILTER', 'caption', 'Aktueller Filter (Regex): ' . $filter);
    }

    protected function maintainEntityVariable(array $entity): void
    {
        $this->syncEntityPresentation($entity, true);
    }

    protected function applyParsedEntityState(string $entityId, array $payload, string $context): void
    {
        $state = (string)($payload[self::KEY_STATE] ?? '');
        $attributes = $payload[self::KEY_ATTRIBUTES] ?? [];

        if (!isset($this->entities[$entityId])) {
            $this->debugExpert(__FUNCTION__, 'Entity nicht konfiguriert', ['EntityID' => $entityId, 'Context' => $context]);
            return;
        }

        $entity = $this->entities[$entityId];
        $domain = $entity['domain'] ?? $this->getEntityDomain($entityId);

        $this->updateEntityValue($entityId, $domain, $state, $attributes, $context);
    }

    protected function updateEntityValue(string $entityId, string $domain, string $state, array $attributes, string $context): void
    {
        $this->debugExpert(__FUNCTION__, "Update für $entityId ($domain) [$context]", ['State' => $state, 'Attr' => count($attributes)]);

        $parsed = [
            self::KEY_STATE => $state,
            self::KEY_ATTRIBUTES => $attributes
        ];

        $ident = $this->getSharedEntityMainIdent($entityId);
        if ($this->handleDomainUpdateEntityValue($domain, $entityId, $ident, $parsed)) {
            return;
        }

        $this->updateEntityRawStateCache($entityId, $state);
        $this->updateAvailabilityValue($entityId, $state);

        $resolvedAttributes = $this->resolveEntityStateAttributes($entityId, $attributes);
        $finalValue = $this->convertValueByDomain($domain, $state, $resolvedAttributes);
        $this->setEntityMainValue($entityId, $ident, $finalValue, $state);
        $this->updateEntityCache($entityId, $state, $attributes !== [] ? $attributes : null);

        if ($attributes !== []) {
            $storedAttributes = $this->storeEntityAttributes($entityId, $attributes);
            $this->updateEntityPresentation($entityId, $this->entities[$entityId][self::KEY_ATTRIBUTES] ?? []);
            if ($domain === HALightDefinitions::DOMAIN) {
                $this->updateLightAttributeValues($entityId, $storedAttributes);
            }
        }
    }

    protected function deriveEntityIdFromIdent(string $ident): string
    {
        $entityId = $this->getSharedEntityIdByMainIdent($ident);
        if ($entityId !== null) {
            return $entityId;
        }

        $entityId = $this->getSharedEntityIdByPrefix($ident);
        if ($entityId !== null) {
            return $entityId;
        }

        return '';
    }

    protected function deriveStateTopic(string $baseTopic, string $entityId): string
    {
        [$domain, $name] = explode('.', $entityId, 2);
        if ($domain === HAEventDefinitions::DOMAIN) {
            return HAEventDefinitions::buildStateTopic($baseTopic, $name);
        }

        return $baseTopic . '/' . $domain . '/' . $name . '/state';
    }

    protected function deriveEntityTopicPrefix(string $baseTopic, string $entityId): string
    {
        [$domain, $name] = explode('.', $entityId, 2);
        return $baseTopic . '/' . $domain . '/' . $name;
    }

    protected function setValueWithDebug(string $ident, $value): void
    {
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'] ?? '';
        if ($caller !== 'UpdateMediaPlayerProgress' || $this->shouldLogMediaPlayerProgress($ident)) {
            $this->debugExpert('SetValue', $caller, [
                'Ident' => $ident,
                'ValueType' => get_debug_type($value),
                'Value' => $value
            ], true);
        }
        $variableId = @$this->GetIDForIdent($ident);
        if ($variableId === false) {
            return;
        }

        $type = IPS_GetVariable($variableId)['VariableType'];
        if (($type === VARIABLETYPE_INTEGER || $type === VARIABLETYPE_FLOAT)
            && !is_numeric($value)
            && !is_bool($value)) {
            $this->debugExpert('SetValue', 'Type mismatch', [
                'Ident' => $ident,
                'ValueType' => get_debug_type($value),
                'Value' => $value,
                'TargetType' => $type
            ], true);
            return;
        }
        if ($type === VARIABLETYPE_BOOLEAN
            && !is_bool($value)
            && !is_numeric($value)) {
            $this->debugExpert('SetValue', 'Type mismatch', [
                'Ident' => $ident,
                'ValueType' => get_debug_type($value),
                'Value' => $value,
                'TargetType' => $type
            ], true);
            return;
        }

        $this->SetValue($ident, $this->castVariableValue($value, $type));
    }

    protected function setEntityMainValue(string $entityId, string $ident, mixed $value, mixed $rawState = null): void
    {
        if ($value === null || !$this->shouldApplyEntityMainValue($entityId, $rawState)) {
            return;
        }

        $this->setValueWithDebug($ident, $value);
    }

    protected function shouldApplyEntityMainValue(string $entityId, mixed $rawState = null): bool
    {
        $effectiveState = $rawState;
        if (!is_string($effectiveState) || trim($effectiveState) === '') {
            // Attribut-Updates dürfen den letzten fachlichen Wert nicht bei unknown/unavailable überschreiben.
            $effectiveState = $this->getCachedEntityRawState($entityId) ?? $this->getCachedEntityState($entityId);
        }

        return !$this->isIndeterminateEntityState($effectiveState);
    }

    protected function isUnavailableEntityState(mixed $state): bool
    {
        return $this->normalizeEntityStateToken($state) === 'unavailable';
    }

    protected function isUnknownEntityState(mixed $state): bool
    {
        return $this->normalizeEntityStateToken($state) === 'unknown';
    }

    protected function isIndeterminateEntityState(mixed $state): bool
    {
        return $this->isUnavailableEntityState($state) || $this->isUnknownEntityState($state);
    }

    protected function normalizeEntityStateToken(mixed $state): string
    {
        if (!is_string($state)) {
            return '';
        }

        return strtolower(trim($state));
    }

    protected function shouldLogMediaPlayerProgress(string $ident): bool
    {
        $buffer = $this->GetBuffer(self::BUFFER_MEDIA_PLAYER_PROGRESS_DEBUG);
        $map = [];
        if ($buffer !== '') {
            $decoded = json_decode($buffer, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                $map = $decoded;
            }
        }

        $now = time();
        $last = isset($map[$ident]) && is_int($map[$ident]) ? $map[$ident] : 0;
        if ($now - $last < self::MEDIA_PLAYER_PROGRESS_DEBUG_INTERVAL) {
            return false;
        }

        $map[$ident] = $now;
        $this->SetBuffer(self::BUFFER_MEDIA_PLAYER_PROGRESS_DEBUG, json_encode($map, JSON_THROW_ON_ERROR));
        return true;
    }

    protected function determineBaseTopic(): string
    {
        $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($parentID > 0) {
            return (string)IPS_GetProperty($parentID, 'MQTTBaseTopic');
        }
        return '';
    }

    protected function sendMqttMessage(string $topic, string $payload): void
    {
        if (!$this->hasActiveParent()) {
            $this->debugExpert(__FUNCTION__, 'No active parent', [], true);
            return;
        }

        $data = [
            'DataID' => HAIds::DATA_DEVICE_TO_SPLITTER,
            'PacketType' => 3,
            'QualityOfService' => 0,
            'Retain' => false,
            'Topic' => $topic,
            'Payload' => bin2hex($payload)
        ];

        $this->SendDataToParent(json_encode($data, JSON_THROW_ON_ERROR));
    }

    protected function getSetTopicForEntity(string $entityId): string
    {
        $baseTopic = $this->ReadAttributeString('MQTTBaseTopic');
        if ($baseTopic === '') {
            $baseTopic = $this->determineBaseTopic();
        }
        if ($baseTopic === '') {
            $this->debugExpert(__FUNCTION__, 'Kein BaseTopic vorhanden, kann nicht senden.');
            return '';
        }

        [$domain, $name] = explode('.', $entityId, 2);
        return $baseTopic . '/' . $domain . '/' . $name . '/set';
    }

    protected function formatPayloadForMqtt(string $domain, mixed $value, array $attributes = []): string
    {
        $domain = $this->normalizeDomainAlias($domain);
        return match ($domain) {
            HALightDefinitions::DOMAIN, HAFanDefinitions::DOMAIN, HAHumidifierDefinitions::DOMAIN => $value ? 'ON' : 'OFF',
            HASwitchDefinitions::DOMAIN => $value ? HASwitchDefinitions::STATE_ON : HASwitchDefinitions::STATE_OFF,
            HACoverDefinitions::DOMAIN => HACoverDefinitions::normalizeCommand($value),
            HAValveDefinitions::DOMAIN => HAValveDefinitions::normalizeCommand($value),
            HALockDefinitions::DOMAIN => HALockDefinitions::normalizeCommand($value),
            HANumberDefinitions::DOMAIN => $this->formatNumberPayload($value, $attributes),
            HASelectDefinitions::DOMAIN => $this->formatSelectPayload($value, $attributes),
            HAButtonDefinitions::DOMAIN => 'press',
            default => (string)$value,
        };
    }

    protected function formatNumberPayload(mixed $value, array $attributes): string
    {
        if (!is_numeric($value)) {
            $normalized = trim((string)$value);
            if ($normalized === '' || !is_numeric(str_replace(',', '.', $normalized))) {
                $this->debugExpert('Number', 'Ungültiger Wert', ['Value' => $value], true);
                return '';
            }
            $value = str_replace(',', '.', $normalized);
        }

        return $this->inferNumberVariableType($attributes) === VARIABLETYPE_INTEGER
            ? (string)(int)$value
            : (string)(float)$value;
    }

    protected function formatSelectPayload(mixed $value, array $attributes): string
    {
        $options = $attributes['options'] ?? null;
        $normalized = HASelectDefinitions::normalizeSelection($value, $options);
        if ($normalized !== null) {
            return $normalized;
        }

        $this->debugExpert(
            'Select',
            'Ungültige Option',
            [
                'Value' => trim((string)$value),
                'Options' => HASelectDefinitions::normalizeOptions($options)
            ],
            true
        );

        return '';
    }

    protected function isClimateTargetWritable(mixed $attributes): bool
    {
        if (!is_array($attributes)) {
            return false;
        }

        $supported = (int)($attributes[HAClimateDefinitions::ATTRIBUTE_SUPPORTED_FEATURES] ?? 0);
        $hasTargetFeature = ($supported & 1) === 1;
        $hasTargetAttribute = array_key_exists(HAClimateDefinitions::ATTRIBUTE_TARGET_TEMPERATURE, $attributes)
            || array_key_exists('temperature', $attributes);

        return $hasTargetFeature || $hasTargetAttribute;
    }

    protected function isFanToggleSupported(mixed $attributes): bool
    {
        if (!is_array($attributes)) {
            return false;
        }

        $supported = (int)($attributes[self::KEY_SUPPORTED_FEATURES] ?? 0);
        return (($supported & HAFanDefinitions::FEATURE_TURN_ON) === HAFanDefinitions::FEATURE_TURN_ON)
            || (($supported & HAFanDefinitions::FEATURE_TURN_OFF) === HAFanDefinitions::FEATURE_TURN_OFF);
    }

    protected function isCoverMainWritable(mixed $attributes): bool
    {
        if (!is_array($attributes)) {
            return true;
        }

        $supported = (int)($attributes[self::KEY_SUPPORTED_FEATURES] ?? 0);
        if ($supported === 0) {
            return true;
        }

        if (($supported & HACoverDefinitions::FEATURE_SET_POSITION) === HACoverDefinitions::FEATURE_SET_POSITION) {
            return true;
        }

        return (($supported & HACoverDefinitions::FEATURE_OPEN) === HACoverDefinitions::FEATURE_OPEN)
            || (($supported & HACoverDefinitions::FEATURE_CLOSE) === HACoverDefinitions::FEATURE_CLOSE);
    }

    protected function isValveMainWritable(mixed $attributes): bool
    {
        if (!is_array($attributes)) {
            return true;
        }

        $supported = (int)($attributes[self::KEY_SUPPORTED_FEATURES] ?? 0);
        if ($supported === 0) {
            return true;
        }

        if (($supported & HAValveDefinitions::FEATURE_SET_POSITION) === HAValveDefinitions::FEATURE_SET_POSITION) {
            return true;
        }

        return (($supported & HAValveDefinitions::FEATURE_OPEN) === HAValveDefinitions::FEATURE_OPEN)
            || (($supported & HAValveDefinitions::FEATURE_CLOSE) === HAValveDefinitions::FEATURE_CLOSE);
    }

    protected function isEntityWritable(string $domain, mixed $attributes): bool
    {
        if (!$this->isWriteable($domain)) {
            return false;
        }
        if ($domain === HAClimateDefinitions::DOMAIN) {
            return $this->isClimateTargetWritable($attributes);
        }
        if ($domain === HAFanDefinitions::DOMAIN) {
            return $this->isFanToggleSupported($attributes);
        }
        if ($domain === HACoverDefinitions::DOMAIN) {
            return $this->isCoverMainWritable($attributes);
        }
        if ($domain === HAValveDefinitions::DOMAIN) {
            return $this->isValveMainWritable($attributes);
        }

        return true;
    }
    public function SyncStates(): void
    {
        $this->debugExpert(__FUNCTION__, 'Synchronisierung angefordert');
        $configData = $this->getConfiguredEntities(__FUNCTION__);
        if ($configData === []) {
            $this->debugExpert(__FUNCTION__, 'Keine Entitäten konfiguriert');
            return;
        }

        $this->initializeStatesFromHa($configData);
    }

    /** @noinspection PhpUnused */
    public function SyncAllEntitiesStates(): void
    {
        $this->SyncStates();
    }
}






