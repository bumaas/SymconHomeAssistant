# Plan fuer das weitere Vorgehen

Diese Datei buendelt die naechsten fachlichen und technischen Arbeitspakete fuer das Repository. Sie ergaenzt die Architektur-Doku um einen umsetzbaren Backlog mit Reihenfolge, Umfang und Abschlusskriterien.

## 1. Zielbild

- Der klassische Home-Assistant-Pfad (`Discovery`, `Configurator`, `Splitter`, `Device`, `Entity`) bleibt stabil und wird fuer fehlende Domains schrittweise vervollstaendigt.
- Der MQTT-Discovery-Pfad wird als eigenstaendige, quellenneutrale Laufzeit sauber fertiggestellt.
- Gemeinsame Normalisierung, Gruppierung und Laufzeitlogik bleiben klar getrennt, damit neue Producer oder Domains nicht zu Speziallogik in den Modulen fuehren.
- Vor weiteren groesseren Funktionsausbauten wird die Basis fuer Regressionstests und reproduzierbare Pruefungen verbessert.

## 2. Prioritaeten

1. MQTT-Discovery-Pfad funktional abschliessen und stabilisieren
2. Self-resolving `DeviceID`-Pfad sauber absichern
3. Fehlende Domain-Funktionalitaet im klassischen Runtime-Pfad ergaenzen
4. Verifikation, Fixtures und Dokumentation ausbauen

## 3. Arbeitspaket A: MQTT Discovery v1 abschliessen

### A1. `DeviceID`-only-Schnittstelle finalisieren

Status:

- abgeschlossen am 10.05.2026

Geliefert:

- Configurator erzeugt Discovery-Devices nur noch mit stabilen Basisdaten:
  - `DeviceID`
  - Instanzname
- Discovery-Device laedt seine vollstaendige Device-Definition ausschliesslich ueber den MQTT Discovery Splitter.
- Doppelte Metadaten in Device-Properties werden nicht weitergefuehrt.
- Formular-Metadaten (`Name`, `Manufacturer`, `Model`) werden nur noch als Anzeige gefuehrt.
- Leere oder ungueltige `DeviceID` loeschen keine bestehenden Variablen mehr.
- Parent- und Statusbehandlung wurde konsistent auf kompatiblen und aktiven Parent ausgerichtet.

Verifiziert:

- `ApplyChanges()` und Formularverhalten bei leerer `DeviceID`
- Verhalten bei ungueltiger `DeviceID`
- Verhalten bei fehlendem, falschem oder inaktivem Parent
- Abgleich des self-resolving Musters mit `Home Assistant Entity`

Offen/Nachlauf:

- `ebusd` HMU-Write-Pfad bleibt separat geparkt, solange der Fixture-Stand `23.3` keine schreibbaren Discovery-Entities liefert.

### A2. Splitter-Runtime absichern

Status:

- abgeschlossen am 10.05.2026

Geliefert:

- Splitter-Cache fuer Discovery und Runtime-Topics auf konsistente Normalisierung umgestellt.
- MQTT-Session-Tracking ueber Reconnects hinweg ergaenzt.
- Formular-Diagnosen fuer aktuelle, stale und fehlende Discovery- sowie Runtime-Topics erweitert.
- Exportierte Discovery-Bundles um Session-, Diagnose- und referenzierte Topic-Informationen erweitert.

Verifiziert:

- Verhalten nach MQTT-Reconnect mit Live-Diagnosen und aktualisiertem Export-Bundle.
- Fixture-Austausch fuer `ebusd`-Bundle in `docs/fixtures`.

- Discovery-Cache und Topic-Cache auf Konsistenz pruefen
- Verhalten bei leeren oder veralteten Retained-Payloads klaeren
- Diagnoseinformationen im Formular auf fehlende Discovery- oder State-Topics ausrichten
- Exportierte Discovery-Bundles als reproduzierbare Analysegrundlage beibehalten

Abschlusskriterien:

- Reproduzierbares Verhalten nach MQTT-Reconnect
- Verstaendliche Status- und Diagnosemeldungen fuer typische Fehlerfaelle
- Export deckt Discovery-Payloads und referenzierte Laufzeit-Topics vollstaendig genug fuer Debugging ab

