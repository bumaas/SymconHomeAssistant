<?php

declare(strict_types=1);

trait HAIdentNamingTrait
{
    private array $sharedEntityIdentIndex = [];
    private array $sharedEntityPrefixIndex = [];

    private function applySharedEntityIdents(array $entities): array
    {
        $assignments = $this->buildSharedEntityIdents($entities);
        foreach ($entities as $index => $entity) {
            if (!is_array($entity)) {
                continue;
            }

            $key = $this->getSharedEntityKey($entity);
            if ($key === '' || !isset($assignments[$key])) {
                continue;
            }

            $assignment = $assignments[$key];
            $entity['object_id'] = $assignment['object_id'];
            $entity['local_object_id'] = $assignment['local_object_id'];
            $entity['ident_prefix'] = $assignment['ident_prefix'];
            $entity['ident'] = $assignment['ident'];
            $entities[$index] = $entity;
        }

        $this->rebuildSharedEntityBaseNameCounts($entities);

        return $entities;
    }

    private function buildSharedEntityIdents(array $entities): array
    {
        $descriptors = [];
        foreach ($entities as $entity) {
            if (!is_array($entity)) {
                continue;
            }

            $key = $this->getSharedEntityKey($entity);
            $domain = $this->getSharedEntityDomain($entity);
            if ($key === '' || $domain === '') {
                continue;
            }

            $objectId = $this->getSharedEntityObjectId($entity);
            $localObjectId = $this->buildSharedLocalObjectId(
                $objectId,
                trim((string)($entity['device_name'] ?? '')),
                trim((string)($entity['device_model'] ?? ''))
            );

            $descriptors[$key] = [
                'key'                  => $key,
                'domain'               => $domain,
                'object_id'            => $objectId,
                'normalized_object_id' => $this->normalizeSharedIdentFragment($objectId),
                'device_key'           => $this->getSharedEntityDeviceKey($entity),
                'local_object_id'      => $localObjectId,
                'is_primary'           => $localObjectId === '',
                'sort_key'             => $domain . '|' . $objectId . '|' . $key,
                'existing_ident'       => trim((string)($entity['ident'] ?? '')),
                'existing_ident_prefix'=> trim((string)($entity['ident_prefix'] ?? '')),
            ];
        }

        uasort($descriptors, static fn(array $left, array $right): int => strcmp($left['sort_key'], $right['sort_key']));

        // Gemeinsamen object_id-Praefix je Geraet ermitteln (= HA-Geraete-Slug, z. B.
        // "milchstrasse_melcloudhome_650e_5ec4"). Dieser wird aus den entity_ids selbst
        // abgeleitet und ist daher – anders als der veraenderliche device_name – stabil.
        $deviceObjectIdPrefixes = $this->computeSharedDeviceObjectIdPrefixes($descriptors);

        // Pre-register all existing idents so new entities cannot claim those tokens.
        $usedTokens = [];
        foreach ($descriptors as $descriptor) {
            if ($descriptor['existing_ident'] !== '' && $descriptor['existing_ident_prefix'] !== '') {
                $usedTokens[$descriptor['existing_ident_prefix']] = true;
                $usedTokens[$descriptor['existing_ident']]        = true;
            }
        }

        $assignments = [];
        foreach ($descriptors as $descriptor) {
            $domain       = $descriptor['domain'];
            $objectId     = $descriptor['object_id'];
            $localObjectId= $descriptor['local_object_id'];
            $isPrimary    = $descriptor['is_primary'];

            // Preserve stable ident once assigned — prevents ident drift on device renames.
            if ($descriptor['existing_ident'] !== '' && $descriptor['existing_ident_prefix'] !== '') {
                $assignments[$descriptor['key']] = [
                    'ident_prefix'   => $descriptor['existing_ident_prefix'],
                    'ident'          => $descriptor['existing_ident'],
                    'object_id'      => $objectId,
                    'local_object_id'=> $localObjectId,
                ];
                continue;
            }

            $normalizedObjectId = $descriptor['normalized_object_id'];

            $stemCandidates = [];

            if ($localObjectId === '') {
                // Primaere Entitaet (Geraete-Hauptzustand) -> domain_status, unveraendert.
                $stemCandidates[] = '';
            } else {
                // Kurzform: object_id ohne den geraeteweiten Slug -> "sensor_room_temperature"
                // statt "sensor_<slug>_room_temperature".
                $prefixStrippedStem = $this->stripSharedObjectIdPrefix(
                    $normalizedObjectId,
                    $deviceObjectIdPrefixes[$descriptor['device_key']] ?? ''
                );
                // Keine Migration des Bestands: Existiert bereits eine Variable mit dem langen
                // Legacy-Ident, bleibt dieser erhalten. Nur wirklich neue Entitaeten (ohne Variable
                // unter dem Legacy-Ident) erhalten die Kurzform. Idents wurden nie persistiert und
                // bisher deterministisch aus der entity_id berechnet -> die Existenzpruefung ist die
                // einzige verlaessliche "neu vs. bestehend"-Unterscheidung.
                $legacyIdent = $this->createSharedIdentAssignment($domain, $localObjectId, $isPrimary)['ident'];
                if ($prefixStrippedStem !== '' && !$this->sharedManagedIdentExists($legacyIdent)) {
                    $stemCandidates[] = $prefixStrippedStem;
                }
                if (!in_array($localObjectId, $stemCandidates, true)) {
                    $stemCandidates[] = $localObjectId;
                }
            }

            if ($normalizedObjectId !== '' && !in_array($normalizedObjectId, $stemCandidates, true)) {
                $stemCandidates[] = $normalizedObjectId;
            }

            $assignment = null;
            foreach ($stemCandidates as $stem) {
                $candidate = $this->createSharedIdentAssignment($domain, $stem, $isPrimary);
                if ($this->isSharedIdentAssignmentAvailable($candidate, $usedTokens)) {
                    $assignment = $candidate;
                    break;
                }
            }

            if ($assignment === null) {
                $baseStem = $stemCandidates[0];
                $counter = 2;
                do {
                    $candidateStem = $baseStem === '' ? (string)$counter : $baseStem . '_' . $counter;
                    $assignment = $this->createSharedIdentAssignment($domain, $candidateStem, $isPrimary);
                    $counter++;
                } while (!$this->isSharedIdentAssignmentAvailable($assignment, $usedTokens));
            }

            $usedTokens[$assignment['ident_prefix']] = true;
            $usedTokens[$assignment['ident']]        = true;

            $assignments[$descriptor['key']] = array_merge($assignment, [
                'object_id'      => $objectId,
                'local_object_id'=> $localObjectId,
            ]);
        }

        return $assignments;
    }

