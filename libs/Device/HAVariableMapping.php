<?php

declare(strict_types=1);

trait HAVariableMappingTrait
{
    // Normale Statusvariablen speichern den letzten Zustand dauerhaft.
    private function createStateVariableDescriptor(): array
    {
        return [
            'kind' => 'state'
        ];
    }

    // Trigger-Variablen werden nach der Aktion wieder auf einen Grundwert gesetzt.
    private function createTriggerVariableDescriptor(mixed $resetValue = -1): array
    {
        return [
            'kind'       => 'trigger',
            'resetValue' => $resetValue
        ];
    }

    // Diese Suffixe kennzeichnen reine Aktionsvariablen ohne dauerhaften Status.
    private function getTriggerVariableSuffixes(): array
    {
        return [
            self::LOCK_ACTION_SUFFIX,
            self::VACUUM_ACTION_SUFFIX,
            self::LAWN_MOWER_ACTION_SUFFIX,
            self::MEDIA_PLAYER_ACTION_SUFFIX
        ];
    }

    // Buttons werden in Symcon immer als Trigger statt als persistenter Zustand geführt.
    private function describeEntityMainVariable(array $entity): array
    {
        $domain = $this->normalizeDomainAlias((string) ($entity['domain'] ?? ''));
        if ($domain === HAButtonDefinitions::DOMAIN) {
            return $this->createTriggerVariableDescriptor();
        }

        return $this->createStateVariableDescriptor();
    }

    // Neben dem Domainwissen werden auch bekannte Aktionssuffixe ausgewertet.
    private function describeVariableByIdent(string $ident, ?string $domain = null): array
    {
        $normalizedDomain = $this->normalizeDomainAlias((string) $domain);
        if ($normalizedDomain === HAButtonDefinitions::DOMAIN) {
            return $this->createTriggerVariableDescriptor();
        }

        if (array_any(
            $this->getTriggerVariableSuffixes(),
            static fn(string $suffix): bool => str_ends_with($ident, $suffix)
        )) {
            return $this->createTriggerVariableDescriptor();
        }

        return $this->createStateVariableDescriptor();
    }

    // Der Descriptor kapselt das Verhalten, nicht nur den Variablentyp.
    private function isTriggerVariableDescriptor(array $descriptor): bool
    {
        return ($descriptor['kind'] ?? 'state') === 'trigger';
    }

    // Neue Trigger werden direkt auf ihren Resetwert initialisiert.
    private function initializeVariableDescriptorValue(string $ident, array $descriptor, bool $exists): void
    {
        if ($exists || !$this->isTriggerVariableDescriptor($descriptor)) {
            return;
        }

        $this->resetVariableByDescriptor($ident, $descriptor);
    }

    // Nach Aktionen wird der sichtbare Triggerzustand wieder neutralisiert.
    private function resetVariableByDescriptor(string $ident, array $descriptor): void
    {
        if (!$this->isTriggerVariableDescriptor($descriptor)) {
            return;
        }
        if (@$this->GetIDForIdent($ident) === false) {
            return;
        }

        $resetValue = $descriptor['resetValue'] ?? null;
        if ($resetValue === null) {
            return;
        }

        $this->setValueWithDebug($ident, $resetValue);
    }

    // Einheitlicher Cast verhindert verteilte Typkonvertierungen im Modul.
    private function castVariableValue(mixed $value, int $type): string|int|bool|float
    {
        return match ($type) {
            VARIABLETYPE_BOOLEAN => (bool) $value,
            VARIABLETYPE_INTEGER => (int) $value,
            VARIABLETYPE_FLOAT => (float) $value,
            default => (string) $value,
        };
    }
}
