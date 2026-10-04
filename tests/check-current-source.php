<?php

declare(strict_types=1);

/**
 * Prüft am ECHTEN Modul (Home Assistant Entity) über den offiziellen Kernel-Stub, dass ein Mediaplayer
 * mit Quellenliste die aktuelle Quelle zusätzlich als reine Anzeige „Current Source" führt.
 *
 * Blindtest 04.10.2026: Die Auswahl „Source" (Sonos Wintergarten) zeigte „-", weil die aktive Quelle
 * „Spotify Connect" nicht in source_list steht — Sonos bietet sie nicht zur Auswahl an. HA selbst zeigt
 * die Quelle dann ebenfalls nicht (Frontend: die Quellenliste öffnet ohne Markierung). Abstimmung
 * Burkhard 04.10.2026: Auswahl unverändert, dazu eine eigene Anzeige der aktuellen Quelle.
 *
 * Fixture: echter Zustand media_player.wintergarten aus HA (04.10.2026, ohne context; source_list auf
 * Sender und Räume gekürzt, die Playlists tragen Vornamen). Die Quellen-Meldungen folgen dem Format von
 * mqtt_statestream (Wert JSON-kodiert), sind aber kein Mitschnitt.
 */

require_once __DIR__ . '/device-harness.php';

error_reporting(E_ALL);

$zustand = json_decode((string)file_get_contents(__DIR__ . '/fixtures/sonos_wintergarten_20261004.json'), true, 512, JSON_THROW_ON_ERROR);