    // Prueft, ob in dieser Instanz bereits eine verwaltete Variable mit diesem Ident existiert.
    // Schuetzt den Bestand vor Ident-Migration: nur fehlende (neue) Idents werden gekuerzt.
    // Default ohne Symcon-Kontext (Tests): false. Im Modul ueber GetIDForIdent abgesichert.
    protected function sharedManagedIdentExists(string $ident): bool
    {
        if ($ident === '' || !method_exists($this, 'GetIDForIdent')) {
            return false;
        }

        return @$this->GetIDForIdent($ident) !== false;
    }

    // Geraete-Identitaet zum Gruppieren der object_ids. device_id ist stabil und HA-eindeutig;
    // sonst device_name + device_model als Ersatz. Leer, wenn keine Geraetezuordnung vorliegt.
    private function getSharedEntityDeviceKey(array $entity): string
    {
        $deviceId = trim((string)($entity['device_id'] ?? ''));
        if ($deviceId !== '') {
            return 'id:' . $deviceId;
        }

        $name = $this->normalizeSharedIdentFragment((string)($entity['device_name'] ?? ''));
        $model = $this->normalizeSharedIdentFragment((string)($entity['device_model'] ?? ''));
        if ($name === '' && $model === '') {
            return '';
        }

        return 'nm:' . $name . '|' . $model;
    }

