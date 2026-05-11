# MQTT-Discovery-Fixtures

Diese Ablage enthaelt reale Export-Bundles aus dem `Home Assistant MQTT Discovery Splitter`.

## Vorhandene Fixtures

- `ha_mqtt_discovery_bundle_ebusd.json`
  - Version: `1`
  - Quelle: `ebusd`
  - Exportdatum: `2026-05-10T14:17:17+02:00`
  - Producer-Version: `23.3`
  - Zweck: Parser-, Gruppierungs- und Runtime-Pruefung fuer `ebusd` mit reproduzierbaren Reconnect-/Cache-Diagnosen
  - Enthaelt: `593` `discovery_configs`, `142` `topic_payloads`, `session`, `diagnostics` und `referenced_topics`
  - Beobachtung: `19` stale Discovery-Configs stammen aus einem aelteren Zigbee2MQTT-Cache-Stand; die `ebusd`-Runtime-Payloads sind damit trotzdem als Reconnect-/Diagnose-Fixture nutzbar
  - Beobachtung: HMU-`SetMode` kommt weiterhin nur als `sensor` ohne `command_topic`; Schreibbarkeit wird daher vorerst nicht aus dem Discovery-Pfad abgeleitet

- `ha_mqtt_discovery_bundle_zigbee2mqtt.json`
  - Version: `1`
  - Quelle: `zigbee2mqtt`
  - Exportdatum: `2026-05-11T11:43:44+02:00`
  - Producer-Version: leer im Bundle
  - Zweck: Discovery-, Gruppierungs- und Event-Runtime-Pruefung fuer Zigbee2MQTT mit aktuellem Session-Stand
  - Enthaelt: `26` `discovery_configs`, `4` `topic_payloads`, `session`, `diagnostics` und `referenced_topics`
  - Beobachtung: Das Bundle ist auf die aktuelle MQTT-Session begrenzt und damit als reproduzierbare Zigbee2MQTT-Referenz deutlich belastbarer als der fruehere Misch-Cache
  - Beobachtung: Fuer den IKEA-Button liegen sowohl das Root-Topic `zigbee2mqtt/...` mit JSON-Feld `action` als auch das direkte Trigger-Topic `zigbee2mqtt/.../action` vor
  - Beobachtung: Das Bundle eignet sich damit auch fuer die Verifikation von `button` und `device_automation` im Discovery-Device

- `ha_mqtt_discovery_bundle_zigbee2mqtt_current_session_v2.json`
  - Version: `2`
  - Quelle: `zigbee2mqtt`
  - Exportdatum: `2026-05-11T14:57:45+02:00`
  - Producer-Version: leer im Bundle
  - Zweck: reproduzierbare Session-Fixture fuer das V2-Bundle-Schema mit frischem Reconnect-Stand
  - Enthaelt: aktuelle `discovery_configs`, `referenced_topics` als normalisierte Topic-Liste, `extra_cached_topics`, `session` und `diagnostics`
  - Beobachtung: eignet sich als kompakte Referenz fuer Support-Faelle und Parser-/Gruppierungspruefungen ohne Altlasten

- `ha_mqtt_discovery_bundle_zigbee2mqtt_full_v2.json`
  - Version: `2`
  - Quelle: `zigbee2mqtt`
  - Exportdatum: `2026-05-11T14:57:30+02:00`
  - Producer-Version: leer im Bundle
  - Zweck: Voll-Cache-Fixture fuer das V2-Bundle-Schema inklusive stale, missing und extra Topics
  - Enthaelt: kompletteren Cache-Stand mit `discovery_configs`, normalisierten `referenced_topics`, `extra_cached_topics`, `session` und `diagnostics`
  - Beobachtung: eignet sich fuer Diagnosefaelle, in denen Session- und Gesamtstand gegeneinander verglichen werden sollen

## Verwendung

- Parser-Aenderungen gegen reale Discovery-Payloads pruefen
- Gruppierung nach `device.identifiers` verifizieren
- Reconnect-, Missing- und Extra-Topic-Diagnosen reproduzierbar nachvollziehen

## Bundle-Erzeugung

Empfohlener Ablauf im `Home Assistant MQTT Discovery Splitter`:

1. Pruefen, ob der MQTT-Parent verbunden ist.
2. Falls Discovery-Eintraege fehlen oder nur alte Cache-Staende sichtbar sind: `MQTT-Parent reconnecten` ausfuehren.
3. Warten, bis das retained Replay durchgelaufen ist und die Session-Anzeige im Formular aktualisiert wurde.
4. Dann den passenden Export ziehen:
   - `Discovery-Bundle herunterladen` fuer den gesamten Cache.
   - `Discovery-Bundle aktuelle Session herunterladen` fuer einen frischen, auf die aktuelle MQTT-Session begrenzten Export.

Wann welcher Export sinnvoll ist:

- Gesamt-Bundle:
  - fuer Bestandsaufnahme
  - fuer Cache-/Reconnect-Diagnosen
  - wenn auch stale Eintraege oder aeltere Producer-Reste sichtbar sein sollen
- Session-Bundle:
  - fuer Support-Faelle
  - fuer reproduzierbare Fixtures
  - wenn nur der aktuelle Replay-Stand ohne Altlasten betrachtet werden soll

Hinweise:

- Eine Session beginnt mit einem neuen MQTT-Connect oder Reconnect des Splitters zum Parent.
- Der Session-Export leert den Cache nicht. Er blendet nur Eintraege aus, die nicht zur aktuellen Session gehoeren.
- Das Bundle wird direkt aus dem Splitter-Cache erzeugt. Es muss daher kein externer MQTT-Mitschnitt erstellt werden.

Lokaler Pruefaufruf:

```powershell
php .\tests\check-mqtt-discovery-fixtures.php
```

Der Checker sammelt dabei automatisch alle `*.json` unter `docs/fixtures`.

Optional mit expliziten Dateien:

```powershell
php .\tests\check-mqtt-discovery-fixtures.php .\docs\fixtures\ha_mqtt_discovery_bundle_ebusd.json .\docs\fixtures\ha_mqtt_discovery_bundle_zigbee2mqtt_full_v2.json
```

Hinweis zum Bundle-Schema:

- Export-Version `2` nutzt `referenced_topics` als normalisierte Liste von Topic-Objekten mit `topic`, `kinds`, `primary_kind`, `status`, `is_current_session` und `has_payload`.
- `extra_cached_topics` fuehrt zusaetzliche Cache-Eintraege auf, die nicht mehr von Discovery-Configs referenziert werden.
- Aeltere Version-`1`-Fixtures bleiben fuer Parser- und Gruppierungspruefungen weiterhin gueltig; der lokale Checker versteht beide Versionen.

Neue Bundles sollten nach Producer benannt und nur mit den Metadaten eingecheckt werden, die fuer reproduzierbare Analyse und Debugging noetig sind.
