# MQTT-Discovery-Fixtures

Diese Ablage enthält reale Export-Bundles aus dem `Home Assistant MQTT Discovery Splitter`.

Versioniert sind nur inhaltlich geprüft unbedenkliche Bundles (`ebusd`, die beiden
`*light*`-Bundles sowie das Mitsubishi-Device-Config-Bundle). Alle übrigen Bundles
bleiben lokal, da sie private Daten (Broker-IP, MQTT-Benutzername) enthalten —
neue Fixtures vor dem Einchecken entsprechend prüfen und in `.gitignore` freischalten.

## Vorhandene Fixtures

- `ha_mqtt_discovery_bundle_ebusd.json`
  - Version: `1`
  - Quelle: `ebusd`
  - Exportdatum: `2026-05-10T14:17:17+02:00`
  - Producer-Version: `23.3`
  - Zweck: Parser-, Gruppierungs- und Runtime-Prüfung für `ebusd` mit reproduzierbaren Reconnect-/Cache-Diagnosen
  - Enthält: `593` `discovery_configs`, `142` `topic_payloads`, `session`, `diagnostics` und `referenced_topics`
  - Beobachtung: `19` stale Discovery-Configs stammen aus einem älteren Zigbee2MQTT-Cache-Stand; die `ebusd`-Runtime-Payloads sind damit trotzdem als Reconnect-/Diagnose-Fixture nutzbar
  - Beobachtung: HMU-`SetMode` kommt weiterhin nur als `sensor` ohne `command_topic`; Schreibbarkeit wird daher vorerst nicht aus dem Discovery-Pfad abgeleitet

- `ha_mqtt_discovery_bundle_zigbee2mqtt.json`
  - Version: `1`
  - Quelle: `zigbee2mqtt`
  - Exportdatum: `2026-05-11T11:43:44+02:00`
  - Producer-Version: leer im Bundle
  - Zweck: Discovery-, Gruppierungs- und Event-Runtime-Prüfung für Zigbee2MQTT mit aktuellem Session-Stand
  - Enthält: `26` `discovery_configs`, `4` `topic_payloads`, `session`, `diagnostics` und `referenced_topics`
  - Beobachtung: Das Bundle ist auf die aktuelle MQTT-Session begrenzt und damit als reproduzierbare Zigbee2MQTT-Referenz deutlich belastbarer als der frühere Misch-Cache
  - Beobachtung: Für den IKEA-Button liegen sowohl das Root-Topic `zigbee2mqtt/...` mit JSON-Feld `action` als auch das direkte Trigger-Topic `zigbee2mqtt/.../action` vor
  - Beobachtung: Das Bundle eignet sich damit auch für die Verifikation von `button` und `device_automation` im Discovery-Device

- `ha_mqtt_discovery_bundle_zigbee2mqtt_current_session_v2.json`
  - Version: `2`
  - Quelle: `zigbee2mqtt`
  - Exportdatum: `2026-05-11T14:57:45+02:00`
  - Producer-Version: leer im Bundle
  - Zweck: reproduzierbare Session-Fixture für das V2-Bundle-Schema mit frischem Reconnect-Stand
  - Enthält: aktuelle `discovery_configs`, `referenced_topics` als normalisierte Topic-Liste, `extra_cached_topics`, `session` und `diagnostics`
  - Beobachtung: eignet sich als kompakte Referenz für Support-Fälle und Parser-/Gruppierungsprüfungen ohne Altlasten

- `ha_mqtt_discovery_bundle_zigbee2mqtt_full_v2.json`
  - Version: `2`
  - Quelle: `zigbee2mqtt`
  - Exportdatum: `2026-05-11T14:57:30+02:00`
  - Producer-Version: leer im Bundle
  - Zweck: Voll-Cache-Fixture für das V2-Bundle-Schema inklusive stale, missing und extra Topics
  - Enthält: kompletteren Cache-Stand mit `discovery_configs`, normalisierten `referenced_topics`, `extra_cached_topics`, `session` und `diagnostics`
  - Beobachtung: eignet sich für Diagnosefälle, in denen Session- und Gesamtstand gegeneinander verglichen werden sollen

