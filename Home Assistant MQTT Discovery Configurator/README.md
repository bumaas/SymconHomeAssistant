[![Version](https://img.shields.io/badge/Symcon%20Version-8.2%20%3E-green.svg)](https://www.symcon.de/forum/threads/30857-IP-Symcon-5-1-%28Stable%29-Changelog)
# Home Assistant MQTT Discovery Configurator

Liest MQTT-Discovery-Konfigurationen aus dem Home Assistant MQTT Discovery Splitter und gruppiert sie zu Geraetekandidaten.

## Funktionsumfang

- Liest gecachte `homeassistant/.../config` Topics aus dem Splitter.
- Parst MQTT-Discovery fuer `sensor`, `binary_sensor`, `switch`, `select` und `button`.
- Reduziert `device_automation` Trigger aus Zigbee2MQTT auf lesbare Event-Kandidaten im Discovery-Modell.
- Gruppiert Entities ueber `device.identifiers`.
- Zeigt Zigbee2MQTT Endgeraete und optional Bridge-Entities im Configurator an.
- Legt daraus `Home Assistant MQTT Discovery Device` Instanzen an.

## Voraussetzungen

- Home Assistant MQTT Discovery Splitter als Parent.
- Parent des Splitters muss ein MQTT Client mit Subscription `homeassistant/#` sein.
- Discovery-Topics muessen im Splitter-Cache angekommen sein.

## Hinweis

Der Configurator ist bewusst auf den Zigbee2MQTT-v1-Pfad begrenzt. Weitere Discovery-Komponenten koennen spaeter ueber dasselbe Transportmodell folgen.
