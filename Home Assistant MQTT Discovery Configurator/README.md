[![Version](https://img.shields.io/badge/Symcon%20Version-9.0%20%3E-green.svg)](https://www.symcon.de/forum/threads/30857-IP-Symcon-5-1-%28Stable%29-Changelog)
# Home Assistant MQTT Discovery Configurator

Liest MQTT-Discovery-Konfigurationen direkt aus einer MQTT-Discovery-Quelle über den Home Assistant MQTT Discovery Splitter und gruppiert sie zu Gerätekandidaten.

> Teil des Projekts **Home Assistant für IP-Symcon** — [Gesamtübersicht, Betriebsarten und Fehlersuche](../README.md).

## Funktionsumfang

- Liest gecachte `homeassistant/.../config` Topics aus dem Splitter.
- Benötigt keine bestehende Home-Assistant-Installation; Grundlage sind die publizierten Discovery-Payloads.
- Parst MQTT-Discovery für `sensor`, `binary_sensor`, `number`, `image`, `device_tracker`, `update`, `lock`, `cover`, `climate`, `switch`, `select`, `button` und `light`.
- Unterstützt sowohl klassische Component-Topics wie `homeassistant/sensor/.../config` als auch HA-Device-Discovery über `homeassistant/device/.../config`.
- Reduziert `device_automation` Trigger aus Zigbee2MQTT auf lesbare Event-Kandidaten im Discovery-Modell.
- Gruppiert Entities über `device.identifiers`.
- Zeigt MQTT-Discovery-Geräte aus klassischen und Device-Discovery-Exports im Configurator an.
- Zeigt zusätzlich eine Diagnose-Summary für nicht unterstützte oder parserseitig übersprungene Discovery-Einträge.
- Legt daraus `Home Assistant MQTT Discovery Device` Instanzen an.
- Übergibt an Discovery-Devices nur noch stabile Basisdaten:
  - `DeviceID`
  - Symcon-Instanzname

## Voraussetzungen

- Home Assistant MQTT Discovery Splitter als Parent.
- Ein Gerät oder Dienst muss Home Assistant MQTT Discovery-Payloads an den Broker publizieren.
- Parent des Splitters kann ein MQTT Client oder im Entwicklungsbetrieb der Bundle-Modus des Splitters sein.
- Wenn als Broker der Symcon MQTT Server genutzt wird, bleibt für den Live-Pfad trotzdem ein MQTT Client vor dem Splitter nötig. Dieser Client kann direkt auf den lokalen Server verbunden werden, zum Beispiel `127.0.0.1:1028`.
- Im Live-Betrieb muss der MQTT Client den Discovery-Prefix abonnieren, zum Beispiel `homeassistant/#` oder `#`.
- Discovery-Topics müssen im Splitter-Cache angekommen sein.

## Konfiguration

- `ShowBridgeDevices`: blendet technische Bridge-Devices im Configurator optional ein oder aus.
- `EnableExpertDebug`: aktiviert zusätzliche Debug-Ausgaben.

## Migration

- Discovery-Devices sind self-resolving und ziehen ihre komplette Definition später selbst über `DeviceID` aus dem Splitter.
- Doppelte Metadaten in Device-Properties werden nicht mehr vom Configurator in den `create`-Block geschrieben.
- Wenn ein neu angelegtes Device leer bleibt, zuerst den Splitter-Cache und die Parent-Kette prüfen.

## Hinweis

Der Configurator ist bewusst vom klassischen Home-Assistant-Configurator getrennt. Er arbeitet direkt auf MQTT-Discovery-Daten und ist auf den aktuellen MQTT-Discovery-Kernpfad für `sensor`, `binary_sensor`, `number`, `image`, `device_tracker`, `update`, `lock`, `cover`, `climate`, `switch`, `select`, `button`, `light` und einfache `device_automation`-Trigger begrenzt. Weitere Discovery-Komponenten können später über dasselbe Transportmodell folgen.
