# Changelog

## Build 113 - 2026-06-30
- Splitter: Die interne Experten-Property `UseRestForSetTopics` (im Formular ausgeblendet, Default `true`) wurde entfernt. `*/set`-Topics werden im klassischen Bridge-Pfad nun immer über die HA-REST-API ausgeführt – der MQTT-`false`-Pfad war funktionslos, da `mqtt_statestream` rein ausgehend ist und der Broker keine Command-Topics konsumiert. Der bestehende Fallback auf MQTT-Weiterleitung (fehlende REST-Konfiguration oder nicht unterstützte Domain) bleibt erhalten.

## Build 111 - 2026-06-24
- Device/Entity: Kürzere Variablen-Idents für **neu** angelegte Entitäten. Der geräteweite HA-Slug (Area + interne Geräte-ID, z. B. `milchstrasse_melcloudhome_650e_5ec4`) wird aus den `entity_id`s selbst abgeleitet (längster gemeinsamer Präfix je Gerät) und abgeschnitten, sodass Idents wie `sensor_room_temperature` statt `sensor_milchstrasse_melcloudhome_650e_5ec4_room_temperature` entstehen. Anders als die bisherige Heuristik (Abgleich gegen den veränderlichen `device_name`) ist das robust gegen Geräte-Umbenennungen in HA.
- Bestand wird nicht migriert: Existiert bereits eine Variable unter dem langen Legacy-Ident, bleibt dieser erhalten. Nur Entitäten ohne vorhandene Variable erhalten die Kurzform.

## Build 110 - 2026-06-24
- Device/Entity: Read-only Auswahl-Attribute (z. B. `climate.hvac_action`, `fan.current_direction`) erhalten keine Aufzählung mehr ohne Variablenaktion, was in der Variablendarstellung den Symcon-Fehler „Diese Darstellung ist nur für Variablen mit einer Variablenaktion verfügbar" auslöste. Ein zentraler Helfer wählt die Darstellung jetzt domänenübergreifend nach derselben Writability, die auch die Aktion steuert: beschreibbar → Aufzählung (mit Aktion), read-only → Wertanzeige mit Optionen. Betrifft climate, fan, media_player (`source`/`sound_mode`), humidifier (`mode`) und light (`effect`); greift auch bei Auswahl-Attributen ohne unterstütztes Feature-Bit.

## Build 109 - 2026-06-23
- Device/Entity: `climate`-Attribut `swing_horizontal_mode` ist jetzt steuerbar. Das Feature-Gate prüfte fälschlich Bit 64 (in Home Assistant entfernt, ehemals `AUX_HEAT`); korrekt ist `ClimateEntityFeature.SWING_HORIZONTAL_MODE` = Bit 512. Geräte, die horizontalen Swing über `supported_features` melden, erhalten damit wieder eine Schreib-Action.
- Device/Entity: `climate`-Feature-Konstanten `TURN_ON`/`TURN_OFF` korrigiert (HA: `TURN_OFF`=128, `TURN_ON`=256) – betraf bisher nur die Beschriftung in der Feature-Liste.

## Build 92 - 2026-05-26
- Device/Entity: `climate`-Hauptwerte werden zentral fachlich aus Temperaturattributen abgeleitet, sodass textuelle HVAC-States wie `cool` oder `heat` die Solltemperatur nicht mehr auf `0` ziehen.

## Build 91 - 2026-05-25
- MQTT Discovery Device: `lock`, `image` und `device_tracker` im klassischen Discovery-Pfad ergaenzt, inklusive identstabiler Namensbildung und passender Zusatzobjekte.
- MQTT Discovery Device: Klima-Werte und `device_tracker`-Hauptzustand folgen wieder strikt dem MQTT-Statuspfad statt lokaler Ersatzwerte.
- MQTT Discovery Splitter: stale Discovery-Configs werden in der Diagnose jetzt deutlich als Warnung markiert.
- MQTT Discovery Splitter: binaere Topic-Payloads werden im Bundle als Base64 konserviert und beim Replay wiederhergestellt.

