[![Version](https://img.shields.io/badge/Symcon%20Version-9.0%20%3E-green.svg)](https://www.symcon.de/forum/threads/30857-IP-Symcon-5-1-%28Stable%29-Changelog)
# Home Assistant

Mit diesem Modul lassen sich Geräte, Entitäten und Dienste aus dem Home-Assistant-Umfeld komfortabel in Symcon nutzen.

Das Modul unterstützt dafür zwei klar getrennte Anwendungsfälle:

1. die klassische Bridge, um bestehende Elemente aus einer Home-Assistant-Installation nach Symcon zu übernehmen
2. MQTT Discovery, um kompatible Geräte und Dienste direkt per MQTT in Symcon einzubinden

So kann Symcon entweder mit einer vorhandenen Home-Assistant-Installation zusammenarbeiten oder Geräte und Dienste direkt über MQTT einbinden. Beide Wege können parallel genutzt werden.

## Inhaltsverzeichnis

1. [Betriebsarten](#1-betriebsarten)
2. [Module](#2-module)
3. [Voraussetzungen](#3-voraussetzungen)
4. [Installation und Konfiguration](#4-installation-und-konfiguration)
5. [Unterstützte Domains und Komponenten](#5-unterstützte-domains-und-komponenten)
6. [Überblick](#6-überblick)
7. [Fehlersuche](#7-fehlersuche)
8. [FAQ](#8-faq)

## 1. Betriebsarten

### 1.1 Klassische Bridge-Funktionalität

Die klassische Bridge ist der richtige Weg, wenn in Home Assistant bereits Geräte, Entitäten oder Dienste vorhanden sind, die auch in Symcon genutzt werden sollen.

- Vorhandene Elemente aus Home Assistant werden in Symcon übernommen.
- Zustände und zusätzliche Informationen bleiben aktuell.
- Viele Funktionen lassen sich anschließend direkt aus Symcon bedienen.
- Geeignet für bestehende Home-Assistant-Installationen, die in Symcon eingebunden werden sollen.

Typische Module in diesem Pfad:

- [Home Assistant Discovery](Home%20Assistant%20Discovery/README.md)
- [Home Assistant Configurator](Home%20Assistant%20Configurator/README.md)
- [Home Assistant Splitter](Home%20Assistant%20Splitter/README.md)
- [Home Assistant Device](Home%20Assistant%20Device/README.md)
- [Home Assistant Entity](Home%20Assistant%20Entity/README.md)

### 1.2 MQTT Discovery für Geräte und Dienste

MQTT Discovery ist der richtige Weg, wenn Geräte oder Dienste direkt über MQTT in Symcon eingebunden werden sollen.

- Es ist keine bestehende Home-Assistant-Installation erforderlich.
- Geräte und Dienste werden automatisch erkannt.
- Aktuelle Werte kommen direkt über MQTT.
- Ein `mqtt_statestream` ist dafür nicht nötig.
- Geeignet für Geräte oder Dienste, die Home Assistant MQTT Discovery unterstützen und ihre Daten an den MQTT-Broker senden.

Typische Module in diesem Pfad:

- [Home Assistant MQTT Discovery Splitter](Home%20Assistant%20MQTT%20Discovery%20Splitter/README.md)
- [Home Assistant MQTT Discovery Configurator](Home%20Assistant%20MQTT%20Discovery%20Configurator/README.md)
- [Home Assistant MQTT Discovery Device](Home%20Assistant%20MQTT%20Discovery%20Device/README.md)

### 1.3 Unterschiede im Überblick

| Thema                                 | Klassische Bridge                                    | MQTT Discovery                                |
|---------------------------------------|------------------------------------------------------|-----------------------------------------------|
| Ausgangspunkt                         | bestehende Home-Assistant-Installation               | Gerät oder Dienst mit MQTT Discovery          |
| Woher kommen die Geräteinformationen? | aus Home Assistant                                   | aus den MQTT-Discovery-Meldungen              |
| Woher kommen die aktuellen Werte?     | aus `mqtt_statestream`                               | direkt über MQTT                              |
| REST erforderlich                     | ja, für Einrichtung und viele Befehle                | nein, im Normalfall nicht                     |
| `mqtt_statestream` erforderlich       | ja                                                   | nein                                          |
| Home Assistant erforderlich           | ja                                                   | nein                                          |
| Typische Nutzung                      | vorhandene HA-Geräte und Entitäten nach Symcon holen | Geräte oder Dienste direkt per MQTT einbinden |
| Wichtige Module                       | Splitter, Configurator, Device, Entity               | MQTT Discovery Splitter, Configurator, Device |

## 2. Module

### Klassische Bridge

- [Home Assistant Discovery](Home%20Assistant%20Discovery/README.md): findet Home-Assistant-Installationen im Netzwerk
- [Home Assistant Configurator](Home%20Assistant%20Configurator/README.md): zeigt Geräte und Entitäten aus Home Assistant zur Auswahl an
- [Home Assistant Splitter](Home%20Assistant%20Splitter/README.md): verbindet Home Assistant mit den Symcon-Modulen
- [Home Assistant Device](Home%20Assistant%20Device/README.md): bildet ein Gerät in Symcon ab
- [Home Assistant Entity](Home%20Assistant%20Entity/README.md): bildet eine einzelne Entität in Symcon ab

### MQTT Discovery

- [Home Assistant MQTT Discovery Splitter](Home%20Assistant%20MQTT%20Discovery%20Splitter/README.md): empfängt die Discovery-Meldungen und bereitet sie für Symcon auf
- [Home Assistant MQTT Discovery Configurator](Home%20Assistant%20MQTT%20Discovery%20Configurator/README.md): zeigt gefundene Geräte zur Auswahl an
- [Home Assistant MQTT Discovery Device](Home%20Assistant%20MQTT%20Discovery%20Device/README.md): bildet ein gefundenes Gerät in Symcon ab

## 3. Voraussetzungen

### Allgemein

- Symcon ab Version 9.0
- MQTT-Broker

### Für die klassische Bridge

- bestehende Home-Assistant-Installation
- MQTT Client oder MQTT Server in Symcon
- `mqtt_statestream` in Home Assistant aktiv
- passender Long-Lived Access Token für den Zugriff auf Home Assistant
- Optional mDNS/DNS-SD für das Modul `Home Assistant Discovery`

### Für MQTT Discovery

- MQTT Client in Symcon
- Wenn der Symcon MQTT Server als Broker genutzt wird, bleibt fuer den Discovery-Pfad trotzdem ein MQTT Client erforderlich. Der MQTT-Client kann dabei direkt auf den lokalen MQTT Server zeigen, z. B. `127.0.0.1:1028`.
- ein Gerät oder Dienst, das bzw. der Home Assistant MQTT Discovery an den Broker meldet
- passende Subscription für die Discovery-Meldungen, typischerweise `homeassistant/#`
- zusätzlich die MQTT-Topics des Geräts oder Dienstes, damit aktuelle Werte ankommen, bei Zigbee2MQTT typischerweise `zigbee2mqtt/#`

## 4. Installation und Konfiguration

### 4.1 Klassische Bridge

1. MQTT Client oder MQTT Server in Symcon einrichten.
2. `Home Assistant Splitter` anlegen und mit diesem Parent verbinden.
3. Im Splitter `MQTTBaseTopic`, `HAUrl` und `HAToken` setzen.
4. `Home Assistant Discovery` oder direkt `Home Assistant Configurator` nutzen.
5. Im Configurator gewünschte Geräte oder Entitäten auswählen und anlegen.

#### `mqtt_statestream` in Home Assistant

Damit Zustände und zusätzliche Informationen in Symcon ankommen, muss `mqtt_statestream` aktiv sein und das `base_topic` zu `MQTTBaseTopic` passen.

```yaml
mqtt_statestream:
  base_topic: homeassistant
  publish_attributes: true
  publish_timestamps: true
```

### 4.2 MQTT Discovery

1. MQTT Client in Symcon einrichten.
   Wenn der Broker als Symcon MQTT Server laeuft, kann der MQTT Client direkt auf diesen Server verbunden werden, z. B. per `127.0.0.1:1028`.
2. Subscription so setzen, dass mindestens `homeassistant/#` empfangen wird.
3. Zusätzlich die Topics des Geräts oder Dienstes abonnieren, bei Zigbee2MQTT typischerweise `zigbee2mqtt/#`.
4. `Home Assistant MQTT Discovery Splitter` anlegen und mit diesem MQTT-Client verbinden.
5. Im Splitter `MQTTDiscoveryPrefix` auf den literalen Discovery-Prefix setzen, typischerweise `homeassistant`.
6. `Home Assistant MQTT Discovery Configurator` öffnen und daraus `Home Assistant MQTT Discovery Device` Instanzen erzeugen.

### 4.3 Bundle-Modus für MQTT Discovery

Der `Home Assistant MQTT Discovery Splitter` kann statt Live-MQTT auch ein exportiertes Discovery-Bundle laden.

- gedacht für Entwicklung, Support und Analyse
- `SourceMode = bundle`
- `BundlePath` auf ein V2-Bundle setzen
- optional `BundleCurrentSessionOnly` und `ReplayTopicsOnApply` verwenden

Im Bundle-Modus werden aktuell keine Befehle an Geräte gesendet.

## 5. Unterstützte Domains und Komponenten

### 5.1 Klassische Bridge

| Domain          | Status    | Hinweise                                                             |
|-----------------|-----------|----------------------------------------------------------------------|
| `light`         | voll      | Attribute und Schreibzugriffe unterstützt                            |
| `switch`        | voll      | schaltbar                                                            |
| `binary_sensor` | voll      | `device_class` und Icons                                             |
| `number`        | voll      | Slider, Min/Max/Step, REST `set_value`; gilt auch für `input_number` |
| `input_text`    | voll      | String-Wert, REST `set_value`                                        |
| `datetime`      | voll      | Integer-Zeitwert, REST `set_value`                                   |
| `input_datetime`| voll      | Integer-Zeitwert, REST `set_datetime`                                |
| `sensor`        | voll      | Units, Suffixe, `enum` als Enumeration                               |
| `select`        | voll      | Enumeration                                                          |
| `climate`       | voll      | Solltemperatur, Modus, Preset, Lüfter, Swing, Ein/Aus, Zielfeuchte   |
| `lock`          | voll      | `lock`, `unlock`, `open`, Aktionsvariable                            |
| `cover`         | teilweise | Position, Tilt, `open`/`close`/`stop`, `set_position`                |
| `valve`         | teilweise | Status oder Positionsventil, `open`/`close`/`stop`, `set_position`   |
| `event`         | teilweise | Enumeration aus `event_type`                                         |
| `fan`           | teilweise | Status und zentrale Attribute                                        |
| `humidifier`    | teilweise | Status und zentrale Attribute                                        |
| `vacuum`        | teilweise | zentrale Services wie `start`, `stop`, `pause`, `return_to_base`     |
| `lawn_mower`    | teilweise | Status plus `start_mowing`, `pause`, `dock`                          |
| `media_player`  | teilweise | Status, Aktionen, Attribute, Cover                                   |
| `camera`        | teilweise | Status, Bild-Vorschau; Live-Stream nur per manueller RTSP-Adresse    |
| `image`         | teilweise | Bild-Entität als Medienobjekt                                        |
| `device_tracker`| teilweise | Status plus Positionsattribute wie Latitude/Longitude                |
| `update`        | teilweise | Read-only Status plus Versions- und Release-Metadaten                |
| `button`        | voll      | `press`                                                              |
| `input_button`  | voll      | `press`                                                              |

### 5.2 MQTT Discovery

Aktuell unterstützt der MQTT-Discovery-Pfad folgende Komponenten:

- `sensor`
- `binary_sensor`
- `number`
- `cover`
- `climate`
- `switch`
- `select`
- `button`
- `light`
- `image`
- `device_tracker`
- `lock`
- `update`

Zusätzlich werden einfache Zigbee2MQTT-`device_automation`-Trigger unterstützt.

## 6. Überblick

### Klassische Bridge

```text
Home Assistant
  |  Geräteinformationen + aktuelle Werte
  v
MQTT Broker
  v
IP-Symcon MQTT Client/Server
  v
Home Assistant Splitter
  |  verteilt Daten an die Symcon-Module
  v
Home Assistant Configurator / Device / Entity
```

### MQTT Discovery

```text
Gerät oder Dienst mit MQTT Discovery
  |  Discovery-Meldungen + aktuelle Werte
  v
MQTT Broker
  v
IP-Symcon MQTT Client
  v
Home Assistant MQTT Discovery Splitter
  |  bereitet die Daten für Symcon auf
  v
Home Assistant MQTT Discovery Configurator / Device
```

## 7. Fehlersuche

> **Diagnosewerkzeug MQTT Explorer:** Für die Analyse des MQTT-Verkehrs empfiehlt sich der kostenlose [MQTT Explorer](https://mqtt-explorer.com/). Er verbindet sich mit demselben Broker wie Symcon und zeigt live alle Topics samt Werten als Baum an. Damit lässt sich prüfen, ob Topics wie `<MQTTBaseTopic>/switch/<entity>/state` (klassische Bridge) bzw. `homeassistant/.../config` (MQTT Discovery) überhaupt ankommen und welche Werte sie tragen.

### 7.1 Klassische Bridge

- Wenn im `Home Assistant Splitter` `Kein aktiver MQTT Parent gefunden` steht: Verbindung zum MQTT-Client oder MQTT-Server prüfen.
- Wenn keine Werte ankommen: `mqtt_statestream` in Home Assistant prüfen und sicherstellen, dass `base_topic` zu `MQTTBaseTopic` passt.
- Wenn der Zugriff auf Home Assistant fehlschlägt: `HAUrl`, `HAToken` und die Erreichbarkeit von Home Assistant prüfen.
- **Wenn der angezeigte Status nicht mit Home Assistant übereinstimmt:** Zustände kommen ausschließlich über den `mqtt_statestream`, nicht über REST. Stimmt die Anzeige nicht, fehlen die aktuellen Statusdaten.
  - **MQTT Client als Parent** verwenden (nicht nur MQTT Server): Nur der Client erhält beim Verbinden den retained-Replay und damit sofort den echten Initialzustand. Subscription z. B. `homeassistant/#` (testweise `#`).
  - Im MQTT Explorer gegenprüfen, ob unter `<MQTTBaseTopic>/switch/<entity>/state` tatsächlich `on`/`off` liegt. Kommt nichts an, bleibt der zuletzt gesetzte bzw. der Default-Wert stehen.
- **Wenn sich Entitäten nicht schalten lassen:** Das Schalten läuft über REST (z. B. `switch.turn_on`/`turn_off`), nicht über MQTT. Voraussetzungen: gültige `HAUrl`, gültiges `HAToken` und ggf. `UseRestForSetTopics` aktiv.
- **Diagnosefelder nutzen:** Die Splitter-Konfiguration zeigt `REST-Fehler`, `REST-Antwort`, `REST-Timeout` und den Parent-Status. Dort steht, ob ein REST-Call durchgeht oder z. B. an Token, URL oder Erreichbarkeit scheitert.

### 7.2 MQTT Discovery

- Wenn Discovery-Geräte nicht auftauchen: prüfen, ob der MQTT-Client mindestens `homeassistant/#` empfängt.
- Wenn Discovery-Geräte angelegt werden, aber keine Werte bekommen: zusätzlich die Topics des Geräts oder Dienstes abonnieren, bei Zigbee2MQTT typischerweise `zigbee2mqtt/#`.
- Wenn bereits vorhandene Discovery-Meldungen nicht vollständig eingelesen wurden: im `Home Assistant MQTT Discovery Splitter` `MQTT-IO reconnecten` ausführen.
- Für Analyse und Support stehen im Discovery-Splitter zwei Exporte bereit:
  - `Discovery-Bundle herunterladen`
  - `Discovery-Bundle aktuelle Session herunterladen`

## 8. FAQ

### Was ist der entscheidende Unterschied zwischen beiden Pfaden?

Die klassische Bridge übernimmt bereits vorhandene Geräte und Entitäten aus Home Assistant nach Symcon. MQTT Discovery bindet Geräte oder Dienste direkt per MQTT ein.

### Brauche ich für MQTT Discovery ebenfalls `mqtt_statestream`?

Nein. Beim Discovery-Pfad kommen die Werte direkt über MQTT.

### Brauche ich für MQTT Discovery überhaupt eine Home-Assistant-Installation?

Nein. Es reicht ein Gerät oder Dienst, das bzw. der Home Assistant MQTT Discovery unterstützt und seine Daten an den MQTT-Broker sendet.

### Kann ich beide Pfade gleichzeitig betreiben?

Ja. Die Modulgruppen sind absichtlich getrennt und können parallel genutzt werden.

### Warum braucht MQTT Discovery einen MQTT Client und nicht nur den MQTT Server?

Der MQTT-Discovery-Pfad baut seinen Discovery-Cache aus den retained `homeassistant/.../config` Topics auf. Dafuer braucht der Splitter einen abonnierenden MQTT Client als Parent, der die Discovery- und Runtime-Topics aktiv vom Broker empfaengt und bei einem Reconnect erneut als retained Replay bekommt. Genau darauf basiert auch die Funktion `MQTT-IO reconnecten`.

Der Symcon MQTT Server kann dabei weiterhin der Broker sein. Fuer den Discovery-Pfad wird dann zusaetzlich ein MQTT Client verwendet, dessen IO direkt auf den lokalen MQTT Server zeigen kann, z. B. `127.0.0.1:1028`.

### Spenden

Die Nutzung des Moduls ist kostenfrei. Niemand sollte sich verpflichtet fühlen, aber wenn das Modul gefällt, freue ich mich über eine Spende.

<a href="https://www.paypal.me/bumaas" target="_blank"><img src="https://www.paypalobjects.com/de_DE/DE/i/btn/btn_donate_LG.gif" border="0" /></a>
