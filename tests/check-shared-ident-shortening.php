<?php

declare(strict_types=1);

/*
 * Regression test for prefix-based ident shortening (new entities only).
 *
 * HA entity_ids carry a device-wide slug (area + integration device id), e.g.
 * climate.milchstrasse_melcloudhome_650e_5ec4_climate. The shared device slug is derived
 * from the entity_ids themselves (longest common object_id prefix per device) and stripped,
 * so new variables get short idents like climate_climate / sensor_room_temperature instead of
 * the full entity_id. Existing idents are preserved (no migration).
 */

foreach ([
    'VARIABLETYPE_BOOLEAN' => 0,
    'VARIABLETYPE_INTEGER' => 1,
    'VARIABLETYPE_FLOAT' => 2,
    'VARIABLETYPE_STRING' => 3,
] as $name => $value) {
    if (!defined($name)) {
        define($name, $value);
    }
}

require_once dirname(__DIR__) . '/libs/HACommonIncludes.php';
require_once dirname(__DIR__) . '/libs/HAIdentNaming.php';

const MELCLOUD_BUNDLE = __DIR__ . '/fixtures/ha_device_config_bundle_mitsubishi_electric_air_to_air_heat_pump_via_melcloud_home_b_ro.json';

final class IdentShorteningHarness
{
    use HAIdentNamingTrait;

    public array $entities = [];

    /** @var array<string, bool> idents that already exist as variables in the simulated instance */
    public array $existingIdents = [];

    protected function sanitizeIdent(string $id): string
    {
        return preg_replace('/[^A-Za-z0-9]/', '_', $id) ?? $id;
    }

    // Simuliert Bestandsvariablen: nur diese Idents gelten als bereits vorhanden.
    protected function sharedManagedIdentExists(string $ident): bool
    {
        return $this->existingIdents[$ident] ?? false;
    }

    /** @return array<string, array<string, string>> keyed by entity_id */
    public function assignments(array $entities): array
    {
        return $this->buildSharedEntityIdents($entities);
    }
}

/** @var list<string> $failures */
$failures = [];

function check(array &$failures, string $label, string $got, string $want): void
{
    $flag = $got === $want ? 'OK ' : 'FAIL';
    if ($got !== $want) {
        $failures[] = $label;
    }
    printf("  [%s] %-46s => '%s' (want '%s')\n", $flag, $label, $got, $want);
}

function prefixOf(array $assignments, string $entityId): string
{
    return (string)($assignments[$entityId]['ident_prefix'] ?? '<missing>');
}

$h = new IdentShorteningHarness();

// --- Case 1: real MELCloud bundle, device slug stripped from all entities ---
$bundle = json_decode((string)file_get_contents(MELCLOUD_BUNDLE), true, 512, JSON_THROW_ON_ERROR);
$melcloud = isset($bundle[0]) ? $bundle : ($bundle['entities'] ?? []);
$a = $h->assignments($melcloud);
check($failures, 'melcloud climate', prefixOf($a, 'climate.milchstrasse_melcloudhome_650e_5ec4_climate'), 'climate_climate');
check($failures, 'melcloud sensor', prefixOf($a, 'sensor.milchstrasse_melcloudhome_650e_5ec4_room_temperature'), 'sensor_room_temperature');
check($failures, 'melcloud binary_sensor', prefixOf($a, 'binary_sensor.milchstrasse_melcloudhome_650e_5ec4_error_state'), 'binary_sensor_error_state');
echo "\n";

// --- Case 2: existing idents are preserved (no migration of the installed base) ---
$existing = [
    ['entity_id' => 'sensor.area_dev_abc_power', 'domain' => 'sensor', 'device_id' => 'D1',
        'ident_prefix' => 'sensor_area_dev_abc_power', 'ident' => 'sensor_area_dev_abc_power'],
    ['entity_id' => 'sensor.area_dev_abc_energy', 'domain' => 'sensor', 'device_id' => 'D1',
        'ident_prefix' => 'sensor_area_dev_abc_energy', 'ident' => 'sensor_area_dev_abc_energy'],
];
$a = $h->assignments($existing);
check($failures, 'existing ident kept', prefixOf($a, 'sensor.area_dev_abc_power'), 'sensor_area_dev_abc_power');
echo "\n";

