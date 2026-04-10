<?php

declare(strict_types=1);

trait HADomainStateHandlersTrait
{
    private function tryHandleStateFromTopic(string $topic, string $payload): bool
    {
        if ($topic === '') {
            $this->debugExpert(__FUNCTION__, 'Leeres Topic, ignoriert.');
            return false;
        }

        $parts = explode('/', trim($topic, '/'));
        if (count($parts) < 3) {
            $this->debugExpert(__FUNCTION__, 'Topic zu kurz', ['Topic' => $topic]);
            return false;
        }

        $suffix = $parts[count($parts) - 1];
        if ($suffix !== 'state' && $suffix !== 'set') {
            return $this->tryHandleAttributeFromTopic($topic, $payload);
        }

        $entity = $parts[count($parts) - 2];
        $domain = $parts[count($parts) - 3];
        $entityId = $domain . '.' . $entity;
        if (!$this->isManagedEntityId($entityId)) {
            $this->debugExpert(__FUNCTION__, 'Fremde Entity ignoriert', ['EntityID' => $entityId, 'Topic' => $topic]);
            return false;
        }

        // State topics and initial REST intentionally share the same domain logic.
        $parsed = $this->parseEntityPayload($payload);
        $rawState = (string)($parsed[self::KEY_STATE] ?? '');
        $this->updateEntityRawStateCache($entityId, $rawState);
        $this->updateAvailabilityValue($entityId, $rawState);
        $this->applyParsedEntityState($entityId, $parsed);
        return true;
    }

    private function shouldSkipStateSetValue(string $ident, mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        $normalized = strtolower(trim($value));
        if (!in_array($normalized, ['unknown', 'unavailable'], true)) {
            return false;
        }

        $varId = @$this->GetIDForIdent($ident);
        if ($varId === false) {
            return false;
        }

        $varType = IPS_GetVariable($varId)['VariableType'] ?? null;
        return $varType !== VARIABLETYPE_STRING;
    }
}