### A3. Discovery-Runtime fuer v1-Komponenten haerten

Status:

- abgeschlossen am 10.05.2026

Geliefert:

- Laufzeitpfad fuer `sensor`, `binary_sensor`, `switch` und `select` gehaertet.
- Initialwerte aus gecachten MQTT-Payloads werden fuer die geprueften v1-Komponenten uebernommen.
- `unknown` und `unavailable` ueberschreiben den letzten fachlich gueltigen Wert nicht.
- Bool-Normalisierung um producerseitig beobachtete Werte wie `ja` und `nein` erweitert.
- Discovery/Runtime-Abweichungen bei Bool-Mappings werden im Device als Warnung sichtbar gemacht.
- Selection-Ansicht um `Command Topic`, `AvailCfg`, `Cache`, `Mapping`, `Warnung` und `Mode` erweitert.
- Schreibpfade fuer `select` und `switch` ueber MQTT Discovery verifiziert.
- Variable-Anlage im Discovery-Device auf Bibliotheks-Presentations statt Variable-Profile umgestellt.
- Live-Receive-Pfad zwischen Splitter und Device fuer Topics mit Slash-Escaping robust gemacht.

Verifiziert:

- `binary_sensor`: `ebusd/700/AdaptHeatCurve`
- `sensor`: `ebusd/700/DisplayedOutsideTemp`
- `select`: Zigbee2MQTT Bridge `log_level`
- `switch`: Zigbee2MQTT Bridge `permit_join`
- Namensbildung und Selection-Ansicht nach `ApplyChanges()`

Restnotiz:

- Der Sonderfall `unknown` konnte fuer Testfall 2 im Live-Betrieb nicht gezielt provoziert werden.

Aktuell im Fokus:

- `sensor`
- `binary_sensor`
- `switch`
- `select`

Pruefpunkte:

- Initialwerte aus gecachten MQTT-Payloads
- `availability` sauber auf Status und Variablen abbilden
- Schreibpfade fuer `command_topic`
- Verhalten bei `unknown`, `unavailable` und fehlenden Payload-Feldern
- Stabile Namensbildung ueber Gruppierung und Laufzeit hinweg

Abschlusskriterien:

- Die vier v1-Komponenten funktionieren im Laufzeitpfad zuverlaessig
- Lesen, Schreiben und Reconnect-Verhalten sind manuell geprueft

## 4. Arbeitspaket B: Discovery-Abdeckung erweitern

### B1. Parser und Gruppierung breiter absichern

- Zigbee2MQTT-v1-Pfade mit echten Beispiel-Payloads absichern
- Bridge-Entities und Endgeraete klar voneinander trennen
- Producer-spezifische Unterschiede ausschliesslich in Parser, Template-Reduktion oder vorgeschalteter Normalisierung behandeln

### B2. Weitere Discovery-Komponenten priorisieren

Empfohlene Reihenfolge nach Nutzwert und Naehe zum bestehenden Mapping:

1. `light`
2. `number`
3. `button`
4. `cover`
5. `climate`

Voraussetzung:

- Erst nach stabilem Abschluss von Arbeitspaket A erweitern

## 5. Arbeitspaket C: Klassischen Runtime-Pfad vervollstaendigen

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

1. Domain-Definitionen in `libs/Domains` pruefen oder ergaenzen
2. Normalisierung in `libs/Config/HAEntityNormalizationTrait` nur bei echtem Bedarf erweitern
3. Hauptzustand, Attribute, Aktionen und Praesentation im Runtime-Kern ergaenzen
4. Schreibpfade fuer MQTT und REST gezielt pruefen
5. Manuelle Pruefrunde fuer `ApplyChanges()`, State, Attribute, Aktionen und Sonderzustaende durchfuehren

## 6. Arbeitspaket D: Verifikation und Tests

Der groesste technische Rueckstand liegt aktuell in der fehlenden automatisierten Absicherung.

### D1. Minimale Pflichtabsicherung

Status:

- abgeschlossen am 10.05.2026

Geliefert:

- PowerShell-Skript `tests/lint-php.ps1` fuer `php -l` ueber alle PHP-Dateien
- Verifikationsdoku `docs/VERIFIKATION.md` mit manueller Pruefliste fuer:
  - `ApplyChanges()`
  - MQTT-State
  - MQTT-Attribute
  - `RequestAction()`
  - `unknown` und `unavailable`
  - `supported_features`
  - Namensbildung und Friendly Names
- Root-README und Architektur-Doku auf den D1-Workflow verlinkt

Verifiziert:

- `tests/lint-php.ps1` lief erfolgreich ueber den aktuellen PHP-Bestand

### D2. Reproduzierbare Fixtures aufbauen

Status:

- abgeschlossen am 11.05.2026

Geliefert:

- `docs/fixtures/ha_mqtt_discovery_bundle_ebusd.json` als reproduzierbare `ebusd`-Referenz
- `docs/fixtures/ha_mqtt_discovery_bundle_zigbee2mqtt.json` als aktuelle Zigbee2MQTT-Session-Fixture
- `docs/fixtures/ha_mqtt_discovery_bundle_zigbee2mqtt_current_session_v2.json` als kompakte Zigbee2MQTT-V2-Session-Fixture
- `docs/fixtures/ha_mqtt_discovery_bundle_zigbee2mqtt_full_v2.json` als Zigbee2MQTT-V2-Voll-Cache-Fixture
- `tests/check-mqtt-discovery-fixtures.php` fuer Parser-, Gruppierungs- und Bundle-Checks gegen lokale Fixtures
- Lokaler Fixture-Checker versteht Export-Bundle-Version `1` und `2` und sammelt Default-Fixtures automatisch aus `docs/fixtures`
- Fixture-Doku in `docs/fixtures/README.md` beschreibt Erzeugung, Einsatz und Unterschiede zwischen Session- und Voll-Export

Verifiziert:

- `php -l tests/check-mqtt-discovery-fixtures.php`
- `php .\tests\check-mqtt-discovery-fixtures.php` gegen die lokalen V1- und V2-Fixtures erfolgreich

### D3. Kleine Testwerkzeuge ergaenzen

- Einfachen Lint- oder Pruef-Workflow dokumentieren oder skripten
- Optional kleine parsernahe PHP-Tests fuer `libs/Discovery` und Gruppierung ergaenzen

## 7. Arbeitspaket E: Dokumentation und Migration

- Root-README nach Stabilisierung des Discovery-Pfads um klare Einrichtungs- und Migrationshinweise ergaenzen
- Modul-READMEs der neuen MQTT-Discovery-Module synchron halten
- Umbauten an Properties oder Create-Configs mit eigener Migrationsnotiz dokumentieren
- Voraussetzungen und typische MQTT-Subscriptions klar benennen

## 8. Empfohlene Reihenfolge der Umsetzung

1. Arbeitspaket E fuer den Discovery-Pfad nachziehen
2. Erst dann Arbeitspaket B fuer zusaetzliche Discovery-Komponenten beginnen
3. Klassische Domains aus Arbeitspaket C schrittweise erweitern

## 8.1 Geparkte Punkte

- `ebusd` HMU-Write-Pfad ueber MQTT Discovery ist mit Fixture-Stand `23.3` geparkt.
- Beobachteter Stand: `SetMode` wird nur als `sensor` ohne `command_topic` publiziert, daher aktuell read-only im Discovery-Modell.
- Wiedervorlage erst nach `ebusd`-Upgrade oder sobald ein Bundle mit schreibbaren Discovery-Entities beziehungsweise `command_topic` vorliegt.

## 9. Definition of Done

Ein Arbeitspaket gilt erst dann als abgeschlossen, wenn:

- die Architekturregeln aus `docs/ARCHITEKTUR.md` eingehalten sind,
- keine doppelte oder driftanfaellige Konfiguration zwischen Configurator, Splitter und Device verbleibt,
- geaenderte PHP-Dateien mindestens mit `php -l` geprueft wurden,
- die betroffenen Laufzeitpfade manuell verifiziert wurden,
- README und Modul-Doku den neuen Stand widerspiegeln.
