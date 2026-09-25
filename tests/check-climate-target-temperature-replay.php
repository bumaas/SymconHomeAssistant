<?php

declare(strict_types=1);

foreach ([
    'VARIABLETYPE_BOOLEAN' => 0,
    'VARIABLETYPE_INTEGER' => 1,
    'VARIABLETYPE_FLOAT' => 2,
    'VARIABLETYPE_STRING' => 3,
    'VARIABLE_PRESENTATION_SWITCH' => 'Switch',
    'VARIABLE_PRESENTATION_VALUE_PRESENTATION' => 'ValuePresentation',
    'VARIABLE_PRESENTATION_DATE_TIME' => 'DateTime',
    'VARIABLE_PRESENTATION_DURATION' => 'Duration',
    'VARIABLE_PRESENTATION_ENUMERATION' => 'Enumeration',
    'VARIABLE_PRESENTATION_LEGACY' => 'Legacy',
    'VARIABLE_PRESENTATION_SLIDER' => 'Slider',
    'VARIABLE_PRESENTATION_COLOR' => 'Color',
    'VARIABLE_PRESENTATION_SHUTTER' => 'Shutter'
] as $name => $value) {
    if (!defined($name)) {
        define($name, $value);
    }
}

require_once __DIR__ . '/harness.php';
require_once dirname(__DIR__) . '/libs/HACommonIncludes.php';
require_once dirname(__DIR__) . '/libs/Device/HADomainValueMapping.php';
require_once dirname(__DIR__) . '/libs/Device/HAEntityStore.php';

function main(): void
{
    $harness = new ClimateReplayHarness();
    $entityId = 'climate.sr_klima';
    $attributes = [
        HAClimateDefinitions::ATTRIBUTE_SUPPORTED_FEATURES => 1,
        HAClimateDefinitions::ATTRIBUTE_TARGET_TEMPERATURE => 23.0,
        HAClimateDefinitions::ATTRIBUTE_CURRENT_TEMPERATURE => 24.0
    ];

    $converted = $harness->convertClimateState('cool', $attributes);
    pruefe(
        $converted === 23.0,
        'climate-Domänenabbildung liefert bei HVAC-Modus „cool“ die Solltemperatur 23.0',
        'erhalten ' . var_export($converted, true)
    );

    $harness->registerEntity($entityId, HAClimateDefinitions::DOMAIN);
    $harness->replayCachedMainValue(
        $entityId,
        $attributes,
        'cool'
    );

    $actual = $harness->getWrittenValue($entityId);
    pruefe(
        $actual === 23.0,
        'climate-Replay behält die Solltemperatur, wenn der Rohzustand ein textueller HVAC-Modus ist',
        'erhalten ' . var_export($actual, true)
    );
}

final class ClimateReplayHarness implements HADeviceConstants
{
    use HAEntityStoreTrait;
    use HADomainValueMappingTrait;

    public array $entities = [];

    /** @var array<string, int> */
    private array $idents = [];

    /** @var array<string, mixed> */
    private array $writtenValues = [];

    public function registerEntity(string $entityId, string $domain): void
    {
        $ident = $this->getSharedEntityMainIdent($entityId);
        $this->entities[$entityId] = [
            'entity_id' => $entityId,
            'domain' => $domain,
            'attributes' => []
        ];
        $this->idents[$ident] = count($this->idents) + 1;
    }

    public function replayCachedMainValue(string $entityId, array $attributes, string $rawState): void
    {
        $entity = $this->entities[$entityId];
        $entity['attributes'] = $attributes;
        $this->replayEntityMainValue($entityId, $entity, $attributes, $rawState);
    }

    public function getWrittenValue(string $entityId): mixed
    {
        $ident = $this->getSharedEntityMainIdent($entityId);
        return $this->writtenValues[$ident] ?? null;
    }

    public function convertClimateState(string $state, array $attributes): mixed
    {
        return $this->convertValueByDomain(HAClimateDefinitions::DOMAIN, $state, $attributes);
    }

    private function normalizeEntityStateToken(mixed $state): string
    {
        return is_string($state) ? strtolower(trim($state)) : '';
    }

    private function getSharedEntityMainIdent(string $entityId): string
    {
        return str_replace('.', '_', $entityId);
    }

    public function GetIDForIdent(string $ident): int|false
    {
        return $this->idents[$ident] ?? false;
    }

    private function describeVariableByIdent(string $ident, ?string $domain = null): array
    {
        return ['kind' => 'state'];
    }

    private function isTriggerVariableDescriptor(array $descriptor): bool
    {
        return false;
    }

    private function setEntityMainValue(string $entityId, string $ident, mixed $value, mixed $rawState = null): void
    {
        $this->writtenValues[$ident] = $value;
    }

}

main();
ergebnis();
