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
- Fixture-Austausch fuer `ebusd`-Bundle in `tests/fixtures`.

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

### A4. Bundle-Modus im Discovery-Splitter nachziehen

Status:

- im Repo umgesetzt, Backlog-Doku nachgezogen am 13.05.2026

Geliefert:

- MQTT Discovery Splitter unterstuetzt `SourceMode = mqtt | bundle`.
- `BundlePath`, `BundleCurrentSessionOnly` und `ReplayTopicsOnApply` sind als Properties vorhanden.
- Discovery-Bundles im Format `V2` koennen ohne MQTT-Parent in die bestehenden Splitter-Caches geladen werden.
- Bundle-Modus beantwortet Discovery- und Topic-Lookups aus denselben Cache-Strukturen wie der Live-Betrieb.
- Optionaler Replay-Schritt fuer gecachte Runtime-Topics an Child-Instanzen ist vorhanden.
- Ausgehende Commands werden im Bundle-Modus aktuell verworfen und nicht simuliert.

Verifiziert im Repo:

- Formular, Locale und Modulcode des MQTT Discovery Splitters enthalten den Bundle-Modus einschliesslich Replay-Button und Session-Export.
- Modul-README dokumentiert Bundle-Modus, Session-Export und die relevanten Properties.

Offen/Nachlauf:

- Eigene Migrationsnotiz fuer den Bundle-Modus und die neuen Properties noch in Arbeitspaket E sauber festziehen.

## 4. Arbeitspaket B: Discovery-Abdeckung erweitern

### B1. Parser und Gruppierung breiter absichern

Status:

- teilweise vorgezogen, aber noch nicht als abgeschlossen abgenommen

Bereits im Repo sichtbar:

- Zigbee2MQTT-`device_automation` ist im Parser und im Discovery-Device beruecksichtigt.
- Reproduzierbare Fixtures und Doku decken `button` und `device_automation` bereits ab.
- Fuer `light` existieren fixture-nahe Hilfsskripte und ein lokaler Runtime-Checker.

- Zigbee2MQTT-v1-Pfade mit echten Beispiel-Payloads absichern
- Bridge-Entities und Endgeraete klar voneinander trennen
- Producer-spezifische Unterschiede ausschliesslich in Parser, Template-Reduktion oder vorgeschalteter Normalisierung behandeln

### B2. Weitere Discovery-Komponenten priorisieren

Status:

- `button` ist im Discovery-Device bereits enthalten
- `light` ist fachlich erkennbar angelaufen, aber noch nicht als stabil abgeschlossen dokumentiert

Empfohlene Reihenfolge nach Nutzwert und Naehe zum bestehenden Mapping:

1. `light` fachlich abschliessen, dokumentieren und manuell abnehmen
2. `number`
3. `cover`
4. `climate`

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

- `tests/fixtures/ha_mqtt_discovery_bundle_ebusd.json` als reproduzierbare `ebusd`-Referenz
- `tests/fixtures/ha_mqtt_discovery_bundle_zigbee2mqtt.json` als aktuelle Zigbee2MQTT-Session-Fixture
- `tests/fixtures/ha_mqtt_discovery_bundle_zigbee2mqtt_current_session_v2.json` als kompakte Zigbee2MQTT-V2-Session-Fixture
- `tests/fixtures/ha_mqtt_discovery_bundle_zigbee2mqtt_full_v2.json` als Zigbee2MQTT-V2-Voll-Cache-Fixture
- `tests/check-mqtt-discovery-fixtures.php` fuer Parser-, Gruppierungs- und Bundle-Checks gegen lokale Fixtures
- Lokaler Fixture-Checker versteht Export-Bundle-Version `1` und `2` und sammelt Default-Fixtures automatisch aus `tests/fixtures`
- Fixture-Doku in `tests/fixtures/README.md` beschreibt Erzeugung, Einsatz und Unterschiede zwischen Session- und Voll-Export

Verifiziert:

- `php -l tests/check-mqtt-discovery-fixtures.php`
- `php .\tests\check-mqtt-discovery-fixtures.php` gegen die damaligen V1- und V2-Fixtures erfolgreich

### D3. Kleine Testwerkzeuge ergaenzen

Status:

- teilweise vorgezogen, aber noch offen

Bereits im Repo sichtbar:

- `tests/check-mqtt-discovery-light-runtime.php` fuer fixture-nahe Light-Pruefungen
- `tests/extract-mqtt-discovery-light-fixture.php` zum Ableiten kleinerer Light-Fixtures
- `tests/zigbee2mqtt_update_task.ps1` als lokales Hilfsskript fuer Fixture-/Update-Arbeit

Weiter offen:

- Einfachen Lint- oder Pruef-Workflow dokumentieren oder skripten
- Optional kleine parsernahe PHP-Tests fuer `libs/Discovery` und Gruppierung ergaenzen

## 7. Arbeitspaket E: Dokumentation und Migration

Status:

- abgeschlossen am 13.05.2026

Geliefert:

- Root-README enthaelt Discovery-Voraussetzungen, MQTT-Subscription-Hinweise sowie Export-/Reconnect-Notizen.
- Root-README beschreibt jetzt den klassischen Runtime-Pfad und den MQTT-Discovery-Pfad getrennt, inklusive Bundle-Modus.
- Modul-READMEs der MQTT-Discovery-Module sind auf `DeviceID`-only-Laufzeitpfad, Bundle-Modus und Subscription-Anforderungen synchronisiert.
- Eigene Migrationsnotiz in `docs/MIGRATION.md` dokumentiert `DeviceID`-only, Bundle-Properties und die reduzierte `create`-Konfiguration des klassischen Configurators.
- Fixture-Doku liegt im versionierten Testbereich unter `tests/fixtures/README.md`.

## 8. Empfohlene Reihenfolge der Umsetzung

1. Arbeitspaket B mit `light` gezielt abschliessen und erst danach weitere Discovery-Komponenten angehen
2. Arbeitspaket D3 schlank mitziehen, damit Parser-/Runtime-Aenderungen nicht wieder ohne lokale Checks wachsen
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
