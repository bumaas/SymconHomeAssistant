<?php

declare(strict_types=1);

/**
 * Prüft am ECHTEN Modul (Home Assistant MQTT Discovery Configurator) über den offiziellen Kernel-Stub,
 * dass der Konfigurator alle eigenen Device-Instanzen seines Splitters listet (Store-Review Punkt 15,
 * 02.11.2026).
 *
 * Bis build 214 erschien eine Device-Instanz nur, wenn ihr Gerät noch im Discovery-Zwischenspeicher
 * des Splitters stand. Wurde ein Gerät nicht mehr angekündigt (abgebaut, umbenannt), verschwand seine
 * Instanz aus der Liste; der Anwender sah sie im Konfigurator nicht mehr und konnte sie dort auch nicht
 * löschen. Eine zweite Instanz mit derselben Geräte-ID fehlte immer. Der HA-Konfigurator machte es
 * schon richtig (appendMissingRows/appendDuplicateRows).
 *
 * Jetzt: Instanzen ohne Gerät im Zwischenspeicher stehen als „Not found in MQTT discovery“ in der
 * Liste, weitere Instanzen derselben Geräte-ID als „Duplicate device ID“. Instanzen an einem anderen
 * Splitter bleiben ausgeblendet, ebenso Bridge-Instanzen, wenn der Anwender Bridges ausblendet.
 *
 * Fixture: echtes Discovery-Bundle tests/fixtures/ha_mqtt_discovery_bundle_ebusd.json.
 */

require_once __DIR__ . '/device-harness.php';

error_reporting(E_ALL);

const BRIDGE = 'zigbee2mqtt_bridge_0xe8e07efffe7c7715';
const GERAET_A = 'zigbee2mqtt_0x2c1165fffebebb93';
const GERAET_B = 'zigbee2mqtt_0x0017880108f83590';

$bundle = json_decode((string)file_get_contents(__DIR__ . '/fixtures/ha_mqtt_discovery_bundle_ebusd.json'), true, 512, JSON_THROW_ON_ERROR);
$alle = $bundle['discovery_configs'];
$ohneB = array_values(array_filter($alle, static fn(array $r): bool => !str_contains((string)$r['payload'], substr(GERAET_B, strlen('zigbee2mqtt_')))));
pruefe(count($ohneB) < count($alle), 'Fixture: Gerät B lässt sich herausnehmen', count($alle) . ' / ' . count($ohneB));

function splitterAntwort(array $datensaetze): void
{
    DiscoveryConfiguratorHarness::$splitterAntwort = [
        'Items'            => $datensaetze,
        'Count'            => count($datensaetze),
        'DiscoveryPrefix'  => 'homeassistant',
        'SessionStartedAt' => time() - 3600,
    ];
}

/** @return list<array<string, mixed>> Zeilen des Konfigurators */
function zeilen(DiscoveryConfiguratorHarness $k): array
{
    $form = json_decode($k->GetConfigurationForm(), true, 512, JSON_THROW_ON_ERROR);
    foreach ($form['actions'] as $element) {
        if (($element['type'] ?? '') === 'Configurator') {
            return $element['values'];
        }
    }
    return [];
}

/** @return list<array<string, mixed>> Zeilen, die auf die Instanz zeigen */
function zeilenFuer(array $zeilen, int $instanz): array
{
    return array_values(array_filter($zeilen, static fn(array $z): bool => (int)($z['instanceID'] ?? 0) === $instanz));
}

function geraeteInstanz(int $splitter, string $geraeteId, string $name): int
{
    $g = neueFensterInstanz(DiscoveryDeviceHarness::class);
    IPS_SetName($g->id(), $name);
    IPS_ConnectInstance($g->id(), $splitter);
    $g->SetProperty('DeviceID', $geraeteId);
    $g->ApplyChanges();
    return $g->id();
}

$splitter = neueFensterInstanz(DiscoverySplitterHarness::class);
$fremderSplitter = neueFensterInstanz(DiscoverySplitterHarness::class);
$k = neueFensterInstanz(DiscoveryConfiguratorHarness::class);
IPS_ConnectInstance($k->id(), $splitter->id());
DiscoveryConfiguratorHarness::$parentAktiv = true;

