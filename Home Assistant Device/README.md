[![Version](https://img.shields.io/badge/Symcon%20Version-8.2%20%3E-green.svg)](https://www.symcon.de/forum/threads/30857-IP-Symcon-5-1-%28Stable%29-Changelog)
# Home Assistant Device

Stellt ein einzelnes Home-Assistant-Gerät in Symcon dar und mappt dessen Entitäten auf Variablen und Medienobjekte.

## Dokumentation

Interne Wartungsdoku: [Architektur](../docs/ARCHITEKTUR.md)

**Inhaltsverzeichnis**

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Funktionsreferenz](#4-funktionsreferenz)
5. [Konfiguration](#5-konfiguration)
6. [Variablen und Medienobjekte](#6-variablen-und-medienobjekte)
7. [Domain-spezifisches Verhalten](#7-domain-spezifisches-verhalten)
8. [Icon-Mapping](#8-icon-mapping)
9. [Home Assistant mqtt_statestream](#9-home-assistant-mqtt_statestream)

## 1. Funktionsumfang

- Legt Hauptvariablen je Entität an und abonniert deren MQTT-Topics.
- Schreibt Werte aus `state`- und Attribut-Topics in Symcon-Variablen.
- Sendet Steuerbefehle an `*/set`-Topics oder, falls vorgesehen, per REST über den Splitter.
- Pflegt Präsentationen, Optionen, Schreibbarkeit und Zusatzvariablen je Domain.
- Erzeugt bei Bedarf Medienobjekte für Kamera-, Image- und Media-Player-Vorschauen.
- Kann optional eine Expertenvariable mit allen aktuell `unknown`/`unavailable` Entitäten bereitstellen.

## 2. Voraussetzungen

- Parent: Home Assistant Splitter.
- `DeviceConfig` wird vom Configurator erzeugt oder manuell gepflegt.
- Home Assistant `mqtt_statestream` ist aktiv.
- `mqtt_statestream.base_topic` passt zu `MQTTBaseTopic`.

## 3. Installation

- In Symcon `Instanz hinzufügen` und `Home Assistant Device` auswählen.
- Konfiguration über den Configurator oder manuell setzen.
- Danach sicherstellen, dass die gewünschten Entitäten in `DeviceConfig` enthalten sind.

## 4. Funktionsreferenz

Keine öffentlichen Funktionen.

## 5. Konfiguration

- `DeviceName`, `DeviceArea`, `DeviceID`
  Vom Configurator gesetzt, nur lesend relevant.
- `DeviceConfig`
  Liste der Entitäten mit Domain, Name, `entity_id`, Position und Aktiv-Flag.
- `EnableExpertDebug`
  Aktiviert zusätzliche Debug-Ausgaben.
- `ShowUnavailableEntitiesJson`
  Blendet optional die Expertenvariable `Unavailable entities JSON` ein.
- `OutputBufferSize`
  Erhöht bei Bedarf den Ausgabepuffer für Bilddownloads über den Splitter.

## 6. Variablen und Medienobjekte

- Pro Entität wird in der Regel eine Hauptvariable angelegt.
- Je nach Domain kommen Zusatzvariablen hinzu, z. B. `Power`, `Aktion`, `Lüfterstufe`, `Playback`, `Event Type`.
- Trigger-Variablen werden nach dem Auslösen wieder auf ihren Grundwert zurückgesetzt.
- Einheiten/Suffixe werden aus `unit_of_measurement`, `native_unit_of_measurement`, `display_unit`, `unit` und `device_class` abgeleitet.
- Namen orientieren sich an `name`, `friendly_name` und, falls vorgesehen, an der `device_class`.
- Für `camera`, `image` und `media_player` können zusätzlich Medienobjekte entstehen:
  - Kamera-Vorschau
  - Kamera-Stream
  - Image-Vorschau
  - Media-Player-Cover
- Optional kann die Expertenvariable `Unavailable entities JSON` erzeugt werden. Sie enthält nur Entitäten mit `unknown` oder `unavailable`.

## 7. Domain-spezifisches Verhalten

- `switch`
  Ein/Aus als boolesche Hauptvariable.
- `light`
  Hauptvariable plus zusätzliche Attribute; schreibbare Attribute nur bei passenden `supported_features` und Color-Modes.
- `binary_sensor`
  Boolesche Hauptvariable mit textlicher Präsentation und Icon anhand von `device_class`.
- `sensor`
  Typableitung über Zustand und Attribute; `enum` mit `options` als Enumeration, `date`/`timestamp` als Zeitwert, `duration` in Symcon-Sekunden.
- `number`
  Numerische Hauptvariable; Präsentation nutzt `min`, `max`, `step` sowie `native_*` auch bei `mode: box`; Eingaben werden numerisch validiert.
- `select`
  Schreibbare Enumeration; Aktionen nur bei vorhandener `options`-Liste.
- `button`
  Trigger-Variable für `press`.
- `input_button`
  Alias zu `button`, identisches Verhalten.
- `lock`
  Read-only-Status als Enumeration, optionale `open`-Aktion und Zusatzattribute `changed_by` / `code_format`.
- `cover`
  Je nach `device_class` als Position, Öffnung oder Shutter; zusätzliche Positions-/Tilt-Attribute gemäß Features.
- `climate`
  Hauptvariable für Soll- bzw. Ist-Temperatur, zusätzliche Attribute (`hvac_mode`, `hvac_action`, `preset_mode`, `fan_mode`, `swing_mode`, `swing_horizontal_mode`, `target_humidity`, `target_temperature_low/high`), zusätzliche `Power`-Variable bei Turn On/Off.
- `fan`
  Hauptvariable Ein/Aus; Attribute wie `percentage`, `oscillating`, `preset_mode`, `direction`.
- `humidifier`
  Hauptvariable Ein/Aus; Attribute `target_humidity`, `current_humidity`, `mode`, `action`.
- `media_player`
  Read-only-Status, zusätzliche Attribute gemäß `supported_features`, zusätzliche `Playback`- und `Power`-Variable, Cover-Medienobjekt.
- `camera`
  Status-Hauptvariable, Kamera-Vorschau, optionaler Stream, zusätzliche `Power`-Variable bei `FEATURE_ON_OFF`.
- `image`
  Hauptvariable `Last Update` als Zeitstempel und zusätzliche Vorschau als Medienobjekt.
- `event`
  Hauptvariable `Last Event` plus Zusatzvariable `Event Type`.
- `vacuum`
  Status plus Aktionen (`Start`, `Stop`, `Pause`, `Zur Basis`, `Punktreinigung`, `Lokalisieren`) gemäß aktuellen `supported_features`; optionale `Lüfterstufe`.
- `lawn_mower`
  Status plus Aktionen (`Start`, `Pause`, `Zur Basis`) gemäß `supported_features`.

## 8. Icon-Mapping

### Binary Sensor

| Quelle | Wert | Icon |
| --- | --- | --- |
| `device_class` | `battery` | `battery-exclamation` |
| `device_class` | `battery_charging` | `battery-bolt` |
| `device_class` | `co` | `triangle-exclamation` |
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

### Vacuum

| Quelle | Wert | Icon |
| --- | --- | --- |
| `state` | `cleaning` | `robot` |
| `state` | `docked` | `house` |
| `state` | `idle` | `robot` |
| `state` | `paused` | `pause` |
| `state` | `returning` | `arrow-rotate-left` |
| `state` | `error` | `triangle-exclamation` |

### Lawn Mower

| Quelle | Wert | Icon |
| --- | --- | --- |
| `state` | `mowing` | `leaf` |
| `state` | `docked` | `house` |
| `state` | `paused` | `pause` |
| `state` | `returning` | `arrow-rotate-left` |
| `state` | `error` | `triangle-exclamation` |

## 9. Home Assistant mqtt_statestream

Siehe Home-Assistant-Doku:
https://www.home-assistant.io/integrations/mqtt_statestream/

Mit `include` und `exclude` können Domains und Entitäten gezielt ein- oder ausgeschlossen werden.

```yaml
mqtt_statestream:
  base_topic: homeassistant
  publish_attributes: true
  publish_timestamps: true
```
