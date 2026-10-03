<?php

declare(strict_types=1);

/**
 * Prüft am ECHTEN Modul (Home Assistant Device, Bundle-Modus) über den offiziellen Kernel-Stub, dass
 * die „Letzte Aktualisierung" einer Button-Variable den letzten Tastendruck zeigt (MCP-Punkt H5,
 * Abstimmung Burkhard 03.10.2026: keine eigene Variable, sondern der Zeitstempel der Button-Variable).
 *
 * Ein Button meldet als Zustand den Zeitpunkt des letzten Drückens. Das Modul schrieb die Variable bei
 * JEDER Meldung — auch bei retained Wiederholungen und beim REST-Abgleich nach Neustart oder Reload.
 * Am nuc zeigten die Buttons deshalb den Reload (03.10.2026 16:53), nicht das letzte Drücken.
 * Jetzt zählt eine Meldung nur, wenn ihr Zeitpunkt neuer ist als die letzte Aktualisierung und höchstens
 * BUTTON_PRESS_MAX_AGE_S alt (ältere stammen aus einer Wiederholung oder aus der Zeit, in der Symcon
 * nicht lief — die Variable kann keinen vergangenen Zeitpunkt tragen).
 *
 * Zustandsformat wie aus HA gelesen (03.10.2026, /api/states):
 *   button.hikvision_ds_2cd2686g2_izs_reboot = 2026-04-06T16:19:43.892080+00:00
 * Gerät: Tesla „Testauto" aus tests/fixtures/naming_collisions_20261003.json.
 */

require_once __DIR__ . '/device-harness.php';

error_reporting(E_ALL);

const BUTTON = 'button.testauto_hupen';
const IDENT = 'button_hupen';

/** Zustand, wie Home Assistant ihn für einen Druck zur Unix-Zeit $ts meldet. */
function haZeitpunkt(int $ts): string
{
    return gmdate('Y-m-d\TH:i:s', $ts) . '.892080+00:00';
}

function aktualisiert(DeviceHarness $g): int
{
    return IPS_GetVariable((int)$g->variablenId(IDENT))['VariableUpdated'];
}

/** Letzte Aktualisierung am Modul vorbei setzen (Stub: IPS\VariableManager::$variables). */
function aktualisiertSetzen(DeviceHarness $g, int $ts): void
{
    $feld = new ReflectionProperty(IPS\VariableManager::class, 'variables');
    $alle = $feld->getValue();
    $alle[(int)$g->variablenId(IDENT)]['VariableUpdated'] = $ts;
    $feld->setValue(null, $alle);
}

function druecke(DeviceHarness $g, string $zustand): void
{
    neueAusfuehrung($g)->ReceiveData(mqttMeldung('statestream/button/testauto_hupen/state', $zustand));
}

$fixture = json_decode((string)file_get_contents(__DIR__ . '/fixtures/naming_collisions_20261003.json'), true, 512, JSON_THROW_ON_ERROR);
$konfiguration = array_values(array_filter(
    $fixture['geraete']['Home Assistant Gerät'],
    static fn(array $z): bool => $z['entity_id'] === BUTTON
));
pruefe(count($konfiguration) === 1, 'Fixture enthält ' . BUTTON);