- `ha_mqtt_discovery_bundle_zigbee2mqtt_light_current_v2.json`
  - Version: `2`
  - Quelle: `zigbee2mqtt`
  - Exportdatum: `2026-05-12T08:50:48+02:00`
  - Producer-Version: leer im Bundle
  - Zweck: fixture-nahe Parser-, Gruppierungs- und Runtime-Prüfung für Discovery-`light` mit aktuellem Cache-/Session-Stand
  - Enthält: `88` `light`-Discovery-Configs plus referenzierte Runtime-Topics für den dedizierten Light-Checker
  - Beobachtung: eignet sich als kompakte Referenz, um `schema=json`, `supported_color_modes`, `effect_list` und Light-Command-Payloads reproduzierbar zu prüfen

- `ha_mqtt_discovery_bundle_zigbee2mqtt_light_stale_v2.json`
  - Version: `2`
  - Quelle: `zigbee2mqtt`
  - Exportdatum: `2026-05-12T08:16:06+02:00`
  - Producer-Version: leer im Bundle
  - Zweck: fixture-nahe Light-Prüfung mit stale/current-Mix für Cache- und Diagnosefälle
  - Enthält: denselben Light-Fokus mit abweichendem Session-Stand für Freshness-Checks
  - Beobachtung: eignet sich, um den Light-Pfad auch gegen stale Referenzen reproduzierbar zu prüfen

## Verwendung

- Parser-Änderungen gegen reale Discovery-Payloads prüfen
- Gruppierung nach `device.identifiers` verifizieren
- Reconnect-, Missing- und Extra-Topic-Diagnosen reproduzierbar nachvollziehen

## Bundle-Erzeugung

Empfohlener Ablauf im `Home Assistant MQTT Discovery Splitter`:

1. Prüfen, ob der MQTT-Parent verbunden ist.
2. Falls Discovery-Einträge fehlen oder nur alte Cache-Stände sichtbar sind: `MQTT-IO reconnecten` ausführen.
3. Warten, bis das retained Replay durchgelaufen ist und die Session-Anzeige im Formular aktualisiert wurde.
4. Dann den passenden Export ziehen:
   - `Discovery-Bundle herunterladen` für den gesamten Cache.
   - `Discovery-Bundle aktuelle Session herunterladen` für einen frischen, auf die aktuelle MQTT-Session begrenzten Export.

Wann welcher Export sinnvoll ist:

- Gesamt-Bundle:
  - für Bestandsaufnahme
  - für Cache-/Reconnect-Diagnosen
  - wenn auch stale Einträge oder ältere Producer-Reste sichtbar sein sollen
- Session-Bundle:
  - für Support-Fälle
  - für reproduzierbare Fixtures
  - wenn nur der aktuelle Replay-Stand ohne Altlasten betrachtet werden soll

Hinweise:

- Eine Session beginnt mit einem neuen MQTT-Connect oder Reconnect des MQTT-Clients über seinen IO.
- Der Session-Export leert den Cache nicht. Er blendet nur Einträge aus, die nicht zur aktuellen Session gehören.
- Das Bundle wird direkt aus dem Splitter-Cache erzeugt. Es muss daher kein externer MQTT-Mitschnitt erstellt werden.

Lokaler Prüfaufruf:

```powershell
php .\tests\check-mqtt-discovery-fixtures.php
```

Der Checker sammelt dabei automatisch alle `*.json` unter `tests/fixtures`.

Optional mit expliziten Dateien:

```powershell
php .\tests\check-mqtt-discovery-fixtures.php .\tests\fixtures\ha_mqtt_discovery_bundle_ebusd.json .\tests\fixtures\ha_mqtt_discovery_bundle_zigbee2mqtt_full_v2.json
```

Dedizierter Light-Check:

```powershell
php .\tests\check-mqtt-discovery-light-runtime.php
```

