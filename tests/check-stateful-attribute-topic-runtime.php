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
require_once dirname(__DIR__) . '/libs/Device/HAAttributeHandlers.php';

function main(): void
{
    $harness = new StatefulAttributeTopicHarness();

    try {
        $harness->handleCover('cover.wohnzimmer_rollo', HACoverDefinitions::ATTRIBUTE_POSITION_ALT, '0');
        $harness->handleValve('valve.heizung', HAValveDefinitions::ATTRIBUTE_POSITION_ALT, '25');
        pruefe(true, 'Attribut-Topic-Handler für cover und valve laufen ohne TypeError');
    } catch (TypeError $e) {
        pruefe(false, 'Attribut-Topic-Handler für cover und valve laufen ohne TypeError', $e->getMessage());
        ergebnis();
    }

    pruefe($harness->coverUpdates === 1, 'Cover-Updater wird genau einmal aufgerufen', 'Aufrufe: ' . $harness->coverUpdates);

    pruefe($harness->valveUpdates === 1, 'Valve-Updater wird genau einmal aufgerufen', 'Aufrufe: ' . $harness->valveUpdates);

    $coverValue = $harness->entities['cover.wohnzimmer_rollo']['attributes'][HACoverDefinitions::ATTRIBUTE_POSITION_ALT] ?? null;
    pruefe($coverValue === 0.0, 'Cover-Attributwert wird als float 0.0 gespeichert', 'erhalten: ' . var_export($coverValue, true));

    $valveValue = $harness->entities['valve.heizung']['attributes'][HAValveDefinitions::ATTRIBUTE_POSITION_ALT] ?? null;
    pruefe($valveValue === 25, 'Valve-Attributwert wird als geparste Zahl 25 gespeichert', 'erhalten: ' . var_export($valveValue, true));
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

main();
ergebnis();
