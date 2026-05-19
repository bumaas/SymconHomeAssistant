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
require_once dirname(__DIR__) . '/libs/Device/HADomainValueMapping.php';
require_once dirname(__DIR__) . '/libs/Device/HAEntityStore.php';

function main(): int
{
    $harness = new TimestampReplayHarness();
    $entityId = 'sensor.waschmaschine_fertigstellungszeit';
    $rawState = '2026-05-17T20:49:09+00:00';

    $harness->registerEntity($entityId, HASensorDefinitions::DOMAIN);
    $harness->replayCachedMainValue($entityId, ['device_class' => HASensorDefinitions::DEVICE_CLASS_TIMESTAMP], $rawState);

    $expected = (new DateTimeImmutable($rawState))->getTimestamp();
    $actual = $harness->getWrittenValue($entityId);
    if ($actual !== $expected) {
        fwrite(STDERR, 'Timestamp replay failed: expected ' . $expected . ', got ' . var_export($actual, true) . PHP_EOL);
        return 1;
    }

    echo "OK: cached timestamp state is replayed with the resolved device_class.\n";
    return 0;
}

final class TimestampReplayHarness implements HADeviceConstants
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

exit(main());