// --- Case 3: single-entity device must not be over-stripped (slug unknowable) ---
$single = [
    ['entity_id' => 'sensor.area_dev_abc_power', 'domain' => 'sensor', 'device_id' => 'D9'],
];
$a = $h->assignments($single);
check($failures, 'single entity not stripped', prefixOf($a, 'sensor.area_dev_abc_power'), 'sensor_area_dev_abc_power');
echo "\n";

// --- Case 4: multi-device batch strips each device by its own slug ---
$multi = [
    ['entity_id' => 'sensor.area_dev_one_temperature', 'domain' => 'sensor', 'device_id' => 'A'],
    ['entity_id' => 'sensor.area_dev_one_humidity', 'domain' => 'sensor', 'device_id' => 'A'],
    ['entity_id' => 'sensor.area_dev_two_pressure', 'domain' => 'sensor', 'device_id' => 'B'],
    ['entity_id' => 'sensor.area_dev_two_battery', 'domain' => 'sensor', 'device_id' => 'B'],
];
$a = $h->assignments($multi);
check($failures, 'device A entity 1', prefixOf($a, 'sensor.area_dev_one_temperature'), 'sensor_temperature');
check($failures, 'device A entity 2', prefixOf($a, 'sensor.area_dev_one_humidity'), 'sensor_humidity');
check($failures, 'device B entity 1', prefixOf($a, 'sensor.area_dev_two_pressure'), 'sensor_pressure');
echo "\n";

// --- Case 5: identical stripped stems across devices get disambiguated ---
$collide = [
    ['entity_id' => 'sensor.area_dev_one_temperature', 'domain' => 'sensor', 'device_id' => 'A'],
    ['entity_id' => 'sensor.area_dev_one_humidity', 'domain' => 'sensor', 'device_id' => 'A'],
    ['entity_id' => 'sensor.zone_dev_two_temperature', 'domain' => 'sensor', 'device_id' => 'B'],
    ['entity_id' => 'sensor.zone_dev_two_battery', 'domain' => 'sensor', 'device_id' => 'B'],
];
$a = $h->assignments($collide);
$p1 = prefixOf($a, 'sensor.area_dev_one_temperature');
$p2 = prefixOf($a, 'sensor.zone_dev_two_temperature');
$flag = ($p1 === 'sensor_temperature' && $p2 !== 'sensor_temperature' && $p2 !== '<missing>') ? 'OK ' : 'FAIL';
if ($flag === 'FAIL') {
    $failures[] = 'collision disambiguated';
}
printf("  [%s] %-46s => '%s' vs '%s' (must differ)\n", $flag, 'collision disambiguated', $p1, $p2);
echo "\n";

// --- Case 6: existing installs are NOT migrated. If a variable already exists under the long
// legacy ident, that ident must be kept (no silent rename to the short form). ---
$h6 = new IdentShorteningHarness();
$h6->existingIdents = [
    // legacy idents as the old deterministic algorithm produced them
    'climate_milchstrasse_melcloudhome_650e_5ec4_climate'           => true,
    'sensor_milchstrasse_melcloudhome_650e_5ec4_room_temperature'   => true,
];
$a = $h6->assignments($melcloud);
check($failures, 'legacy climate preserved', prefixOf($a, 'climate.milchstrasse_melcloudhome_650e_5ec4_climate'), 'climate_milchstrasse_melcloudhome_650e_5ec4_climate');
check($failures, 'legacy sensor preserved', prefixOf($a, 'sensor.milchstrasse_melcloudhome_650e_5ec4_room_temperature'), 'sensor_milchstrasse_melcloudhome_650e_5ec4_room_temperature');
// A sibling WITHOUT an existing legacy variable is treated as new -> short.
check($failures, 'new sibling shortened', prefixOf($a, 'sensor.milchstrasse_melcloudhome_650e_5ec4_energy'), 'sensor_energy');
echo "\n";

if ($failures !== []) {
    fwrite(STDERR, 'FAILED: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "OK: prefix-based ident shortening works; existing idents preserved.\n");
exit(0);
