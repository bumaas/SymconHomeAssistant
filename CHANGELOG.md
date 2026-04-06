# Changelog

## Build 40 - 2026-04-06
- Device: neue Domain `camera` in Konfiguration, Includes und Praesentationslogik aufgenommen.
- Device: optionale `stream_source_override`-Konfiguration fuer Kameras hinzugefuegt.
- Device: `entity_picture` wird fuer Kameras als `camera_image_url` uebernommen und in die Kameralogik eingespeist.
- Device: Kamera-State- und Attribut-Updates ueber eigene Domain-Handler angebunden.
- Device: Trigger-Variablen (`button`, Lock/Vacuum/Lawn-Mower/Media-Action) auf zentrales Descriptor-Modell umgestellt und konsistent auf Neutralwert zurueckgesetzt.
- Device: fremde MQTT-Entities ausserhalb der eigenen `DeviceConfig` werden bei State- und Attribut-Topics ignoriert.
- Device: Timestamp-Sensoren werden frueh im fachlichen Konvertierungspfad mit angereicherten Attributen in Unix-Timestamps umgewandelt.
- UI: Device-Form und Locale um `camera`, RTSP-Override und Kamera-Texte erweitert.

## Build 37 - 2026-03-29
- Device-Form: Domain-Auswahl um `input_button` erweitert.
- Device-Form-Snapshot (`form_result.json`) entsprechend synchronisiert.
- Locale: Uebersetzung fuer `Input Button (input_button)` ergaenzt.
- Konsistenzfix: manuelle Device-Konfiguration jetzt im Einklang mit bestehender `input_button`-Runtime-Unterstuetzung.

## Build 35 - 2026-03-14
- Debug-Trait auf `ModuleDebugTrait` umgestellt und zentrale Includes angepasst.
- `libs/HADebug.php` entfernt, `libs/ModuleDebug.php` hinzugefuegt.
- Discovery/Splitter/Configurator/Device auf neues Debug-Trait migriert.
- Device: `light.effect` als Enumeration mit Optionen aus `effect_list` umgesetzt.
- Device: `light.rgb_color` auf Farb-Praesentation (`ENCODING = 0`, RGB) umgestellt.
- Device: `rgb_color` wird intern als JSON-Objekt gespeichert (`{"r":...,"g":...,"b":...}`) und fuer Payload korrekt nach RGB-Array konvertiert.
- Diverse Robustheitsanpassungen in Light-Attributverarbeitung und Praesentationslogik.
