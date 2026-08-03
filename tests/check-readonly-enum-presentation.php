<?php

declare(strict_types=1);

/*
 * Regression test for the presentation/action mismatch reported in the forum:
 * a read-only enum variable (e.g. climate hvac_action, fan current_direction)
 * must NOT receive an ENUMERATION presentation, which Symcon only allows for
 * variables WITH a Variablenaktion ("Diese Darstellung ist nur fuer Variablen
 * mit einer Variablenaktion verfuegbar"). Read-only enums use a VALUE_PRESENTATION
 * with options instead.
 *
 * The fix is domain-agnostic: buildOptionPresentation() picks the presentation by
 * the same writability the action state uses. This test exercises several domains.
 */

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
require_once dirname(__DIR__) . '/libs/Device/HAStandardAttributeMaintenance.php';
require_once dirname(__DIR__) . '/libs/Device/HAAttributeActionMapping.php';
require_once dirname(__DIR__) . '/libs/Device/HADomainAttributeMaintenance.php';
require_once dirname(__DIR__) . '/libs/Device/HAPresentation.php';

const MELCLOUD_BUNDLE = __DIR__ . '/fixtures/ha_device_config_bundle_mitsubishi_electric_air_to_air_heat_pump_via_melcloud_home_b_ro.json';

final class ReadonlyEnumPresentationHarness
{
    use HAPresentationTrait;
    use HAStandardAttributeMaintenanceTrait;
    use HADomainAttributeMaintenanceTrait;
    use HAAttributeActionMappingTrait;

    public function presentClimate(string $attribute, array $attributes): array
    {
        return $this->getClimateAttributePresentation($attribute, $attributes);
    }

    public function presentFan(string $attribute, array $attributes): array
    {
        $meta = HAFanDefinitions::ATTRIBUTE_DEFINITIONS[$attribute] ?? [];
        return $this->getFanAttributePresentation($attribute, $attributes, $meta);
    }

    protected function debugExpert(string $context, string $message, array $data = [], bool $log = false): void
    {
    }

    protected function debugRuntimeIssue(string $context, string $message, array $data = [], bool $log = true): void
    {
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

function loadClimateAttributes(): array
{
    $bundle = json_decode((string)file_get_contents(MELCLOUD_BUNDLE), true, 512, JSON_THROW_ON_ERROR);
    foreach ($bundle as $entity) {
        if (is_array($entity) && ($entity['domain'] ?? null) === HAClimateDefinitions::DOMAIN) {
            return $entity['attributes'] ?? [];
        }
    }
    throw new RuntimeException('Keine climate-Entity im Bundle gefunden.');
}

function optionKeys(array $presentation): array
{
    $options = json_decode((string)($presentation['OPTIONS'] ?? '[]'), true, 512, JSON_THROW_ON_ERROR);
    return is_array($options) && isset($options[0]) && is_array($options[0]) ? array_keys($options[0]) : [];
}

function assertReadonlyEnum(string $label, array $presentation): ?string
{
    if (($presentation['PRESENTATION'] ?? null) !== VARIABLE_PRESENTATION_VALUE_PRESENTATION) {
        return $label . ': erwartete Wertanzeige, bekam ' . var_export($presentation['PRESENTATION'] ?? null, true);
    }
    $keys = optionKeys($presentation);
    if (!in_array('ColorActive', $keys, true) || !in_array('ColorValue', $keys, true) || in_array('Color', $keys, true)) {
        return $label . ': Optionsschema passt nicht zur Wertanzeige (keys: ' . implode(',', $keys) . ').';
    }
    return null;
}

function assertWritableEnum(string $label, array $presentation): ?string
{
    if (($presentation['PRESENTATION'] ?? null) !== VARIABLE_PRESENTATION_ENUMERATION) {
        return $label . ': erwartete Aufzaehlung, bekam ' . var_export($presentation['PRESENTATION'] ?? null, true);
    }
    $keys = optionKeys($presentation);
    if (!in_array('Color', $keys, true) || in_array('ColorActive', $keys, true)) {
        return $label . ': Optionsschema passt nicht zur Aufzaehlung (keys: ' . implode(',', $keys) . ').';
    }
    return null;
}

function main(): int
{
    $harness = new ReadonlyEnumPresentationHarness();
    $errors = [];

    // --- climate (MELCloud bundle) ---
    $climate = loadClimateAttributes();
    $errors[] = assertReadonlyEnum('climate hvac_action', $harness->presentClimate(HAClimateDefinitions::ATTRIBUTE_HVAC_ACTION, $climate));
    foreach ([
        HAClimateDefinitions::ATTRIBUTE_HVAC_MODE,
        HAClimateDefinitions::ATTRIBUTE_SWING_MODE,
        HAClimateDefinitions::ATTRIBUTE_SWING_HORIZONTAL_MODE
    ] as $attribute) {
        $errors[] = assertWritableEnum('climate ' . $attribute, $harness->presentClimate($attribute, $climate));
    }

    // --- fan: direction (writable, feature 4) vs current_direction (read-only) ---
    $fan = [
        'supported_features' => HAFanDefinitions::FEATURE_DIRECTION, // 4
        'direction_list' => ['forward', 'reverse'],
        'direction' => 'forward',
        'current_direction' => 'forward'
    ];
    $errors[] = assertWritableEnum('fan direction', $harness->presentFan('direction', $fan));
    $errors[] = assertReadonlyEnum('fan current_direction', $harness->presentFan('current_direction', $fan));

    // --- counter-check: climate mode WITHOUT supported feature -> not writable -> Wertanzeige ---
    $noFeature = $climate;
    $noFeature['supported_features'] = 0;
    $errors[] = assertReadonlyEnum(
        'climate swing_horizontal_mode ohne Feature-Bit',
        $harness->presentClimate(HAClimateDefinitions::ATTRIBUTE_SWING_HORIZONTAL_MODE, $noFeature)
    );

    $errors = array_values(array_filter($errors));
    if ($errors !== []) {
        fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
        return 1;
    }

    fwrite(STDOUT, "OK: read-only Enums -> Wertanzeige, beschreibbare Enums -> Aufzaehlung (climate, fan).\n");
    return 0;
}

exit(main());
