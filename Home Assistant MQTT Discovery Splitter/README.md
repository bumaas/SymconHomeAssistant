[![Version](https://img.shields.io/badge/Symcon%20Version-9.0%20%3E-green.svg)](https://www.symcon.de/forum/threads/30857-IP-Symcon-5-1-%28Stable%29-Changelog)
# Home Assistant MQTT Discovery Splitter

Transportknoten für Geräte oder Dienste, die Home Assistant MQTT Discovery per MQTT publizieren. Eine bestehende Home-Assistant-Installation ist für diesen Pfad nicht erforderlich.

## Funktionsumfang

- Nimmt MQTT-Discovery direkt von einer MQTT-Discovery-Quelle entgegen, nicht aus dem klassischen Home-Assistant-Bridge-Pfad.
- Empfängt MQTT-Daten von einem MQTT Client.
- Cacht `homeassistant/.../config` Topics für spätere Discovery-Auswertung.
- Cacht zusätzlich die letzten MQTT-Payloads je referenziertem Runtime-Topic für Initialwerte und Diagnosezwecke.
- Berücksichtigt bei Zigbee2MQTT-`device_automation` sowohl das deklarierte Trigger-Topic als auch den bekannten Root-Topic-JSON-Fallback für Diagnose und Bundle-Export.
- Trennt Discovery-Cache und Runtime-Topic-Cache sauber, damit `.../config` Topics nicht doppelt im Runtime-Cache landen.
- Markiert Cache-Einträge je MQTT-Session, damit nach Reconnect zwischen aktuellem Replay und altem Cache-Stand unterschieden werden kann.
- Reicht MQTT-Nachrichten an Child-Instanzen weiter.
- Stellt den Discovery-Cache per internem Parent-Request zur Verfügung.
- Kann ein Discovery-Bundle für Fremd-Producer exportieren, bestehend aus gecachten Discovery-Configs und referenzierten MQTT-Topic-Payloads.

## Voraussetzungen

- Im Live-Betrieb: MQTT Client Instanz als Parent.
- Wenn der Symcon MQTT Server als Broker genutzt wird, wird fuer den Discovery-Pfad trotzdem ein MQTT Client benoetigt. Dessen IO kann direkt auf den lokalen MQTT Server zeigen, z. B. `127.0.0.1:1028`.
- Im Bundle-Modus: kein MQTT-Parent erforderlich.
- Eine bestehende Home-Assistant-Installation ist nicht erforderlich.
- Der MQTT Client muss im Live-Betrieb den Discovery-Prefix abonnieren, z. B. `homeassistant/#` oder `#`.
- Für Discovery-Device-Runtime müssen über denselben MQTT Client auch die State-Topics der Quelle ankommen, bei Zigbee2MQTT typischerweise `zigbee2mqtt/#`.

## Typische Subscription

- Discovery selbst: `homeassistant/#`
- Discovery plus Zigbee2MQTT-Runtime: `homeassistant/#` und `zigbee2mqtt/#`
- Ein breiteres `#` funktioniert ebenfalls, ist aber nur dann sinnvoll, wenn der MQTT-Client bewusst für weitere Topics genutzt wird.
- `MQTTDiscoveryPrefix` selbst bleibt der literale Prefix ohne Wildcards, typischerweise `homeassistant`.

## Konfiguration

- `SourceMode`: `mqtt` für Live-Betrieb mit MQTT-Parent oder `bundle` für Entwicklung gegen ein exportiertes Discovery-Bundle.
- `MQTTDiscoveryPrefix`: Prefix für MQTT-Discovery-Konfigurationen, typischerweise `homeassistant`.
- `MQTTDiscoveryPrefix` ist kein MQTT-Filter mit Wildcards, sondern der literale Prefix der Discovery-Topics. Gültig ist daher typischerweise `homeassistant`, nicht `#`.
- Wildcards wie `#` oder `+` gehören nur in die Subscription des MQTT-Clients, z. B. `homeassistant/#` oder `#`.
- Wenn der Broker lokal als Symcon MQTT Server laeuft, kann der MQTT Client direkt auf diesen Broker verbunden werden, z. B. Host `127.0.0.1` und Port `1028`.
- `BundlePath`: Dateiname oder absoluter Pfad zum Discovery-Bundle. Relative Angaben werden gegen `<modulpath>/tests/fixtures` aufgelöst.
- `BundleCurrentSessionOnly`: Lädt aus dem Bundle nur Discovery-Configs und Topic-Payloads der exportierten Session.
- `ReplayTopicsOnApply`: Replayed im Bundle-Modus nach `ApplyChanges()` alle gecachten Runtime-Topics einmal an die Child-Instanzen.
- Optional: `EnableExpertDebug`.

## Bundle-Modus

- Der Bundle-Modus ist für Entwicklung und Analyse gedacht und benötigt keinen MQTT-Parent.
- Der Import erwartet das aktuelle Discovery-Bundle-Format `V2`.
- Im Bundle-Modus werden Discovery-Configs und gecachte Topic-Payloads aus der Datei in die normalen Splitter-Caches geladen.
- `BundleCurrentSessionOnly` ist sinnvoll, wenn nur der frische Stand eines Session-Exports simuliert werden soll.
- `ReplayTopicsOnApply` ist sinnvoll, wenn Discovery-Devices ihren Receive-Pfad direkt nach dem Laden noch einmal durchlaufen sollen.
- Der Button `Bundle-Topics replayen` stößt diesen Replay-Schritt manuell an.
- Ausgehende Commands werden im Bundle-Modus aktuell nicht simuliert, sondern nur verworfen.

## Migration

- Bestehende Live-Installationen bleiben auf `SourceMode = mqtt`.
- `SourceMode = bundle` ist kein Ersatz für den regulären MQTT-Betrieb, sondern ein Entwicklungs- und Supportwerkzeug.
- Nach einem Update sollte geprüft werden, ob:
  - `MQTTDiscoveryPrefix` noch als literaler Prefix gesetzt ist
  - der MQTT Client neben Discovery auch die benötigten Runtime-Topics der Quelle empfängt
  - Bundle-Dateien nur bewusst und mit aktuellem V2-Format aktiviert werden

## Export

- Im Formular steht ein Button `MQTT-IO reconnecten` zur Verfügung.
- Im Formular steht ein Button `Discovery-Bundle herunterladen` zur Verfügung.
- Zusätzlich steht ein Button `Discovery-Bundle aktuelle Session herunterladen` zur Verfügung.
- `MQTT-IO reconnecten` schließt den IO des MQTT-Clients kurz und öffnet ihn wieder. Damit wird ein echter MQTT-Reconnect inklusive retained Replay angestoßen.
- Das Bundle enthält alle gecachten `homeassistant/.../config` Topics sowie die dazu in den Configs referenzierten MQTT-Topics, sofern dafür Payloads im Cache vorhanden sind.
- Der Session-Export beschränkt Discovery-Configs und exportierte Topic-Payloads auf Records der aktuellen MQTT-Session. Damit lassen sich stale Cache-Einträge für frische Fixture-Exporte gezielt ausblenden, ohne den Cache zu leeren.
- Zusätzlich exportiert das Bundle Session-Informationen, Freshness (`is_current_session`) und eine normalisierte Liste referenzierter Runtime-Topics mit `status`, `primary_kind` und `kinds`.
- Das Exportformat kombiniert rohe Discovery-Configs mit einer normalisierten Runtime-Topic-Diagnostik, damit Producer-Unterschiede nachvollziehbar bleiben und Support-/Fixture-Auswertungen weniger Redundanz pflegen müssen.

## Session-Verhalten

- Eine neue MQTT-Session beginnt, wenn der Splitter eine aktive Verbindung zu seinem MQTT-Parent feststellt und dabei einen neuen Connect/Reconnect verarbeitet.
- Der Zeitpunkt wird im Formular über `MQTT Session: ...` angezeigt.
- Ein manueller Reconnect über `MQTT-IO reconnecten` startet damit praktisch eine neue Session.
- Alte Cache-Einträge bleiben erhalten, werden aber für Diagnose und Session-Export als nicht aktuell markiert.

## Bundle ziehen

1. Sicherstellen, dass der Parent verbunden ist und der Splitter Discovery-Daten empfängt.
2. Wenn Discovery-Topics trotz bestehender Broker-Verbindung nicht vollständig auftauchen: `MQTT-IO reconnecten` ausführen.
3. Kurz warten, bis retained Discovery-Topics erneut eingelaufen sind und die Zähler im Formular plausibel aussehen.
4. Danach den passenden Export ziehen:
   - `Discovery-Bundle herunterladen`: kompletter Cache, inklusive aelterer Sessions.
   - `Discovery-Bundle aktuelle Session herunterladen`: nur die aktuell per Reconnect oder Verbindungsaufbau gesehene Session.

Empfohlene Verwendung:

- Gesamt-Bundle für Bestandsaufnahme, Cache-Analyse und Fälle, in denen auch stale Einträge relevant sind.
- Session-Bundle für Support, Fixtures und reproduzierbare Einzelanalysen nach einem frischen Reconnect.

## Bundle-Modus aktivieren

1. Bundle-Datei auf dem Symcon-Server ablegen (z. B. `/var/lib/symcon/ha_mqtt_discovery_bundle.json`).
2. Im Formular unter `Datenquelle` auf `Bundle file` umstellen.
3. Im nun sichtbaren Feld `Bundle-Dateipfad` den absoluten Pfad oder Dateinamen eintragen. Relative Angaben werden gegen `<modulpfad>/tests/fixtures` aufgelöst.
4. Optional: `Nur Datensätze der aktuellen Session` und `Replay bei ApplyChanges` nach Bedarf setzen.
5. `Übernehmen` — der Splitter lädt die Discovery-Configs und Topic-Payloads aus der Datei. Ein MQTT-Parent ist nicht erforderlich.

## Bundle-Modus deaktivieren

1. Im Formular unter `Datenquelle` auf `Home Assistant (MQTT)` zurückstellen.
2. `Übernehmen` — der Splitter empfängt Discovery-Daten wieder live vom MQTT-Parent.

## Diagnose

- Das Formular zeigt getrennt an, wie viele Discovery-Configs in der aktuellen MQTT-Session gesehen wurden und wie viele nur noch als stale Cache vorliegen.
- Für referenzierte Runtime-Topics werden aktuelle, stale und fehlende Topics getrennt ausgewiesen.
- Leere Payloads entfernen den jeweiligen Cache-Eintrag. Bei Discovery-Configs entspricht das dem üblichen MQTT-Delete über leere retained Config-Payloads.

## Hinweis

Dieser Splitter ist bewusst vom bestehenden Home Assistant Splitter getrennt. Er verwaltet keine REST-Verbindung und keinen `mqtt_statestream`, sondern arbeitet direkt mit MQTT-Discovery-Payloads und deren Runtime-Topics. Für einen vollständigen Discovery-Cache wird ein MQTT Client als Parent benötigt, damit die retained `homeassistant/.../config` Topics sauber replayed werden. Der entscheidende Punkt ist also nicht "Client statt Broker", sondern der abonnierende Client mit Reconnect- und Replay-Verhalten. Wenn der Broker als Symcon MQTT Server läuft, kann der benötigte MQTT Client direkt auf diesen lokalen Broker zeigen. Wenn bereits lange eine bestehende Broker-Verbindung läuft, kann ein manueller IO-Reconnect nötig sein, damit alle retained Discovery-Topics erneut eingelesen werden.
