<?php

declare(strict_types=1);

trait HAParentConnectionTrait
{
    private function isKernelReady(): bool
    {
        return IPS_GetKernelRunlevel() === KR_READY;
    }

    protected function isModuleRuntimeReady(): bool
    {
        if (!$this->isKernelReady()) {
            return false;
        }

        if (!property_exists($this, 'InstanceID')) {
            return false;
        }

        $instanceId = (int)($this->InstanceID ?? 0);
        if ($instanceId <= 0 || !IPS_InstanceExists($instanceId)) {
            return false;
        }

        return is_string(@$this->Translate(''));
    }

    private function determineParentRuntimeState(array $moduleIds): string
    {
        $parentId = $this->getCurrentParentId();
        if ($parentId <= 0 || !IPS_InstanceExists($parentId)) {
            return 'missing';
        }

        if (!$this->hasCompatibleParentModules($moduleIds)) {
            return 'invalid';
        }

        if (!$this->HasActiveParent()) {
            return 'inactive';
        }

        return 'active';
    }

    private function getCurrentParentId(): int
    {
        $instance = IPS_GetInstance($this->InstanceID);
        return (int)($instance['ConnectionID'] ?? 0);
    }

    private function hasCompatibleParentModule(string $moduleId): bool
    {
        return $this->hasCompatibleParentModules([$moduleId]);
    }

    private function hasCompatibleParentModules(array $moduleIds): bool
    {
        $parentId = $this->getCurrentParentId();
        if ($parentId <= 0 || !IPS_InstanceExists($parentId)) {
            return false;
        }

        $parent = IPS_GetInstance($parentId);
        $currentModuleId = (string)($parent['ModuleInfo']['ModuleID'] ?? '');
        return in_array($currentModuleId, $moduleIds, true);
    }

    private function hasCompatibleActiveParentModule(string $moduleId): bool
    {
        return $this->hasCompatibleParentModule($moduleId) && $this->HasActiveParent();
    }

    private function hasCompatibleActiveParentModules(array $moduleIds): bool
    {
        return $this->hasCompatibleParentModules($moduleIds) && $this->HasActiveParent();
    }

    private function buildCurrentParentDebugContext(): array
    {
        $parentId = $this->getCurrentParentId();
        if ($parentId <= 0 || !IPS_InstanceExists($parentId)) {
            return [
                'ParentID' => 0,
                'ParentName' => '',
                'ModuleName' => '',
                'ModuleID' => '',
                'ParentStatus' => 0
            ];
        }

        $parent = IPS_GetInstance($parentId);
        return [
            'ParentID' => $parentId,
            'ParentName' => IPS_GetName($parentId),
            'ModuleName' => (string)($parent['ModuleInfo']['ModuleName'] ?? ''),
            'ModuleID' => (string)($parent['ModuleInfo']['ModuleID'] ?? ''),
            'ParentStatus' => (int)($parent['InstanceStatus'] ?? 0)
        ];
    }

    /**
     * Registriert die Instanz auf Statuswechsel ihres direkten Parents. Gehört nach dem Muster
     * des HomeConnect-Moduls in Create(): Alle Instanzen werden vor KR_READY konstruiert,
     * dadurch entsteht kein Rennen zwischen Registrierung und dem Wechsel, den sie hören soll.
     *
     * ACHTUNG: Hier darf KEIN SetStatus() stehen — das verhindert die Instanzerzeugung
     * (am 30.08.2026 auf dem nuc erprobt: alle Splitter fielen auf Status 105).
     */
    protected function registerParentStatusTracking(): void
    {
        try {
            $parentId = (int) (IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
            if ($parentId > 0 && IPS_InstanceExists($parentId)) {
                $this->RegisterMessage($parentId, IM_CHANGESTATUS);
            }
        } catch (Throwable) {
            // Beim allerersten Anlegen existiert noch kein Parent; ApplyChanges holt es nach.
        }
    }

    /**
     * Entprellt Statusmeldungen des Parents: liefert nur bei einem echten Wechsel true.
     * Muster aus dem HomeConnect-Modul — ein flatternder Parent feuert IM_CHANGESTATUS sonst
     * mehrfach je Sekunde mit demselben Wert.
     */
    protected function isNewParentStatus(int $newStatus): bool
    {
        if ((int) $this->GetBuffer('HALastParentStatus') === $newStatus) {
            return false;
        }

        $this->SetBuffer('HALastParentStatus', (string) $newStatus);
        return true;
    }

    private function syncParentStatusMessageRegistration(): void
    {
        $currentParentId = $this->getCurrentParentId();

        foreach ($this->GetMessageList() as $senderId => $messages) {
            if ($senderId <= 0 || $senderId === $this->InstanceID || $senderId === $currentParentId || !is_array($messages)) {
                continue;
            }

            if (in_array(IM_CHANGESTATUS, $messages, true)) {
                $this->UnregisterMessage($senderId, IM_CHANGESTATUS);
            }
        }

        if ($currentParentId > 0 && IPS_InstanceExists($currentParentId)) {
            $this->RegisterMessage($currentParentId, IM_CHANGESTATUS);
        }
    }
}
