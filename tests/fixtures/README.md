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