Der Light-Checker sammelt automatisch alle `*light*.json` unter `tests/fixtures` und prüft Light-Metadaten, Gruppierung, Runtime-State-Extraktion und Command-Payloads.

Hinweis zum Bundle-Schema:

- Export-Version `2` nutzt `referenced_topics` als normalisierte Liste von Topic-Objekten mit `topic`, `kinds`, `primary_kind`, `status`, `is_current_session` und `has_payload`.
- `extra_cached_topics` führt zusätzliche Cache-Einträge auf, die nicht mehr von Discovery-Configs referenziert werden.
- Ältere Version-`1`-Fixtures bleiben für Parser- und Gruppierungsprüfungen weiterhin gültig; der lokale Checker versteht beide Versionen.

Neue Bundles sollten nach Producer benannt und nur mit den Metadaten eingecheckt werden, die für reproduzierbare Analyse und Debugging nötig sind.

## Erreichbarkeit (`reachability/`)

Echte Verläufe aus dem HA-Recorder (`/api/history/period`, `minimal_response`), je Datei alle
Entitäten eines Geräts mit `ts`, `entity_id`, `state`. Enthalten keine Adressen oder
Zugangsdaten, nur HA-Geräte-IDs und Entitätsnamen. Extraktion: `tools/fixture_reachability.php`.

- `luftentfeuchter_20260930.json` — seit 13.08.2026 ohne WLAN, am 30.09. 14:07:49 UTC zurück
- `wandthermostat_20260929.json` — HA-Neustart, alle Entitäten 143–161 s `unavailable`
- `geschirrspueler_20260927.json` — HA-Neustart, 15 von 16 Entitäten kurz `unavailable`
- `backofen_20260929.json` — seit 06.06.2026 getrennt, alle 22 Entitäten durchgehend `unavailable`

## Test-Entitäten (`ha_states_test_entities.json`)

Echte Zustände aus `/api/states` (01.10.2026, ohne `context`) für `input_select.test_auswahl`
(Optionen A, B, C), `input_number.test_zahl` (0–100, Schritt 1, cm) und `input_text.test_text`
(Länge 0–100, kein Muster), dazu `input_button.test_button` (04.10.2026, Grundlage von
`check-button-caption.php`). Grundlage von `check-action-contract.php`. Reine HA-Helfer, keine
privaten Daten.

Die Attribut-Meldungen in `check-action-contract.php` (`…/options`, `…/step`, `…/max`) sind
keine Mitschnitte: Sie folgen dem Format von `mqtt_statestream` (je Attribut ein Topic,
Wert JSON-kodiert), wurden aber nicht vom Broker abgegriffen.

## Rollladen per statestream (`cover_*_gast_20261003.*`)

Echter Mitschnitt vom nuc (03.10.2026, Modul 1.5 build 181, Rust 9.1-953) für
`cover.gast_licht_rolladen` (HM-LC-Bl1-FM über die HA-Homematic-Integration):

- `cover_statestream_gast_20261003.txt` — alle statestream-Topics der Entität während zweier
  Fahrbefehle aus Symcon, samt der Symcon-Spur der Hauptvariable im Kopf.
- `cover_config_gast_20261003.json` — die Konfiguration der Device-Instanz
  (`HA_ExportConfigBundleDataUrl`); ihr `current_position` steht auf 100, dem Stand des letzten
  `ApplyChanges`.

Grundlage von `check-cover-position-statestream.php`. Enthält die Seriennummer des Aktors und den
Raumnamen, keine IP-Adressen oder Zugangsdaten.

## Altes Ident-Schema (`legacy_idents_20261003.json`)

Konfiguration (`HA_ExportConfigBundleDataUrl`) und Variablen (Ident, Name, Typ, Aktion, zuletzt
aktualisiert) der Device-Instanzen „Denon Wohnzimmer" und „Gast.Licht.Rolladen" am nuc
(03.10.2026, 1.5 build 184). Neben den aktuellen Variablen stehen dort die Variablen aus dem
Ident-Schema vor Mai 2026 (voller Entitätsname im Ident), seit Mai ohne Aktualisierung.
Grundlage von `check-legacy-idents.php`. Keine IP-Adressen oder Zugangsdaten. Auch
`check-media-image-token.php` nutzt die Denon-Konfiguration; die Cover-Adresse mit `token=` setzt der
Test selbst (Format wie aus HA gelesen, Schlüssel als Platzhalter).

