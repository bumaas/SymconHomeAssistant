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
require_once dirname(__DIR__) . '/libs/Device/HAPresentation.php';

function main(): void
{
    $runtimeHarness = new DateTimeRuntimeHarness();
    $presentationHarness = new DateTimePresentationHarness();

    $dateTimeState = '2026-05-22 06:30:00';
    $expectedDateTime = (new DateTimeImmutable($dateTimeState))->getTimestamp();
    $actualDateTime = $runtimeHarness->convert(HADateTimeDefinitions::DOMAIN, $dateTimeState);
    pruefe(
        $actualDateTime === $expectedDateTime,
        'datetime: Zustand wird in Unix-Zeit umgerechnet',
        'erwartet ' . $expectedDateTime . ', erhalten ' . var_export($actualDateTime, true)
    );

    $timeOnlyState = '06:30:00';
    $expectedTimeOnly = DateTimeImmutable::createFromFormat('!H:i:s', $timeOnlyState)->getTimestamp();
    $actualTimeOnly = $runtimeHarness->convert(
        HAInputDateTimeDefinitions::DOMAIN,
        $timeOnlyState,
        ['has_date' => false, 'has_time' => true]
    );
    pruefe(
        $actualTimeOnly === $expectedTimeOnly,
        'input_datetime (nur Uhrzeit): Zustand wird in Unix-Zeit umgerechnet',
        'erwartet ' . $expectedTimeOnly . ', erhalten ' . var_export($actualTimeOnly, true)
    );

    [$service, $data] = HADateTimeDefinitions::buildRestServicePayload($expectedDateTime);
    $expectedServiceValue = (new DateTimeImmutable('@' . $expectedDateTime))
        ->setTimezone(new DateTimeZone(date_default_timezone_get()))
        ->format('Y-m-d H:i:s');
    pruefe(
        !($service !== 'set_value' || ($data['datetime'] ?? null) !== $expectedServiceValue),
        'datetime: REST-Payload ist set_value mit lokaler Datumszeit',
        'erhalten ' . var_export([$service, $data], true)
    );

    [$inputService, $inputData] = HAInputDateTimeDefinitions::buildRestServicePayload(
        $timeOnlyState,
        ['has_date' => false, 'has_time' => true]
    );
    pruefe(
        !($inputService !== 'set_datetime' || ($inputData['timestamp'] ?? null) !== $expectedTimeOnly),
        'input_datetime: REST-Payload ist set_datetime mit Zeitstempel',
        'erhalten ' . var_export([$inputService, $inputData], true)
    );

    $dateTimePresentation = $presentationHarness->present(HADateTimeDefinitions::DOMAIN, [], VARIABLETYPE_INTEGER);
    pruefe(
        !(($dateTimePresentation['PRESENTATION'] ?? null) !== VARIABLE_PRESENTATION_DATE_TIME
            || ($dateTimePresentation['DATE'] ?? null) !== 1
            || ($dateTimePresentation['TIME'] ?? null) !== 2),
        'datetime: Darstellung DateTime mit Datum und Uhrzeit',
        'erhalten ' . var_export($dateTimePresentation, true)
    );

    $timeOnlyPresentation = $presentationHarness->present(
        HAInputDateTimeDefinitions::DOMAIN,
        ['has_date' => false, 'has_time' => true],
        VARIABLETYPE_INTEGER
    );
    pruefe(
        !(($timeOnlyPresentation['PRESENTATION'] ?? null) !== VARIABLE_PRESENTATION_DATE_TIME
            || ($timeOnlyPresentation['DATE'] ?? null) !== 0
            || ($timeOnlyPresentation['TIME'] ?? null) !== 2),
        'input_datetime (nur Uhrzeit): Darstellung DateTime ohne Datum, mit Uhrzeit',
        'erhalten ' . var_export($timeOnlyPresentation, true)
    );
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

main();
ergebnis();
