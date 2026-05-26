# Migration

Diese Notiz beschreibt die relevanten Umstellungen für bestehende Installationen.
Sie ergänzt die README-Texte um die Punkte, die bei einem Update aktiv geprüft werden sollten.

## 1. MQTT Discovery Device arbeitet `DeviceID`-only

- `Home Assistant MQTT Discovery Device` speichert keine eigene `DeviceConfig` mehr.
- Die Laufzeit löst Metadaten und Entity-Definition ausschließlich über `DeviceID` aus dem `Home Assistant MQTT Discovery Splitter` auf.
- Der MQTT Discovery Configurator erzeugt Discovery-Devices daher nur noch mit:
  - `DeviceID`
  - Symcon-Instanzname

Prüfen nach dem Update:

1. Discovery-Device hat einen aktiven und kompatiblen MQTT Discovery Splitter als Parent.
2. Der Splitter hat die passende Discovery-Definition bereits im Cache.
3. Falls die Definition fehlt: `MQTT-IO reconnecten` im Splitter ausführen oder im Entwicklungsbetrieb den Bundle-Modus nutzen.

Wichtig:

- Leere oder ungültige `DeviceID` löschen bestehende Variablen nicht mehr implizit.
- Anzeigen wie `Name`, `Manufacturer` und `Model` sind Formular-Metadaten; die fachliche Laufzeit kommt aus der aufgelösten Cache-Definition.

## 2. MQTT Discovery Splitter hat neue Bundle-Properties

Der `Home Assistant MQTT Discovery Splitter` kennt zusätzlich folgende Properties:

- `SourceMode = mqtt | bundle`
- `BundlePath`
- `BundleCurrentSessionOnly`
- `ReplayTopicsOnApply`

Verhalten:

- Standard bleibt `SourceMode = mqtt`.
- `bundle` ist für Entwicklung, Support und reproduzierbare Analyse gedacht.
- Im Bundle-Modus wird kein MQTT-Parent benötigt.
- Ausgehende Commands werden im Bundle-Modus aktuell verworfen und nicht simuliert.

Prüfen nach dem Update:

1. Live-Betrieb bleibt auf `SourceMode = mqtt`.
2. `MQTTDiscoveryPrefix` bleibt der literale Discovery-Prefix, typischerweise `homeassistant`, nicht `#`.
3. Falls Bundle-Modus aktiv genutzt wird: Bundle-Datei muss zum aktuellen Exportformat `V2` passen.

## 3. Klassischer Configurator reduziert den `create`-Block auf stabile Struktur

Der klassische `Home Assistant Configurator` führt im `create`-Block nur noch stabile Strukturattribute.
Flüchtige Live-Werte und Prognosedaten werden dort bewusst nicht mehr gespiegelt.

Ziel:

- `Als gelesen markiert` soll nicht durch volatile Attributänderungen erneut neue Configurator-Einträge erzeugen.
- Klassische Device-Instanzen sollen im `create`-Block nur noch mit ihrer stabilen `DeviceID` angelegt werden.

Auswirkung:

- Bestehende Device- und Entity-Instanzen bleiben gültig.
- Configurator-Zeilen können nach dem Update anders gruppiert oder stabiler wiedererkannt werden als in älteren Ständen.

## 4. Typische MQTT-Subscriptions

Klassischer Runtime-Pfad:

- MQTT Client oder MQTT Server muss den Statestream empfangen.
- Typisch: `homeassistant/#`

MQTT-Discovery-Pfad:

- Derselbe MQTT Client am MQTT Discovery Splitter muss sowohl Discovery-Topics als auch die Laufzeit-Topics der Quelle empfangen.
- Minimal für Discovery: `homeassistant/#`
- Typisch für Zigbee2MQTT zusätzlich: `zigbee2mqtt/#`

Grundregel:

- Wildcards wie `#` oder `+` gehören in die Subscription des MQTT-Clients.
- Properties wie `MQTTBaseTopic` oder `MQTTDiscoveryPrefix` enthalten dagegen den literalen Prefix ohne Wildcards.
