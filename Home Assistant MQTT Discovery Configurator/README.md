[![Version](https://img.shields.io/badge/Symcon%20Version-8.2%20%3E-green.svg)](https://www.symcon.de/forum/threads/30857-IP-Symcon-5-1-%28Stable%29-Changelog)
# Home Assistant MQTT Discovery Configurator

Liest MQTT-Discovery-Konfigurationen aus dem Home Assistant MQTT Discovery Splitter und gruppiert sie zu Geraetekandidaten.

## Funktionsumfang

- Liest gecachte `homeassistant/.../config` Topics aus dem Splitter.
- Parst MQTT-Discovery fuer `sensor`, `binary_sensor`, `switch`, `select`, `button` und `light`.
- Unterstuetzt sowohl klassische Component-Topics wie `homeassistant/sensor/.../config` als auch HA-Device-Discovery ueber `homeassistant/device/.../config`.
- Reduziert `device_automation` Trigger aus Zigbee2MQTT auf lesbare Event-Kandidaten im Discovery-Modell.
- Gruppiert Entities ueber `device.identifiers`.
- Zeigt MQTT-Discovery-Geraete aus klassischen und Device-Discovery-Exports im Configurator an.
- Zeigt zusaetzlich eine Diagnose-Summary fuer nicht unterstuetzte oder parserseitig uebersprungene Discovery-Eintraege.
- Legt daraus `Home Assistant MQTT Discovery Device` Instanzen an.
- Uebergibt an Discovery-Devices nur noch stabile Basisdaten:
  - `DeviceID`
  - Symcon-Instanzname

## Voraussetzungen

- Home Assistant MQTT Discovery Splitter als Parent.
- Parent des Splitters kann ein MQTT Client oder im Entwicklungsbetrieb der Bundle-Modus des Splitters sein.
- Im Live-Betrieb muss der MQTT Client den Discovery-Prefix abonnieren, z. B. `homeassistant/#` oder `#`.
- Discovery-Topics muessen im Splitter-Cache angekommen sein.

## Migration

- Discovery-Devices sind self-resolving und ziehen ihre komplette Definition spaeter selbst ueber `DeviceID` aus dem Splitter.
- Doppelte Metadaten in Device-Properties werden nicht mehr vom Configurator in den `create`-Block geschrieben.
- Wenn ein neu angelegtes Device leer bleibt, zuerst den Splitter-Cache und die Parent-Kette pruefen.

## Hinweis

Der Configurator ist bewusst auf den aktuellen MQTT-Discovery-Kernpfad fuer `sensor`, `binary_sensor`, `switch`, `select`, `button`, `light` und einfache `device_automation`-Trigger begrenzt. Weitere Discovery-Komponenten koennen spaeter ueber dasselbe Transportmodell folgen.
