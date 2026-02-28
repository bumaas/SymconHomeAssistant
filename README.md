[![Version](https://img.shields.io/badge/Symcon%20Version-8.2%20%3E-green.svg)](https://www.symcon.de/forum/threads/30857-IP-Symcon-5-1-%28Stable%29-Changelog)
# Home Assistant

Module für Symcon zur Einbindung und Steuerung von Home Assistant Geräten.

## Dokumentation

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

- Module für Discovery, Konfiguration, Splitter und Device.
- Anbindung von Home Assistant Entitäten per MQTT Statestream.
- Optionaler REST-Zugriff für Steuerbefehle.
- Mapping von Domains auf Symcon-Variablen.

**Module**

- [Home Assistant Discovery](Home%20Assistant%20Discovery/README.md)
- [Home Assistant Configurator](Home%20Assistant%20Configurator/README.md)
- [Home Assistant Splitter](Home%20Assistant%20Splitter/README.md)
- [Home Assistant Device](Home%20Assistant%20Device/README.md)

**Unterstützte Domains**

| Domain          | Status      | Hinweise | Offen |
| --------------- | ----------- | -------- | ----- |
| `light`         | voll        | Attribute + schreibbar | - |
| `switch`        | voll        | schaltbar | - |
| `binary_sensor` | voll        | `device_class` + Icons | - |
| `number`        | voll        | Slider/Min/Max/Step, REST `set_value` | - |
| `sensor`        | voll        | Units/Suffix, `enum` als Enumeration | - |
| `select`        | voll        | Enumeration | - |
| `climate`       | teilweise   | Solltemp (Slider), REST `set_temperature`, Modi/Attribute read-only | Preset/Fan/Swing write, Target-Range/Target-Humidity write |
| `lock`          | voll        | REST `lock`/`unlock`/`open`, Hauptvariable Wertanzeige, Aktion als Enumeration | Code-Handling bei `open` (falls erforderlich) |
| `cover`         | teilweise   | Position/Tilt, REST `open/close/stop` + `set_position` | Device-Class-Spezifika/weitere Attribute |
| `event`         | teilweise   | Enumeration aus `event_type` | Weitere Event-Attribute |
| `fan`           | teilweise   | Status (On/Off), Attribute (`percentage`, `oscillating`, `preset_mode`, `direction`) | Weitere Dienste/Features je Modell |
| `humidifier`    | teilweise   | Status (On/Off), Attribute (`target_humidity`, `current_humidity`, `mode`, `action`) | Weitere Dienste/Features je Modell |
| `vacuum`        | teilweise   | REST `start`/`stop`/`pause`/`return_to_base`, `fan_speed` | Weitere Dienste/Features je Modell |
| `media_player`  | teilweise   | Status, Aktionen, Attribute, Cover als Medienobjekt | Weitere Dienste/Features je Modell |
| `button`        | voll        | `press` als Aktion | - |
| `input_button`  | voll        | `press` als Aktion | - |

## 2. Voraussetzungen

- Symcon ab Version 8.2
- MQTT Broker in Home Assistant und eine MQTT Client Instanz in Symcon oder alternativ ein Symcon MQTT Server
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

1. MQTT Client in Symcon einrichten und mit dem Broker verbinden.
2. Home Assistant Splitter anlegen und mit dem MQTT Client verbinden.
3. Im Splitter `MQTTBaseTopic` setzen (typisch: `homeassistant`) sowie `HAUrl` und `HAToken` für REST.
4. Home Assistant Discovery ausführen, um eine Configurator Instanz zu erstellen.
5. Im Configurator gefundene Geräte auswählen und Device Instanzen erzeugen.

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

Die Variablen werden in den jeweiligen Device-Instanzen pro Entität angelegt. Details siehe `Home Assistant Device`.

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
Home Assistant Device / Configurator
```

**Hinweise zur Fehlersuche**

- Wenn es im Datenfluss hakt: mit Hilfe des [MQTT Explorer](https://mqtt-explorer.com/) kann komfortabel geprüft werden, ob der MQTT-Server mit Daten versorgt wird (Topic, Payload, Attribute).
- IP-Adressen und Ports prüfen: MQTT standardmäßig `1883` (TLS `8883`), Home Assistant REST standardmäßig `8123`.
- MQTT-Broker-Log in Home Assistant prüfen (z. B. Mosquitto Add-on Logs), um Verbindungsfehler oder Auth-Probleme zu erkennen.

### Datenfluss
- Device/Configurator -> Splitter: `{E62B0B4F-1B5C-4F2C-9B6B-2C86F5B7C1D1}`
- Splitter -> Device/Configurator: `{F4A2B9F1-1D3B-44A9-9B6A-0D3A5A7D6E10}`
- Splitter <-> MQTT Client (RX): `{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}`
- Splitter <-> MQTT Client (TX): `{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}`

### GUIDs der Module

| Modul                        | Typ          | GUID |
| --------------------------- | ------------ | -------------------------------------- |
| Home Assistant Discovery    | Discovery    | `{C36FEFA4-4732-CBD6-0216-A1DB30D036CF}` |
| Home Assistant Configurator | Configurator | `{B9830F89-98E6-106C-CD6C-A3AD76FD5AE9}` |
| Home Assistant Splitter     | Splitter     | `{0A4C4B31-2F59-4D21-8F62-3A12A0A0F3E1}` |
| Home Assistant Device       | Device       | `{72D6A284-1870-4E11-92D8-0402C8233C29}` |

### FAQ

**MQTT Verbindung / Instanzanlage schlägt fehl (Code -32603)**

- In Home Assistant die MQTT-Integration möglichst über das offizielle Mosquitto Broker Add-on einrichten.
- Im Splitter eine interne HA-URL verwenden (lokale IP/Host, Port 8123), nicht die externe Zugriff-URL.