## Entfallene Entitäten (`removed_entities_ccu3_20261003.json`)

Instanz „homematic-ccu3" am nuc (03.10.2026, 1.5 build 186). `konfiguration_nachher` und
`variablen` stammen aus dem Export nach dem Löschen von zehn verwaisten `testraum_homematic_ccu3_*`
-Entitäten in HA. `konfiguration_vorher` ergänzt diese zehn Zeilen: `entity_id`, `name`, `area` und
`create_var` aus dem Export vor dem Löschen, die Attribute so, wie HA sie für eine wiederhergestellte
(`restored`) Entität meldet — die vollständigen Zeilen von damals sind nicht mehr abrufbar.
Grundlage von `check-removed-entities.php`. Enthält die Seriennummer eines Rauchmelders
(`devices` in `sensor.homematic_ccu3_posteingang`), keine IP-Adressen oder Zugangsdaten.

## Kamera (`camera_hikvision_20261003.json`)

Konfiguration (`HA_ExportConfigBundleDataUrl`) der Device-Instanz „HIKVISION DS-2CD2686G2-IZS" am nuc
(03.10.2026, 1.5 build 186), 20 Entitäten. Der Kamera-Schlüssel (`access_token` und `token=` in
`entity_picture`) ist durch den Platzhalter `0123456789abcdef…` gleicher Länge ersetzt. Grundlage von
`check-camera-state-token.php`.

## Sonos Wintergarten (`sonos_wintergarten_20261004.json`)

Echter Zustand von `media_player.wintergarten` aus `/api/states` (04.10.2026, ohne `context`, Player
inaktiv, also ohne `source`). Die `source_list` ist auf Sender und Räume gekürzt (11 von 44); die
gestrichenen Playlists tragen Vornamen. Grundlage von `check-current-source.php`; die
`source`-Meldungen dort folgen dem statestream-Format, sind aber kein Mitschnitt.

## Gleichnamige Variablen (`naming_collisions_20261003.json`)

Konfiguration (`HA_ExportConfigBundleDataUrl`) dreier Device-Instanzen am nuc (03.10.2026,
1.5 build 186): ein Tesla aus dem Config-Bundle eines Supportfalls — Anwenderdaten, neutralisiert (Name „Testauto",
Geräte-ID Platzhalter) — (85 Entitäten, zwei Klima-Entitäten, zwei Ortungen, „Ladekabel"
als Ja/Nein- und als Textsensor), Luftentfeuchter (Entfeuchter und Lüfter als Hauptteile) und Aqara
FP300 (vier nummerierte Identifizieren-Taster). Die Standortkoordinaten des Tesla (`latitude`,
`longitude`) sind durch 0 ersetzt. Grundlage von `check-variable-name-collisions.php`.

## Discovery-Erreichbarkeit (`discovery_availability_20261003.json`)

Aus einem **Anwender-Bundle** (Discovery-Splitter im Bundle-Modus, 03.10.2026), **neutralisiert**: alle 23
Ankündigungen eines Heizkörperthermostats (Bosch Radiator thermostat II, zwei availability-Topics, Modus
`all`) und die Payloads seiner availability-Topics (Gerät offline, Bridge online) sowie die Online-Meldung
eines Nachbargeräts. Raumpfade, eigener Basis-Topic und Zigbee-Adressen sind durch Platzhalter ersetzt
(`Raum/Heizkoerper`, `Raum/Nachbar`, `zigbee2mqtt`, `0x…a1`/`0x…b2`); Struktur und Payload-Format sind
unverändert. Grundlage von `check-discovery-reachability.php`. **Fixtures aus Anwender-Bundles nie
unverändert einchecken.**
