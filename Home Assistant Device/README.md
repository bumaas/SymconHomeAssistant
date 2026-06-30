[![Version](https://img.shields.io/badge/Symcon%20Version-9.0%20%3E-green.svg)](https://www.symcon.de/forum/threads/30857-IP-Symcon-5-1-%28Stable%29-Changelog)
# Home Assistant Device

Stellt ein einzelnes Gerät aus einer bestehenden Home-Assistant-Installation in Symcon dar und mappt dessen Entitäten auf Variablen und Medienobjekte.

> Teil des Projekts **Home Assistant für IP-Symcon** — [Gesamtübersicht, Betriebsarten und Fehlersuche](../README.md).

## Dokumentation

**Inhaltsverzeichnis**

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Funktionsreferenz](#4-funktionsreferenz)
5. [Konfiguration](#5-konfiguration)
6. [Bundle-Modus](#6-bundle-modus)
7. [Variablen und Medienobjekte](#7-variablen-und-medienobjekte)
8. [Domain-spezifisches Verhalten](#8-domain-spezifisches-verhalten)
9. [Icon-Mapping](#9-icon-mapping)
10. [Home Assistant mqtt_statestream](#10-home-assistant-mqtt_statestream)

## 1. Funktionsumfang

- Gehört zum klassischen Bridge-Pfad und übernimmt Geräte aus Home Assistant nach Symcon.
- Legt Hauptvariablen je Entität an und abonniert deren MQTT-Topics.
- Schreibt Werte aus `state`- und Attribut-Topics in Symcon-Variablen.
- Sendet Steuerbefehle an `*/set`-Topics oder, falls vorgesehen, per REST über den Splitter.
- Pflegt Präsentationen, Optionen, Schreibbarkeit und Zusatzvariablen je Domain.
- Erzeugt bei Bedarf Medienobjekte für Kamera-, Image- und Media-Player-Vorschauen.
- Kann optional eine Expertenvariable mit allen aktuell `unknown`/`unavailable` Entitäten bereitstellen.

## 2. Voraussetzungen

- Parent: Home Assistant Splitter.
- Bestehende Home-Assistant-Installation als Quelle des Geräts.
- `DeviceID` ist der fachliche Schlüssel; die Entity-Konfiguration wird zur Laufzeit aus Home Assistant aufgelöst.
- Home Assistant `mqtt_statestream` ist aktiv.
- `mqtt_statestream.base_topic` passt zu `MQTTBaseTopic`.

## 3. Installation

- In Symcon `Instanz hinzufügen` und `Home Assistant Device` auswählen.
- Konfiguration über den Configurator oder manuell setzen.
- Danach reicht eine gültige `DeviceID`; die zugehörigen Entitäten werden automatisch geladen.

## 4. Funktionsreferenz

- `HA_ExportConfigBundleDataUrl($id)`
  Gibt die aktuell aufgelöste Gerätekonfiguration (`ResolvedConfig`) als base64-encodierte Data-URL zurück. Wird intern vom Download-Button im Formular aufgerufen. Kann auch direkt per Skript aufgerufen werden.
- `HA_ActivateBundleMode($id, $BundlePath)`
  Schaltet die Instanz in den Bundle-Modus und lädt die Konfiguration aus der angegebenen Datei.
- `HA_ActivateMqttMode($id)`
  Schaltet die Instanz zurück in den Live-Modus (Home Assistant REST API).

## 5. Konfiguration

- `DeviceID`
  Die Home-Assistant-Geräte-ID des Zielgeräts. Über sie löst das Modul seine Entitäten bei `ApplyChanges()` selbst aus Home Assistant auf. Im Bundle-Modus optional.
- `DeviceName`, `DeviceArea`
  Legacy-Felder aus älteren Create-Configs. Sie sind nicht mehr maßgeblich für die Laufzeitauflösung.
- `SourceMode`
  `Home Assistant (REST API)` (Standard) oder `Bundle file`. Im Bundle-Modus wird die Konfiguration aus einer lokalen JSON-Datei geladen statt per REST-API aus Home Assistant.
- `BundlePath`
  Dateiname oder absoluter Pfad zur Bundle-Datei (JSON). Relative Angaben werden gegen `<modulpfad>/tests/fixtures` aufgelöst. Nur im Bundle-Modus sichtbar und relevant.
- `EnableExpertDebug`
  Aktiviert zusätzliche Debug-Ausgaben.
- `ShowUnavailableEntitiesJson`
  Blendet optional die Expertenvariable `Unavailable entities JSON` ein. Diese dient nur der Diagnose und enthält ausschließlich Entitäten, deren aktueller Zustand `unknown` oder `unavailable` ist.
  Beispiel: `[{"entity_id":"light.test","state":"unavailable"}]`
- `OutputBufferSize`
  Erhöht bei Bedarf den Ausgabepuffer für Bilddownloads über den Splitter.

## 6. Bundle-Modus

Der Bundle-Modus ist für Entwicklung, Tests und Support gedacht. Er ersetzt den REST-API-Abruf aus Home Assistant durch eine lokale JSON-Datei.

### Bundle ziehen

1. Sicherstellen, dass die Instanz aktiv ist und `ResolvedConfig` vollständig befüllt ist (Formular → `Aufgelöste Konfiguration`).
2. Im Formular den Button **`Config-Bundle herunterladen`** klicken. Es wird eine Datei `ha_device_config_bundle.json` heruntergeladen.
3. Die Datei enthält alle aufgelösten Entity-Konfigurationen inklusive Attributen und States zum Zeitpunkt des letzten `ApplyChanges`.

### Bundle-Modus aktivieren

Der Bundle-Modus wird ausschließlich per Skript aktiviert:

```php
// Absoluter Pfad:
HA_ActivateBundleMode($id, '/var/lib/symcon/ha_device_config_bundle.json');

// Dateiname (relativ): wird gegen <modulpfad>/tests/fixtures aufgelöst:
HA_ActivateBundleMode($id, 'mein_geraet.json');
```

`$id` ist die Instanz-ID der Device-Instanz. Die Konfiguration wird sofort aus der angegebenen Datei geladen. Ein aktiver Home-Assistant-Parent ist nicht erforderlich.

### Bundle-Modus deaktivieren

```php
HA_ActivateMqttMode($id);
```

Die Instanz lädt die Konfiguration wieder per REST-API aus Home Assistant.

### Hinweise

- Im Bundle-Modus wird kein MQTT-Parent benötigt, um Variablen anzulegen.
- MQTT-State-Updates funktionieren im Bundle-Modus nur wenn ein Parent verbunden ist.
- `SourceMode = Bundle file` ist kein Ersatz für den regulären Betrieb, sondern ein Entwicklungs- und Supportwerkzeug.
- Bestehende Live-Installationen bleiben auf `SourceMode = Home Assistant (REST API)`.

## 7. Variablen und Medienobjekte

- Pro Entität wird in der Regel eine Hauptvariable angelegt.
- Je nach Domain kommen Zusatzvariablen hinzu, zum Beispiel `Power`, `Aktion`, `Lüfterstufe`, `Playback` oder `Event Type`.
- Trigger-Variablen werden nach dem Auslösen wieder auf ihren Grundwert zurückgesetzt.
- Einheiten und Suffixe werden aus `unit_of_measurement`, `native_unit_of_measurement`, `display_unit`, `unit` und `device_class` abgeleitet.
- Namen orientieren sich an `name`, `friendly_name` und, falls vorgesehen, an der `device_class`.
- Für `camera`, `image` und `media_player` können zusätzlich Medienobjekte entstehen:
  - Kamera-Vorschau
  - Kamera-Stream
  - Image-Vorschau
  - Media-Player-Cover
- Optional kann die Expertenvariable `Unavailable entities JSON` erzeugt werden. Sie dient als kompakte Diagnoseübersicht für problematische Entitäten und enthält nur Einträge mit `unknown` oder `unavailable`.
  Beispiel: `[{"entity_id":"light.test","state":"unavailable"}]`

## 8. Domain-spezifisches Verhalten

- `switch`
  Ein/Aus als boolesche Hauptvariable.
- `light`
  Hauptvariable plus zusätzliche Attribute; schreibbare Attribute nur bei passenden `supported_features` und Color-Modes.
- `binary_sensor`
  Boolesche Hauptvariable mit textlicher Präsentation und Icon anhand von `device_class`.
- `sensor`
  Typableitung über Zustand und Attribute; `enum` mit `options` als Enumeration, `date` und `timestamp` als Zeitwert, `duration` in Symcon-Sekunden.
- `number`
  Numerische Hauptvariable; Präsentation nutzt `min`, `max`, `step` sowie `native_*` auch bei `mode: box`; Eingaben werden numerisch validiert.
- `input_text`
  Schreibbare String-Hauptvariable.
- `datetime`
  Schreibbare Integer-Hauptvariable für Datum/Uhrzeit via Home-Assistant-Service `set_value`.
- `input_datetime`
  Schreibbare Integer-Hauptvariable für Datum, Uhrzeit oder kombinierte Datums-/Zeitwerte; `has_date` und `has_time` steuern Darstellung und REST-Payload.
- `select`
  Schreibbare Enumeration; Aktionen nur bei vorhandener `options`-Liste.
- `button`
  Trigger-Variable für `press`.
- `input_button`
  Alias zu `button`, identisches Verhalten.
- `lock`
  Read-only-Status als Enumeration, optionale `open`-Aktion und Zusatzattribute `changed_by` und `code_format`.
- `cover`
  Je nach `device_class` als Position, Öffnung oder Status; zusätzliche Positions- und Tilt-Attribute gemäß Features. Zusätzlich werden eine Aktionsvariable mit `Open`/`Close`/`Stop` sowie bei passenden Features eine getrennte Tilt-Aktionsvariable mit `Open Tilt`/`Close Tilt`/`Stop Tilt` angelegt.
- `valve`
  Reine Ventile als Status-Enumeration, Positionsventile als 0-100-Hauptvariable; Positionsmodus wird über `current_valve_position`, `current_position`, `reports_position` oder `supported_features` erkannt. Zusätzlich wird eine Aktionsvariable mit `Open`/`Close`/`Stop` gemäß `supported_features` angelegt.
- `climate`
  Hauptvariable für Soll- beziehungsweise Ist-Temperatur, zusätzliche Attribute wie `hvac_mode`, `hvac_action`, `preset_mode`, `fan_mode`, `swing_mode`, `swing_horizontal_mode`, `target_humidity` und `target_temperature_low/high`, zusätzliche `Power`-Variable bei Turn On/Off.
- `fan`
  Hauptvariable Ein/Aus; Attribute wie `percentage`, `oscillating`, `preset_mode` und `direction`.
- `humidifier`
  Hauptvariable Ein/Aus; Attribute `target_humidity`, `current_humidity`, `mode` und `action`.
- `media_player`
  Read-only-Status, zusätzliche Attribute gemäß `supported_features`, zusätzliche `Playback`- und `Power`-Variable, Cover-Medienobjekt.
- `camera`
  Status-Hauptvariable, Kamera-Vorschau (Standbild über `…/api/camera_proxy/<entity_id>`),
  Stream-Medienobjekt und zusätzliche `Power`-Variable bei `FEATURE_ON_OFF`.
  Hinweis zum Live-Stream: Die Symcon-Kachel-Visualisierung spielt Kamera-Streams nur als
  RTSP/RTSPS (H264) ab. Home Assistant gibt die RTSP-Adresse aus Sicherheitsgründen nicht in
  den Attributen heraus, daher bleibt das angelegte Stream-Medienobjekt zunächst leer. Trage
  die RTSP-Adresse der Kamera direkt am Stream-Medienobjekt ein, z. B.
  `rtsp://<user>:<pass>@<kamera-ip>:554/Streaming/Channels/101`. Symcon verbindet sich damit
  direkt mit der Kamera (an Home Assistant vorbei); das Modul überschreibt eine manuell
  eingetragene Adresse nicht. Liefert eine Integration ausnahmsweise `stream_source`/`rtsp_url`,
  wird der Stream automatisch gesetzt.
- `image`
  Hauptvariable `Last Update` als Zeitstempel und zusätzliche Vorschau als Medienobjekt.
- `device_tracker`
  Status-Hauptvariable plus Positions- und Verfügbarkeitsattribute aus den Home-Assistant-Attributen.
- `update`
  Read-only Hauptvariable `Update Available`/`Up to Date` plus Zusatzattribute wie `installed_version`, `latest_version`, `skipped_version`, `in_progress`, `update_percentage`, `title`, `release_summary` und `release_url`.
- `event`
  Hauptvariable `Last Event` plus Zusatzvariable `Event Type`.
- `vacuum`
  Status plus Aktionen `Start`, `Stop`, `Pause`, `Zur Basis`, `Punktreinigung`, `Lokalisieren` gemäß aktuellen `supported_features`; optionale `Lüfterstufe`.
- `lawn_mower`
  Status plus Aktionen `Start`, `Pause`, `Zur Basis` gemäß `supported_features`.

## 9. Icon-Mapping

### Binary Sensor

| Quelle         | Wert               | Icon                   |
|----------------|--------------------|------------------------|
| `device_class` | `battery`          | `battery-exclamation`  |
| `device_class` | `battery_charging` | `battery-bolt`         |
| `device_class` | `co`               | `triangle-exclamation` |
| `device_class` | `cold`             | `snowflake`            |
| `device_class` | `connectivity`     | `wifi`                 |
| `device_class` | `door`             | `door-open`            |
| `device_class` | `garage_door`      | `garage-open`          |
| `device_class` | `gas`              | `cloud-bolt`           |
| `device_class` | `heat`             | `fire`                 |
| `device_class` | `light`            | `lightbulb-on`         |
| `device_class` | `lock`             | `lock-open`            |
| `device_class` | `moisture`         | `droplet`              |
| `device_class` | `motion`           | `person-running`       |
| `device_class` | `moving`           | `person-running`       |
| `device_class` | `occupancy`        | `house-person-return`  |
| `device_class` | `opening`          | `up-right-from-square` |
| `device_class` | `plug`             | `plug`                 |
| `device_class` | `power`            | `bolt`                 |
| `device_class` | `presence`         | `user`                 |
| `device_class` | `problem`          | `triangle-exclamation` |
| `device_class` | `running`          | `play`                 |
| `device_class` | `safety`           | `shield-exclamation`   |
| `device_class` | `smoke`            | `fire-smoke`           |
| `device_class` | `sound`            | `volume-high`          |
| `device_class` | `tamper`           | `hand`                 |
| `device_class` | `update`           | `arrows-rotate`        |
| `device_class` | `vibration`        | `chart-fft`            |
| `device_class` | `window`           | `window-frame-open`    |

### Vacuum

| Quelle  | Wert        | Icon                |
|---------|-------------|---------------------|
| `state` | `cleaning`  | `robot`             |
| `state` | `docked`    | `house`             |
| `state` | `idle`      | `robot`             |
| `state` | `paused`    | `pause`             |
| `state` | `returning` | `arrow-rotate-left` |
| `state` | `error`     | `triangle-exclamation` |

### Lawn Mower

| Quelle  | Wert        | Icon                |
|---------|-------------|---------------------|
| `state` | `mowing`    | `leaf`              |
| `state` | `docked`    | `house`             |
| `state` | `paused`    | `pause`             |
| `state` | `returning` | `arrow-rotate-left` |
| `state` | `error`     | `triangle-exclamation` |

## 10. Home Assistant mqtt_statestream

Siehe Home-Assistant-Doku:
https://www.home-assistant.io/integrations/mqtt_statestream/

Mit `include` und `exclude` können Domains und Entitäten gezielt ein- oder ausgeschlossen werden.

```yaml
mqtt_statestream:
  base_topic: homeassistant
  publish_attributes: true
  publish_timestamps: true
```

#### Reaktionszeit bei Tasterereignissen

Eingehende States werden über HA's `mqtt_statestream` übermittelt. Die Standardverzögerung beträgt 1 Sekunde (`batch_delay: 1`). Für zeitkritische Taster-Reaktionen kann dieser Wert in der HA `configuration.yaml` reduziert werden:

```yaml
mqtt_statestream:
  batch_delay: 0
```

**Hinweis:** Ein kleinerer `batch_delay` erhöht das MQTT-Nachrichtenvolumen, besonders bei Sensoren mit häufigen Updates (z.B. Energiemonitoring).

### Spenden

Die Nutzung des Moduls ist kostenfrei. Niemand sollte sich verpflichtet fühlen, aber wenn das Modul gefällt, dann freue ich mich über eine Spende.

<a href="https://www.paypal.me/bumaas" target="_blank"><img src="https://www.paypalobjects.com/de_DE/DE/i/btn/btn_donate_LG.gif" border="0" /></a>
