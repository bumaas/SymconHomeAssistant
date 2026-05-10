[![Version](https://img.shields.io/badge/Symcon%20Version-8.2%20%3E-green.svg)](https://www.symcon.de/forum/threads/30857-IP-Symcon-5-1-%28Stable%29-Changelog)
# Home Assistant MQTT Discovery Device

Laufzeitmodul fuer MQTT-Discovery-Geraete aus dem Home Assistant MQTT Discovery Configurator.

## Funktionsumfang

- Empfaengt MQTT-Nachrichten ueber den Home Assistant MQTT Discovery Splitter.
- Wertet `state_topic`, `command_topic` und `availability` quellenneutral fuer MQTT-Discovery aus.
- Unterstuetzt in v1 die Komponenten `sensor`, `binary_sensor`, `switch` und `select`.
- Nutzt den Topic-Cache des Splitters fuer Initialwerte aus retained MQTT-Payloads.
- Uebernimmt bei `unknown` oder `unavailable` den letzten fachlich gueltigen Wert weiter, wertet benutzerdefinierte Bool-Payloads ueber `state_on`/`state_off` sowie `payload_on`/`payload_off` aus und markiert Bool-Mapping-Abweichungen zwischen Discovery und beobachtetem Runtime-Wert.
- Kann auch manuell angelegt werden und laedt seine Entities dann ueber `DeviceID` selbst aus dem Discovery-Splitter.
- Persistiert keine eigene `DeviceConfig` mehr. Die Laufzeit arbeitet mit `DeviceID` und einer aufgeloesten Cache-Definition aus dem Splitter.

## Voraussetzungen

- Home Assistant MQTT Discovery Splitter als Parent.
- Parent des Splitters muss ein MQTT Client mit Subscription `homeassistant/#` sein.
- Die relevanten State-Topics muessen vom MQTT Client empfangen werden, typischerweise ueber `zigbee2mqtt/#` oder `#`.

## Hinweis

Das Modul ist bewusst auf den v1-Pfad fuer `sensor`, `binary_sensor`, `switch` und `select` begrenzt. Weitere Discovery-Komponenten koennen spaeter darauf aufbauen, ohne den bestehenden Home Assistant Runtime-Pfad zu vermischen.
