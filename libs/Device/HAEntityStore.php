<?php

declare(strict_types=1);

trait HAEntityStoreTrait
{
    protected function isManagedEntityId(string $entityId): bool
    {
        if (isset($this->entities[$entityId]) && (($this->entities[$entityId]['create_var'] ?? true) !== false)) {
            return true;
        }

        // O(1) über den Konfigurations-Index (HADeviceCore); Fallback für
        // Harnesse ohne Core-Trait bleibt der lineare Scan.
        if (method_exists($this, 'getConfiguredEntityById')) {
            return $this->getConfiguredEntityById($entityId) !== null;
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

    // Runtime-Entities ohne Konfigurationszeile erhalten nur Minimalmetadaten
    // (siehe HADeviceCoreTrait::rehydrateRuntimeEntity).
    private function ensureStoredEntity(string $entityId): void
    {
        if ($this->rehydrateRuntimeEntity($entityId)) {
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

    // In-Memory-Spiegel des State-Caches: pro PHP-Ausführung einmal dekodiert, danach aus dem Speicher
    // bedient. Persistenz zweistufig: Der heiße Pfad schreibt nur in den Buffer (kernel-seitig
    // In-Memory, überlebt getrennte PHP-Ausführungen, keine Settings-Persistenz); das
    // EntityStateCache-Attribut schreibt gebündelt der One-Shot-Flush-Timer. Grund: Der Cache kann
    // groß werden (z. B. evcc ~47 KB) und wurde zuvor bis zu zweimal pro Message als Attribut
    // geschrieben — ein 47-KB-Kernel-Write kostet gemessen 2,5–12 ms. Nach einem Kernel-Neustart ist
    // der Buffer leer und es gilt der letzte Flush-Stand (max. STATE_CACHE_FLUSH_DELAY_MS alt) —
    // verschmerzbar, weil der Cache aus dem retained-Replay des Brokers ohnehin neu aufgebaut wird.
    private ?array $entityStateCacheMemory = null;
    private ?string $entityStateCacheEncoded = null;

    // Der State-Cache wird im Store zentral gelesen und geschrieben.
    private function readEntityStateCache(): array
    {
        if (is_array($this->entityStateCacheMemory)) {
            return $this->entityStateCacheMemory;
        }

        $raw = $this->GetBuffer(self::BUFFER_ENTITY_STATE_CACHE);
        if ($raw === '') {
            $raw = $this->ReadAttributeString('EntityStateCache');
        }
        $this->entityStateCacheEncoded = $raw;
        if ($raw === '') {
            return $this->entityStateCacheMemory = [];
        }

        try {
            $cache = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->entityStateCacheMemory = [];
        }

        return $this->entityStateCacheMemory = is_array($cache) ? $cache : [];
    }

    private function writeEntityStateCache(array $cache): void
    {
        $this->entityStateCacheMemory = $cache;
        $encoded = json_encode($cache, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === $this->entityStateCacheEncoded) {
            return;
        }

        $this->entityStateCacheEncoded = $encoded;
        $this->SetBuffer(self::BUFFER_ENTITY_STATE_CACHE, $encoded);
        if ($this->GetTimerInterval(self::TIMER_STATE_CACHE_FLUSH) <= 0) {
            $this->SetTimerInterval(self::TIMER_STATE_CACHE_FLUSH, self::STATE_CACHE_FLUSH_DELAY_MS);
        }
    }

    protected function registerStateCacheFlushTimer(): void
    {
        $this->RegisterTimer(
            self::TIMER_STATE_CACHE_FLUSH,
            0,
            'IPS_RequestAction($_IPS["TARGET"], "' . self::ACTION_STATE_CACHE_FLUSH . '", "");'
        );
    }

    protected function handleStateCacheFlushAction(string $ident): bool
    {
        if ($ident !== self::ACTION_STATE_CACHE_FLUSH) {
            return false;
        }
        $this->flushEntityStateCache();
        return true;
    }

    // Persistiert den Buffer-Stand ins Attribut (Flush-Timer und ApplyChanges)
    // und aktualisiert bei Bedarf die Unavailable-Entities-JSON-Variable (P7:
    // gebündelt statt pro Message).
    protected function flushEntityStateCache(): void
    {
        $this->SetTimerInterval(self::TIMER_STATE_CACHE_FLUSH, 0);
        $encoded = $this->GetBuffer(self::BUFFER_ENTITY_STATE_CACHE);
        if ($encoded !== '' && $encoded !== $this->ReadAttributeString('EntityStateCache')) {
            $this->WriteAttributeString('EntityStateCache', $encoded);
        }

        if ($this->GetBuffer(self::BUFFER_UNAVAILABLE_JSON_DIRTY) === '1') {
            $this->SetBuffer(self::BUFFER_UNAVAILABLE_JSON_DIRTY, '');
            $this->updateUnavailableEntitiesJsonVariable();
        }

        if ($this->GetBuffer(self::BUFFER_REACHABILITY_DIRTY) === '1') {
            $this->SetBuffer(self::BUFFER_REACHABILITY_DIRTY, '');
            $this->evaluateReachability();
        }
    }

    protected function registerReachabilityTimer(): void
    {
        $this->RegisterTimer(
            self::TIMER_REACHABILITY,
            0,
            'IPS_RequestAction($_IPS["TARGET"], "' . self::ACTION_REACHABILITY_CHECK . '", "");'
        );
    }

    protected function handleReachabilityAction(string $ident): bool
    {
        if ($ident !== self::ACTION_REACHABILITY_CHECK) {
            return false;
        }
        $this->evaluateReachability();
        return true;
    }

    // Bestandsinstanzen kennen den Timer erst nach ihrem nächsten Create(): Beim Neuladen des Moduls
    // (Update 1.4 → 1.5) läuft ApplyChanges schon mit diesem Code, während der Kernel noch die alte
    // Instanz hält — ohne @ meldet er dann je Instanz „Timer ReachabilityTimer does not exist".
    private function setReachabilityTimerInterval(int $milliseconds): void
    {
        @$this->SetTimerInterval(self::TIMER_REACHABILITY, $milliseconds);
    }

    // Überschreibbar für Tests.
    protected function reachabilityNow(): int
    {
        return time();
    }

    private function maintainReachableVariable(): void
    {
        $isNew = @$this->GetIDForIdent(self::REACHABLE_IDENT) === false;
        $this->MaintainVariable(
            self::REACHABLE_IDENT,
            $this->Translate('Reachable'),
            VARIABLETYPE_BOOLEAN,
            $this->buildSharedBinarySensorPresentation($this->Translate('Reachable'), $this->Translate('Not reachable'), 'wifi'),
            9999,
            true
        );
        // Eine neue Variable kennt keinen Ausfall: Mit dem Standardwert false gälte das Gerät schon als
        // nicht erreichbar, und evaluateReachability übersprünge die Entprellung (Instanz während des
        // HA-Neustarts angelegt, Update auf 1.5).
        if ($isNew) {
            $this->SetValue(self::REACHABLE_IDENT, true);
        }
    }

    // Heißer Pfad: nur dann zur gebündelten Auswertung vormerken, wenn sich an der Erreichbarkeit
    // etwas ändern kann — eine unavailable-Meldung, oder eine gültige Meldung, solange das Gerät
    // nicht als erreichbar gilt ('0'). Im Normalbetrieb bleibt es bei einem Buffer-Lesezugriff.
    private function markReachabilityDirty(string $rawState): void
    {
        $unavailable = strtolower(trim($rawState)) === 'unavailable';
        if (!$unavailable && $this->GetBuffer(self::BUFFER_REACHABILITY_SINCE) === '0') {
            return;
        }
        if ($this->GetBuffer(self::BUFFER_REACHABILITY_DIRTY) !== '1') {
            $this->SetBuffer(self::BUFFER_REACHABILITY_DIRTY, '1');
        }
        if ($this->GetTimerInterval(self::TIMER_STATE_CACHE_FLUSH) <= 0) {
            $this->SetTimerInterval(self::TIMER_STATE_CACHE_FLUSH, self::STATE_CACHE_FLUSH_DELAY_MS);
        }
    }

    // Nicht erreichbar = mindestens eine Entität hat einen Cache-Eintrag, und alle Entitäten mit
    // Cache-Eintrag melden „unavailable" — seit mindestens REACHABILITY_DELAY_S. „unknown" ist kein
    // Ausfall (HA kennt das Gerät, nur den Wert nicht).
    protected function evaluateReachability(): void
    {
        if (@$this->GetIDForIdent(self::REACHABLE_IDENT) === false) {
            return;
        }

        $cache = $this->readEntityStateCache();
        $seen = 0;
        $allUnavailable = true;
        foreach ($this->getConfiguredEntities(__FUNCTION__) as $entity) {
            $entityId = (string)($entity['entity_id'] ?? '');
            if ($entityId === '' || ($entity['create_var'] ?? true) === false) {
                continue;
            }
            $entry = $this->getEntityStateCacheEntry($entityId, $cache);
            $raw = $entry['raw_state'] ?? $entry[self::KEY_STATE] ?? null;
            if (!is_string($raw) || trim($raw) === '') {
                continue;
            }
            $seen++;
            if (strtolower(trim($raw)) !== 'unavailable') {
                $allUnavailable = false;
                break;
            }
        }

        $now = $this->reachabilityNow();
        if ($seen === 0 || !$allUnavailable) {
            $this->SetBuffer(self::BUFFER_REACHABILITY_SINCE, '0');
            $this->setReachabilityTimerInterval(0);
            $this->setReachableValue(true);
            return;
        }

        $since = (int)$this->GetBuffer(self::BUFFER_REACHABILITY_SINCE);
        if ($since <= 0) {
            $since = $now;
            $this->SetBuffer(self::BUFFER_REACHABILITY_SINCE, (string)$since);
        }

        // Die Entprellung gilt nur für den Weg erreichbar → nicht erreichbar. Gilt das Gerät schon
        // als nicht erreichbar, gibt es nichts abzuwarten — sonst sprang es nach jedem Neuladen
        // und Kernel-Neustart (Buffer leer) für REACHABILITY_DELAY_S auf „erreichbar".
        if ($this->GetValue(self::REACHABLE_IDENT) === false) {
            $this->setReachabilityTimerInterval(0);
            return;
        }

        $remaining = $since + self::REACHABILITY_DELAY_S - $now;
        if ($remaining > 0) {
            $this->setReachabilityTimerInterval($remaining * 1000);
            $this->setReachableValue(true);
            return;
        }

        $this->setReachabilityTimerInterval(0);
        $this->setReachableValue(false);
    }

    private function setReachableValue(bool $reachable): void
    {
        if ($this->GetValue(self::REACHABLE_IDENT) !== $reachable) {
            $this->SetValue(self::REACHABLE_IDENT, $reachable);
        }
    }

    // Schreibt das LastMQTTMessage-Attribut und aktualisiert die Diagnose-Labels höchstens alle
    // LAST_MQTT_LABEL_THROTTLE_SEC Sekunden (Muster wie im Splitter): Bei mehreren Messages/Sek.
    // würden WriteAttributeString + mehrere UpdateFormField pro Message dauerhaft messbare Last
    // erzeugen, ohne Mehrwert — das Label hat Sekunden-Granularität.
    protected function touchLastMqttMessage(): void
    {
        $now = time();
        $last = (int)$this->GetBuffer(self::BUFFER_LAST_MQTT_TOUCH);
        if ($last > 0 && ($now - $last) < self::LAST_MQTT_LABEL_THROTTLE_SEC) {
            return;
        }
        $this->SetBuffer(self::BUFFER_LAST_MQTT_TOUCH, (string)$now);
        $this->WriteAttributeString('LastMQTTMessage', date('Y-m-d H:i:s', $now));
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
            return $this->getMediaPlayerOrderPosition($position, 'status');
        }

        $linkedPosition = $this->getMediaPlayerLinkedPosition($entityId, $domain);
        return $linkedPosition ?? $position;
    }

    // Initiale Anlage und Refresh teilen sich einen Pfad für Hauptvariable und Domain-Extras.
    private function syncEntityPresentation(array $entity, bool $initializeDescriptorValue = false, bool $withExtraMaintenance = true): void
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
        // Altnamen wandern nur im vollen Pfad (ApplyChanges läuft nach jedem Update ohnehin); der
        // heiße Pfad spart sich dafür GetIDForIdent und IPS_GetObject je Attributmeldung.
        $wasLegacy = false;
        if ($initializeDescriptorValue) {
            $existingId = @$this->GetIDForIdent($ident);
            $wasLegacy = $existingId !== false && str_ends_with(
                trim((string)(IPS_GetObject((int)$existingId)['ObjectName'] ?? '')),
                $this->getLegacyNameSuffix()
            );
        }
        $presentation = $this->getEntityPresentation($domain, $entity, $type);
        $position = $this->getEntityMainVariablePosition($entity, $domain);
        $name = $this->getEntityVariableName($domain, $entity);

        // IPSModuleStrict: true = die Variable wurde erstellt — neu, oder bei einem Typwechsel
        // (z. B. number: step 1 → 0.5) unter neuer ID ohne Aktion. Beides zählt als neu, auch im
        // heißen Pfad; ein Vorher/Nachher-Vergleich der ID kostete je Meldung einen Kernel-Aufruf.
        $exists = !$this->MaintainVariable($ident, $name, $type, $presentation, $position, true);
        if ($wasLegacy) {
            IPS_SetName($this->GetIDForIdent($ident), $name);
        }
        if ($initializeDescriptorValue) {
            $descriptor = $this->describeEntityMainVariable($entity);
            $this->initializeVariableDescriptorValue($ident, $descriptor, $exists);
        }

        // Der volle Pfad (ApplyChanges) gleicht die Aktion auch bei bestehenden Variablen ab: Eine
        // Variable, die ihre Aktion verloren hat, bliebe sonst dauerhaft nicht schaltbar. Der heiße
        // Pfad (Zustandsmeldung) fasst sie weiterhin nur bei Domains mit wechselnder Schreibbarkeit an.
        // Kosten: ein Enable/DisableAction je schreibbarer Hauptvariable und ApplyChanges. Vorher
        // nachzusehen, ob die Aktion fehlt (IPS_GetVariable), kostete ebenso einen Aufruf und zöge
        // eine geänderte Schreibbarkeit nicht nach (Code-Review build 167, Befund 7: bewusst so).
        if (!$exists || $wasLegacy || $initializeDescriptorValue || $this->shouldApplyDomainActionStateOnExisting($domain)) {
            $this->applyDomainActionState($domain, $ident, $entity);
        }
        // Die Domain-Extra-Maintenance iteriert alle Attribut-Definitionen der Domain und ist damit
        // die teuerste Stufe der Kaskade. Gezielte Refreshes (Wertänderung eines bekannten Attributs)
        // überspringen sie — Variablen anlegen muss nur der volle Pfad (neue Schlüssel, ApplyChanges).
        if ($withExtraMaintenance) {
            $this->applyDomainExtraMaintenance($domain, $entity);
        }
    }

    private function updateEntityPresentation(string $entityId, array $attributes, bool $withExtraMaintenance = true): void
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
        $this->syncEntityPresentation($entity, false, $withExtraMaintenance);
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

        $this->markReachabilityDirty($rawState);

        if (!$this->shouldShowUnavailableEntitiesJson()) {
            return;
        }

        // P7: Nicht mehr pro Message schreiben — Dirty-Flag setzen, der
        // StateCacheFlush-Timer schreibt gebündelt (und mit korrekter
        // Datenbasis aus der gecachten Konfiguration).
        $this->SetBuffer(self::BUFFER_UNAVAILABLE_JSON_DIRTY, '1');
        if ($this->GetTimerInterval(self::TIMER_STATE_CACHE_FLUSH) <= 0) {
            $this->SetTimerInterval(self::TIMER_STATE_CACHE_FLUSH, self::STATE_CACHE_FLUSH_DELAY_MS);
        }
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

        // Datenbasis ist die (gecachte) Entitäten-Konfiguration — das Laufzeit-Array
        // $this->entities ist außerhalb von ApplyChanges leer (getrennte
        // PHP-Ausführungen) und lieferte hier zuvor stets ein leeres Ergebnis.
        $rows = method_exists($this, 'getConfiguredEntities')
            ? $this->getConfiguredEntities(__FUNCTION__)
            : array_values($this->entities);

        $entries = [];
        foreach ($rows as $entity) {
            if (!is_array($entity)) {
                continue;
            }
            $entityId = (string)($entity['entity_id'] ?? '');
            if ($entityId === '' || ($entity['create_var'] ?? true) === false) {
                continue;
            }

            $rawState = $this->getCachedEntityRawState($entityId) ?? $this->getCachedEntityState($entityId);
            // Die Expertenliste zeigt nur auffällige Zustände statt aller Entities.
            if (!is_string($rawState) || !$this->isIndeterminateEntityState($rawState)) {
                continue;
            }

            $entityIdent = trim((string)($entity['ident'] ?? ''));
            if ($entityIdent === '') {
                $entityIdent = $this->getSharedEntityMainIdent($entityId);
            }
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
