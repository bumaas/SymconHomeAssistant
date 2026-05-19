<?php

declare(strict_types=1);

trait HALegacyVariableMigrationTrait
{
    private function markVariableAsLegacy(int $variableId): bool
    {
        $object = IPS_GetObject($variableId);
        if ((int)($object['ObjectType'] ?? -1) !== OBJECTTYPE_VARIABLE) {
            return false;
        }

        $currentIdent = trim((string)($object['ObjectIdent'] ?? ''));
        $currentName = trim((string)($object['ObjectName'] ?? ''));
        $currentPosition = (int)($object['ObjectPosition'] ?? 0);
        $legacyIdent = $this->buildAvailableLegacyIdent($currentIdent, $variableId);
        $legacyName = $this->buildLegacyName($currentName);
        $changed = false;

        if ($legacyIdent !== '' && $legacyIdent !== $currentIdent) {
            IPS_SetIdent($variableId, $legacyIdent);
            $changed = true;
        }

        if ($legacyName !== '' && $legacyName !== $currentName) {
            IPS_SetName($variableId, $legacyName);
            $changed = true;
        }

        if ($this->shouldMoveLegacyVariableToEnd($variableId, $currentPosition)) {
            IPS_SetPosition($variableId, $this->getLegacyVariableEndPosition($variableId));
            $changed = true;
        }

        IPS_SetVariableCustomAction($variableId, 0);
        return $changed;
    }

    private function shouldMoveLegacyVariableToEnd(int $variableId, int $currentPosition): bool
    {
        return $currentPosition <= $this->getLegacyBaselinePosition($variableId);
    }

    private function getLegacyVariableEndPosition(int $variableId): int
    {
        $maxPosition = 0;
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childId) {
            if ($childId === $variableId) {
                continue;
            }

            $position = (int)(IPS_GetObject($childId)['ObjectPosition'] ?? 0);
            $maxPosition = max($maxPosition, $position);
        }

        return $maxPosition + $this->getLegacyPositionStep();
    }

    private function getLegacyBaselinePosition(int $variableId): int
    {
        $maxPosition = 0;
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childId) {
            if ($childId === $variableId) {
                continue;
            }

            $object = IPS_GetObject($childId);
            $ident = trim((string)($object['ObjectIdent'] ?? ''));
            if ($ident !== '' && $this->isLegacyIdent($ident)) {
                continue;
            }

            $position = (int)($object['ObjectPosition'] ?? 0);
            $maxPosition = max($maxPosition, $position);
        }

        return $maxPosition;
    }

    private function buildAvailableLegacyIdent(string $ident, int $objectId): string
    {
        $ident = trim($ident);
        if ($ident === '') {
            return '';
        }

        if ($this->isLegacyIdent($ident)) {
            return $ident;
        }

        $baseIdent = $ident . $this->getLegacyIdentSuffix();
        $candidate = $baseIdent;
        $counter = 2;
        while (true) {
            $existingObjectId = @$this->GetIDForIdent($candidate);
            if ($existingObjectId === false || $existingObjectId === $objectId) {
                return $candidate;
            }

            $candidate = $baseIdent . '_' . $counter;
            $counter++;
        }
    }

    private function buildLegacyName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return 'veraltet';
        }

        $suffix = $this->getLegacyNameSuffix();
        if (str_ends_with($name, $suffix)) {
            return $name;
        }

        return $name . $suffix;
    }

    private function isLegacyIdent(string $ident): bool
    {
        return preg_match('/' . preg_quote($this->getLegacyIdentSuffix(), '/') . '(?:_\\d+)?$/', $ident) === 1;
    }

    private function getLegacyIdentSuffix(): string
    {
        return '_veraltet';
    }

    private function getLegacyNameSuffix(): string
    {
        return ' (veraltet)';
    }

    private function getLegacyPositionStep(): int
    {
        return 10;
    }
}
