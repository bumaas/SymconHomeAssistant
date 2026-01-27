# Home Assistant Splitter

Verbindet den MQTT Client mit den Device/Configurator Instanzen und bietet optional REST-basierte Steuerung für `*/set` Topics.

## Voraussetzungen

- MQTT Client oder MQTT Server Instanz als Parent (direkt verbinden).
  - Bei MQTT Client: mindestens `ClientID` setzen und eine Subscription konfigurieren (z.B. `#` oder `homeassistant/#`).
- Home Assistant MQTT Integration aktiv.

## Konfiguration

- `MQTTBaseTopic`: Basis-Topic für Discovery (typisch `homeassistant`).
- `HAUrl`: Base URL von Home Assistant im Format `http(s)://<host>:<port>` (z.B. `http://homeassistant.local:8123`).
- `HAToken`: Long-Lived Access Token aus Home Assistant (Profil -> Long-Lived Access Tokens) für REST.
- `UseRestForSetTopics`: Leitet `*/set` Topics an REST weiter (optional).
- `RestAckTimeoutSec`: Timeout in Sekunden für REST-ACKs (Fallback/Status).
- Optional: `EnableExpertDebug`, `DebugResponseFormat`.

## Verhalten

- MQTT Daten werden an Devices verteilt.
- REST Requests vom Configurator laufen über den Splitter.
- Für `light`, `switch`, `lock`, `cover`, `number`, `climate` können Steuerbefehle per REST gesendet werden.

## Home Assistant mqtt_statestream

Damit Zustandsänderungen per MQTT ankommen, muss `mqtt_statestream` aktiv sein und das `base_topic` mit `MQTTBaseTopic` übereinstimmen:

```yaml
mqtt_statestream:
  base_topic: homeassistant
  publish_attributes: true
  publish_timestamps: true
```