    // Pro Geraet den laengsten gemeinsamen object_id-Praefix (segmentweise an '_') ueber alle
    // Entitaeten bilden. Nur Gruppen mit >=2 Entitaeten liefern einen Praefix; bei einer einzelnen
    // Entitaet laesst sich der Geraete-Slug nicht vom Entitaets-Suffix trennen.
    private function computeSharedDeviceObjectIdPrefixes(array $descriptors): array
    {
        $segmentsByDevice = [];
        foreach ($descriptors as $descriptor) {
            $deviceKey = $descriptor['device_key'];
            $objectId  = $descriptor['normalized_object_id'];
            if ($deviceKey === '' || $objectId === '') {
                continue;
            }
            $segmentsByDevice[$deviceKey][] = explode('_', $objectId);
        }

        $prefixes = [];
        foreach ($segmentsByDevice as $deviceKey => $segmentLists) {
            if (count($segmentLists) < 2) {
                continue;
            }
            $common = $this->longestCommonSegmentPrefix($segmentLists);
            if ($common !== []) {
                $prefixes[$deviceKey] = implode('_', $common);
            }
        }

        return $prefixes;
    }

    /** @param array<int, array<int, string>> $segmentLists */
    private function longestCommonSegmentPrefix(array $segmentLists): array
    {
        $first = $segmentLists[0] ?? [];
        $common = [];
        foreach ($first as $index => $segment) {
            foreach ($segmentLists as $segments) {
                if (($segments[$index] ?? null) !== $segment) {
                    return $common;
                }
            }
            $common[] = $segment;
        }

        return $common;
    }

    // Schneidet den geraeteweiten Praefix segmentscharf vom object_id ab. Liefert den
    // entitaetsspezifischen Rest ('' wenn der object_id exakt dem Praefix entspricht).
    private function stripSharedObjectIdPrefix(string $normalizedObjectId, string $prefix): string
    {
        if ($prefix === '' || $normalizedObjectId === '') {
            return '';
        }
        if ($normalizedObjectId === $prefix) {
            return '';
        }
        if (str_starts_with($normalizedObjectId, $prefix . '_')) {
            return substr($normalizedObjectId, strlen($prefix) + 1);
        }

        return '';
    }

    private function createSharedIdentAssignment(string $domain, string $stem, bool $isPrimary): array
    {
        $prefix = $domain;
        if ($stem !== '') {
            $prefix .= '_' . $stem;
        }

        $prefix = $this->normalizeSharedIdentFragment($prefix);
        $ident = $isPrimary ? $this->normalizeSharedIdentFragment($prefix . '_status') : $prefix;

        return [
            'ident_prefix' => $prefix,
            'ident' => $ident
        ];
    }

    private function isSharedIdentAssignmentAvailable(array $assignment, array $usedTokens): bool
    {
        $prefix = (string)($assignment['ident_prefix'] ?? '');
        $ident = (string)($assignment['ident'] ?? '');
        if ($prefix === '' || $ident === '') {
            return false;
        }

        return !isset($usedTokens[$prefix]) && !isset($usedTokens[$ident]);
    }

    private function rebuildSharedEntityIdentIndexes(): void
    {
        $this->sharedEntityIdentIndex = [];
        $this->sharedEntityPrefixIndex = [];

        foreach ($this->entities as $entityId => $entity) {
            $ident = trim((string)($entity['ident'] ?? ''));
            if ($ident !== '') {
                $this->sharedEntityIdentIndex[$ident] = $entityId;
            }

            $prefix = trim((string)($entity['ident_prefix'] ?? ''));
            if ($prefix !== '') {
                $this->sharedEntityPrefixIndex[$prefix] = $entityId;
            }
        }
    }

    private function getSharedEntityMainIdent(string $entityId): string
    {
        $ident = $this->getSharedConfiguredEntityFieldValue($entityId, 'ident');
        if ($ident !== '') {
            return $ident;
        }

        return $this->sanitizeIdent($entityId);
    }

