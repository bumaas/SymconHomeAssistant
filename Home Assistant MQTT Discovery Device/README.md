[![Version](https://img.shields.io/badge/Symcon%20Version-8.2%20%3E-green.svg)](https://www.symcon.de/forum/threads/30857-IP-Symcon-5-1-%28Stable%29-Changelog)
# Home Assistant MQTT Discovery Device

Laufzeitmodul fuer MQTT-Discovery-Geraete aus dem Home Assistant MQTT Discovery Configurator.

## Funktionsumfang

- Empfaengt MQTT-Nachrichten ueber den Home Assistant MQTT Discovery Splitter.
- Wertet `state_topic`, `command_topic` und `availability` quellenneutral fuer MQTT-Discovery aus.
- Unterstuetzt aktuell die Komponenten `sensor`, `binary_sensor`, `switch`, `select` und `button`.
- Kann diese Komponenten sowohl aus klassischen Discovery-Topics `homeassistant/<component>/.../config` als auch aus HA-Device-Discovery `homeassistant/device/.../config` aufloesen.
- Stellt Zigbee2MQTT-`device_automation` Trigger als read-only Event-Zeitstempel pro Trigger-Subtype dar.
- Behaelt fuer Zigbee2MQTT-Trigger einen Root-Topic-JSON-Fallback bei, falls statt des deklarierten Trigger-Topics nur das Runtime-JSON mit Feld wie `action` ankommt.
- Nutzt den Topic-Cache des Splitters fuer Initialwerte aus retained MQTT-Payloads.
- Uebernimmt bei `unknown` oder `unavailable` den letzten fachlich gueltigen Wert weiter, wertet benutzerdefinierte Bool-Payloads ueber `state_on`/`state_off` sowie `payload_on`/`payload_off` aus und markiert Bool-Mapping-Abweichungen zwischen Discovery und beobachtetem Runtime-Wert.
- Markiert bei Event-Triggern auch den Fall, dass nur der Zigbee2MQTT-Fallback greift (`disc topic fehlt; root-json fallback`).
- Kann auch manuell angelegt werden und laedt seine Entities dann ueber `DeviceID` selbst aus dem Discovery-Splitter.
- Persistiert keine eigene `DeviceConfig` mehr. Die Laufzeit arbeitet mit `DeviceID` und einer aufgeloesten Cache-Definition aus dem Splitter.

## Voraussetzungen

- Home Assistant MQTT Discovery Splitter als Parent.
- Der Splitter kann live an einem MQTT Client haengen oder im Entwicklungsbetrieb aus einem Discovery-Bundle gespeist werden.
- Im Live-Betrieb muss der Parent des Splitters ein MQTT Client sein, der den Discovery-Prefix abonniert, z. B. `homeassistant/#` oder `#`.
- Die relevanten State-Topics muessen im Live-Betrieb vom MQTT Client empfangen werden, typischerweise ueber `zigbee2mqtt/#` oder `#`.

## Migration

- Das Device persistiert keine eigene `DeviceConfig` mehr.
- Bestehende und neu erzeugte Instanzen arbeiten mit `DeviceID` plus aufgeloester Cache-Definition aus dem Splitter.
- Wenn ein Device nach dem Update keine Definition findet:
  1. Parent-Kette pruefen
  2. Splitter-Cache pruefen
  3. `MQTT-IO reconnecten` ausfuehren oder im Entwicklungsbetrieb Bundle-Modus samt passendem Bundle aktivieren

## Hinweis

Das Modul ist bewusst auf den aktuellen MQTT-Discovery-Kernpfad fuer `sensor`, `binary_sensor`, `switch`, `select`, `button` und einfache Zigbee2MQTT-Trigger begrenzt. Weitere Discovery-Komponenten koennen spaeter darauf aufbauen, ohne den bestehenden Home Assistant Runtime-Pfad zu vermischen.

Der Zigbee2MQTT-Fallback ist als Kompatibilitaetspfad gedacht, nicht als Discovery-Istzustand. Sobald das deklarierte Trigger-Topic selbst geliefert wird, verschwindet die Warnung automatisch und der regulaere Discovery-Pfad greift.
