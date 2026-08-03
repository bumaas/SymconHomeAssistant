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

require_once dirname(__DIR__) . '/libs/HACommonIncludes.php';
require_once dirname(__DIR__) . '/libs/Device/HAAttributeHandlers.php';

function main(): int
{
    $harness = new StatefulAttributeTopicHarness();

    try {
        $harness->handleCover('cover.wohnzimmer_rollo', HACoverDefinitions::ATTRIBUTE_POSITION_ALT, '0');
        $harness->handleValve('valve.heizung', HAValveDefinitions::ATTRIBUTE_POSITION_ALT, '25');
    } catch (TypeError $e) {
        fwrite(STDERR, 'Stateful attribute topic handler raised TypeError: ' . $e->getMessage() . PHP_EOL);
        return 1;
    }

    if ($harness->coverUpdates !== 1) {
        fwrite(STDERR, 'Cover updater was not invoked exactly once.' . PHP_EOL);
        return 1;
    }

    if ($harness->valveUpdates !== 1) {
        fwrite(STDERR, 'Valve updater was not invoked exactly once.' . PHP_EOL);
        return 1;
    }

    $coverValue = $harness->entities['cover.wohnzimmer_rollo']['attributes'][HACoverDefinitions::ATTRIBUTE_POSITION_ALT] ?? null;
    if ($coverValue !== 0.0) {
        fwrite(STDERR, 'Cover attribute value was not stored as float 0.0.' . PHP_EOL);
        return 1;
    }

    $valveValue = $harness->entities['valve.heizung']['attributes'][HAValveDefinitions::ATTRIBUTE_POSITION_ALT] ?? null;
    if ($valveValue !== 25) {
        fwrite(STDERR, 'Valve attribute value was not stored as parsed numeric payload.' . PHP_EOL);
        return 1;
    }

    fwrite(STDOUT, "OK: stateful attribute topic updaters run without return-type mismatch.\n");
    return 0;
}

final class StatefulAttributeTopicHarness implements HADeviceConstants
{
    use HAAttributeHandlersTrait;

    /** @var array<string, array<string, mixed>> */
    public array $entities = [];

    public int $coverUpdates = 0;
    public int $valveUpdates = 0;

    public function handleCover(string $entityId, string $attribute, string $payload): bool
    {
        $this->entities[$entityId] = [
            'entity_id' => $entityId,
            'domain' => HACoverDefinitions::DOMAIN,
            'attributes' => []
        ];

        return $this->handleCoverAttributeTopic($entityId, $attribute, $payload);
    }

    public function handleValve(string $entityId, string $attribute, string $payload): bool
    {
        $this->entities[$entityId] = [
            'entity_id' => $entityId,
            'domain' => HAValveDefinitions::DOMAIN,
            'attributes' => []
        ];

        return $this->handleValveAttributeTopic($entityId, $attribute, $payload);
    }

    protected function debugExpert(string $context, string $message, array $data = [], bool $log = false): void
    {
    }

    protected function debugRuntimeIssue(string $context, string $message, array $data = [], bool $log = true): void
    {
    }

    protected function storeEntityAttribute(string $entityId, string $attribute, mixed $value): void
    {
        $this->entities[$entityId]['attributes'][$attribute] = $value;
    }

    protected function updateEntityCache(string $entityId, mixed $rawState = null, array $attributes = []): void
    {
        foreach ($attributes as $attribute => $value) {
            $this->entities[$entityId]['attributes'][$attribute] = $value;
        }
    }

    protected function updateEntityPresentation(string $entityId, array $attributes = []): void
    {
    }

    protected function castVariableValue(mixed $value, int $type): string|int|bool|float
    {
        return match ($type) {
            VARIABLETYPE_FLOAT => (float)$value,
            VARIABLETYPE_INTEGER => (int)$value,
            VARIABLETYPE_BOOLEAN => (bool)$value,
            default => (string)$value
        };
    }

    protected function getCachedEntityAttributes(string $entityId): array
    {
        $attributes = $this->entities[$entityId]['attributes'] ?? [];
        return is_array($attributes) ? $attributes : [];
    }

    protected function getCachedEntityRawState(string $entityId): ?string
    {
        return 'closed';
    }

    protected function getCachedEntityState(string $entityId): ?string
    {
        return 'closed';
    }

    protected function updateCoverAttributeValues(string $entityId, array $attributes, string $state = ''): void
    {
        $this->coverUpdates++;
    }

    protected function updateValveAttributeValues(string $entityId, array $attributes, string $state = ''): void
    {
        $this->valveUpdates++;
    }
}

exit(main());
