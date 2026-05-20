# Plan für das weitere Vorgehen

Diese Datei bündelt die nächsten fachlichen und technischen Arbeitspakete für das Repository. Sie ergänzt die Architektur-Doku um einen umsetzbaren Backlog mit Reihenfolge, Umfang und Abschlusskriterien.

## 1. Zielbild

- Der klassische Home-Assistant-Pfad (`Discovery`, `Configurator`, `Splitter`, `Device`, `Entity`) bleibt stabil und wird für fehlende Domains schrittweise vervollständigt.
- Der MQTT-Discovery-Pfad wird als eigenständige, quellenneutrale Laufzeit sauber fertiggestellt.
- Gemeinsame Normalisierung, Gruppierung, Laufzeitlogik sowie Namensbildung bleiben klar getrennt und zentralisiert, damit neue Producer oder Domains nicht zu Speziallogik in den Modulen führen.
- Vor weiteren größeren Funktionsausbauten wird die Basis für Regressionstests und reproduzierbare Prüfungen verbessert.

## 2. Prioritäten

1. Weitere Discovery-Komponenten nach dem stabilen `light`-Pfad ausbauen
2. Fehlende Domain-Funktionalität im klassischen Runtime-Pfad ergänzen
3. Verifikation, Fixtures und kleine Prüfwerkzeuge weiter ausbauen
4. Dokumentation und Migrationshinweise auf dem vereinheitlichten Stand halten

## 3. Arbeitspaket A: MQTT Discovery v1 abschliessen

Kurzdefinition `v1`:

- Mit `v1` ist in dieser Datei der erste bewusst begrenzte, alltagstaugliche Funktionsumfang des MQTT-Discovery-Pfads gemeint.
- Gemeint ist also kein externes Discovery-Protokoll und auch keine eigene Bundle-Version, sondern der erste intern abgenommene Ausbauzustand des Discovery-Devices.
- Zu diesem `v1`-Kern gehören die grundlegende self-resolving `DeviceID`-Architektur, stabile Cache-/Diagnosepfade sowie die zuerst priorisierten Discovery-Komponenten mit belastbarem Lese-, Schreib- und Reconnect-Verhalten.
- Weitere Komponenten wie `number`, `cover` oder `climate` bauen auf diesem `v1`-Kern auf und erweitern ihn danach schrittweise.

### A1. `DeviceID`-only-Schnittstelle finalisieren

Status:

- abgeschlossen am 10.05.2026

Geliefert:

- Configurator erzeugt Discovery-Devices nur noch mit stabilen Basisdaten:
  - `DeviceID`
  - Instanzname
- Discovery-Device lädt seine vollständige Device-Definition ausschließlich über den MQTT Discovery Splitter.
- Doppelte Metadaten in Device-Properties werden nicht weitergeführt.
- Formular-Metadaten (`Name`, `Manufacturer`, `Model`) werden nur noch als Anzeige geführt.
- Leere oder ungültige `DeviceID` löschen keine bestehenden Variablen mehr.
- Parent- und Statusbehandlung wurde konsistent auf kompatiblen und aktiven Parent ausgerichtet.

Verifiziert:

- `ApplyChanges()` und Formularverhalten bei leerer `DeviceID`
- Verhalten bei ungültiger `DeviceID`
- Verhalten bei fehlendem, falschem oder inaktivem Parent
- Abgleich des self-resolving Musters mit `Home Assistant Entity`

Offen/Nachlauf:

- `ebusd` HMU-Write-Pfad bleibt separat geparkt, solange der Fixture-Stand `23.3` keine schreibbaren Discovery-Entities liefert.

### A2. Splitter-Runtime absichern

Status:

- abgeschlossen am 10.05.2026

Geliefert:

- Splitter-Cache für Discovery und Runtime-Topics auf konsistente Normalisierung umgestellt.
- MQTT-Session-Tracking über Reconnects hinweg ergänzt.
- Formular-Diagnosen für aktuelle, stale und fehlende Discovery- sowie Runtime-Topics erweitert.
- Exportierte Discovery-Bundles um Session-, Diagnose- und referenzierte Topic-Informationen erweitert.

Verifiziert:

- Verhalten nach MQTT-Reconnect mit Live-Diagnosen und aktualisiertem Export-Bundle.
- Fixture-Austausch für `ebusd`-Bundle in `tests/fixtures`.

Nachlauf:

- Discovery-Cache und Topic-Cache auf Konsistenz prüfen
- Verhalten bei leeren oder veralteten Retained-Payloads klären
- Diagnoseinformationen im Formular auf fehlende Discovery- oder State-Topics ausrichten
- Exportierte Discovery-Bundles als reproduzierbare Analysegrundlage beibehalten

