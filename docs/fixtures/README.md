# MQTT-Discovery-Fixtures

Diese Ablage enthaelt reale Export-Bundles aus dem `Home Assistant MQTT Discovery Splitter`.

## Vorhandene Fixtures

- `ha_mqtt_discovery_bundle_ebusd.json`
  - Quelle: `ebusd`
  - Exportdatum: `2026-05-10T14:17:17+02:00`
  - Producer-Version: `23.3`
  - Zweck: Parser-, Gruppierungs- und Runtime-Pruefung fuer `ebusd` mit reproduzierbaren Reconnect-/Cache-Diagnosen
  - Enthaelt: `593` `discovery_configs`, `142` `topic_payloads`, `session`, `diagnostics` und `referenced_topics`
  - Beobachtung: `19` stale Discovery-Configs stammen aus einem aelteren Zigbee2MQTT-Cache-Stand; die `ebusd`-Runtime-Payloads sind damit trotzdem als Reconnect-/Diagnose-Fixture nutzbar
  - Beobachtung: HMU-`SetMode` kommt weiterhin nur als `sensor` ohne `command_topic`; Schreibbarkeit wird daher vorerst nicht aus dem Discovery-Pfad abgeleitet

- `ha_mqtt_discovery_bundle_zigbee2mqtt.json`
  - Quelle: `zigbee2mqtt`
  - Exportdatum: `2026-05-11T11:43:44+02:00`
  - Producer-Version: leer im Bundle
  - Zweck: Discovery-, Gruppierungs- und Event-Runtime-Pruefung fuer Zigbee2MQTT mit aktuellem Session-Stand
  - Enthaelt: `26` `discovery_configs`, `4` `topic_payloads`, `session`, `diagnostics` und `referenced_topics`
  - Beobachtung: Das Bundle ist auf die aktuelle MQTT-Session begrenzt und damit als reproduzierbare Zigbee2MQTT-Referenz deutlich belastbarer als der fruehere Misch-Cache
  - Beobachtung: Fuer den IKEA-Button liegen sowohl das Root-Topic `zigbee2mqtt/...` mit JSON-Feld `action` als auch das direkte Trigger-Topic `zigbee2mqtt/.../action` vor
  - Beobachtung: Das Bundle eignet sich damit auch fuer die Verifikation von `button` und `device_automation` im Discovery-Device

## Verwendung

- Parser-Aenderungen gegen reale Discovery-Payloads pruefen
- Gruppierung nach `device.identifiers` verifizieren
- Reconnect-, Missing- und Extra-Topic-Diagnosen reproduzierbar nachvollziehen

Lokaler Pruefaufruf:

```powershell
php .\scripts\check-mqtt-discovery-fixtures.php
```

Optional mit expliziten Dateien:

```powershell
php .\scripts\check-mqtt-discovery-fixtures.php .\docs\fixtures\ha_mqtt_discovery_bundle_ebusd.json .\docs\fixtures\ha_mqtt_discovery_bundle_zigbee2mqtt.json
```

Neue Bundles sollten nach Producer benannt und nur mit den Metadaten eingecheckt werden, die fuer reproduzierbare Analyse und Debugging noetig sind.