$entitaet = neueEntitaet([
    'EntityID'   => $zustand['entity_id'],
    'DeviceID'   => $zustand['entity_id'],
    'DeviceName' => $zustand['attributes']['friendly_name'],
    'DeviceArea' => 'Wintergarten',
]);
// Zeile wie die Template-Abfrage von UpdateConfiguration sie liefert (HAEntityConfigLoader).
$roh = $zustand + [
    'domain'      => 'media_player',
    'name'        => $zustand['attributes']['friendly_name'],
    'device_name' => 'Wintergarten',
    'device_id'   => 'none',
    'area'        => 'Wintergarten',
];
$zeile = $entitaet->rufe('buildResolvedEntityRow', $roh, true, true);
$entitaet->rufe('writeResolvedConfig', json_encode([$zeile], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$neu = neueAusfuehrung($entitaet);
$neu->rufe('processEntities', $neu->rufe('getConfiguredEntities', 'check'), 'statestream');

$auswahlIdent = (string)$entitaet->rufe('buildSharedAttributeIdent', 'media_player.wintergarten', 'source');
$anzeigeIdent = (string)$entitaet->rufe('buildSharedAttributeIdent', 'media_player.wintergarten', 'current_source');
$auswahl = $entitaet->variablenId($auswahlIdent);
$anzeige = $entitaet->variablenId($anzeigeIdent);

pruefe($auswahl !== null, 'Auswahl „Source" angelegt (' . $auswahlIdent . ')');
pruefe($anzeige !== null, 'Anzeige der aktuellen Quelle angelegt (' . $anzeigeIdent . ')');
if ($anzeige === null) {
    ergebnis();
}
pruefe(IPS_GetName($anzeige) === 'Current Source', 'Anzeige heißt „Current Source"', IPS_GetName($anzeige));
pruefe(IPS_GetVariable($anzeige)['VariableType'] === VARIABLETYPE_STRING, 'Anzeige ist Text');
pruefe(IPS_GetVariable($anzeige)['VariableAction'] === 0 && IPS_GetVariable($anzeige)['VariableCustomAction'] === 0,
    'Anzeige ist nicht schaltbar');
pruefe(GetValueString($anzeige) === '', 'Ohne source in HA: Anzeige leer', GetValueString($anzeige));

// Spotify Connect: nicht in source_list
neueAusfuehrung($entitaet)->ReceiveData(mqttMeldung('statestream/media_player/wintergarten/source', '"Spotify Connect"'));
pruefe(GetValueString($anzeige) === 'Spotify Connect', 'Quelle außerhalb der Liste: Anzeige zeigt sie', GetValueString($anzeige));
pruefe(GetValueString((int)$auswahl) === 'Spotify Connect', 'Auswahl trägt denselben Wert');
$optionen = array_column(json_decode((string)(IPS_GetVariable((int)$auswahl)['VariablePresentation']['OPTIONS'] ?? '[]'), true) ?: [], 'Value');
pruefe(!in_array('Spotify Connect', $optionen, true) && in_array('SWR3', $optionen, true),
    'Auswahl bietet nur die Quellen aus source_list an', json_encode($optionen, JSON_UNESCAPED_UNICODE));

// Quelle aus der Liste
neueAusfuehrung($entitaet)->ReceiveData(mqttMeldung('statestream/media_player/wintergarten/source', '"SWR3"'));
pruefe(GetValueString($anzeige) === 'SWR3', 'Quelle aus der Liste: Anzeige folgt', GetValueString($anzeige));

// Quelle fällt weg (Player gestoppt): statestream veröffentlicht nur vorhandene Attribute, das alte
// source-Topic bleibt stehen (HA mqtt_statestream, _state_publisher). Erkennbar nur per REST-Abfrage
// nach der Zustandsmeldung; entkoppelt über den MediaRefresh-Timer.
HaSendungen::$zustaende['media_player.wintergarten'] = $zustand;   // echter Zustand: idle, ohne source
HaSendungen::$zustandsAbfragen = [];
neueAusfuehrung($entitaet)->ReceiveData(mqttMeldung('statestream/media_player/wintergarten/state', 'idle'));
pruefe(HaSendungen::$zustandsAbfragen === [], 'Zustandsmeldung fragt nicht im MQTT-Pfad per REST nach');
neueAusfuehrung($entitaet)->RequestAction('MediaRefresh', '');
pruefe(HaSendungen::$zustandsAbfragen === ['media_player.wintergarten'], 'Timer fragt den Zustand einmal per REST ab',
    json_encode(HaSendungen::$zustandsAbfragen));
pruefe(GetValueString($anzeige) === '', 'Quelle weggefallen: Anzeige leer', GetValueString($anzeige));
pruefe(GetValueString((int)$auswahl) === '', 'Quelle weggefallen: Auswahl leer', GetValueString((int)$auswahl));

// Ohne Quelle löst die nächste Zustandsmeldung keine weitere Abfrage aus.
HaSendungen::$zustandsAbfragen = [];
neueAusfuehrung($entitaet)->ReceiveData(mqttMeldung('statestream/media_player/wintergarten/state', 'idle'));
neueAusfuehrung($entitaet)->RequestAction('MediaRefresh', '');
pruefe(HaSendungen::$zustandsAbfragen === [], 'Schon ohne Quelle: keine REST-Abfrage', json_encode(HaSendungen::$zustandsAbfragen));

// Quelle bleibt (Pause bei laufendem Sender): REST liefert sie, nichts wird geleert.
neueAusfuehrung($entitaet)->ReceiveData(mqttMeldung('statestream/media_player/wintergarten/source', '"SWR3"'));
$pausiert = $zustand;
$pausiert['state'] = 'paused';
$pausiert['attributes']['source'] = 'SWR3';
HaSendungen::$zustaende['media_player.wintergarten'] = $pausiert;
neueAusfuehrung($entitaet)->ReceiveData(mqttMeldung('statestream/media_player/wintergarten/state', 'paused'));
neueAusfuehrung($entitaet)->RequestAction('MediaRefresh', '');
pruefe(GetValueString($anzeige) === 'SWR3' && GetValueString((int)$auswahl) === 'SWR3', 'Quelle noch gemeldet: beide bleiben',
    GetValueString($anzeige) . ' / ' . GetValueString((int)$auswahl));
HaSendungen::$zustaende = [];

// Device-Modul (Bundle): Konfiguration „Denon Wohnzimmer" aus tests/fixtures/legacy_idents_20261003.json,
// source_list mit neun Eingängen, source fehlt (Gerät aus).
$fixture = json_decode((string)file_get_contents(__DIR__ . '/fixtures/legacy_idents_20261003.json'), true, 512, JSON_THROW_ON_ERROR);
$konfiguration = array_values(array_filter($fixture['instanzen'], static fn(array $i): bool => $i['instanz'] === 'Denon Wohnzimmer'))[0]['konfiguration'];
$datei = tempnam(sys_get_temp_dir(), 'ha_source_check_');
file_put_contents((string)$datei, json_encode($konfiguration, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$g = neuesGeraet([
    'SourceMode' => 'bundle',
    'BundlePath' => $datei,
    'DeviceName' => $konfiguration[0]['device_name'],
    'DeviceID'   => $konfiguration[0]['device_id'],
]);
$geraetAnzeige = $g->variablenId((string)$g->rufe('buildSharedAttributeIdent', 'media_player.denon_wohnzimmer', 'current_source'));
pruefe($geraetAnzeige !== null, 'Device: Anzeige der aktuellen Quelle angelegt');
neueAusfuehrung($g)->ReceiveData(mqttMeldung('statestream/media_player/denon_wohnzimmer/source', '"HEOS Music"'));
pruefe($geraetAnzeige !== null && GetValueString($geraetAnzeige) === 'HEOS Music', 'Device: Anzeige folgt der Quelle',
    $geraetAnzeige !== null ? GetValueString($geraetAnzeige) : '');
HaSendungen::$zustaende['media_player.denon_wohnzimmer'] = [
    'entity_id'  => 'media_player.denon_wohnzimmer',
    'state'      => 'off',
    'attributes' => $konfiguration[0]['attributes'],   // Konfiguration vom nuc: Gerät aus, ohne source
];
neueAusfuehrung($g)->ReceiveData(mqttMeldung('statestream/media_player/denon_wohnzimmer/state', 'off'));
neueAusfuehrung($g)->RequestAction('MediaRefresh', '');
pruefe($geraetAnzeige !== null && GetValueString($geraetAnzeige) === '', 'Device: Quelle weggefallen, Anzeige leer',
    $geraetAnzeige !== null ? GetValueString($geraetAnzeige) : '');
HaSendungen::$zustaende = [];
unlink((string)$datei);

ergebnis();