Abschlusskriterien:

- Reproduzierbares Verhalten nach MQTT-Reconnect
- Verständliche Status- und Diagnosemeldungen für typische Fehlerfälle
- Export deckt Discovery-Payloads und referenzierte Laufzeit-Topics vollständig genug für Debugging ab

### A3. Discovery-Runtime für v1-Komponenten härten

Status:

- abgeschlossen am 10.05.2026

Geliefert:

- Laufzeitpfad für `sensor`, `binary_sensor`, `switch` und `select` gehärtet.
- Initialwerte aus gecachten MQTT-Payloads werden für die geprüften v1-Komponenten übernommen.
- `unknown` und `unavailable` überschreiben den letzten fachlich gültigen Wert nicht.
- Bool-Normalisierung um producerseitig beobachtete Werte wie `ja` und `nein` erweitert.
- Discovery/Runtime-Abweichungen bei Bool-Mappings werden im Device als Warnung sichtbar gemacht.
- Selection-Ansicht um `Command Topic`, `AvailCfg`, `Cache`, `Mapping`, `Warnung` und `Mode` erweitert.
- Schreibpfade für `select` und `switch` über MQTT Discovery verifiziert.
- Variable-Anlage im Discovery-Device auf Bibliotheks-Presentations statt Variable-Profile umgestellt.
- Live-Receive-Pfad zwischen Splitter und Device für Topics mit Slash-Escaping robust gemacht.

Verifiziert:

- `binary_sensor`: `ebusd/700/AdaptHeatCurve`
- `sensor`: `ebusd/700/DisplayedOutsideTemp`
- `select`: Zigbee2MQTT Bridge `log_level`
- `switch`: Zigbee2MQTT Bridge `permit_join`
- Namensbildung und Selection-Ansicht nach `ApplyChanges()`

Restnotiz:

- Der Sonderfall `unknown` konnte für Testfall 2 im Live-Betrieb nicht gezielt provoziert werden.

Abgedeckter Kern für v1:

- `sensor`
- `binary_sensor`
- `switch`
- `select`

Prüfpunkte:

- Initialwerte aus gecachten MQTT-Payloads
- `availability` sauber auf Status und Variablen abbilden
- Schreibpfade für `command_topic`
- Verhalten bei `unknown`, `unavailable` und fehlenden Payload-Feldern
- Stabile Namensbildung über Gruppierung und Laufzeit hinweg

Abschlusskriterien:

- Die vier v1-Komponenten funktionieren im Laufzeitpfad zuverlässig
- Lesen, Schreiben und Reconnect-Verhalten sind manuell geprüft

### A4. Bundle-Modus im Discovery-Splitter nachziehen

Status:

- im Repo umgesetzt, Backlog-Doku nachgezogen am 13.05.2026

Geliefert:

- MQTT Discovery Splitter unterstützt `SourceMode = mqtt | bundle`.
- `BundlePath`, `BundleCurrentSessionOnly` und `ReplayTopicsOnApply` sind als Properties vorhanden.
- Discovery-Bundles im Format `V2` können ohne MQTT-Parent in die bestehenden Splitter-Caches geladen werden.
- Bundle-Modus beantwortet Discovery- und Topic-Lookups aus denselben Cache-Strukturen wie der Live-Betrieb.
- Optionaler Replay-Schritt für gecachte Runtime-Topics an Child-Instanzen ist vorhanden.
- Ausgehende Commands werden im Bundle-Modus aktuell verworfen und nicht simuliert.

Verifiziert im Repo:

- Formular, Locale und Modulcode des MQTT Discovery Splitters enthalten den Bundle-Modus einschliesslich Replay-Button und Session-Export.
- Modul-README dokumentiert Bundle-Modus, Session-Export und die relevanten Properties.

Offen/Nachlauf:

- Eigene Migrationsnotiz für den Bundle-Modus und die neuen Properties noch in Arbeitspaket E sauber festziehen.

## 4. Arbeitspaket B: Discovery-Abdeckung erweitern

### B1. Parser und Gruppierung breiter absichern

Status:

- für den aktuellen v1-Umfang weitgehend umgesetzt; weitere Komponenten bleiben offen

Bereits im Repo sichtbar:

- Zigbee2MQTT-`device_automation` ist im Parser und im Discovery-Device berücksichtigt.
- Reproduzierbare Fixtures und Doku decken `button` und `device_automation` bereits ab.
- Für `light` sind Parser, Gruppierung, Runtime-Extraktion, Attributpflege, Schreibpfade und fixture-nahe Checks vorhanden.
- Für `number` sind Parser, Gruppierung, Hauptvariable, Typableitung, Slider-Präsentation und Schreibpfade vorhanden.
- Für `cover` sind Parser, Gruppierung, Positionspfad, Hauptvariable, Zusatzaktionen und Namensbildung vorhanden.
- Für `climate` ist der Zieltemperatur-Kernpfad über `temperature_state_topic` und `temperature_command_topic` mit Slider-Präsentation und Schreibpfad vorhanden.
- Der allgemeine Fixture-Checker behandelt `light`, `number`, `cover` und `climate` als unterstützte Discovery-Komponenten.
- Die Namensbildung für `light`, `number`, `cover` und `climate` läuft über dieselben gemeinsamen Routinen wie im klassischen Device-Pfad.

Noch offen:

- Zigbee2MQTT-v1-Pfade mit echten Beispiel-Payloads absichern
- Komplexere Climate-Mode-/Action-Templates bleiben mit der aktuellen Template-Reduktion noch ausserhalb des robusten Discovery-Kernpfads
- Bridge-Entities und Endgeräte klar voneinander trennen
- Producer-spezifische Unterschiede ausschließlich in Parser, Template-Reduktion oder vorgeschalteter Normalisierung behandeln

### B2. Weitere Discovery-Komponenten priorisieren

Status:

- `button` ist im Discovery-Device bereits enthalten
- `light` ist im Repo für Parser, Gruppierung, Runtime-Extraktion, Attributvariablen, `command_topic`-Payloads und Namensbildung umgesetzt und verifiziert
- `number`, `cover` und `climate` sind im priorisierten Discovery-Block inzwischen ebenfalls umgesetzt

Nächster Fokus nach dem priorisierten Block:

1. Climate-Nachlauf für komplexere Mode-/Action-Templates nur dann erweitern, wenn dafür ein sauberer template-reduzierter Pfad definiert ist
2. Fixture-nahe Checks für `number`, `cover` und `climate` analog zum vorhandenen Light-Check ergänzen
3. Weitere Discovery-Komponenten erst bei belastbaren Real-World-Fixtures priorisieren

## 5. Arbeitspaket C: Klassischen Runtime-Pfad vervollständigen

Laut Root-README sind folgende Domains noch nur teilweise umgesetzt:

- `cover`: Device-Class-Spezifika, weitere Attribute
- `valve`: weitere ventilspezifische Attribute und Details
- `event`: weitere Event-Attribute
- `fan`: weitere Dienste und Features je Modell
- `humidifier`: weitere Dienste und Features je Modell
- `vacuum`: weitere Dienste und Features je Modell
- `lawn_mower`: weitere Dienste und Features je Modell
- `media_player`: weitere Dienste und Features je Modell
- `camera`: Kamera-Aktionen und Services
- `image`: weitere image-spezifische Attribute

Empfohlene Reihenfolge:

1. `cover`
2. `fan`
3. `humidifier`
4. `media_player`
5. `vacuum`
6. `camera`
7. `valve`
8. `lawn_mower`
9. `event`
10. `image`

Vorgehen je Domain:

1. Domain-Definitionen in `libs/Domains` prüfen oder ergänzen
2. Normalisierung in `libs/Config/HAEntityNormalizationTrait` nur bei echtem Bedarf erweitern
3. Hauptzustand, Attribute, Aktionen und Präsentation im Runtime-Kern ergänzen
4. Schreibpfade für MQTT und REST gezielt prüfen
5. Manuelle Prüfrunde für `ApplyChanges()`, State, Attribute, Aktionen und Sonderzustände durchführen

## 6. Arbeitspaket D: Verifikation und Tests

Der größte technische Rückstand liegt aktuell in der fehlenden automatisierten Absicherung.

### D1. Minimale Pflichtabsicherung

Status:

- abgeschlossen am 10.05.2026

Geliefert:

- PowerShell-Skript `tests/lint-php.ps1` für `php -l` über alle PHP-Dateien
- Verifikationsdoku `docs/VERIFIKATION.md` mit manueller Prüfliste für:
  - `ApplyChanges()`
  - MQTT-State
  - MQTT-Attribute
  - `RequestAction()`
  - `unknown` und `unavailable`
  - `supported_features`
  - Namensbildung und Friendly Names
- Root-README und Architektur-Doku auf den D1-Workflow verlinkt

Verifiziert:

- `tests/lint-php.ps1` lief erfolgreich über den aktuellen PHP-Bestand

### D2. Reproduzierbare Fixtures aufbauen

Status:

- abgeschlossen am 11.05.2026

Geliefert:

- `tests/fixtures/ha_mqtt_discovery_bundle_ebusd.json` als reproduzierbare `ebusd`-Referenz
- `tests/fixtures/ha_mqtt_discovery_bundle_zigbee2mqtt.json` als aktuelle Zigbee2MQTT-Session-Fixture
- `tests/fixtures/ha_mqtt_discovery_bundle_zigbee2mqtt_current_session_v2.json` als kompakte Zigbee2MQTT-V2-Session-Fixture
- `tests/fixtures/ha_mqtt_discovery_bundle_zigbee2mqtt_full_v2.json` als Zigbee2MQTT-V2-Voll-Cache-Fixture
- `tests/check-mqtt-discovery-fixtures.php` für Parser-, Gruppierungs- und Bundle-Checks gegen lokale Fixtures
- Lokaler Fixture-Checker versteht Export-Bundle-Version `1` und `2` und sammelt Default-Fixtures automatisch aus `tests/fixtures`
- Fixture-Doku in `tests/fixtures/README.md` beschreibt Erzeugung, Einsatz und Unterschiede zwischen Session- und Voll-Export

Verifiziert:

- `php -l tests/check-mqtt-discovery-fixtures.php`
- `php .\tests\check-mqtt-discovery-fixtures.php` gegen die damaligen V1- und V2-Fixtures erfolgreich

### D3. Kleine Testwerkzeuge ergänzen

Status:

- teilweise vorgezogen, aber noch offen

Bereits im Repo sichtbar:

- `tests/check-mqtt-discovery-light-runtime.php` für fixture-nahe Light-Prüfungen
- `tests/extract-mqtt-discovery-light-fixture.php` zum Ableiten kleinerer Light-Fixtures
- `tests/zigbee2mqtt_update_task.ps1` als lokales Hilfsskript für Fixture-/Update-Arbeit

Weiter offen:

- Einfachen kombinierten Lint-/Prüf-Workflow für typische lokale Checks dokumentieren oder skripten
- Optional kleine parsernahe PHP-Tests für `libs/Discovery`, Gruppierung und weitere Discovery-Komponenten ergänzen

## 7. Arbeitspaket E: Dokumentation und Migration

Status:

- im Kern abgeschlossen; zuletzt nachgezogen am 19.05.2026

Geliefert:

- Root-README enthält Discovery-Voraussetzungen, MQTT-Subscription-Hinweise sowie Export-/Reconnect-Notizen.
- Root-README beschreibt jetzt den klassischen Runtime-Pfad und den MQTT-Discovery-Pfad getrennt, inklusive Bundle-Modus.
- Modul-READMEs der MQTT-Discovery-Module sind auf `DeviceID`-only-Laufzeitpfad, Bundle-Modus und Subscription-Anforderungen synchronisiert.
- Eigene Migrationsnotiz in `docs/MIGRATION.md` dokumentiert `DeviceID`-only, Bundle-Properties und die reduzierte `create`-Konfiguration des klassischen Configurators.
- Fixture-Doku liegt im versionierten Testbereich unter `tests/fixtures/README.md`.
- `docs/ARCHITEKTUR.md` dokumentiert die gemeinsame Ident- und Variablennamensbildung über `HAIdentNamingTrait` und `HAEntityVariableNamingTrait`.
- Die Benennungsregeln für Device, Entity und MQTT Discovery Device sind auf kürzere, instanzlokale und domain-präfixbasierte Idents vereinheitlicht.

## 8. Empfohlene Reihenfolge der Umsetzung

1. In Arbeitspaket B den Climate-Nachlauf für komplexere Mode-/Action-Templates nur bei klarer template-reduzierter Lösung weiterziehen
2. Arbeitspaket D3 für `number`, `cover` und `climate` schlank mitziehen, damit Parser-/Runtime-Änderungen nicht wieder ohne lokale Checks wachsen
3. Danach die klassischen Domains aus Arbeitspaket C schrittweise erweitern

## 8.1 Geparkte Punkte

- `ebusd` HMU-Write-Pfad über MQTT Discovery ist mit Fixture-Stand `23.3` geparkt.
- Beobachteter Stand: `SetMode` wird nur als `sensor` ohne `command_topic` publiziert, daher aktuell read-only im Discovery-Modell.
- Wiedervorlage erst nach `ebusd`-Upgrade oder sobald ein Bundle mit schreibbaren Discovery-Entities beziehungsweise `command_topic` vorliegt.

## 9. Definition of Done

Ein Arbeitspaket gilt erst dann als abgeschlossen, wenn:

- die Architekturregeln aus `docs/ARCHITEKTUR.md` eingehalten sind,
- keine doppelte oder driftanfällige Konfiguration zwischen Configurator, Splitter und Device verbleibt,
- geänderte PHP-Dateien mindestens mit `php -l` geprüft wurden,
- die betroffenen Laufzeitpfade manuell verifiziert wurden,
- README und Modul-Doku den neuen Stand widerspiegeln.