    private function getSharedEntityIdentPrefix(string $entityId): string
    {
        $prefix = $this->getSharedConfiguredEntityFieldValue($entityId, 'ident_prefix');
        if ($prefix !== '') {
            return $prefix;
        }

        return $this->getSharedEntityMainIdent($entityId);
    }

    protected function buildSharedAttributeIdent(string $entityId, string $attribute): string
    {
        return $this->buildSharedAttributeIdentFromPrefix($this->getSharedEntityIdentPrefix($entityId), $attribute);
    }

    private function buildSharedAttributeIdentFromPrefix(string $identPrefix, string $attribute): string
    {
        return $this->normalizeSharedIdentFragment($identPrefix . '_' . $attribute);
    }

    protected function buildSharedSuffixIdent(string $entityId, string $suffix): string
    {
        return $this->buildSharedSuffixIdentFromPrefix($this->getSharedEntityIdentPrefix($entityId), $suffix);
    }

    private function buildSharedSuffixIdentFromPrefix(string $identPrefix, string $suffix): string
    {
        return $this->normalizeSharedIdentFragment($identPrefix . $suffix);
    }

    protected function getSharedEntityIdByMainIdent(string $ident): ?string
    {
        return $this->sharedEntityIdentIndex[$ident] ?? null;
    }

    protected function getSharedEntityIdByPrefix(string $prefix): ?string
    {
        return $this->sharedEntityPrefixIndex[$prefix] ?? null;
    }

    protected function findSharedConfiguredEntityByMainIdent(string $ident): ?array
    {
        return array_find(
            $this->getSharedConfiguredEntitiesForNaming(),
            static fn(array $row): bool => ($row['ident'] ?? '') === $ident
        );
    }

    protected function findSharedConfiguredEntityByPrefix(string $prefix): ?array
    {
        return array_find(
            $this->getSharedConfiguredEntitiesForNaming(),
            static fn(array $row): bool => ($row['ident_prefix'] ?? '') === $prefix
        );
    }

    private function findSharedConfiguredEntityByEntityId(string $entityId): ?array
    {
        return array_find(
            $this->getSharedConfiguredEntitiesForNaming(),
            static fn(array $row): bool => ($row['entity_id'] ?? '') === $entityId
        );
    }

    private function buildSharedLocalObjectId(string $objectId, string $deviceName, string $deviceModel = ''): string
    {
        $normalizedObjectId = $this->normalizeSharedIdentFragment($objectId);
        if ($normalizedObjectId === '') {
            return '';
        }

        foreach ($this->getSharedRedundantObjectNameCandidates($deviceName, $deviceModel) as $candidate) {
            // Direct prefix: device_name_something
            if (str_starts_with($normalizedObjectId, $candidate . '_')) {
                return substr($normalizedObjectId, strlen($candidate) + 1);
            }
            if ($normalizedObjectId === $candidate) {
                return '';
            }
            // Qualified prefix: area_device_name_something (HA prepends area slug to entity_id)
            $pos = strpos($normalizedObjectId, '_' . $candidate . '_');
            if ($pos !== false) {
                $rest = substr($normalizedObjectId, $pos + strlen($candidate) + 2);
                if ($rest !== '') {
                    return $rest;
                }
            }
            // Qualified exact match: area_device_name (primary entity without attribute suffix)
            if (str_ends_with($normalizedObjectId, '_' . $candidate)) {
                return '';
            }
        }

        return $normalizedObjectId;
    }