// Ausgangslage: alle Geräte angekündigt, noch keine Instanzen.
splitterAntwort($alle);
$geraeteIds = array_column(zeilen($k), 'DeviceID');
pruefe(in_array(GERAET_A, $geraeteIds, true) && in_array(GERAET_B, $geraeteIds, true) && in_array(BRIDGE, $geraeteIds, true),
    'Fixture: Geräte A, B und Bridge stehen in der Liste', implode(', ', $geraeteIds));

$a = geraeteInstanz($splitter->id(), GERAET_A, 'Gerät A');
$aDoppelt = geraeteInstanz($splitter->id(), GERAET_A, 'Gerät A (zweite Instanz)');
$b = geraeteInstanz($splitter->id(), GERAET_B, 'Gerät B');
$bFremd = geraeteInstanz($fremderSplitter->id(), GERAET_B, 'Gerät B am anderen Splitter');
$bridge = geraeteInstanz($splitter->id(), BRIDGE, 'Bridge');

// 1. Gerät B wird nicht mehr angekündigt.
splitterAntwort($ohneB);
$z = zeilen($k);

$zA = zeilenFuer($z, $a);
pruefe(count($zA) === 1 && ($zA[0]['DeviceID'] ?? '') === GERAET_A && isset($zA[0]['create']),
    'Angekündigtes Gerät: eine Zeile mit Instanz und Anlegedaten', json_encode($zA));

$zB = zeilenFuer($z, $b);
pruefe(count($zB) === 1, 'Nicht mehr angekündigtes Gerät: Instanz steht trotzdem in der Liste', (string)count($zB));
pruefe(($zB[0]['DeviceID'] ?? '') === GERAET_B, 'Nicht angekündigt: Zeile trägt die Geräte-ID', json_encode($zB));
pruefe(($zB[0]['Summary'] ?? '') === 'Not found in MQTT discovery', 'Nicht angekündigt: Zeile sagt „nicht gefunden“', (string)($zB[0]['Summary'] ?? ''));
pruefe(($zB[0]['name'] ?? '') === 'Gerät B', 'Nicht angekündigt: Name der Instanz', (string)($zB[0]['name'] ?? ''));
pruefe(!isset($zB[0]['create']), 'Nicht angekündigt: nichts zum Anlegen');

$zDoppelt = zeilenFuer($z, $aDoppelt);
pruefe(count($zDoppelt) === 1 && ($zDoppelt[0]['Summary'] ?? '') === 'Duplicate device ID',
    'Zweite Instanz derselben Geräte-ID: eigene Zeile „doppelt“', json_encode($zDoppelt));

pruefe(zeilenFuer($z, $bFremd) === [], 'Instanz an einem anderen Splitter bleibt ausgeblendet');

$zBridge = zeilenFuer($z, $bridge);
pruefe(count($zBridge) === 1 && ($zBridge[0]['Summary'] ?? '') !== 'Not found in MQTT discovery',
    'Bridge sichtbar und angekündigt: normale Zeile', json_encode($zBridge));

$anzahlB = count(array_filter($z, static fn(array $r): bool => ($r['DeviceID'] ?? '') === GERAET_B));
pruefe($anzahlB === 1, 'Gerät B steht genau einmal in der Liste', (string)$anzahlB);

// 2. Bridges ausgeblendet: eine angekündigte Bridge-Instanz ist nicht „nicht gefunden“.
$k->SetProperty('ShowBridgeDevices', false);
$k->ApplyChanges();
$z = zeilen($k);
pruefe(zeilenFuer($z, $bridge) === [], 'Bridges ausgeblendet: Bridge-Instanz erscheint nicht als „nicht gefunden“',
    json_encode(zeilenFuer($z, $bridge)));
pruefe(count(zeilenFuer($z, $b)) === 1, 'Bridges ausgeblendet: verwaiste Instanz bleibt sichtbar');

ergebnis();
