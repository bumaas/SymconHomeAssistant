[![Version](https://img.shields.io/badge/Symcon%20Version-8.2%20%3E-green.svg)](https://www.symcon.de/forum/threads/30857-IP-Symcon-5-1-%28Stable%29-Changelog)
# Home Assistant Splitter

Verbindet MQTT mit den Device- und Configurator-Instanzen und bietet optional REST-basierte Steuerung für `*/set` Topics.

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

- Verteilt MQTT-Daten an Device-Instanzen.
- Leitet REST-Requests vom Configurator an Home Assistant weiter.
- Optional REST-Steuerung für `*/set` Topics.
- REST-Steuerung für `light`, `switch`, `lock`, `cover`, `number`, `climate`, `fan`, `humidifier`, `media_player`, `button`, `input_button`, `vacuum`.
- Optionaler generischer REST-Service-Call für beliebige Home Assistant Services.

## 2. Voraussetzungen

- Home Assistant MQTT Integration aktiv.
- MQTT Client oder MQTT Server Instanz als Parent.
- Bei MQTT Client: `ClientID` setzen und Subscription konfigurieren (z.B. `#` oder `homeassistant/#`).
- Ports: MQTT i.d.R. `1883` (oder `8883` bei TLS), Home Assistant REST typischerweise `8123`.

## 3. Installation

- In Symcon `Instanz hinzufügen` und `Home Assistant Splitter` auswählen.
- Parent auf MQTT Client oder MQTT Server verbinden.

## 4. Funktionsreferenz

Öffentliche Funktion:

- `CallService(string $domain, string $service, array $data): bool`  
  Führt einen beliebigen Home Assistant Service per REST aus.

Beispiel:

```php
$splitterId = 12345;
HA_CallService($splitterId, 'script', 'turn_on', [
    'entity_id' => 'script.play_swr3'
]);
```

## 5. Konfiguration

- `MQTTBaseTopic`: Basis-Topic für Discovery (typisch `homeassistant`).
- `HAUrl`: Base URL `http(s)://<host>:<port>` (z.B. `http://homeassistant.local:8123`).
- `HAToken`: Long-Lived Access Token (Home Assistant Profil).
- `UseRestForSetTopics`: Leitet `*/set` Topics an REST weiter.
- `RestAckTimeoutSec`: Timeout in Sekunden für REST-ACKs.
- Optional: `EnableExpertDebug`, `DebugResponseFormat`.

## 6. Statusvariablen und Profile

- Diagnosefelder in der Konfiguration (z.B. REST-Fehler, REST-Antwort, REST-Timeout, Parent-Status).

## 7. Anhang

### Home Assistant mqtt_statestream

Damit Zustandsänderungen per MQTT ankommen, muss `mqtt_statestream` aktiv sein und das `base_topic` mit `MQTTBaseTopic` übereinstimmen.
Siehe Home Assistant Doku: https://www.home-assistant.io/integrations/mqtt_statestream/
Mit `include` und `exclude` können Domains/Entitäten gezielt ein- oder ausgeschlossen werden.

```yaml
mqtt_statestream:
  base_topic: homeassistant
  publish_attributes: true
  publish_timestamps: true
```