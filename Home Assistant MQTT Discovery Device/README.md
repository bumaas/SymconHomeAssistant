[![Version](https://img.shields.io/badge/Symcon%20Version-9.0%20%3E-green.svg)](https://www.symcon.de/forum/threads/30857-IP-Symcon-5-1-%28Stable%29-Changelog)
# Home Assistant MQTT Discovery Device

Laufzeitmodul für Geräte, die ihre Struktur und Laufzeitdaten über Home Assistant MQTT Discovery bereitstellen. Eine bestehende Home-Assistant-Installation ist dafür nicht erforderlich.

> Teil des Projekts **Home Assistant für IP-Symcon** — [Gesamtübersicht, Betriebsarten und Fehlersuche](../README.md).

## Funktionsumfang

- Arbeitet direkt auf MQTT-Discovery-Daten einer MQTT-Discovery-Quelle, nicht auf aus Home Assistant geholten Entitäten.
- Empfängt MQTT-Nachrichten über den Home Assistant MQTT Discovery Splitter.
- Wertet `state_topic`, `command_topic` und `availability` quellenneutral für MQTT Discovery aus.
- Unterstützt aktuell die Komponenten `sensor`, `binary_sensor`, `number`, `image`, `device_tracker`, `update`, `lock`, `cover`, `climate`, `switch`, `select`, `button` und `light`.
- Kann diese Komponenten sowohl aus klassischen Discovery-Topics `homeassistant/<component>/.../config` als auch aus HA-Device-Discovery `homeassistant/device/.../config` auflösen.
- Stellt Zigbee2MQTT-`device_automation` Trigger als read-only Event-Zeitstempel pro Trigger-Subtype dar.
- Behält für Zigbee2MQTT-Trigger einen Root-Topic-JSON-Fallback bei, falls statt des deklarierten Trigger-Topics nur das Runtime-JSON mit Feld wie `action` ankommt.
- Nutzt den Topic-Cache des Splitters für Initialwerte aus retained MQTT-Payloads.
- Übernimmt bei `unknown` oder `unavailable` den letzten fachlich gültigen Wert weiter, wertet benutzerdefinierte Bool-Payloads über `state_on`/`state_off` sowie `payload_on`/`payload_off` aus und markiert Bool-Mapping-Abweichungen zwischen Discovery und beobachtetem Runtime-Wert.
- Unterstützt für `light` den JSON-Schema-v1-Write-Pfad über `command_topic`, wertet den `state`-Schlüssel aus Runtime-JSONs aus und übernimmt Metadaten wie `supported_color_modes`, `brightness_scale` und `effect_list` in Diagnose und Laufzeitmodell.
- Legt für Discovery-`light` zusätzliche Laufzeitvariablen für erkannte Lichtattribute wie `brightness`, `color_mode`, `color_temp`, `xy_color`, `hs_color` oder `effect` an und aktualisiert sie aus State- beziehungsweise `json_attributes_topic`-Payloads.
- Schreibt derzeit konservativ nur generisch ableitbare Light-Attribute direkt über `command_topic`, insbesondere `brightness`, `color_temp`, `color_temp_kelvin`, `effect`, `flash` und `transition`; komplexe Farb-Payloads wie `xy_color` oder `rgb_color` bleiben vorerst read-only.
- Unterstützt für `number` numerische Hauptvariablen inklusive Typableitung (`Integer`/`Float`), Slider-Präsentation aus `min`/`max`/`step` und MQTT-Schreibpfade über `command_topic` beziehungsweise einfache `command_template`.
- Unterstützt für `cover` den MQTT-Discovery-Positionspfad über `position_topic` und `set_position_topic`, leitet daraus automatisch Slider- beziehungsweise Shutter-Präsentationen ab und fällt ohne Positionsdaten auf textuelle Statuswerte zurück.
- Legt für Discovery-`cover` zusätzliche Aktionsvariablen für `open`/`close`/`stop` und bei vorhandenem `tilt_command_topic` auch für `open_tilt`/`close_tilt`/`stop_tilt` an.
- Unterstützt für `climate` den Zieltemperatur-Pfad über `temperature_state_topic` und `temperature_command_topic`, bildet daraus eine Temperatur-Slider-Hauptvariable und übernimmt `min_temp`, `max_temp`, `temp_step` sowie `temperature_unit` in die Präsentation.
- Kann auch manuell angelegt werden und lädt seine Entities dann über `DeviceID` selbst aus dem Discovery-Splitter.
- Persistiert keine eigene `DeviceConfig` mehr. Die Laufzeit arbeitet mit `DeviceID` und einer aufgelösten Cache-Definition aus dem Splitter.
- Die `Entity Selection` im Formular erlaubt es, einzelne Discovery-Entities gezielt zu aktivieren oder zu deaktivieren.

## Voraussetzungen

- Home Assistant MQTT Discovery Splitter als Parent.
- Ein Gerät oder Dienst muss Home Assistant MQTT Discovery und die dazugehörigen Runtime-Topics an den Broker publizieren.
- Der Splitter kann live an einem MQTT Client hängen oder im Entwicklungsbetrieb aus einem Discovery-Bundle gespeist werden.
- Im Live-Betrieb muss der Parent des Splitters ein MQTT Client sein, der den Discovery-Prefix abonniert, zum Beispiel `homeassistant/#` oder `#`.
- Die relevanten State-Topics müssen im Live-Betrieb vom MQTT Client empfangen werden, typischerweise über `zigbee2mqtt/#` oder `#`.

## Migration

- Das Device persistiert keine eigene `DeviceConfig` mehr.
- Bestehende und neu erzeugte Instanzen arbeiten mit `DeviceID` plus aufgelöster Cache-Definition aus dem Splitter.
- Wenn ein Device nach dem Update keine Definition findet:
  1. Parent-Kette prüfen
  2. Splitter-Cache prüfen
  3. `MQTT-IO reconnecten` ausführen oder im Entwicklungsbetrieb Bundle-Modus samt passendem Bundle aktivieren

## Nicht mehr angekündigte Geräte

- Kennt der Splitter das Gerät nicht, arbeitet die Instanz mit der zuletzt gespeicherten Definition weiter (Variablen bleiben, nach einem Neustart von Broker oder Splitter treffen die Ankündigungen erst nach und nach ein).
- Läuft die MQTT-Sitzung des Splitters seit mindestens 10 Minuten und fehlt das Gerät weiterhin, geht die Instanz auf Status **202** und schreibt einmal eine **Warnung** ins Meldungsprotokoll („Nicht angekündigt: Das Gerät wird seit mindestens 10 Minuten nicht per MQTT Discovery angekündigt …"). Sie prüft alle 10 Minuten erneut; kündigt sich das Gerät wieder an, geht sie auf aktiv zurück und meldet das (seit 1.6). Die Warnung kommt je Ausfall nur einmal, auch über einen Neustart von Symcon oder ein Modul-Update hinweg.
- Ein dauerhaft nicht angekündigtes Gerät gibt es meist nicht mehr oder es hat MQTT Discovery abgeschaltet — Gerät prüfen oder die Instanz löschen.
- Steht der Splitter im Bundle-Modus (Testbetrieb mit einer gespeicherten Momentaufnahme), entfällt diese Prüfung: Eine Momentaufnahme sagt nichts darüber, welche Geräte heute angekündigt werden.

## Erreichbarkeit

- Meldet ein Gerät seine Erreichbarkeit selbst (availability-Topic in der Discovery-Ankündigung, bei Zigbee2MQTT z. B. `{"state":"offline"}`), legt die Instanz die Variable **„Erreichbar"** an (seit 1.6).
- Sie steht auf **nicht erreichbar**, wenn alle Entitäten mit bekanntem Stand offline melden; ein noch unbekannter Stand gilt nicht als Ausfall. Eine eigene Wartezeit gibt es nicht — die Bridge urteilt mit ihrer eigenen Frist.
- Jeder Wechsel steht im Meldungsprotokoll: der Ausfall als **Warnung**, die Erholung als **Meldung**. Der Instanzstatus bleibt aktiv.
- Geräte ohne availability-Topic bekommen keine solche Variable.
- Ein nicht mehr angekündigtes Gerät (Status 202, siehe oben) gilt auch als nicht erreichbar. Dieser Wechsel steht nicht zusätzlich im Meldungsprotokoll — die Warnung „Nicht angekündigt" sagt es schon. Kündigt es sich wieder an, zählt wieder seine eigene Meldung.

## Hinweis

Das Modul ist bewusst auf den aktuellen MQTT-Discovery-Kernpfad für `sensor`, `binary_sensor`, `number`, `image`, `device_tracker`, `update`, `lock`, `cover`, `climate`, `switch`, `select`, `button`, `light` und einfache Zigbee2MQTT-Trigger begrenzt. Weitere Discovery-Komponenten können später darauf aufbauen, ohne den klassischen Bridge-Pfad für bestehende Home-Assistant-Installationen zu vermischen.

Der Zigbee2MQTT-Fallback ist als Kompatibilitätspfad gedacht, nicht als Discovery-Istzustand. Sobald das deklarierte Trigger-Topic selbst geliefert wird, verschwindet die Warnung automatisch und der reguläre Discovery-Pfad greift.