    private function getSharedRedundantObjectNameCandidates(string $deviceName, string $deviceModel = ''): array
    {
        $candidates = [];

        $normalizedDeviceName = $this->normalizeSharedIdentFragment($deviceName);
        if ($normalizedDeviceName !== '') {
            $candidates[] = $normalizedDeviceName;
        }

        $normalizedDeviceModel = $this->normalizeSharedIdentFragment($deviceModel);
        if ($normalizedDeviceModel !== '') {
            $candidates[] = $normalizedDeviceModel;
        }

        if (method_exists($this, 'getSharedCurrentInstanceDeviceName')) {
            $instanceDeviceName = $this->normalizeSharedIdentFragment($this->getSharedCurrentInstanceDeviceName());
            if ($instanceDeviceName !== '') {
                $candidates[] = $instanceDeviceName;
            }
        }

        return array_values(array_unique($candidates));
    }

    private function getSharedEntityKey(array $entity): string
    {
        $key = trim((string)($entity['entity_id'] ?? $entity['entity_key'] ?? ''));
        if ($key !== '') {
            return $key;
        }

        $domain = $this->getSharedEntityDomain($entity);
        $objectId = $this->getSharedEntityObjectId($entity);
        if ($domain === '' || $objectId === '') {
            return '';
        }

        return $domain . '.' . $objectId;
    }

    private function getSharedConfiguredEntityFieldValue(string $entityId, string $field): string
    {
        $value = trim((string)($this->entities[$entityId][$field] ?? ''));
        if ($value !== '') {
            return $value;
        }

        $entity = $this->findSharedConfiguredEntityByEntityId($entityId);
        return trim((string)($entity[$field] ?? ''));
    }

    private function getSharedEntityDomain(array $entity): string
    {
        $domain = trim((string)($entity['domain'] ?? $entity['component'] ?? ''));
        if ($domain === '' && isset($entity['entity_id']) && is_string($entity['entity_id']) && str_contains($entity['entity_id'], '.')) {
            [$domain] = explode('.', $entity['entity_id'], 2);
        }

        return HADomainCatalog::normalizeDomainAlias($domain);
    }

    private function getSharedEntityObjectId(array $entity): string
    {
        $objectId = trim((string)($entity['object_id'] ?? ''));
        if ($objectId !== '') {
            return $objectId;
        }

        $entityId = trim((string)($entity['entity_id'] ?? $entity['entity_key'] ?? ''));
        if ($entityId !== '' && str_contains($entityId, '.')) {
            [, $objectId] = explode('.', $entityId, 2);
        }

        return $objectId;
    }

    private function normalizeSharedIdentFragment(string $value): string
    {
        $value = strtolower(trim($value));
        $value = $this->transliterateSharedIdentFragment($value);
        $value = $this->sanitizeIdent($value);
        $value = preg_replace('/_+/', '_', $value) ?? $value;
        return trim($value, '_');
    }

    private function transliterateSharedIdentFragment(string $value): string
    {
        $value = strtr($value, [
            'ä' => 'a',
            'ö' => 'o',
            'ü' => 'u',
            'ß' => 'ss',
            'à' => 'a',
            'á' => 'a',
            'â' => 'a',
            'ã' => 'a',
            'å' => 'a',
            'æ' => 'ae',
            'ç' => 'c',
            'è' => 'e',
            'é' => 'e',
            'ê' => 'e',
            'ë' => 'e',
            'ì' => 'i',
            'í' => 'i',
            'î' => 'i',
            'ï' => 'i',
            'ñ' => 'n',
            'ò' => 'o',
            'ó' => 'o',
            'ô' => 'o',
            'õ' => 'o',
            'ø' => 'o',
            'ù' => 'u',
            'ú' => 'u',
            'û' => 'u',
            'ý' => 'y',
            'ÿ' => 'y',
        ]);

        if (function_exists('iconv')) {
            $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($transliterated) && $transliterated !== '') {
                $value = $transliterated;
            }
        }

        return $value;
    }

    private function getSharedConfiguredEntitiesForNaming(): array
    {
        if (!method_exists($this, 'getConfiguredEntities')) {
            return [];
        }

        $method = new ReflectionMethod($this, 'getConfiguredEntities');
        $entities = $method->getNumberOfParameters() === 0
            ? $this->getConfiguredEntities()
            : $this->getConfiguredEntities(__FUNCTION__);

        return $entities;
    }
}
