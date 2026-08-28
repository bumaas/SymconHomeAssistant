[![Version](https://img.shields.io/badge/Symcon%20Version-9.0%20%3E-green.svg)](https://www.symcon.de/forum/threads/30857-IP-Symcon-5-1-%28Stable%29-Changelog)
[![Checks](https://github.com/bumaas/SymconHomeAssistant/actions/workflows/check.yml/badge.svg)](https://github.com/bumaas/SymconHomeAssistant/actions/workflows/check.yml)
# Home Assistant

Mit diesem Modul lassen sich Geräte, Entitäten und Dienste aus dem Home-Assistant-Umfeld komfortabel in Symcon nutzen.

Das Modul unterstützt dafür zwei klar getrennte Anwendungsfälle:

1. die klassische Bridge, um bestehende Elemente aus einer Home-Assistant-Installation nach Symcon zu übernehmen
2. MQTT Discovery, um kompatible Geräte und Dienste direkt per MQTT in Symcon einzubinden

So kann Symcon entweder mit einer vorhandenen Home-Assistant-Installation zusammenarbeiten oder Geräte und Dienste direkt über MQTT einbinden — auch ganz ohne Home-Assistant-Server. Beide Wege können parallel genutzt werden.

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
- Wenn der Symcon MQTT Server als Broker genutzt wird, bleibt für den Discovery-Pfad trotzdem ein MQTT Client erforderlich. Der MQTT-Client kann dabei direkt auf den lokalen MQTT Server zeigen, z. B. `127.0.0.1:1028`.
- ein Gerät oder Dienst, das bzw. der Home Assistant MQTT Discovery an den Broker meldet
- passende Subscription für die Discovery-Meldungen, typischerweise `homeassistant/#`
- zusätzlich die MQTT-Topics des Geräts oder Dienstes, damit aktuelle Werte ankommen, bei Zigbee2MQTT typischerweise `zigbee2mqtt/#`

## 4. Installation und Konfiguration

### 4.1 Klassische Bridge

1. MQTT Client oder MQTT Server in Symcon einrichten.
   **Wichtig:** Der Mosquitto-Broker in Home Assistant lehnt anonyme Verbindungen standardmäßig ab.
   Daher in Home Assistant einen Benutzer anlegen (Einstellungen → Personen → Benutzer, z. B. `mqtt-symcon`)
   und dessen Name und Passwort in der Symcon-MQTT-Client-Instanz eintragen — sonst wird die Verbindung
   nach dem TCP-Aufbau abgewiesen und die Instanz als fehlerhaft markiert.
   (Die `Home Assistant Discovery` legt die Instanzkette ohne Zugangsdaten an, weil sie diese nicht kennt —
   die Zugangsdaten müssen danach manuell im MQTT Client ergänzt werden.)
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
  publish_timestamps: false
```

> **`publish_timestamps` bewusst auf `false`:** Home Assistant publiziert damit zu jedem Wert zusätzlich `last_changed` und `last_updated`. Beide verwirft der Splitter ausnahmslos — Symcon führt die Zeitstempel seiner Variablen selbst. Auf `true` verdreifacht sich die Nachrichtenmenge ohne jeden Gewinn; auf schwächerer Hardware ist das der wirksamste Hebel gegen hohe Last (siehe [Hohe CPU-Last](#73-hohe-cpu-last-durch-die-nachrichtenmenge)).

### 4.2 MQTT Discovery

1. MQTT Client in Symcon einrichten.
   Wenn der Broker als Symcon MQTT Server läuft, kann der MQTT Client direkt auf diesen Server verbunden werden, z. B. per `127.0.0.1:1028`.
   Verlangt der Broker eine Anmeldung (z. B. Mosquitto in Home Assistant), Benutzername und Passwort in der MQTT-Client-Instanz eintragen.
2. Subscription so setzen, dass mindestens `homeassistant/#` empfangen wird. Sie gehört in die Liste `Subscriptions` der MQTT-Client-Instanz, nicht in die Module dieser Bibliothek.
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
MQTT Broker (z.B. Mosquitto)
  v
IP-Symcon MQTT Client
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
MQTT Broker (z.B. Mosquitto)
  v
IP-Symcon MQTT Client
  v
Home Assistant MQTT Discovery Splitter
  |  bereitet die Daten für Symcon auf
  v
Home Assistant MQTT Discovery Configurator / Device
```

## 7. Fehlersuche

> **Selbsttest im Modul:** Sowohl der `Home Assistant Splitter` (klassische Bridge) als auch der `Home Assistant MQTT Discovery Splitter` bieten in der Konfiguration den Knopf **„Selbsttest ausführen"**. Er prüft die häufigsten Fehlerquellen (Parent aktiv/Typ, REST/Token, `MQTTBaseTopic` bzw. Discovery-Prefix, MQTT-Zugangsdaten, ankommende MQTT-Daten, Subscription, Discovery-Cache) und zeigt das Ergebnis als Checkliste mit konkreten Tipps. Das ist der schnellste erste Schritt bei Problemen.

> **Diagnosewerkzeug MQTT Explorer:** Für die Analyse des MQTT-Verkehrs empfiehlt sich der kostenlose [MQTT Explorer](https://mqtt-explorer.com/). Er verbindet sich mit demselben Broker wie Symcon und zeigt live alle Topics samt Werten als Baum an. Damit lässt sich prüfen, ob Topics wie `<MQTTBaseTopic>/switch/<entity>/state` (klassische Bridge) bzw. `homeassistant/.../config` (MQTT Discovery) überhaupt ankommen und welche Werte sie tragen.

### 7.1 Klassische Bridge

- **Wenn der MQTT Client bzw. Client Socket als fehlerhaft markiert wird, obwohl IP und Port 1883 stimmen:** Fast immer fehlen die MQTT-Zugangsdaten. Der Mosquitto-Broker in Home Assistant lehnt anonyme Verbindungen standardmäßig ab — in Home Assistant einen Benutzer anlegen (Einstellungen → Personen → Benutzer) und dessen Name und Passwort in der Symcon-MQTT-Client-Instanz eintragen. Die `Home Assistant Discovery` legt die Instanzkette ohne Zugangsdaten an; sie müssen manuell ergänzt werden.
- Wenn im `Home Assistant Splitter` `Kein aktiver MQTT Parent gefunden` steht: Verbindung zum MQTT-Client oder MQTT-Server prüfen.
- **Wenn der angezeigte Status nicht mit Home Assistant übereinstimmt:** Zustände kommen ausschließlich über den `mqtt_statestream`, nicht über REST. Stimmt die Anzeige nicht, fehlen die aktuellen Statusdaten. `mqtt_statestream` in Home Assistant prüfen und sicherstellen, dass `base_topic` zu `MQTTBaseTopic` passt.
  - **MQTT Client als Parent** verwenden (nicht nur MQTT Server): Nur der Client erhält beim Verbinden den retained-Replay und damit sofort den echten Initialzustand. Subscription auf den Statestream-Baum setzen: `homeassistant/#` bzw. `<base_topic>/#` — nicht `#`, sonst läuft aller Broker-Verkehr durch den Splitter.
  - Im MQTT Explorer gegenprüfen, ob unter `<MQTTBaseTopic>/switch/<entity>/state` tatsächlich `on`/`off` liegt. Kommt nichts an, bleibt der zuletzt gesetzte bzw. der Default-Wert stehen.
- **Wenn ganze Domänen fehlen (z. B. Fenster/`binary_sensor` oder Steckdosen/`switch`), obwohl `sensor`-Werte ankommen:** Dann blendet in Home Assistant der `include`/`exclude`-Filter von `mqtt_statestream` diese Domänen aus. Prüfen, ob unter `mqtt_statestream` ein `include:`-Block nur bestimmte Domänen publiziert (z. B. nur `sensor`), und die fehlenden Domänen ergänzen oder den Filter entfernen; danach Home Assistant neu laden. Der Selbsttest-Button des Splitters zeigt unter „Empfangene Domänen" an, welche Domänen tatsächlich eintreffen.
- **Wenn sich Entitäten nicht schalten lassen:** Das Schalten läuft über REST (z. B. `switch.turn_on`/`turn_off`), nicht über MQTT. Voraussetzungen: gültige `HAUrl` und gültiges `HAToken` im Splitter.
- **Diagnosefelder nutzen:** Die Splitter-Konfiguration zeigt `REST-Fehler`, `REST-Antwort`, `REST-Timeout` und den Parent-Status. Dort steht, ob ein REST-Call durchgeht oder z. B. an Token, URL oder Erreichbarkeit scheitert.

### 7.2 MQTT Discovery

- Wenn Discovery-Geräte nicht auftauchen: prüfen, ob der MQTT-Client mindestens `homeassistant/#` empfängt.
- Wenn Discovery-Geräte angelegt werden, aber keine Werte bekommen: zusätzlich die Topics des Geräts oder Dienstes abonnieren, bei Zigbee2MQTT typischerweise `zigbee2mqtt/#`.
- Wenn bereits vorhandene Discovery-Meldungen nicht vollständig eingelesen wurden: im `Home Assistant MQTT Discovery Splitter` `MQTT-IO reconnecten` ausführen.
- Für Analyse und Support stehen im Discovery-Splitter zwei Exporte bereit:
  - `Discovery-Bundle herunterladen`
  - `Discovery-Bundle aktuelle Session herunterladen`

### 7.3 Hohe CPU-Last durch die Nachrichtenmenge

Die übrigen Abschnitte behandeln „es kommt nichts an" — hier geht es um den umgekehrten Fall: Alles funktioniert, aber die CPU-Last ist dauerhaft hoch und Symcon reagiert träge.

**Erkennungszeichen:** Die Last bleibt auch dann oben, wenn gar keine Geräte-Instanzen angelegt sind, und sie fällt nach dem Abschalten der Quelle erst mit einigen Minuten Verzögerung. Dieses Nachlaufen ist der entscheidende Hinweis: Es ist ein Rückstau — es kommt mehr herein, als der Rechner abarbeitet. Deshalb kippt so ein System nicht allmählich, sondern springt bei wenigen Prozent mehr Nachrichten von unauffällig auf Vollauslastung.

**Messen** statt raten: Im Splitter unter *Experte* `Topic-Statistik aktivieren` (Intervall z. B. 5 Minuten). Jedes Fenster landet als Zeile mit dem Präfix `Topic-Statistik` im Symcon-Log und nennt Nachrichten und Datenmenge je Gerät sowie die größte Einzelnachricht. Damit ist sofort sichtbar, welches Gerät die Last erzeugt — und ob es die Menge (viele kleine Nachrichten) oder einzelne große Payloads sind.

**Gegenmittel, in dieser Reihenfolge:**

1. **`publish_timestamps: false`** in der `mqtt_statestream`-Konfiguration. Der größte Hebel: Die Topics `last_changed`/`last_updated` verwirft der Splitter ohnehin, sie machen aber zwei Drittel der Nachrichten aus.
2. **`include`/`exclude`** in `mqtt_statestream`: nur die Entitäten publizieren, die in Symcon wirklich gebraucht werden. Sekundengenaue Diagnosewerte von Wechselrichtern, Wallboxen oder Wärmepumpen sind hier meist der Hauptposten.
3. **Abonnement des MQTT Clients eingrenzen** auf `<base_topic>/#`. Steht dort `#`, reicht der Client den gesamten Broker-Verkehr an den Splitter weiter — auch Topics fremder Geräte. Tauchen in der Topic-Statistik Namen auf, die gar nicht aus Home Assistant stammen, ist das die Ursache. (Seit 1.4 build 150 sortiert der Splitter solche Topics zusätzlich selbst aus.)
   **Wo:** nicht im HA-Modul, sondern in der **MQTT-Client-Instanz**, mit der der Splitter verbunden ist — dort den Filter in die Liste `Subscriptions` eintragen. Welche Instanz das ist, steht in der Splitter-Konfiguration im Abschnitt **Diagnose** (zugeklappt, erst aufklappen) in der Zeile `MQTT-Parent`, mitsamt Instanz-ID und Namen. Direkt dorthin gelangt man auch über das Zahnrad im Konfigurationsreiter der Splitter-Instanz.
4. **Diagnose-Schalter wieder abschalten:** `Topic-Statistik` und `Performance-Timing` kosten selbst Rechenzeit pro Nachricht und gehören nach der Messung aus.

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

Der MQTT-Discovery-Pfad baut seinen Discovery-Cache aus den retained `homeassistant/.../config` Topics auf. Dafür braucht der Splitter einen abonnierenden MQTT Client als Parent, der die Discovery- und Runtime-Topics aktiv vom Broker empfängt und bei einem Reconnect erneut als retained Replay bekommt. Genau darauf basiert auch die Funktion `MQTT-IO reconnecten`.

Der Symcon MQTT Server kann dabei weiterhin der Broker sein. Für den Discovery-Pfad wird dann zusätzlich ein MQTT Client verwendet, dessen IO direkt auf den lokalen MQTT Server zeigen kann, z. B. `127.0.0.1:1028`.

### Spenden

Die Nutzung des Moduls ist kostenfrei. Niemand sollte sich verpflichtet fühlen, aber wenn das Modul gefällt, freue ich mich über eine Spende.

<a href="https://www.paypal.me/bumaas" target="_blank"><img src="https://www.paypalobjects.com/de_DE/DE/i/btn/btn_donate_LG.gif" border="0" /></a>
