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
require_once dirname(__DIR__) . '/libs/Device/HADomainStateHandlers.php';
require_once dirname(__DIR__) . '/libs/Device/HAAttributeHandlers.php';
require_once dirname(__DIR__) . '/libs/Device/HADeviceCore.php';

function main(): void
{
    $harness = new EntityMqttTopicRuntimeHarness();
    $numberEntityId = 'input_number.test_zahl';
    $textEntityId = 'input_text.test_text';

    $harness->registerConfiguredEntity($numberEntityId, HANumberDefinitions::DOMAIN, true);
    $harness->registerConfiguredEntity($textEntityId, HAInputTextDefinitions::DOMAIN, false);

    $harness->feedMqttTopic('homeassistant/input_number/test_zahl/friendly_name', '"Test Zahl"');
    $storedAttribute = $harness->getStoredAttributeValue($numberEntityId, 'friendly_name');
    pruefe($storedAttribute === 'Test Zahl', 'input_number: Attribut-Topic friendly_name wird als "Test Zahl" gespeichert', 'erhalten: ' . var_export($storedAttribute, true));

    $harness->feedMqttTopic('homeassistant/input_number/test_zahl/state', '78.0');
    $appliedState = $harness->getAppliedState($numberEntityId);
    if (!pruefe($appliedState !== null, 'input_number: State-Topic führt zu einem angewendeten Zustand')) {
        ergebnis();
    }

    pruefe(($appliedState['state'] ?? null) === '78.0', 'input_number: Zustand ist "78.0"', 'erhalten: ' . var_export($appliedState['state'] ?? null, true));

    pruefe(($appliedState['context'] ?? null) === 'MQTT State Topic', 'input_number: Kontext ist "MQTT State Topic"', 'erhalten: ' . var_export($appliedState['context'] ?? null, true));

    $harness->feedMqttTopic('homeassistant/input_text/test_text/state', 'abcdefgxy');
    $textState = $harness->getAppliedState($textEntityId);
    if (!pruefe($textState !== null, 'input_text: State-Topic führt zu einem angewendeten Zustand')) {
        ergebnis();
    }

    pruefe(($textState['domain'] ?? null) === HAInputTextDefinitions::DOMAIN, 'input_text: Domain ist input_text', 'erhalten: ' . var_export($textState['domain'] ?? null, true));

    pruefe(($textState['state'] ?? null) === 'abcdefgxy', 'input_text: Zustand ist "abcdefgxy"', 'erhalten: ' . var_export($textState['state'] ?? null, true));

    $harness->feedMqttTopic('homeassistant/input_text/test_text/friendly_name', '"Test Text"');
    $textAttribute = $harness->getStoredAttributeValue($textEntityId, 'friendly_name');
    pruefe($textAttribute === 'Test Text', 'input_text: Attribut-Topic friendly_name wird als "Test Text" gespeichert', 'erhalten: ' . var_export($textAttribute, true));
}

final class EntityMqttTopicRuntimeHarness implements HADeviceConstants
{
    use HADomainStateHandlersTrait;
    use HAAttributeHandlersTrait;
    use HADeviceCoreTrait;

    /** @var array<string, array<string, mixed>> */
    private array $storedAttributes = [];

    /** @var array<string, array{domain: string, state: string, attributes: array<string, mixed>, context: string}> */
    private array $appliedStates = [];

    /** @var array<string, array<string, mixed>> */
    private array $configuredEntities = [];

    public function registerConfiguredEntity(string $entityId, string $domain, bool $materializeRuntime): void
    {
        $entity = [
            'entity_id' => $entityId,
            'domain' => $domain,
            'name' => $entityId
        ];
        $this->configuredEntities[$entityId] = $entity;
        if ($materializeRuntime) {
            $this->entities[$entityId] = $entity;
        }
    }

    public function feedMqttTopic(string $topic, string $payload): void
    {
        $this->ReceiveData(json_encode([
            'DataID' => HAIds::DATA_SPLITTER_TO_DEVICE,
            'PacketType' => 3,
            'Payload' => bin2hex($payload),
            'QualityOfService' => 1,
            'Retain' => false,
            'Topic' => $topic
        ], JSON_THROW_ON_ERROR));
    }

    public function getStoredAttributeValue(string $entityId, string $attribute): mixed
    {
        return $this->storedAttributes[$entityId][$attribute] ?? null;
    }

    /** @return array{domain: string, state: string, attributes: array<string, mixed>, context: string}|null */
    public function getAppliedState(string $entityId): ?array
    {
        return $this->appliedStates[$entityId] ?? null;
    }

    protected function debugExpert(string $context, string $message, array $data = [], bool $log = false): void
    {
    }

    protected function touchLastMqttMessage(): void
    {
    }

    protected function isManagedEntityId(string $entityId): bool
    {
        return isset($this->entities[$entityId]) || isset($this->configuredEntities[$entityId]);
    }

    protected function updateEntityRawStateCache(string $entityId, mixed $rawState): void
    {
    }

    protected function updateAvailabilityValue(mixed $rawState): void
    {
    }

    protected function updateEntityValue(string $entityId, string $domain, string $state, array $attributes, string $context): void
    {
        $this->appliedStates[$entityId] = [
            'domain' => $domain,
            'state' => $state,
            'attributes' => $attributes,
            'context' => $context
        ];
    }

    protected function storeAttributeTopicValue(string $entityId, string $attribute, mixed $value, bool $refreshPresentation = true): void
    {
        if (!isset($this->storedAttributes[$entityId])) {
            $this->storedAttributes[$entityId] = [];
        }
        $this->storedAttributes[$entityId][$attribute] = $value;
    }

    public function WriteAttributeString(string $name, string $value): void
    {
    }

    protected function findConfiguredEntityById(string $entityId): ?array
    {
        return $this->configuredEntities[$entityId] ?? null;
    }

    protected function rebuildSharedEntityIdentIndexes(): void
    {
    }
}

main();
ergebnis();
