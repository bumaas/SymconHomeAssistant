[![Version](https://img.shields.io/badge/Symcon%20Version-8.2%20%3E-green.svg)](https://www.symcon.de/forum/threads/30857-IP-Symcon-5-1-%28Stable%29-Changelog)
# Home Assistant MQTT Discovery Device

Laufzeitmodul für Geräte, die ihre Struktur und Laufzeitdaten über Home Assistant MQTT Discovery bereitstellen. Eine bestehende Home-Assistant-Installation ist dafür nicht erforderlich.

## Funktionsumfang

- Arbeitet direkt auf MQTT-Discovery-Daten einer MQTT-Discovery-Quelle, nicht auf aus Home Assistant geholten Entitaeten.
- Empfängt MQTT-Nachrichten über den Home Assistant MQTT Discovery Splitter.
- Wertet `state_topic`, `command_topic` und `availability` quellenneutral für MQTT-Discovery aus.
- Unterstützt aktuell die Komponenten `sensor`, `binary_sensor`, `number`, `cover`, `climate`, `switch`, `select`, `button` und `light`.
- Kann diese Komponenten sowohl aus klassischen Discovery-Topics `homeassistant/<component>/.../config` als auch aus HA-Device-Discovery `homeassistant/device/.../config` aufloesen.
- Stellt Zigbee2MQTT-`device_automation` Trigger als read-only Event-Zeitstempel pro Trigger-Subtype dar.
- Behält für Zigbee2MQTT-Trigger einen Root-Topic-JSON-Fallback bei, falls statt des deklarierten Trigger-Topics nur das Runtime-JSON mit Feld wie `action` ankommt.
- Nutzt den Topic-Cache des Splitters für Initialwerte aus retained MQTT-Payloads.
- Übernimmt bei `unknown` oder `unavailable` den letzten fachlich gültigen Wert weiter, wertet benutzerdefinierte Bool-Payloads über `state_on`/`state_off` sowie `payload_on`/`payload_off` aus und markiert Bool-Mapping-Abweichungen zwischen Discovery und beobachtetem Runtime-Wert.
- Unterstützt für `light` den JSON-Schema-v1-Write-Pfad über `command_topic`, wertet den `state`-Schluessel aus Runtime-JSONs aus und übernimmt Metadaten wie `supported_color_modes`, `brightness_scale` und `effect_list` in Diagnose und Laufzeitmodell.
- Legt für Discovery-`light` zusätzliche Laufzeitvariablen für erkannte Lichtattribute wie `brightness`, `color_mode`, `color_temp`, `xy_color`, `hs_color` oder `effect` an und aktualisiert sie aus State- beziehungsweise `json_attributes_topic`-Payloads.
- Schreibt derzeit konservativ nur generisch ableitbare Light-Attribute direkt über `command_topic`, insbesondere `brightness`, `color_temp`, `color_temp_kelvin`, `effect`, `flash` und `transition`; komplexe Farb-Payloads wie `xy_color` oder `rgb_color` bleiben vorerst read-only.
- Unterstützt für `number` numerische Hauptvariablen inklusive Typableitung (`Integer`/`Float`), Slider-Präsentation aus `min`/`max`/`step` und MQTT-Schreibpfade über `command_topic` beziehungsweise einfache `command_template`.
- Unterstützt für `cover` den MQTT-Discovery-Positionspfad über `position_topic` und `set_position_topic`, leitet daraus automatisch Slider- beziehungsweise Shutter-Präsentationen ab und fällt ohne Positionsdaten auf textuelle Statuswerte zurück.
- Legt für Discovery-`cover` zusätzliche Aktionsvariablen für `open`/`close`/`stop` und bei vorhandenem `tilt_command_topic` auch für `open_tilt`/`close_tilt`/`stop_tilt` an.
- Unterstützt für `climate` den Zieltemperatur-Pfad über `temperature_state_topic` und `temperature_command_topic`, bildet daraus eine Temperatur-Slider-Hauptvariable und übernimmt `min_temp`, `max_temp`, `temp_step` sowie `temperature_unit` in die Präsentation.
- Markiert bei Event-Triggern auch den Fall, dass nur der Zigbee2MQTT-Fallback greift (`disc topic fehlt; root-json fallback`).
- Kann auch manuell angelegt werden und lädt seine Entities dann über `DeviceID` selbst aus dem Discovery-Splitter.
- Persistiert keine eigene `DeviceConfig` mehr. Die Laufzeit arbeitet mit `DeviceID` und einer aufgelösten Cache-Definition aus dem Splitter.

## Voraussetzungen

- Home Assistant MQTT Discovery Splitter als Parent.
- Ein Gerät oder Dienst muss Home Assistant MQTT Discovery und die dazugehörigen Runtime-Topics an den Broker publizieren.
- Der Splitter kann live an einem MQTT Client haengen oder im Entwicklungsbetrieb aus einem Discovery-Bundle gespeist werden.
- Im Live-Betrieb muss der Parent des Splitters ein MQTT Client sein, der den Discovery-Prefix abonniert, z. B. `homeassistant/#` oder `#`.
- Die relevanten State-Topics müssen im Live-Betrieb vom MQTT Client empfangen werden, typischerweise über `zigbee2mqtt/#` oder `#`.

## Migration

- Das Device persistiert keine eigene `DeviceConfig` mehr.
- Bestehende und neu erzeugte Instanzen arbeiten mit `DeviceID` plus aufgelöster Cache-Definition aus dem Splitter.
- Wenn ein Device nach dem Update keine Definition findet:
  1. Parent-Kette prüfen
  2. Splitter-Cache prüfen
  3. `MQTT-IO reconnecten` ausführen oder im Entwicklungsbetrieb Bundle-Modus samt passendem Bundle aktivieren

## Hinweis

Das Modul ist bewusst auf den aktuellen MQTT-Discovery-Kernpfad für `sensor`, `binary_sensor`, `number`, `cover`, `climate`, `switch`, `select`, `button`, `light` und einfache Zigbee2MQTT-Trigger begrenzt. Weitere Discovery-Komponenten können später darauf aufbauen, ohne den klassischen Bridge-Pfad für bestehende Home-Assistant-Installationen zu vermischen.

Der Zigbee2MQTT-Fallback ist als Kompatibilitätspfad gedacht, nicht als Discovery-Istzustand. Sobald das deklarierte Trigger-Topic selbst geliefert wird, verschwindet die Warnung automatisch und der reguläre Discovery-Pfad greift.
