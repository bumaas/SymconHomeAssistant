<?php

declare(strict_types=1);

trait HAStandardAttributeMaintenanceTrait
{
    // Definition-based attribute domains share one creation and refresh pipeline.
    private function normalizeAttributesArray(mixed $attributes): array
    {
        return is_array($attributes) ? $attributes : [];
    }

    private function getStoredEntityDefinition(string $entityId, array $defaults = []): array
    {
        $entity = $this->entities[$entityId] ?? [];
        return array_replace(['entity_id' => $entityId], $defaults, $entity);
    }

    private function getAttributeVariableIdent(string $entityId, string $attribute): string
    {
        return $this->buildSharedAttributeIdent($entityId, $attribute);
    }

    private function attributeVariableExists(string $ident): bool
    {
        return @$this->GetIDForIdent($ident) !== false;
    }

    protected function syncAttributeActionState(string $ident, bool $enabled): void
    {
        if ($enabled) {
            $this->EnableAction($ident);
            return;
        }

        $this->DisableAction($ident);
    }

    private function syncStandardAttributeVariable(
        string $entityId,
        string $attribute,
        array $meta,
        array $attributes,
        callable $buildPresentation,
        callable $resolvePosition,
        ?callable $applyActionState = null,
        int $basePosition = 0,
        ?callable $afterMaintain = null,
        bool $applyActionStateOnExisting = true
    ): void {
        $ident = $this->getAttributeVariableIdent($entityId, $attribute);
        $exists = $this->attributeVariableExists($ident);
        $name = $this->Translate((string)($meta['caption'] ?? $attribute));
        $presentation = $buildPresentation($attribute, $attributes, $meta);
        $position = $resolvePosition($attribute, $basePosition);

        $this->MaintainVariable($ident, $name, (int)$meta['type'], $presentation, $position, true);

        if ($applyActionState !== null && ($applyActionStateOnExisting || !$exists)) {
            $applyActionState($attribute, $attributes, $ident, $presentation);
        }

        if ($afterMaintain !== null) {
            $afterMaintain($attribute, $meta, $entityId, $attributes, $basePosition);
        }
    }

    protected function maintainStandardAttributeVariables(
        array $entity,
        array $definitions,
        callable $shouldCreate,
        callable $buildPresentation,
        callable $resolvePosition,
        ?callable $applyActionState = null,
        int $basePosition = 0,
        ?callable $shouldSkip = null,
        ?callable $afterMaintain = null,
        bool $applyActionStateOnExisting = true
    ): void {
        $entityId = (string)($entity['entity_id'] ?? '');
        if ($entityId === '') {
            return;
        }

        $attributes = $this->normalizeAttributesArray($entity['attributes'] ?? []);
        foreach ($definitions as $attribute => $meta) {
            if (!is_array($meta)) {
                continue;
            }
            if ($shouldSkip !== null && $shouldSkip($attribute, $meta, $attributes)) {
                continue;
            }
            if (!$shouldCreate($attribute, $meta, $attributes)) {
                continue;
            }

            $this->syncStandardAttributeVariable(
                $entityId,
                $attribute,
                $meta,
                $attributes,
                $buildPresentation,
                $resolvePosition,
                $applyActionState,
                $basePosition,
                $afterMaintain,
                $applyActionStateOnExisting
            );
        }
    }

    protected function ensureStandardAttributeVariable(
        string $entityId,
        string $attribute,
        array $definitions,
        callable $shouldCreate,
        callable $buildPresentation,
        callable $resolvePosition,
        ?callable $applyActionState = null,
        int $basePosition = 0,
        array $entityDefaults = [],
        ?callable $prepareAttributes = null,
        ?callable $shouldSkip = null,
        ?callable $afterMaintain = null
    ): bool {
        $meta = $definitions[$attribute] ?? null;
        if (!is_array($meta)) {
            return false;
        }

        $entity = $this->getStoredEntityDefinition($entityId, $entityDefaults);
        $attributes = $this->normalizeAttributesArray($entity['attributes'] ?? []);
        if ($prepareAttributes !== null) {
            $attributes = $this->normalizeAttributesArray($prepareAttributes($attribute, $attributes, $meta));
        }
        if ($shouldSkip !== null && $shouldSkip($attribute, $meta, $attributes)) {
            return false;
        }

        $ident = $this->getAttributeVariableIdent($entityId, $attribute);
        if ($this->attributeVariableExists($ident)) {
            return true;
        }
        if (!$shouldCreate($attribute, $meta, $attributes)) {
            return false;
        }

        $this->syncStandardAttributeVariable(
            $entityId,
            $attribute,
            $meta,
            $attributes,
            $buildPresentation,
            $resolvePosition,
            $applyActionState,
            $basePosition,
            $afterMaintain
        );
        return true;
    }

    protected function updateStandardAttributeValues(
        string $entityId,
        array $attributes,
        array $definitions,
        callable $mapValue,
        ?callable $afterUpdate = null,
        ?string $refreshDomain = null
    ): void {
        foreach ($definitions as $attribute => $meta) {
            if (!is_array($meta) || !array_key_exists($attribute, $attributes)) {
                continue;
            }

            $ident = $this->getAttributeVariableIdent($entityId, $attribute);
            if (!$this->attributeVariableExists($ident)) {
                continue;
            }

            $rawValue = $attributes[$attribute];
            $value = $mapValue($attribute, $rawValue, $meta, $attributes);
            $this->setValueWithDebug($ident, $value);

            if ($afterUpdate !== null) {
                $afterUpdate($attribute, $value, $rawValue, $meta, $attributes);
            }
        }

        if ($refreshDomain !== null) {
            $this->refreshDomainAttributePresentations($refreshDomain, $entityId, $attributes);
        }
    }

    protected function refreshStandardAttributePresentationIfExists(
        string $entityId,
        string $attribute,
        array $attributes,
        array $definitions,
        callable $buildPresentation,
        callable $resolvePosition,
        int $basePosition = 0
    ): void {
        $meta = $definitions[$attribute] ?? null;
        if (!is_array($meta)) {
            return;
        }

        $ident = $this->getAttributeVariableIdent($entityId, $attribute);
        if (!$this->attributeVariableExists($ident)) {
            return;
        }

        $presentation = $buildPresentation($attribute, $attributes, $meta);
        $position = $resolvePosition($attribute, $basePosition);
        $this->refreshAttributePresentation(
            $ident,
            (string)$meta['caption'],
            (int)$meta['type'],
            $presentation,
            $position
        );
    }
}
