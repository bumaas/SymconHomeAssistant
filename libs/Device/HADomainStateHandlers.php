<?php

declare(strict_types=1);

trait HADomainStateHandlersTrait
{
    private function tryHandleStateFromTopic(string $topic, string $payload): bool
    {
        if ($topic === '') {
            $this->debugExpert('StateTopic', 'Leeres Topic, ignoriert.');
            return false;
        }

        $parts = explode('/', trim($topic, '/'));
        if (count($parts) < 3) {
            $this->debugExpert('StateTopic', 'Topic zu kurz', ['Topic' => $topic]);
            return false;
        }

        $suffix = $parts[count($parts) - 1];
        if ($suffix !== 'state' && $suffix !== 'set') {
            return $this->tryHandleAttributeFromTopic($topic, $payload);
        }

        $entity = $parts[count($parts) - 2];
        $domain = $parts[count($parts) - 3];
        $entityId = $domain . '.' . $entity;

        $ident = $this->sanitizeIdent($domain . '_' . $entity);
        if ($domain === HAEventDefinitions::DOMAIN) {
            $this->debugExpert('StateTopic', 'Event-State ignoriert', ['EntityID' => $entityId]);
            return true;
        }

        $handlers = [
            HACoverDefinitions::DOMAIN => fn() => $this->handleStateTopicCover($ident, $entityId, $payload),
            HALightDefinitions::DOMAIN => fn() => $this->handleStateTopicLight($ident, $entityId, $payload),
            HALockDefinitions::DOMAIN => fn() => $this->handleStateTopicLock($ident, $entityId, $payload),
            HAVacuumDefinitions::DOMAIN => fn() => $this->handleStateTopicVacuum($ident, $entityId, $payload),
            HAClimateDefinitions::DOMAIN => fn() => $this->handleStateTopicClimate($ident, $entityId, $payload)
        ];
        if (isset($handlers[$domain])) {
            return $handlers[$domain]();
        }

        if (!$this->ensureStateVariable($ident)) {
            return false;
        }

        $parsed = $this->parseEntityPayload($payload);
        $value = $this->convertValueByDomain($domain, $parsed[self::KEY_STATE]);

        $this->debugExpert(
            'StateTopic',
            'SetValue',
            ['Ident' => $ident, 'Domain' => $domain, 'Entity' => $entity, 'Value' => $value]
        );
        $this->SetValue($ident, $value);
        $this->updateEntityCache($entityId, $parsed[self::KEY_STATE], $parsed[self::KEY_ATTRIBUTES] ?? null);

        if (!empty($parsed[self::KEY_ATTRIBUTES])) {
            $this->updateEntityPresentation($entityId, $this->entities[$entityId][self::KEY_ATTRIBUTES] ?? []);
        }
        return true;
    }

    private function handleStateTopicCover(string $ident, string $entityId, string $payload): bool
    {
        $level = $this->normalizeCoverStateToLevel($payload);
        if ($level === null) {
            return true;
        }
        if (!$this->ensureStateVariable($ident)) {
            return false;
        }
        $this->SetValue($ident, $level);
        $this->updateEntityCache($entityId, $level, null);
        return true;
    }

    private function handleStateTopicLight(string $ident, string $entityId, string $payload): bool
    {
        if (!$this->ensureStateVariable($ident)) {
            return false;
        }

        $parsed = $this->parseStatePayloadForEntity($entityId, $payload);
        $value = $this->convertValueByDomain(HALightDefinitions::DOMAIN, $parsed['state']);

        $this->SetValue($ident, $value);
        $this->updateEntityCache($entityId, $parsed['state'], $parsed['raw_attributes']);

        if (!empty($parsed['raw_attributes'])) {
            $attributes = $parsed['attributes'] ?? $parsed['raw_attributes'];
            $this->updateLightAttributeValues($entityId, $attributes);
            $this->updateEntityPresentation($entityId, $this->entities[$entityId][self::KEY_ATTRIBUTES] ?? []);
        }

        return true;
    }

    private function handleStateTopicLock(string $ident, string $entityId, string $payload): bool
    {
        $parsed = $this->parseStatePayloadForEntity($entityId, $payload);
        $attributes = $parsed['attributes'];
        $displayState = $this->resolveLockDisplayState($parsed['state'], $attributes);
        if ($displayState !== null) {
            if (!$this->ensureStateVariable($ident)) {
                return false;
            }
            $this->SetValue($ident, $displayState);
        }
        $this->updateLockActionValue($entityId, $parsed['state'], $attributes);
        $this->updateEntityCache($entityId, $parsed['state'], $parsed['raw_attributes']);
        $this->updateEntityPresentation($entityId, $this->entities[$entityId][self::KEY_ATTRIBUTES] ?? []);
        return true;
    }

    private function handleStateTopicVacuum(string $ident, string $entityId, string $payload): bool
    {
        $parsed = $this->parseStatePayloadForEntity($entityId, $payload);
        $attributes = $parsed['attributes'];
        $stateValue = $parsed['state'];
        if ($stateValue !== '') {
            if (!$this->ensureStateVariable($ident)) {
                return false;
            }
            $this->SetValue($ident, $stateValue);
        }
        $this->updateVacuumFanSpeedValue($entityId, $attributes);
        $this->updateEntityCache($entityId, $parsed['state'], $parsed['raw_attributes']);
        $this->updateEntityPresentation($entityId, $this->entities[$entityId][self::KEY_ATTRIBUTES] ?? []);
        return true;
    }

    private function handleStateTopicClimate(string $ident, string $entityId, string $payload): bool
    {
        if (is_numeric($payload)) {
            if (!$this->ensureStateVariable($ident)) {
                return false;
            }
            $this->SetValue($ident, (float)$payload);
            $this->updateEntityCache($entityId, (float)$payload, null);
        } elseif ($payload !== '') {
            $this->storeEntityAttribute($entityId, HAClimateDefinitions::ATTRIBUTE_HVAC_MODE, $payload);
            $this->updateEntityCache($entityId, null, [HAClimateDefinitions::ATTRIBUTE_HVAC_MODE => $payload]);
            $this->updateEntityPresentation($entityId, $this->entities[$entityId][self::KEY_ATTRIBUTES] ?? []);
        }
        return true;
    }

    private function ensureStateVariable(string $ident): bool
    {
        $varId = @$this->GetIDForIdent($ident);
        if ($varId === false) {
            $this->debugExpert('StateTopic', 'Variable nicht gefunden', ['Ident' => $ident]);
            return false;
        }
        return true;
    }

    private function parseStatePayloadForEntity(string $entityId, string $payload): array
    {
        $parsed = $this->parseEntityPayload($payload);
        $rawAttributes = $parsed[self::KEY_ATTRIBUTES] ?? null;
        $attributes = null;
        if (is_array($rawAttributes) && $rawAttributes !== []) {
            $attributes = $this->storeEntityAttributes($entityId, $rawAttributes);
        }

        return [
            'state' => (string)$parsed[self::KEY_STATE],
            'attributes' => $attributes,
            'raw_attributes' => is_array($rawAttributes) ? $rawAttributes : null
        ];
    }
}
