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
            HAClimateDefinitions::DOMAIN => fn() => $this->handleStateTopicClimate($ident, $entityId, $payload),
            HAFanDefinitions::DOMAIN => fn() => $this->handleStateTopicFan($ident, $entityId, $payload),
            HAHumidifierDefinitions::DOMAIN => fn() => $this->handleStateTopicHumidifier($ident, $entityId, $payload),
            HAMediaPlayerDefinitions::DOMAIN => fn() => $this->handleStateTopicMediaPlayer($ident, $entityId, $payload)
        ];
        if (isset($handlers[$domain])) {
            return $handlers[$domain]();
        }

        if (!$this->ensureStateVariable($ident)) {
            return false;
        }

        $parsed = $this->parseEntityPayload($payload);
        $attributes = [];
        if (!empty($parsed[self::KEY_ATTRIBUTES]) && is_array($parsed[self::KEY_ATTRIBUTES])) {
            $attributes = $parsed[self::KEY_ATTRIBUTES];
        } elseif (!empty($this->entities[$entityId][self::KEY_ATTRIBUTES])
                  && is_array($this->entities[$entityId][self::KEY_ATTRIBUTES])) {
            $attributes = $this->entities[$entityId][self::KEY_ATTRIBUTES];
        }
        $value = $this->convertValueByDomain($domain, $parsed[self::KEY_STATE], $attributes);

        $this->debugExpert(
            'StateTopic',
            'SetValue',
            ['Ident' => $ident, 'Domain' => $domain, 'Entity' => $entity, 'Value' => $value]
        );
        if ($value !== null) {
            $this->setValueWithDebug($ident, $value);
        }
        $this->updateEntityCache($entityId, $parsed[self::KEY_STATE], $parsed[self::KEY_ATTRIBUTES] ?? null);

        if (!empty($parsed[self::KEY_ATTRIBUTES])) {
            $this->updateEntityPresentation($entityId, $this->entities[$entityId][self::KEY_ATTRIBUTES] ?? []);
        }
        return true;
    }

    private function handleStateTopicCover(string $ident, string $entityId, string $payload): bool
    {
        return $this->handleStateTopicWithLevel(
            $ident,
            $entityId,
            $payload,
            fn(string $state): ?float => $this->normalizeCoverStateToLevel($state)
        );
    }

    private function handleStateTopicLight(string $ident, string $entityId, string $payload): bool
    {
        return $this->handleStateTopicWithAttributes(
            $ident,
            $entityId,
            $payload,
            fn(string $state, array $attributes): mixed => $this->convertValueByDomain(HALightDefinitions::DOMAIN, $state, $attributes),
            function (string $id, array $attributes, string $state): void {
                if ($attributes === []) {
                    return;
                }
                $this->updateLightAttributeValues($id, $attributes);
            }
        );
    }

    private function handleStateTopicLock(string $ident, string $entityId, string $payload): bool
    {
        return $this->handleStateTopicWithAttributes(
            $ident,
            $entityId,
            $payload,
            fn(string $state, array $attributes): mixed => $this->resolveLockDisplayState($state, $attributes),
            fn(string $id, array $attributes, string $state) => $this->updateLockActionValue($id, $state, $attributes)
        );
    }

    private function handleStateTopicVacuum(string $ident, string $entityId, string $payload): bool
    {
        return $this->handleStateTopicWithAttributes(
            $ident,
            $entityId,
            $payload,
            null,
            fn(string $id, array $attributes, string $state) => $this->updateVacuumFanSpeedValue($id, $attributes)
        );
    }

    private function handleStateTopicFan(string $ident, string $entityId, string $payload): bool
    {
        return $this->handleStateTopicWithAttributes(
            $ident,
            $entityId,
            $payload,
            fn(string $state, array $attributes): mixed => $this->convertValueByDomain(HAFanDefinitions::DOMAIN, $state, $attributes),
            fn(string $id, array $attributes, string $state) => $this->updateFanAttributeValues($id, $attributes)
        );
    }

    private function handleStateTopicHumidifier(string $ident, string $entityId, string $payload): bool
    {
        return $this->handleStateTopicWithAttributes(
            $ident,
            $entityId,
            $payload,
            fn(string $state, array $attributes): mixed => $this->convertValueByDomain(HAHumidifierDefinitions::DOMAIN, $state, $attributes),
            fn(string $id, array $attributes, string $state) => $this->updateHumidifierAttributeValues($id, $attributes)
        );
    }

    private function handleStateTopicClimate(string $ident, string $entityId, string $payload): bool
    {
        return $this->handleStateTopicNumericOrAttribute(
            $ident,
            $entityId,
            $payload,
            HAClimateDefinitions::ATTRIBUTE_HVAC_MODE
        );
    }

    private function handleStateTopicMediaPlayer(string $ident, string $entityId, string $payload): bool
    {
        return $this->handleStateTopicWithAttributes(
            $ident,
            $entityId,
            $payload,
            null,
            fn(string $id, array $attributes, string $state) => $this->updateMediaPlayerAttributeValues($id, $attributes)
        );
    }

    private function handleStateTopicWithAttributes(
        string $ident,
        string $entityId,
        string $payload,
        ?callable $stateValueResolver,
        ?callable $attributeUpdater
    ): bool {
        $parsed = $this->parseStatePayloadForEntity($entityId, $payload);
        $stateValue = $parsed['state'];
        $attributes = is_array($parsed['attributes']) ? $parsed['attributes'] : [];
        if ($stateValue !== '') {
            if (!$this->ensureStateVariable($ident)) {
                return false;
            }
            $value = $stateValueResolver !== null
                ? $stateValueResolver($stateValue, $attributes)
                : $stateValue;
            if ($value !== null) {
                $this->setValueWithDebug($ident, $value);
            }
        }

        $updateAttributes = [];
        if (is_array($parsed['attributes'])) {
            $updateAttributes = $parsed['attributes'];
        } elseif (is_array($parsed['raw_attributes'])) {
            $updateAttributes = $parsed['raw_attributes'];
        }
        if ($attributeUpdater !== null) {
            $attributeUpdater($entityId, $updateAttributes, $stateValue);
        }

        $this->updateEntityCache($entityId, $parsed['state'], $parsed['raw_attributes']);
        $this->updateEntityPresentation($entityId, $this->entities[$entityId][self::KEY_ATTRIBUTES] ?? []);
        return true;
    }

    private function handleStateTopicWithLevel(
        string $ident,
        string $entityId,
        string $payload,
        callable $levelResolver
    ): bool {
        $level = $levelResolver($payload);
        if ($level === null) {
            return true;
        }
        if (!$this->ensureStateVariable($ident)) {
            return false;
        }
        $this->setValueWithDebug($ident, $level);
        $this->updateEntityCache($entityId, $level, null);
        return true;
    }

    private function handleStateTopicNumericOrAttribute(
        string $ident,
        string $entityId,
        string $payload,
        string $attribute
    ): bool {
        if (is_numeric($payload)) {
            if (!$this->ensureStateVariable($ident)) {
                return false;
            }
            $value = (float)$payload;
            $this->setValueWithDebug($ident, $value);
            $this->updateEntityCache($entityId, $value, null);
            return true;
        }
        if ($payload === '') {
            return true;
        }
        $this->storeEntityAttribute($entityId, $attribute, $payload);
        $this->updateEntityCache($entityId, null, [$attribute => $payload]);
        $this->updateEntityPresentation($entityId, $this->entities[$entityId][self::KEY_ATTRIBUTES] ?? []);
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

