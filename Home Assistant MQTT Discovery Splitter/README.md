[![Version](https://img.shields.io/badge/Symcon%20Version-8.2%20%3E-green.svg)](https://www.symcon.de/forum/threads/30857-IP-Symcon-5-1-%28Stable%29-Changelog)
# Home Assistant MQTT Discovery Splitter

Verbindet MQTT-Discovery-Topics mit Discovery-Configurator- und spaeteren Discovery-Device-Instanzen.

## Funktionsumfang

- Empfaengt MQTT-Daten von einem MQTT Client.
- Cacht `homeassistant/.../config` Topics fuer spaetere Discovery-Auswertung.
- Cacht zusaetzlich die letzten MQTT-Payloads je referenziertem Runtime-Topic fuer Initialwerte und Diagnosezwecke.
- Beruecksichtigt bei Zigbee2MQTT-`device_automation` sowohl das deklarierte Trigger-Topic als auch den bekannten Root-Topic-JSON-Fallback fuer Diagnose und Bundle-Export.
- Trennt Discovery-Cache und Runtime-Topic-Cache sauber, damit `.../config` Topics nicht doppelt im Runtime-Cache landen.
- Markiert Cache-Eintraege je MQTT-Session, damit nach Reconnect zwischen aktuellem Replay und altem Cache-Stand unterschieden werden kann.
- Reicht MQTT-Nachrichten an Child-Instanzen weiter.
- Stellt den Discovery-Cache per internem Parent-Request zur Verfuegung.
- Kann ein Discovery-Bundle fuer Fremd-Producer exportieren, bestehend aus gecachten Discovery-Configs und referenzierten MQTT-Topic-Payloads.

## Voraussetzungen

- MQTT Client Instanz als Parent.
- Der MQTT Client muss den Discovery-Prefix abonnieren, typischerweise `homeassistant/#`.
- Fuer Discovery-Device-Runtime muessen ueber denselben MQTT Client auch die State-Topics der Quelle ankommen, bei Zigbee2MQTT typischerweise `zigbee2mqtt/#`.

## Konfiguration

- `MQTTDiscoveryPrefix`: Prefix fuer MQTT-Discovery-Konfigurationen, typischerweise `homeassistant`.
- Optional: `EnableExpertDebug`.

## Export

- Im Formular steht ein Button `MQTT-Parent reconnecten` zur Verfuegung.
- Im Formular steht ein Button `Discovery-Bundle herunterladen` zur Verfuegung.
- Zusaetzlich steht ein Button `Discovery-Bundle aktuelle Session herunterladen` zur Verfuegung.
- `MQTT-Parent reconnecten` trennt den MQTT-Client-Parent kurz von seinem IO-Parent und verbindet ihn wieder. Damit kann ein frischer MQTT-Reconnect inklusive retained Replay angestossen werden.
- Das Bundle enthaelt alle gecachten `homeassistant/.../config` Topics sowie die dazu in den Configs referenzierten MQTT-Topics, sofern dafuer Payloads im Cache vorhanden sind.
- Der Session-Export beschraenkt Discovery-Configs und exportierte Topic-Payloads auf Records der aktuellen MQTT-Session. Damit lassen sich stale Cache-Eintraege fuer frische Fixture-Exporte gezielt ausblenden, ohne den Cache zu leeren.
- Zusaetzlich exportiert das Bundle Session-Informationen, Freshness (`is_current_session`) und Listen fuer fehlende, stale oder zusaetzlich gecachte Runtime-Topics.
- Das Exportformat ist bewusst roh gehalten, damit Producer-spezifische Unterschiede spaeter im Parser nachvollzogen und als Fixture abgelegt werden koennen.

## Session-Verhalten

- Eine neue MQTT-Session beginnt, wenn der Splitter eine aktive Verbindung zu seinem MQTT-Parent feststellt und dabei einen neuen Connect/Reconnect verarbeitet.
- Der Zeitpunkt wird im Formular ueber `MQTT Session: ...` angezeigt.
- Ein manueller Reconnect ueber `MQTT-Parent reconnecten` startet damit praktisch eine neue Session.
- Alte Cache-Eintraege bleiben erhalten, werden aber fuer Diagnose und Session-Export als nicht aktuell markiert.

## Bundle ziehen

1. Sicherstellen, dass der Parent verbunden ist und der Splitter Discovery-Daten empfaengt.
2. Wenn Discovery-Topics trotz bestehender Broker-Verbindung nicht vollstaendig auftauchen: `MQTT-Parent reconnecten` ausfuehren.
3. Kurz warten, bis retained Discovery-Topics erneut eingelaufen sind und die Zaehler im Formular plausibel aussehen.
4. Danach den passenden Export ziehen:
   - `Discovery-Bundle herunterladen`: kompletter Cache, inklusive aelterer Sessions.
   - `Discovery-Bundle aktuelle Session herunterladen`: nur die aktuell per Reconnect oder Verbindungsaufbau gesehene Session.

Empfohlene Verwendung:

- Gesamt-Bundle fuer Bestandsaufnahme, Cache-Analyse und Faelle, in denen auch stale Eintraege relevant sind.
- Session-Bundle fuer Support, Fixtures und reproduzierbare Einzelanalysen nach einem frischen Reconnect.

## Diagnose

- Das Formular zeigt getrennt an, wie viele Discovery-Configs in der aktuellen MQTT-Session gesehen wurden und wie viele nur noch als stale Cache vorliegen.
- Fuer referenzierte Runtime-Topics werden aktuelle, stale und fehlende Topics getrennt ausgewiesen.
- Leere Payloads entfernen den jeweiligen Cache-Eintrag. Bei Discovery-Configs entspricht das dem ueblichen MQTT-Delete ueber leere retained Config-Payloads.

## Hinweis

Dieser Splitter ist bewusst vom bestehenden Home Assistant Splitter getrennt. Er verwaltet keine REST-Verbindung und keinen `mqtt_statestream`. Fuer einen vollstaendigen Discovery-Cache wird ein MQTT Client als Parent benoetigt, damit die retained `homeassistant/.../config` Topics sauber replayed werden. Wenn bereits lange eine bestehende Broker-Verbindung laeuft, kann ein manueller Reconnect noetig sein, damit alle retained Discovery-Topics erneut eingelesen werden.
