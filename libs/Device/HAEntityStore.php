<?php

declare(strict_types=1);

trait HAEntityStoreTrait
{

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

        $configData = $this->decodeJsonArray($this->ReadPropertyString(self::PROP_DEVICE_CONFIG), 'findEntityByIdent');
        if ($configData === null) {
            return null;
        }
        foreach ($configData as $row) {
            $row = $this->normalizeEntity($row, 'findEntityByIdent');
            if ($row === null) {
                continue;
            }
            if (($row['create_var'] ?? true) === false) {
                continue;
            }
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

    private function storeEntityAttributes(string $entityId, array $attributes): array
    {
        if (!isset($this->entities[$entityId])) {
            $this->entities[$entityId] = [
                'entity_id' => $entityId,
                'domain'    => $this->getEntityDomain($entityId),
                'name'      => $entityId
            ];
        }

        $domain = $this->getEntityDomain($entityId);
        if ($domain !== '') {
            $attributes = $this->filterAttributesByDomain($domain, $attributes, __FUNCTION__);
        }

        $existing = $this->entities[$entityId]['attributes'] ?? [];
        if (!is_array($existing)) {
            $existing = [];
        }

        $this->entities[$entityId]['attributes'] = array_merge($existing, $attributes);
        return $attributes;
    }

    private function storeEntityAttribute(string $entityId, string $attribute, mixed $value): void
    {
        $this->storeEntityAttributes($entityId, [$attribute => $value]);
    }

    private function updateEntityCache(string $entityId, mixed $state, ?array $attributes): void
    {
        $cache = json_decode($this->ReadAttributeString('EntityStateCache'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($cache)) {
            $cache = [];
        }

        $entry = $cache[$entityId] ?? [];
        if ($state !== null) {
            $entry[self::KEY_STATE] = $state;
        }
        if (is_array($attributes)) {
            $existing            = isset($entry[self::KEY_ATTRIBUTES]) && is_array($entry[self::KEY_ATTRIBUTES]) ? $entry[self::KEY_ATTRIBUTES] : [];
            $entry[self::KEY_ATTRIBUTES] = array_merge($existing, $attributes);
        }
        $entry['ts']      = time();
        $cache[$entityId] = $entry;

        $this->WriteAttributeString('EntityStateCache', json_encode($cache, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->updateDiagnosticsLabels();
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

        $existing = $this->entities[$entityId]['attributes'] ?? [];
        if (!is_array($existing)) {
            $existing = [];
        }
        $mergedAttributes = array_merge($existing, $attributes);
        $this->entities[$entityId]['attributes'] = $mergedAttributes;
        $entity = $this->entities[$entityId];
        $entity['attributes'] = $mergedAttributes;
        $type = $this->getVariableType($domain, $entity['attributes']);
        $presentation = $this->getEntityPresentation($domain, $entity, $type);
        $position = $this->getEntityPosition($entityId);
        $name = $this->getEntityVariableName($domain, $entity);

        $this->MaintainVariable($ident, $name, $type, $presentation, $position, true);

        if ($domain === HALockDefinitions::DOMAIN) {
            $this->DisableAction($ident);
            $this->maintainLockActionVariable($entity);
        }
        if ($domain === HAVacuumDefinitions::DOMAIN) {
            $this->maintainVacuumActionVariable($entity);
            $this->maintainVacuumFanSpeedVariable($entity);
        }
        if ($domain === HAFanDefinitions::DOMAIN) {
            $this->maintainFanAttributeVariables($entity);
        }
        if ($domain === HAHumidifierDefinitions::DOMAIN) {
            $this->maintainHumidifierAttributeVariables($entity);
        }
        if ($domain === HAMediaPlayerDefinitions::DOMAIN) {
            $this->maintainMediaPlayerActionVariable($entity);
            $this->maintainMediaPlayerPowerVariable($entity);
            $this->maintainMediaPlayerAttributeVariables($entity);
        }
    }

    private function updateDiagnosticsLabels(): void
    {
        $lastMqtt = $this->ReadAttributeString('LastMQTTMessage');
        if ($lastMqtt === '') {
            $lastMqtt = 'nie';
        }
        $this->updateFormFieldSafe('DiagLastMQTT', 'caption', 'Letzte MQTT-Message: ' . $lastMqtt);

        $lastRest = $this->ReadAttributeString('LastRESTFetch');
        if ($lastRest === '') {
            $lastRest = 'nie';
        }
        $this->updateFormFieldSafe('DiagLastREST', 'caption', 'Letzter REST-Abruf: ' . $lastRest);

        $count = count($this->entities);
        $this->updateFormFieldSafe('DiagEntityCount', 'caption', 'Entitäten (aktiv): ' . $count);
    }
}
