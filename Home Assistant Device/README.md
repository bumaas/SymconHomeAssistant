[![Version](https://img.shields.io/badge/Symcon%20Version-8.2%20%3E-green.svg)](https://www.symcon.de/forum/threads/30857-IP-Symcon-5-1-%28Stable%29-Changelog)
# Home Assistant Device

Stellt ein einzelnes Home Assistant Gerät in Symcon dar und mappt Entitäten auf Variablen.

## Dokumentation

**Inhaltsverzeichnis**

1. [Funktionsumfang](#1-funktionsumfang)  
2. [Voraussetzungen](#2-voraussetzungen)  
3. [Installation](#3-installation)  
4. [Funktionsreferenz](#4-funktionsreferenz)  
5. [Konfiguration](#5-konfiguration)  
6. [Statusvariablen und Profile](#6-statusvariablen-und-profile)  
7. [Anhang](#7-anhang)  
8. [Home Assistant mqtt_statestream](#home-assistant-mqtt_statestream)

## 1. Funktionsumfang

- Legt Variablen je Entität an und abonniert deren MQTT Topics.
- Schreibt Werte aus `state` Topics in Variablen.
- Sendet Steuerbefehle an `*/set` Topics oder via REST über den Splitter.
- Mappt Präsentationen und Optionen je Domain.

## 2. Voraussetzungen

- Parent: Home Assistant Splitter.
- `DeviceConfig` wird vom Configurator erzeugt oder manuell gepflegt.
- Home Assistant `mqtt_statestream` aktiv und `base_topic` passend zu `MQTTBaseTopic`.

## 3. Installation

- In Symcon `Instanz hinzufügen` und `Home Assistant Device` auswählen.
- Konfiguration über den Configurator oder manuell setzen.

## 4. Funktionsreferenz

Keine öffentlichen Funktionen.

## 5. Konfiguration

- `DeviceName`, `DeviceArea`, `DeviceID`: nur lesbar (vom Configurator gesetzt).
- `DeviceConfig`: Liste der Entitäten mit Domain, Name, `entity_id` und Aktiv-Flag.
- Optional: `EnableExpertDebug`.

## 6. Statusvariablen und Profile

- Variablen je Entität.
- Suffix aus `unit_of_measurement`, `native_unit_of_measurement` und `device_class`.
- Diagnosefelder in der Konfiguration (z.B. letzte MQTT-Message, letzter REST-Abruf, Entity-Count).

## 7. Anhang

### Domain-spezifisches Verhalten

- `light`: zusätzliche Attribute als Variablen, schreibbare Attribute nur bei passenden `supported_features`.
- `binary_sensor`: Präsentation und Icon anhand von `device_class`.
- `number`: Präsentation nutzt `min`, `max`, `step` (bzw. `native_*`) auch bei `mode: box`.
- `sensor`: `device_class: enum` mit `options` als Enumeration.
- `lock`: Darstellung als Enumeration und optional `open` wenn unterstützt.
- `media_player`: Status read-only, Attribute je `supported_features`, zusätzliche `Power`-Variable bei Turn On/Off.
- `fan`: Status Ein/Aus; Attribute (`percentage`, `oscillating`, `preset_mode`, `direction`).
- `humidifier`: Status Ein/Aus; Attribute (`target_humidity`, `current_humidity`, `mode`, `action`).

- `vacuum`: Status + Aktionen (`Start`, `Stop`, `Pause`, `Zur Basis`, `Punktreinigung`, `Lokalisieren`) gemaess aktuellen `supported_features`.
- `lawn_mower`: Status + Aktionen (`Start`, `Pause`, `Zur Basis`) gemaess `supported_features`.

### Icon Mapping

#### Binary Sensor

| Quelle | Wert | Icon |
| --- | --- | --- |
| `device_class` | `battery` | `battery-exclamation` |
| `device_class` | `battery_charging` | `battery-bolt` |
| `device_class` | `cold` | `snowflake` |
| `device_class` | `connectivity` | `wifi` |
| `device_class` | `door` | `door-open` |
| `device_class` | `garage_door` | `garage-open` |
| `device_class` | `gas` | `cloud-bolt` |
| `device_class` | `heat` | `fire` |
| `device_class` | `light` | `lightbulb-on` |
| `device_class` | `lock` | `lock-open` |
| `device_class` | `moisture` | `droplet` |
| `device_class` | `motion` | `person-running` |
| `device_class` | `moving` | `person-running` |
| `device_class` | `occupancy` | `house-person-return` |
| `device_class` | `opening` | `up-right-from-square` |
| `device_class` | `plug` | `plug` |
| `device_class` | `power` | `bolt` |
| `device_class` | `presence` | `user` |
| `device_class` | `problem` | `triangle-exclamation` |
| `device_class` | `running` | `play` |
| `device_class` | `safety` | `shield-exclamation` |
| `device_class` | `smoke` | `fire-smoke` |
| `device_class` | `sound` | `volume-high` |
| `device_class` | `tamper` | `hand` |
| `device_class` | `update` | `arrows-rotate` |
| `device_class` | `vibration` | `chart-fft` |
| `device_class` | `window` | `window-frame-open` |

#### Vacuum

| Quelle | Wert | Icon |
| --- | --- | --- |
| `state` | `cleaning` | `robot` |
| `state` | `docked` | `house` |
| `state` | `idle` | `robot` |
| `state` | `paused` | `pause` |
| `state` | `returning` | `arrow-rotate-left` |
| `state` | `error` | `triangle-exclamation` |

#### Lawn Mower

| Quelle | Wert | Icon |
| --- | --- | --- |
| `state` | `mowing` | `leaf` |
| `state` | `docked` | `house` |
| `state` | `paused` | `pause` |
| `state` | `returning` | `arrow-rotate-left` |
| `state` | `error` | `triangle-exclamation` |

### Schreibbare Light-Attribute

`brightness`, `color_temp`, `color_temp_kelvin`, `effect`, `flash`, `hs_color`, `rgb_color`, `rgbw_color`, `rgbww_color`, `transition`, `xy_color`.

### Home Assistant mqtt_statestream

Siehe Home Assistant Doku: https://www.home-assistant.io/integrations/mqtt_statestream/
Mit `include` und `exclude` können Domains/Entitäten gezielt ein- oder ausgeschlossen werden.

```yaml
mqtt_statestream:
  base_topic: homeassistant
  publish_attributes: true
  publish_timestamps: true
```
