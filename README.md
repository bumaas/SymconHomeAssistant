[![Version](https://img.shields.io/badge/Symcon%20Version-8.2%20%3E-green.svg)](https://www.symcon.de/forum/threads/30857-IP-Symcon-5-1-%28Stable%29-Changelog)
# Home Assistant

Module für Symcon zur Einbindung und Steuerung von Home Assistant Geräten.

## Dokumentation

Interne Wartungsdoku: [Architektur](docs/ARCHITEKTUR.md)
Umsetzungs- und Backlog-Plan: [Weiteres Vorgehen](docs/WEITERES_VORGEHEN.md)
Discovery-Fixtures: [docs/fixtures](docs/fixtures/README.md)

**Inhaltsverzeichnis**

1. [Funktionsumfang](#1-funktionsumfang)  
2. [Voraussetzungen](#2-voraussetzungen)  
3. [Installation](#3-installation)  
4. [Funktionsreferenz](#4-funktionsreferenz)  
5. [Konfiguration](#5-konfiguration)  
6. [Statusvariablen und Profile](#6-statusvariablen-und-profile)  
7. [Anhang](#7-anhang)  
    1. [Datenfluss](#datenfluss)  
    2. [GUIDs der Module](#guids-der-module)  
    3. [FAQ](#faq)

## 1. Funktionsumfang

- Module für Discovery, Konfiguration, Splitter, Device und Entity.
- MQTT Discovery Splitter, Configurator und Device fuer `homeassistant/.../config` Topics.
- Anbindung von Home Assistant Entitäten per MQTT Statestream.
- Optionaler REST-Zugriff für Steuerbefehle.
- Mapping von Domains auf Symcon-Variablen.

**Module**

- [Home Assistant Discovery](Home%20Assistant%20Discovery/README.md)
- [Home Assistant Configurator](Home%20Assistant%20Configurator/README.md)
- [Home Assistant MQTT Discovery Splitter](Home%20Assistant%20MQTT%20Discovery%20Splitter/README.md)
- [Home Assistant MQTT Discovery Configurator](Home%20Assistant%20MQTT%20Discovery%20Configurator/README.md)
- [Home Assistant MQTT Discovery Device](Home%20Assistant%20MQTT%20Discovery%20Device/README.md)
- [Home Assistant Splitter](Home%20Assistant%20Splitter/README.md)
- [Home Assistant Device](Home%20Assistant%20Device/README.md)
- [Home Assistant Entity](Home%20Assistant%20Entity/README.md)

**Unterstützte Domains**

| Domain          | Status    | Hinweise                                                                                                                             | Offen                                         |
|-----------------|-----------|--------------------------------------------------------------------------------------------------------------------------------------|-----------------------------------------------|
| `light`         | voll      | Attribute + schreibbar                                                                                                               | -                                             |
| `switch`        | voll      | schaltbar                                                                                                                            | -                                             |
| `binary_sensor` | voll      | `device_class` + Icons                                                                                                               | -                                             |
| `number`        | voll      | Slider/Min/Max/Step, REST `set_value` (gilt auch fuer `input_number`)                                                                | -                                             |
| `sensor`        | voll      | Units/Suffix, `enum` als Enumeration                                                                                                 | -                                             |
| `select`        | voll      | Enumeration                                                                                                                          | -                                             |
| `climate`       | voll      | Heizen/Kühlen steuerbar: Solltemperatur, Modus (z. B. Heizen/Kühlen), Preset-, Lüfter- und Swing-Modus sowie Ein/Aus und Zielfeuchte | -                                             |
| `lock`          | voll      | REST `lock`/`unlock`/`open`, Hauptvariable Wertanzeige, Aktion als Enumeration                                                       | Code-Handling bei `open` (falls erforderlich) |
| `cover`         | teilweise | Position/Tilt, REST `open`/`close`/`stop` + `set_position`, eigene Aktionsvariable für `open`/`close`/`stop`, separate Tilt-Aktion | Device-Class-Spezifika/weitere Attribute      |
| `valve`         | teilweise | Reine Ventile als Status, Positionsventile als 0-100 Hauptvariable; MQTT/REST `open`/`close`/`stop` + `set_position`, Aktionsvariable | Weitere ventilspezifische Attribute/Details   |
| `event`         | teilweise | Enumeration aus `event_type`                                                                                                         | Weitere Event-Attribute                       |
| `fan`           | teilweise | Status (On/Off), Attribute (`percentage`, `oscillating`, `preset_mode`, `direction`)                                                | Weitere Dienste/Features je Modell            |
| `humidifier`    | teilweise | Status (On/Off), Attribute (`target_humidity`, `current_humidity`, `mode`, `action`)                                                | Weitere Dienste/Features je Modell            |
| `vacuum`        | teilweise | REST `start`/`stop`/`pause`/`return_to_base`, `clean_spot`, `locate`, `fan_speed`                                                   | Weitere Dienste/Features je Modell            |
| `lawn_mower`    | teilweise | Status + Aktionen `start_mowing`/`pause`/`dock`                                                                                      | Weitere Dienste/Features je Modell            |
| `media_player`  | teilweise | Status, Aktionen, Attribute, Cover als Medienobjekt                                                                                  | Weitere Dienste/Features je Modell            |
| `camera`        | teilweise | Status + Kamera-Bild als Medienobjekt; Vorschau wird stabil über `camera_proxy/<entity_id>` geladen                                 | Kamera-Aktionen/Services                      |
| `image`         | teilweise | Bild-Entität als Medienobjekt; Status wird als Zeitstempel mit Datum/Uhrzeit dargestellt                                            | Weitere image-spezifische Attribute           |
| `button`        | voll      | `press` als Aktion                                                                                                                   | -                                             |
| `input_button`  | voll      | `press` als Aktion                                                                                                                   | -                                             |

## 2. Voraussetzungen

- Symcon ab Version 9.0
- MQTT Broker in Home Assistant und eine MQTT Client-Instanz in Symcon oder alternativ ein Symcon MQTT Server
- Fuer den MQTT Discovery Splitter wird ein zusaetzlicher Symcon MQTT Client mit Subscription `homeassistant/#` benoetigt.
- Fuer MQTT Discovery Devices muessen ueber denselben MQTT Client zusaetzlich die Laufzeit-Topics der Quelle empfangen werden, bei Zigbee2MQTT typischerweise `zigbee2mqtt/#`.
- Home Assistant mit aktivierter MQTT Integration (Statestream)
- Optional: mDNS/DNS-SD (Discovery)
- Long-Lived Access Token (REST)
- MQTT Statestream in Home Assistant (`mqtt_statestream`) aktiv und `base_topic` passend zu `MQTTBaseTopic`

## 3. Installation

- Modul über den Symcon Module Store hinzufügen (Library: "Home Assistant").
- Danach die benötigten Instanzen anlegen.

## 4. Funktionsreferenz

Keine öffentlichen Funktionen im Root-Modul. Siehe die jeweiligen Modul-READMEs.

## 5. Konfiguration

**Einrichtung (Kurzfassung)**

1. MQTT Client in Symcon einrichten und mit dem Broker verbinden (inkl. Subscription `homeassistant/#` oder `#`).
2. Home Assistant Splitter anlegen und mit dem MQTT Client verbinden.
3. Im Splitter `MQTTBaseTopic` setzen (typisch: `homeassistant`) sowie `HAUrl` und `HAToken` für REST.
4. Home Assistant Discovery ausführen, um eine Configurator Instanz zu erstellen.
5. Im Configurator gefundene Geräte oder Entitäten auswählen und Instanzen erzeugen.

**Home Assistant mqtt_statestream**

Damit Zustandsänderungen per MQTT ankommen, muss `mqtt_statestream` aktiv sein und das `base_topic` mit `MQTTBaseTopic` übereinstimmen:
Siehe Home Assistant Doku: https://www.home-assistant.io/integrations/mqtt_statestream/

```yaml
mqtt_statestream:
  base_topic: homeassistant
  publish_attributes: true
  publish_timestamps: true
```

## 6. Statusvariablen und Profile

Die Variablen werden in den jeweiligen Instanzen pro Entität angelegt. Details siehe `Home Assistant Device` oder `Home Assistant Entity`.

Hinweise:

- `camera` legt zusätzlich eine Bild-Vorschau als Medienobjekt an.
- `image` legt ebenfalls eine Bild-Vorschau als Medienobjekt an; die Hauptvariable enthält den letzten Bild-Zeitstempel als Integer mit Datum/Uhrzeit-Darstellung.

## 7. Anhang

**Überblick (Ablaufdiagramm)**

```text
Home Assistant
  |  mqtt_statestream (states + attributes)
  v
MQTT Broker
  |  homeassistant/<domain>/<entity>/state
  |  homeassistant/<domain>/<entity>/attributes
  v
IP-Symcon MQTT Client/Server
  v
Home Assistant Splitter
  |  verteilt an Device/Configurator
  |  optional REST für Set/Service-Calls
  v
Home Assistant Device / Entity / Configurator
```

**Hinweise zur Fehlersuche**

- Wenn im Splitter `Kein aktiver MQTT Parent gefunden` steht: Parent-Verbindung im Splitter, MQTT-Client-Status und Subscription in Symcon prüfen (`homeassistant/#`).
- Wenn es im Datenfluss hakt: Mithilfe des [MQTT Explorer](https://mqtt-explorer.com/) kann komfortabel geprüft werden, ob der MQTT-Server mit Daten versorgt wird (Topic, Payload, Attribute).
- IP-Adressen und Ports prüfen: MQTT standardmäßig `1883` (TLS `8883`), Home Assistant REST standardmäßig `8123`.
- MQTT-Broker-Log in Home Assistant prüfen (z. B. Mosquitto Add-on Logs), um Verbindungsfehler oder Auth-Probleme zu erkennen.

### Datenfluss
- Device/Configurator -> Splitter: `{E62B0B4F-1B5C-4F2C-9B6B-2C86F5B7C1D1}`
- Splitter -> Device/Configurator: `{F4A2B9F1-1D3B-44A9-9B6A-0D3A5A7D6E10}`
- Splitter <-> MQTT Client (RX): `{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}`
- Splitter <-> MQTT Client (TX): `{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}`

### GUIDs der Module

| Modul                       | Typ          | GUID                                     |
|-----------------------------|--------------|------------------------------------------|
| Home Assistant Discovery    | Discovery    | `{C36FEFA4-4732-CBD6-0216-A1DB30D036CF}` |
| Home Assistant Configurator | Configurator | `{B9830F89-98E6-106C-CD6C-A3AD76FD5AE9}` |
| Home Assistant MQTT Discovery Splitter | Splitter | `{68522B48-8638-4AA1-995F-84DD1CF32CD8}` |
| Home Assistant MQTT Discovery Configurator | Configurator | `{E5739B2D-7732-4398-9AD1-BECF0B8738C5}` |
| Home Assistant MQTT Discovery Device | Device | `{5A6C7B2A-14B4-4D6C-AC3C-07D7D6A7568D}` |
| Home Assistant Splitter     | Splitter     | `{0A4C4B31-2F59-4D21-8F62-3A12A0A0F3E1}` |
| Home Assistant Device       | Device       | `{72D6A284-1870-4E11-92D8-0402C8233C29}` |
| Home Assistant Entity       | Device       | `{C27D957C-3761-497B-8A30-A223405E04F2}` |

### FAQ

**MQTT Verbindung / Instanzanlage schlägt fehl (Code -32603)**

- In Home Assistant die MQTT-Integration möglichst über das offizielle Mosquitto Broker Add-on einrichten.
- Im Splitter eine interne HA-URL verwenden (lokale IP/Host, Port 8123), nicht die externe Zugriff-URL.

### Spenden

Die Nutzung des Moduls ist kostenfrei. Niemand sollte sich verpflichtet fühlen, aber wenn das Modul gefällt, dann freue ich mich über eine Spende.

<a href="https://www.paypal.me/bumaas" target="_blank"><img src="https://www.paypalobjects.com/de_DE/DE/i/btn/btn_donate_LG.gif" border="0" /></a>
