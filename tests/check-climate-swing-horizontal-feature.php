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

function main(): int
{
    $harness = new ClimateSwingHorizontalHarness();
    $attributes = MELCLOUD_ATTRIBUTES;

    // Sanity: the dump's bitmask must actually carry SWING_HORIZONTAL_MODE (512).
    if ((937 & 512) !== 512) {
        fwrite(STDERR, 'Test fixture inconsistent: 937 does not contain bit 512.' . PHP_EOL);
        return 1;
    }

    // Vertical swing (bit 32) was always controllable -- guard against regressions.
    if (!$harness->isWritable(HAClimateDefinitions::ATTRIBUTE_SWING_MODE, $attributes)) {
        fwrite(STDERR, 'Vertical swing_mode unexpectedly reported as not writable.' . PHP_EOL);
        return 1;
    }

    // The actual fix: horizontal swing must now be writable for this device.
    if (!$harness->isWritable(HAClimateDefinitions::ATTRIBUTE_SWING_HORIZONTAL_MODE, $attributes)) {
        fwrite(STDERR, 'swing_horizontal_mode is not writable despite supported_features bit 512.' . PHP_EOL);
        return 1;
    }

    if (!$harness->shouldCreate(HAClimateDefinitions::ATTRIBUTE_SWING_HORIZONTAL_MODE, $attributes)) {
        fwrite(STDERR, 'swing_horizontal_mode variable would not be created.' . PHP_EOL);
        return 1;
    }

    // Counter-check: a device WITHOUT bit 512 must not expose horizontal control.
    $withoutHorizontal = $attributes;
    $withoutHorizontal['supported_features'] = 937 & ~512; // 425
    unset($withoutHorizontal['swing_horizontal_mode'], $withoutHorizontal['swing_horizontal_modes']);
    if ($harness->isWritable(HAClimateDefinitions::ATTRIBUTE_SWING_HORIZONTAL_MODE, $withoutHorizontal)) {
        fwrite(STDERR, 'swing_horizontal_mode reported writable although feature/options are absent.' . PHP_EOL);
        return 1;
    }

    // The set-payload must map to the correct HA service.
    [$service] = HAClimateDefinitions::buildRestServicePayload(
        [HAClimateDefinitions::ATTRIBUTE_SWING_HORIZONTAL_MODE => 'left']
    );
    if ($service !== 'set_swing_horizontal_mode') {
        fwrite(STDERR, 'Set payload did not map to set_swing_horizontal_mode (got: ' . $service . ').' . PHP_EOL);
        return 1;
    }

    fwrite(STDOUT, "OK: swing_horizontal_mode is controllable for supported_features=937 (bit 512).\n");
    return 0;
}

exit(main());