$datei = tempnam(sys_get_temp_dir(), 'ha_button_check_');
if ($datei === false) {
    throw new RuntimeException('Temp-Datei für das Bundle nicht angelegt');
}
file_put_contents($datei, json_encode($konfiguration, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$g = neuesGeraet([
    'SourceMode' => 'bundle',
    'BundlePath' => $datei,
    'DeviceName' => $konfiguration[0]['device_name'],
    'DeviceID'   => $konfiguration[0]['device_id'],
]);
pruefe($g->variablenId(IDENT) !== null, 'Button-Variable ' . IDENT . ' angelegt');

$vorEinerStunde = time() - 3600;

// 1. Wiederholung eines alten Drucks (retained, REST-Abgleich): bewegt nichts.
aktualisiertSetzen($g, $vorEinerStunde);
druecke($g, haZeitpunkt($vorEinerStunde - 600));
pruefe(aktualisiert($g) === $vorEinerStunde, 'Alter Druck (vor der letzten Aktualisierung) bewegt nichts', (string)aktualisiert($g));

// 2. Derselbe Druck noch einmal (retained nach Reconnect): bewegt nichts.
druecke($g, haZeitpunkt($vorEinerStunde));
pruefe(aktualisiert($g) === $vorEinerStunde, 'Wiederholung desselben Drucks bewegt nichts', (string)aktualisiert($g));

// 3. Druck während Symcon nicht lief (neuer als die Aktualisierung, aber lange her): bewegt nichts —
//    die Variable kann keinen vergangenen Zeitpunkt tragen, „jetzt" wäre falsch.
druecke($g, haZeitpunkt($vorEinerStunde + 1800));
pruefe(aktualisiert($g) === $vorEinerStunde, 'Verpasster Druck von vor 30 Minuten bewegt nichts', (string)aktualisiert($g));

// 4. unknown/unavailable: bewegt nichts.
druecke($g, 'unavailable');
druecke($g, 'unknown');
pruefe(aktualisiert($g) === $vorEinerStunde, 'unknown/unavailable bewegen nichts', (string)aktualisiert($g));

// 5. Ein echter Druck: Die letzte Aktualisierung springt auf jetzt.
$vorher = time();
druecke($g, haZeitpunkt(time() - 1));
pruefe(aktualisiert($g) >= $vorher, 'Echter Druck setzt die letzte Aktualisierung auf jetzt', (string)aktualisiert($g));

// 6. ApplyChanges (Kernel-Neustart, Reload) bewegt nichts.
aktualisiertSetzen($g, $vorEinerStunde);
neueAusfuehrung($g)->ApplyChanges();
pruefe(aktualisiert($g) === $vorEinerStunde, 'ApplyChanges bewegt nichts', (string)aktualisiert($g));

// 7. Der Wert bleibt der Grundwert der Button-Variable (nur ein Auslöser, kein Zustand).
pruefe($g->wert(IDENT) === -1 || $g->wert(IDENT) === 0, 'Wert bleibt der Grundwert', var_export($g->wert(IDENT), true));

// ---- Dasselbe im Entity-Modul (eigener Pfad über HADeviceCore::updateEntityValue; dort wurde die
//      Variable bisher bei JEDER Meldung geschrieben) ----
$zustand = ['entity_id' => BUTTON, 'state' => 'unknown', 'attributes' => $konfiguration[0]['attributes']];
$e = neueEntitaet([
    'EntityID'   => BUTTON,
    'DeviceID'   => BUTTON,
    'DeviceName' => (string)($zustand['attributes']['friendly_name'] ?? BUTTON),
    'DeviceArea' => 'Sonstiges',
]);
$zeile = $e->rufe('buildResolvedEntityRow', $zustand, true, true);
$e->rufe('writeResolvedConfig', json_encode([$zeile], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$neu = neueAusfuehrung($e);
$neu->rufe('processEntities', $neu->rufe('getConfiguredEntities', 'check'), 'statestream');
$eIdent = $e->rufe('getSharedEntityMainIdent', BUTTON);
$eId = $e->variablenId($eIdent);
pruefe($eId !== null, 'Entity: Button-Variable angelegt');

$feld = new ReflectionProperty(IPS\VariableManager::class, 'variables');
$alle = $feld->getValue();
$alle[(int)$eId]['VariableUpdated'] = $vorEinerStunde;
$feld->setValue(null, $alle);

neueAusfuehrung($e)->ReceiveData(mqttMeldung('statestream/button/testauto_hupen/state', haZeitpunkt($vorEinerStunde - 600)));
pruefe(IPS_GetVariable((int)$eId)['VariableUpdated'] === $vorEinerStunde, 'Entity: alter Druck bewegt nichts', (string)IPS_GetVariable((int)$eId)['VariableUpdated']);
$vorher = time();
neueAusfuehrung($e)->ReceiveData(mqttMeldung('statestream/button/testauto_hupen/state', haZeitpunkt(time() - 1)));
pruefe(IPS_GetVariable((int)$eId)['VariableUpdated'] >= $vorher, 'Entity: echter Druck setzt die letzte Aktualisierung auf jetzt', (string)IPS_GetVariable((int)$eId)['VariableUpdated']);

@unlink($datei);
ergebnis();
