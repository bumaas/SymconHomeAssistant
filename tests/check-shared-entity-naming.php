<?php

declare(strict_types=1);

// Regression test for the "identical devices get diverging variable names" bug.
// Three identical IKEA lamps: HA gives ONE of them _2-suffixed entity_ids for global
// slug-uniqueness. That _2 must NOT leak into the variable names. A genuine in-instance
// name collision MUST still be disambiguated.

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
require_once dirname(__DIR__) . '/libs/HAEntityVariableNaming.php';
require_once dirname(__DIR__) . '/libs/HAIdentNaming.php';

final class SharedEntityNamingHarness
{
    use HAEntityVariableNamingTrait;
    use HAIdentNamingTrait;

    public string $instanceName = '';
    public bool $hasMultipleStatusEntities = false;

    public function Translate($text): string
    {
        return (string)$text;
    }

    public function readResolvedDeviceDefinition(): array
    {
        return ['device_name' => $this->instanceName];
    }

    // mirror of the module implementation (HAIdentNaming depends on it)
    private function sanitizeIdent(string $id): string
    {
        $id = preg_replace('/\W+/', '_', $id) ?? $id;
        return trim($id, '_');
    }

    public function process(array $entities): array
    {
        return $this->applySharedEntityIdents($entities);
    }

    // name a single entity exactly as the module would
    public function nameOf(array $entity): string
    {
        return $this->buildSharedEntityVariableName(
            (string)($entity['domain'] ?? ''),
            $entity,
            $this->hasMultipleStatusEntities
        );
    }
}

function run(string $label, array $entities, array $expected, bool $multiStatus = false): bool
{
    $h = new SharedEntityNamingHarness();
    $h->instanceName = 'Stehlampe Max M';
    $h->hasMultipleStatusEntities = $multiStatus;

    $withIdents = $h->process($entities);

    $ok = true;
    foreach ($withIdents as $entity) {
        $id = (string)$entity['entity_id'];
        $got = $h->nameOf($entity);
        $want = $expected[$id] ?? '<unexpected>';
        $flag = $got === $want ? 'OK ' : 'FAIL';
        if ($got !== $want) {
            $ok = false;
        }
        printf("  [%s] %-52s => '%s' (want '%s')\n", $flag, $id, $got, $want);
    }
    echo ($ok ? "OK: " : "FAIL: ") . $label . "\n\n";
    return $ok;
}

$e = static fn(string $id, string $domain, string $name): array => [
    'entity_id'   => $id,
    'domain'      => $domain,
    'name'        => $name,
    'device_name' => 'Stehlampe Max M',
    'attributes'  => [],
];

$allOk = true;

// Scenario 1: the bug report. Whole device carries _2 slugs, names are unique.
$allOk = run('identical lamp with _2 slugs keeps clean names', [
    $e('light.kinderzimmer_stehlampe_max_m_2', 'light', 'Stehlampe Max M'),
    $e('update.kinderzimmer_stehlampe_max_m_2', 'update', 'Stehlampe Max M Firmware'),
    $e('button.kinderzimmer_stehlampe_max_m_2_identify', 'button', 'Stehlampe Max M Identifizieren'),
    $e('number.kinderzimmer_stehlampe_max_m_2_on_level', 'number', 'Stehlampe Max M Ein-Level'),
], [
    'light.kinderzimmer_stehlampe_max_m_2'            => 'Status',
    'update.kinderzimmer_stehlampe_max_m_2'           => 'Firmware',
    'button.kinderzimmer_stehlampe_max_m_2_identify'  => 'Identifizieren',
    'number.kinderzimmer_stehlampe_max_m_2_on_level'  => 'Ein-Level',
]) && $allOk;

// Scenario 2: genuine in-instance collision still disambiguates.
// Two sensors that really share the same display name; HA suffixes one with _2.
$allOk = run('genuine duplicate name is still numbered', [
    $e('sensor.stehlampe_max_m_temperature', 'sensor', 'Stehlampe Max M Temperature'),
    $e('sensor.stehlampe_max_m_temperature_2', 'sensor', 'Stehlampe Max M Temperature'),
], [
    'sensor.stehlampe_max_m_temperature'   => 'Temperature',
    'sensor.stehlampe_max_m_temperature_2' => 'Temperature 2',
]) && $allOk;

// Scenario 3: zigbee2mqtt light without an own name. The derived name is humanized
// ("Buero beleuchtung test") while device_name keeps the "/" separators
// ("Buero/Beleuchtung/Test"). Slug-equal to the device name => no own name => "Status".
// The second light has a genuine own name and a cleanly-formatted device prefix, which must
// still be stripped to "Nachtlicht" (and must NOT collapse to "Status").
$allOk = run('zigbee2mqtt light: humanized name slug-equal to device name => Status', [
    [
        'entity_id'   => 'light.buero_beleuchtung_test',
        'domain'      => 'light',
        'name'        => 'Buero beleuchtung test',
        'device_name' => 'Buero/Beleuchtung/Test',
        'attributes'  => [],
    ],
    [
        'entity_id'   => 'light.buero_beleuchtung_test_nachtlicht',
        'domain'      => 'light',
        'name'        => 'Buero Beleuchtung Test Nachtlicht',
        'device_name' => 'Buero Beleuchtung Test',
        'attributes'  => [],
    ],
], [
    'light.buero_beleuchtung_test'            => 'Status',
    'light.buero_beleuchtung_test_nachtlicht' => 'Nachtlicht',
]) && $allOk;

exit($allOk ? 0 : 1);
