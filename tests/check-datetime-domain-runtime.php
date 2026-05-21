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
require_once dirname(__DIR__) . '/libs/Device/HAPresentation.php';

function main(): int
{
    $runtimeHarness = new DateTimeRuntimeHarness();
    $presentationHarness = new DateTimePresentationHarness();

    $dateTimeState = '2026-05-22 06:30:00';
    $expectedDateTime = (new DateTimeImmutable($dateTimeState))->getTimestamp();
    $actualDateTime = $runtimeHarness->convert(HADateTimeDefinitions::DOMAIN, $dateTimeState);
    if ($actualDateTime !== $expectedDateTime) {
        fwrite(STDERR, 'datetime conversion failed: expected ' . $expectedDateTime . ', got ' . var_export($actualDateTime, true) . PHP_EOL);
        return 1;
    }

    $timeOnlyState = '06:30:00';
    $expectedTimeOnly = DateTimeImmutable::createFromFormat('!H:i:s', $timeOnlyState)->getTimestamp();
    $actualTimeOnly = $runtimeHarness->convert(
        HAInputDateTimeDefinitions::DOMAIN,
        $timeOnlyState,
        ['has_date' => false, 'has_time' => true]
    );
    if ($actualTimeOnly !== $expectedTimeOnly) {
        fwrite(STDERR, 'input_datetime time-only conversion failed: expected ' . $expectedTimeOnly . ', got ' . var_export($actualTimeOnly, true) . PHP_EOL);
        return 1;
    }

    [$service, $data] = HADateTimeDefinitions::buildRestServicePayload($expectedDateTime);
    $expectedServiceValue = (new DateTimeImmutable('@' . $expectedDateTime))
        ->setTimezone(new DateTimeZone(date_default_timezone_get()))
        ->format('Y-m-d H:i:s');
    if ($service !== 'set_value' || ($data['datetime'] ?? null) !== $expectedServiceValue) {
        fwrite(STDERR, 'datetime REST payload failed: got ' . var_export([$service, $data], true) . PHP_EOL);
        return 1;
    }

    [$inputService, $inputData] = HAInputDateTimeDefinitions::buildRestServicePayload(
        $timeOnlyState,
        ['has_date' => false, 'has_time' => true]
    );
    if ($inputService !== 'set_datetime' || ($inputData['timestamp'] ?? null) !== $expectedTimeOnly) {
        fwrite(STDERR, 'input_datetime REST payload failed: got ' . var_export([$inputService, $inputData], true) . PHP_EOL);
        return 1;
    }

    $dateTimePresentation = $presentationHarness->present(HADateTimeDefinitions::DOMAIN, [], VARIABLETYPE_INTEGER);
    if (($dateTimePresentation['PRESENTATION'] ?? null) !== VARIABLE_PRESENTATION_DATE_TIME
        || ($dateTimePresentation['DATE'] ?? null) !== 1
        || ($dateTimePresentation['TIME'] ?? null) !== 2) {
        fwrite(STDERR, 'datetime presentation failed: got ' . var_export($dateTimePresentation, true) . PHP_EOL);
        return 1;
    }

    $timeOnlyPresentation = $presentationHarness->present(
        HAInputDateTimeDefinitions::DOMAIN,
        ['has_date' => false, 'has_time' => true],
        VARIABLETYPE_INTEGER
    );
    if (($timeOnlyPresentation['PRESENTATION'] ?? null) !== VARIABLE_PRESENTATION_DATE_TIME
        || ($timeOnlyPresentation['DATE'] ?? null) !== 0
        || ($timeOnlyPresentation['TIME'] ?? null) !== 2) {
        fwrite(STDERR, 'input_datetime presentation failed: got ' . var_export($timeOnlyPresentation, true) . PHP_EOL);
        return 1;
    }

    fwrite(STDOUT, "OK: datetime and input_datetime use shared integer time handling.\n");
    return 0;
}

final class DateTimeRuntimeHarness
{
    use HADomainValueMappingTrait;

    public function convert(string $domain, string $state, array $attributes = []): mixed
    {
        return $this->convertValueByDomain($domain, $state, $attributes);
    }

    private function normalizeEntityStateToken(mixed $state): string
    {
        return is_string($state) ? strtolower(trim($state)) : '';
    }

    protected function debugExpert(string $context, string $message, array $data = [], bool $log = false): void
    {
    }
}

final class DateTimePresentationHarness
{
    use HAPresentationTrait;

    public function present(string $domain, array $attributes, int $type): array
    {
        return $this->getEntityPresentation($domain, ['attributes' => $attributes], $type);
    }

    protected function debugExpert(string $context, string $message, array $data = [], bool $log = false): void
    {
    }

    private function isWriteable(string $domain): bool
    {
        return HADomainCatalog::isMainWritable(HADomainCatalog::normalizeDomainAlias($domain));
    }

    public function Translate(string $text): string
    {
        return $text;
    }

    public function ReadPropertyString(string $name): string
    {
        return '';
    }
}

exit(main());