## Build 90 - 2026-05-23
- Device: Attribut-Updates fuer `cover` und `valve` verwenden jetzt korrekt `void`-Updater und laufen nicht mehr in einen PHP-`TypeError` bei stateful attribute topics.

## Build 66 - 2026-05-10
- MQTT Discovery: Splitter, Configurator und Device als eigener Laufzeitpfad für homeassistant/.../config ergänzt.
- Device/Entity: Parent- und REST-Anbindung über gemeinsame Traits vereinheitlicht und Status- sowie Formularaktualisierung robuster gemacht.
- Device: deaktivierte oder entfernte Entitäten räumen ihre Variablen und Medienobjekte beim nächsten ApplyChanges() auf.
- Device/Entity: input_number wird über den fachlich vorgesehenen Home-Assistant-Service geschrieben.
- Architektur- und README-Dokumentation auf den neuen Laufzeit- und Schreibpfad abgestimmt.
## Build 57 - 2026-04-20
- Configurator: `create`-Konfiguration auf stabile Strukturattribute reduziert, damit volatile Home-Assistant-Attribute keine bereits gelesenen Einträge erneut als neu erscheinen lassen.
- Configurator: `DeviceConfig` im `create`-Block zusätzlich stabil nach `entity_id` sortiert.

## Build 41 - 2026-04-06
- Device: `camera.entity_picture` wird als separate Vorschau behandelt; der Stream bleibt ein eigenes Medienobjekt.
- Device: Kamera-Streams werden nur aus Home-Assistant-Daten aktualisiert, wenn eine RTSP-Quelle vorhanden ist; manuell gepflegte Stream-Medien bleiben sonst unangetastet.

## Build 40 - 2026-04-06
- Device: neue Domain `camera` in Konfiguration, Includes und Präsentationslogik aufgenommen.
- Device: Kamera-State- und Attribut-Updates über eigene Domain-Handler angebunden.
- Device: Trigger-Variablen (`button`, Lock/Vacuum/Lawn-Mower/Media-Action) auf zentrales Descriptor-Modell umgestellt und konsistent auf Neutralwert zurückgesetzt.
- Device: fremde MQTT-Entities außerhalb der eigenen `DeviceConfig` werden bei State- und Attribut-Topics ignoriert.
- Device: Timestamp-Sensoren werden früh im fachlichen Konvertierungspfad mit angereicherten Attributen in Unix-Timestamps umgewandelt.
- UI: Device-Form und Locale um `camera` und Kamera-Texte erweitert.

## Build 37 - 2026-03-29
- Device-Form: Domain-Auswahl um `input_button` erweitert.
- Device-Form-Snapshot (`form_result.json`) entsprechend synchronisiert.
- Locale: Übersetzung für `Input Button (input_button)` ergänzt.
- Konsistenzfix: manuelle Device-Konfiguration jetzt im Einklang mit bestehender `input_button`-Runtime-Unterstützung.

## Build 35 - 2026-03-14
- Debug-Trait auf `ModuleDebugTrait` umgestellt und zentrale Includes angepasst.
- `libs/HADebug.php` entfernt, `libs/ModuleDebug.php` hinzugefügt.
- Discovery/Splitter/Configurator/Device auf neues Debug-Trait migriert.
- Device: `light.effect` als Enumeration mit Optionen aus `effect_list` umgesetzt.
- Device: `light.rgb_color` auf Farb-Präsentation (`ENCODING = 0`, RGB) umgestellt.
- Device: `rgb_color` wird intern als JSON-Objekt gespeichert (`{"r":...,"g":...,"b":...}`) und für Payload korrekt nach RGB-Array konvertiert.
- Diverse Robustheitsanpassungen in Light-Attributverarbeitung und Präsentationslogik.
