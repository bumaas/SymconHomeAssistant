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
                trim((string)($entity['device_name'] ?? ''))
            );

            $descriptors[$key] = [
                'key' => $key,
                'domain' => $domain,
                'object_id' => $objectId,
                'local_object_id' => $localObjectId,
                'is_primary' => $localObjectId === '',
                'sort_key' => $domain . '|' . $objectId . '|' . $key
            ];
        }

        uasort($descriptors, static fn(array $left, array $right): int => strcmp($left['sort_key'], $right['sort_key']));

        $usedTokens = [];
        $assignments = [];
        foreach ($descriptors as $descriptor) {
            $domain = $descriptor['domain'];
            $objectId = $descriptor['object_id'];
            $localObjectId = $descriptor['local_object_id'];
            $isPrimary = $descriptor['is_primary'];

            $stemCandidates = [];
            if ($localObjectId === '') {
                $stemCandidates[] = '';
            } else {
                $stemCandidates[] = $localObjectId;
            }

            $normalizedObjectId = $this->normalizeSharedIdentFragment($objectId);
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
            $usedTokens[$assignment['ident']] = true;

            $assignments[$descriptor['key']] = array_merge($assignment, [
                'object_id' => $objectId,
                'local_object_id' => $localObjectId
            ]);
        }

        return $assignments;
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
        $ident = trim((string)($this->entities[$entityId]['ident'] ?? ''));
        if ($ident !== '') {
            return $ident;
        }

        $entity = $this->findSharedConfiguredEntityByEntityId($entityId);
        $ident = trim((string)($entity['ident'] ?? ''));
        if ($ident !== '') {
            return $ident;
        }

        return $this->sanitizeIdent($entityId);
    }

    private function getSharedEntityIdentPrefix(string $entityId): string
    {
        $prefix = trim((string)($this->entities[$entityId]['ident_prefix'] ?? ''));
        if ($prefix !== '') {
            return $prefix;
        }

        $entity = $this->findSharedConfiguredEntityByEntityId($entityId);
        $prefix = trim((string)($entity['ident_prefix'] ?? ''));
        if ($prefix !== '') {
            return $prefix;
        }

        return $this->getSharedEntityMainIdent($entityId);
    }

    private function buildSharedAttributeIdent(string $entityId, string $attribute): string
    {
        return $this->buildSharedAttributeIdentFromPrefix($this->getSharedEntityIdentPrefix($entityId), $attribute);
    }

    private function buildSharedAttributeIdentFromPrefix(string $identPrefix, string $attribute): string
    {
        return $this->normalizeSharedIdentFragment($identPrefix . '_' . $attribute);
    }

    private function buildSharedSuffixIdent(string $entityId, string $suffix): string
    {
        return $this->buildSharedSuffixIdentFromPrefix($this->getSharedEntityIdentPrefix($entityId), $suffix);
    }

    private function buildSharedSuffixIdentFromPrefix(string $identPrefix, string $suffix): string
    {
        return $this->normalizeSharedIdentFragment($identPrefix . $suffix);
    }

    private function getSharedEntityIdByMainIdent(string $ident): ?string
    {
        return $this->sharedEntityIdentIndex[$ident] ?? null;
    }

    private function getSharedEntityIdByPrefix(string $prefix): ?string
    {
        return $this->sharedEntityPrefixIndex[$prefix] ?? null;
    }

    private function findSharedConfiguredEntityByMainIdent(string $ident): ?array
    {
        foreach ($this->getSharedConfiguredEntitiesForNaming() as $row) {
            if (($row['ident'] ?? '') === $ident) {
                return $row;
            }
        }

        return null;
    }

    private function findSharedConfiguredEntityByPrefix(string $prefix): ?array
    {
        foreach ($this->getSharedConfiguredEntitiesForNaming() as $row) {
            if (($row['ident_prefix'] ?? '') === $prefix) {
                return $row;
            }
        }

        return null;
    }

    private function findSharedConfiguredEntityByEntityId(string $entityId): ?array
    {
        foreach ($this->getSharedConfiguredEntitiesForNaming() as $row) {
            if (($row['entity_id'] ?? '') === $entityId) {
                return $row;
            }
        }

        return null;
    }

    private function buildSharedLocalObjectId(string $objectId, string $deviceName): string
    {
        $normalizedObjectId = $this->normalizeSharedIdentFragment($objectId);
        if ($normalizedObjectId === '') {
            return '';
        }

        foreach ($this->getSharedRedundantObjectNameCandidates($deviceName) as $candidate) {
            if (str_starts_with($normalizedObjectId, $candidate . '_')) {
                return (string)substr($normalizedObjectId, strlen($candidate) + 1);
            }
            if ($normalizedObjectId === $candidate) {
                return '';
            }
        }

        return $normalizedObjectId;
    }

    private function getSharedRedundantObjectNameCandidates(string $deviceName): array
    {
        $candidates = [];

        $normalizedDeviceName = $this->normalizeSharedIdentFragment($deviceName);
        if ($normalizedDeviceName !== '') {
            $candidates[] = $normalizedDeviceName;
        }

        if (method_exists($this, 'getSharedCurrentInstanceDeviceName')) {
            $instanceDeviceName = $this->normalizeSharedIdentFragment((string)$this->getSharedCurrentInstanceDeviceName());
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

        try {
            $method = new ReflectionMethod($this, 'getConfiguredEntities');
        } catch (ReflectionException) {
            return [];
        }

        $entities = $method->getNumberOfParameters() === 0
            ? $this->getConfiguredEntities()
            : $this->getConfiguredEntities(__FUNCTION__);

        return is_array($entities) ? $entities : [];
    }
}
