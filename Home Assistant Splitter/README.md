# Home Assistant Splitter

Verbindet den MQTT Client oder Server mit den Device/Configurator Instanzen und bietet optional REST-basierte Steuerung für `*/set` Topics.

## Voraussetzungen

- Home Assistant MQTT Integration aktiv.
- MQTT Client oder MQTT Server Instanz als Parent (direkt verbinden).
  - Bei MQTT Client: mindestens `ClientID` setzen und eine Subscription konfigurieren (z.B. `#` oder `homeassistant/#`).
  - Hinweis zu Ports: MQTT nutzt i.d.R. `1883` (oder `8883` bei TLS). Der Home Assistant Web/REST-Port ist typischerweise `8123`.

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
Siehe Home Assistant Doku: https://www.home-assistant.io/integrations/mqtt_statestream/
Mit den Optionen `include` und `exclude` kannst du gezielt Domains/Entitäten ein- oder ausschließen und damit beeinflussen, welche Integrationen hier ankommen.

```yaml
mqtt_statestream:
  base_topic: homeassistant
  publish_attributes: true
  publish_timestamps: true
```




