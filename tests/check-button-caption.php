<?php

declare(strict_types=1);

/**
 * Prüft am ECHTEN Modul (Home Assistant Entity) über den offiziellen Kernel-Stub, dass ein Button keine
 * technische Kennung als Namen oder Beschriftung trägt (MCP-Regel 14).
 *
 * Blindtest 04.10.2026: Die einzige Option der Variable „Test Button" hieß „input_button.test_button".
 * Wiederholt der HA-Name nur den Instanznamen, gilt er als „kein eigener Name"; Variablen anderer Art fallen
 * dann auf den ungekürzten Namen zurück (build 171), Buttons gingen direkt zur Entity-ID.
 *
 * Fixture: echter Zustand aus HA in tests/fixtures/ha_states_test_entities.json (input_button.test_button).
 */

require_once __DIR__ . '/device-harness.php';

error_reporting(E_ALL);

$zustaende = json_decode((string)file_get_contents(__DIR__ . '/fixtures/ha_states_test_entities.json'), true, 512, JSON_THROW_ON_ERROR);
$zustand = array_values(array_filter($zustaende, static fn(array $z): bool => $z['entity_id'] === 'input_button.test_button'))[0] ?? null;
pruefe($zustand !== null, 'Fixture enthält input_button.test_button');

$entitaet = neueEntitaet([
    'EntityID'   => $zustand['entity_id'],
    'DeviceID'   => $zustand['entity_id'],
    'DeviceName' => $zustand['attributes']['friendly_name'],
    'DeviceArea' => 'Sonstiges',
]);
// Zeile wie die Template-Abfrage von UpdateConfiguration sie liefert (HAEntityConfigLoader): name aus
// friendly_name, ein Helfer hat kein Gerät und keinen Bereich.
$roh = $zustand + [
    'domain'      => 'input_button',
    'name'        => $zustand['attributes']['friendly_name'],
    'device_name' => 'Unknown',
    'device_id'   => 'none',
    'area'        => 'No area',
];
$zeile = $entitaet->rufe('buildResolvedEntityRow', $roh, true, true);
$entitaet->rufe('writeResolvedConfig', json_encode([$zeile], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$neu = neueAusfuehrung($entitaet);
$neu->rufe('processEntities', $neu->rufe('getConfiguredEntities', 'check'), 'statestream');

$ident = neueAusfuehrung($entitaet)->rufe('getConfiguredEntityById', 'input_button.test_button')['ident'] ?? '';
$id = $entitaet->variablenId($ident);
pruefe($id !== null, 'Button-Variable angelegt (' . $ident . ')');

$name = IPS_GetName((int)$id);
pruefe($name === 'Test Button', 'Variable heißt wie in Home Assistant', $name);

$optionen = json_decode((string)(IPS_GetVariable((int)$id)['VariablePresentation']['OPTIONS'] ?? '[]'), true);
$beschriftungen = array_column(is_array($optionen) ? $optionen : [], 'Caption');
pruefe($beschriftungen !== [] && !array_any($beschriftungen, static fn(string $c): bool => str_contains($c, '.')),
    'Option trägt keine Entity-ID als Beschriftung', json_encode($beschriftungen));
pruefe($beschriftungen === ['Test Button'], 'Option heißt wie der Button', json_encode($beschriftungen));

ergebnis();
