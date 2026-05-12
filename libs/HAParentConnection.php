<?php

declare(strict_types=1);

trait HAParentConnectionTrait
{
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
