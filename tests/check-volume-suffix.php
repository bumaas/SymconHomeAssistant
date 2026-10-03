<?php

declare(strict_types=1);

/**
 * Prüft am ECHTEN Modul (Home Assistant Device, Bundle-Modus) über den offiziellen Kernel-Stub, dass
 * die Lautstärke eines Mediaplayers im formatierten Wert ein „ %" trägt (MCP-Regel 14,
 * Darstellungsteil; Abstimmung Burkhard 03.10.2026).
 *
 * Home Assistant meldet volume_level als 0…1; das Anzeigeintervall rechnet auf 0…100 um, hängte aber
 * kein Suffix an. Die Kachel ergänzt das „%" selbst (Verwendung „Lautstärke"), der formatierte Wert —
 * den eine KI über MCP sieht — lautete dagegen nur „60". Am nuc (Sonos #31588) per eigener Darstellung
 * erprobt: formatiert „20 %", die Kachel zeigt „20 %", kein doppeltes Prozent.
 *
 * Fixture: Konfiguration „Denon Wohnzimmer" aus tests/fixtures/legacy_idents_20261003.json. Die
 * Lautstärke-Variable entsteht erst mit der ersten Attributmeldung (statestream: je Attribut ein Topic).
 */

require_once __DIR__ . '/device-harness.php';

error_reporting(E_ALL);

const IDENT = 'media_player_volume_level';

/** @return array<string, mixed> Anzeigeintervalle der Lautstärke-Darstellung */
function intervalle(DeviceHarness $g): array
{
    $p = IPS_GetVariable((int)$g->variablenId(IDENT))['VariablePresentation'];
    return [
        'usage'     => $p['USAGE_TYPE'] ?? null,
        'aktiv'     => $p['INTERVALS_ACTIVE'] ?? null,
        'intervall' => json_decode((string)($p['INTERVALS'] ?? '[]'), true)[0] ?? [],
    ];
}

$fixture = json_decode((string)file_get_contents(__DIR__ . '/fixtures/legacy_idents_20261003.json'), true, 512, JSON_THROW_ON_ERROR);
$denon = array_values(array_filter($fixture['instanzen'], static fn(array $i): bool => $i['instanz'] === 'Denon Wohnzimmer'))[0];
$konfiguration = $denon['konfiguration'];

$datei = tempnam(sys_get_temp_dir(), 'ha_volume_check_');
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

// Erste Lautstärke-Meldung legt die Variable an.
neueAusfuehrung($g)->ReceiveData(mqttMeldung('statestream/media_player/denon_wohnzimmer/volume_level', '0.6'));
pruefe($g->variablenId(IDENT) !== null, 'Lautstärke-Variable angelegt');
$i = intervalle($g);
pruefe($i['usage'] === 3, 'Verwendung „Lautstärke" (Kachel)', var_export($i['usage'], true));
pruefe($i['aktiv'] === true && ($i['intervall']['ConversionFactor'] ?? null) === 0.01, 'Anzeige 0…100 über Umrechnungsfaktor', json_encode($i));
pruefe(($i['intervall']['SuffixActive'] ?? null) === true && ($i['intervall']['SuffixValue'] ?? null) === ' %',
    'Anzeigeintervall hängt „ %" an', json_encode($i['intervall']));

// Bestandsinstanz: Eine Variable mit der alten Darstellung (ohne Suffix, Stand bis build 191) bekommt
// das „ %" beim nächsten ApplyChanges.
$feld = new ReflectionProperty(IPS\VariableManager::class, 'variables');
$alle = $feld->getValue();
$vid = (int)$g->variablenId(IDENT);
$alt = json_decode((string)$alle[$vid]['VariablePresentation']['INTERVALS'], true);
$alt[0]['SuffixActive'] = false;
$alt[0]['SuffixValue'] = '';
$alle[$vid]['VariablePresentation']['INTERVALS'] = json_encode($alt);
$feld->setValue(null, $alle);
neueAusfuehrung($g)->ApplyChanges();
$i = intervalle($g);
pruefe(($i['intervall']['SuffixActive'] ?? null) === true, 'Alte Darstellung bekommt „ %" beim ApplyChanges', json_encode($i['intervall']));

@unlink($datei);
ergebnis();
