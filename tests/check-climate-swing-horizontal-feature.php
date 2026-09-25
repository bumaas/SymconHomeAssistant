<?php

declare(strict_types=1);

/*
 * Regression test for the MELCloud climate swing-horizontal control reported in
 * https://community.symcon.de/t/142973/282
 *
 * The device reports supported_features = 937 (1 + 8 + 32 + 128 + 256 + 512).
 * Bit 512 = ClimateEntityFeature.SWING_HORIZONTAL_MODE. The module previously
 * gated swing_horizontal_mode on bit 64 (the deprecated AUX_HEAT), so the
 * variable was shown but never made writable. This test pins the correct bit.
 */

foreach ([
    'VARIABLETYPE_BOOLEAN' => 0,
    'VARIABLETYPE_INTEGER' => 1,
    'VARIABLETYPE_FLOAT' => 2,
    'VARIABLETYPE_STRING' => 3
] as $name => $value) {
    if (!defined($name)) {
        define($name, $value);
    }
}

require_once __DIR__ . '/harness.php';
require_once dirname(__DIR__) . '/libs/Device/HADeviceConstants.php';
require_once dirname(__DIR__) . '/libs/Domains/HASelectDefinitions.php';
require_once dirname(__DIR__) . '/libs/Domains/HAClimateDefinitions.php';
require_once dirname(__DIR__) . '/libs/Domains/HALightDefinitions.php';
require_once dirname(__DIR__) . '/libs/Domains/HAFanDefinitions.php';
require_once dirname(__DIR__) . '/libs/Domains/HAHumidifierDefinitions.php';
require_once dirname(__DIR__) . '/libs/Device/HADomainAttributeMaintenance.php';

// Attribute profile taken verbatim from the reported dump.txt.
const MELCLOUD_ATTRIBUTES = [
    'supported_features' => 937,
    'current_temperature' => 26,
    'target_temperature' => 23,
    'target_temperature_step' => 0.5,
    'min_temp' => 16,
    'max_temp' => 31,
    'fan_mode' => 'auto',
    'hvac_action' => 'cooling',
    'swing_mode' => 'auto',
    'swing_modes' => ['auto', 'swing', 'one', 'two', 'three', 'four', 'five'],
    'swing_horizontal_mode' => 'auto',
    'swing_horizontal_modes' => ['auto', 'swing', 'left', 'leftcentre', 'centre', 'rightcentre', 'right']
];

final class ClimateSwingHorizontalHarness
{
    use HADomainAttributeMaintenanceTrait;

    public function isWritable(string $attribute, array $attributes): bool
    {
        return $this->isWritableClimateAttribute($attribute, $attributes);
    }

    public function shouldCreate(string $attribute, array $attributes): bool
    {
        $meta = HAClimateDefinitions::ATTRIBUTE_DEFINITIONS[$attribute] ?? [];
        return $this->shouldCreateClimateAttribute($attribute, $meta, $attributes);
    }
}

function main(): void
{
    $harness = new ClimateSwingHorizontalHarness();
    $attributes = MELCLOUD_ATTRIBUTES;

    // Sanity: the dump's bitmask must actually carry SWING_HORIZONTAL_MODE (512).
    if (!pruefe((937 & 512) === 512, 'Fixture: supported_features 937 enthält Bit 512')) {
        ergebnis();
    }

    // Vertical swing (bit 32) was always controllable -- guard against regressions.
    pruefe($harness->isWritable(HAClimateDefinitions::ATTRIBUTE_SWING_MODE, $attributes), 'swing_mode (vertikal) ist schreibbar');

    // The actual fix: horizontal swing must now be writable for this device.
    pruefe($harness->isWritable(HAClimateDefinitions::ATTRIBUTE_SWING_HORIZONTAL_MODE, $attributes), 'swing_horizontal_mode ist bei Bit 512 schreibbar');

    pruefe($harness->shouldCreate(HAClimateDefinitions::ATTRIBUTE_SWING_HORIZONTAL_MODE, $attributes), 'swing_horizontal_mode-Variable wird angelegt');

    // Counter-check: a device WITHOUT bit 512 must not expose horizontal control.
    $withoutHorizontal = $attributes;
    $withoutHorizontal['supported_features'] = 937 & ~512; // 425
    unset($withoutHorizontal['swing_horizontal_mode'], $withoutHorizontal['swing_horizontal_modes']);
    pruefe(!$harness->isWritable(HAClimateDefinitions::ATTRIBUTE_SWING_HORIZONTAL_MODE, $withoutHorizontal), 'ohne Bit 512 ist swing_horizontal_mode nicht schreibbar');

    // The set-payload must map to the correct HA service.
    [$service] = HAClimateDefinitions::buildRestServicePayload(
        [HAClimateDefinitions::ATTRIBUTE_SWING_HORIZONTAL_MODE => 'left']
    );
    pruefe($service === 'set_swing_horizontal_mode', 'Set-Payload wird auf set_swing_horizontal_mode abgebildet', 'erhalten: ' . $service);
}

main();
ergebnis();
