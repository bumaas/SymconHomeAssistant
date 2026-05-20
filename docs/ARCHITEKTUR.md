# Architektur

Diese Datei ist eine interne Wartungsdoku. Sie beschreibt die Struktur des Moduls, die Verantwortung der Kernbausteine und die Leitplanken für Erweiterungen.

## 1. Modulgrenzen

- `Home Assistant Discovery`
  Sucht Home-Assistant-Instanzen per mDNS und legt bei Bedarf Configurator-Instanzen an.
- `Home Assistant Configurator`
  Liest Geräte- und Entity-Daten aus Home Assistant, gruppiert sie zu Symcon-Geräten und erzeugt daraus `DeviceConfig`.
  Für den Symcon-`create`-Block wird dabei nur eine stabile CreateConfig mit strukturellen Attributen erzeugt, damit volatile Live-Daten keine neuen Configurator-Einträge vortäuschen.
- `Home Assistant MQTT Discovery Splitter`
  Ist der Transportknoten für `homeassistant/.../config` Topics. Er cached MQTT-Discovery-Payloads, reicht MQTT an Kinder weiter und stellt den Cache für Discovery-Module bereit.
- `Home Assistant MQTT Discovery Configurator`
  Liest gecachte MQTT-Discovery-Configs aus dem MQTT Discovery Splitter, parst die Payloads in eine interne Transportstruktur und gruppiert sie zu Gerätekandidaten für Zigbee2MQTT und ähnliche Discovery-Quellen.
- `Home Assistant MQTT Discovery Device`
  Ist der schlanke Laufzeitpfad für MQTT-Discovery-Geräte. Er löst seine Entities über `DeviceID` aus dem MQTT Discovery Splitter auf, cached die aufgelöste Device-Definition intern und verarbeitet `state_topic`, `command_topic` sowie `availability`.
- `libs/Config`
  Enthält die gemeinsame Aufbereitungsschicht für Configurator und spätere self-resolving Module. Loader lädt Rohdaten aus HA, Builder normalisiert die Entity-Konfiguration und Grouping bündelt Entities für die Geräteansicht.
- `libs/Discovery`
  Enthält die vorbereitende MQTT-Discovery-Schicht. Parser und Grouping normalisieren `homeassistant/.../config` Topics in eine transportfeste interne Struktur, ohne den bestehenden Statestream-Pfad zu vermischen.
- `Home Assistant Splitter`
  Ist der zentrale Transportknoten. Er verteilt MQTT-Nachrichten an Kinder und kapselt REST- sowie Bildabrufe.
- `Home Assistant Device`
  Ist das eigentliche Laufzeitmodell eines Home-Assistant-Geräts. Hier entstehen Variablen, Medienobjekte, Aktionen, Präsentationen und Domain-spezifische Logik.
- `Home Assistant Entity`
  Ist eine selbstauflösende Einzel-Entity-Instanz. Sie lädt ihre Konfiguration anhand der `EntityID` selbst aus Home Assistant nach und nutzt dafür denselben Runtime-Kern wie Device-Instanzen.

## 2. Laufzeitfluss im Device

1. `ApplyChanges()`
   Liest die Rohkonfiguration, bestimmt das MQTT-Basetopic, ergänzt initiale Zustände und baut daraus die Laufzeitstruktur auf.
2. `ReceiveData()`
   Nimmt Daten vom Splitter entgegen und verzweigt in State-, Attribut- und Diagnosepfade.
3. `RequestAction()`
   Löst einen Symcon-Ident wieder auf eine Home-Assistant-Entität oder ein Attribut auf und sendet den Schreibvorgang per MQTT oder REST.
4. `UpdateMediaPlayerProgress()`
   Fortschritts-Timer für Media Player auf Basis des Laufzeit-Caches.

## 3. Interne Schichten im Device

- `libs/Config/HAEntityNormalizationTrait`
  Zentrale, fachliche Normalisierung von Entitäten (Struktur, Aliase, Features). Wird von Configurator und Device-Modulen genutzt.
- `libs/Device/HADeviceEntityNormalizationTrait`
  Laufzeit-Bridge, die Konfigurationsdaten mit Live-MQTT/REST-Attributen verheiratet.
- `HAIdentNamingTrait`
  Gemeinsame Benennungsschicht für `Home Assistant Device`, `Home Assistant Entity` und `Home Assistant MQTT Discovery Device`. Sie erzeugt kurze, instanzlokale Idents und trennt dabei `ident_prefix` von der eigentlichen Hauptvariable.
- `HAEntityVariableNamingTrait`
  Gemeinsame Routine für die sichtbaren Namen der Hauptvariablen. Der klassische Device-Pfad ist dabei die fachliche Vorlage; Discovery nutzt dieselben Regeln für `Status`, Domain-Sonderfälle und Fallbacks.
- `HAEntityStoreTrait`
  Verwaltet die Runtime-Entity-Liste, den `EntityStateCache`, Entity-Lookups und cachegestützte Präsentationsaktualisierungen.
- `HADomainRegistryTrait`
  Ordnet eingehende State-Payloads den Domains zu und steuert den Hauptpfad für State-Updates.
- `HADomainStateHandlersTrait`
  Enthält die Zustandslogik der Hauptvariablen je Domain.
- `HAAttributeHandlersTrait`
  Verarbeitet Attribut-Topics und schiebt Attributwerte in die passenden Wartungspfade.
- `HAPresentationTrait`
  Bestimmt Variablentyp, Präsentation, Namen und visuelle Metadaten.
- `HADomainValueMappingTrait` und `HAVariableMappingTrait`
  Kümmern sich um Werte-Casts, Optionsabbildungen und Symcon-spezifische Profileigenschaften.
- `HASupportedFeaturesTrait`
  Übersetzt Feature-Bitmasks in lesbare Fähigkeiten und hält die Bitlogik zentral.
- `HAStandardAttributeMaintenanceTrait`
  Pflegt generische Zusatzvariablen aus Attributdefinitionen.
- `HADomainAttributeMaintenanceTrait`
  Ergänzt domain-spezifische Zusatzvariablen und Sonderfälle, die nicht in den generischen Pfad passen.
- `HADomainSpecialActionsTrait`
  Pflegt explizite Aktionsvariablen und Service-spezifische Schreiblogik.
- `HAAttributeActionMappingTrait`
  Ordnet Schreibvorgänge von Zusatzvariablen wieder den richtigen Entitäten und Attributen zu.
- `HAMediaObjectsTrait`
  Verwaltet Vorschau-, Stream- und Cover-Medienobjekte.
- `HARestParentClientTrait`
  Kapselt REST- und Service-Aufrufe des Devices an den Splitter.
- `HADiagnosticsTrait`
  Hält Diagnose- und Statusinformationen für Formular und Laufzeit aktuell.
- `HAAttributeFilter`
  Entfernt nicht unterstützte Attribute vor der weiteren Domain-Verarbeitung.
- `libs/Config/HAEntityConfigLoaderTrait`
  Lädt Rohdaten und Templates aus Home Assistant und kapselt die dafür nötigen Template-Requests.
- `libs/Config/HAEntityConfigBuilderTrait`
  Baut aus Rohdaten eine stabile interne Entity-Konfiguration und filtert strukturrelevante Attribute.
- `libs/Config/HAEntityGroupingTrait`
  Gruppiert normalisierte Entities zu Geräten und bereitet Namen sowie Zusammenfassungen für die UI auf.
- `libs/Discovery/HAMqttDiscoveryTemplate`
  Reduziert die für v1 unterstützten MQTT-Discovery-Templates auf eine kleine interne Struktur, damit Runtime-Code keine freien Jinja-Ausdrücke auswerten muss.
- `libs/Discovery/HAMqttDiscoveryParser`
  Übersetzt einzelne `homeassistant/.../config` Payloads in normalisierte Discovery-Entities mit expliziten Topics, Availability und Schreibmetadaten.
- `libs/Discovery/HAMqttDiscoveryGrouping`
  Gruppiert normalisierte Discovery-Entities über `device.identifiers` zu Symcon-Gerätekandidaten und baut daraus eine stabile Discovery-Device-Definition für Configurator und Laufzeitmodul.
- `libs/Device/HADeviceCoreTrait`
  Kapselt den gemeinsamen Laufzeitkern für Device- und Entity-Instanzen, insbesondere MQTT/REST-Synchronisierung, Topic-Ableitung, Initialzustände und Action-Dispatch.
- `libs/Domains/*Definitions.php`
  Sind die fachliche Quelle für Domain-Konstanten, Features, Zustände und Attributdefinitionen.

## 4. Architekturregeln

- `DeviceConfig` als Roh-JSON ist Teil des klassischen Home-Assistant-Device-Pfads. MQTT-Discovery-Geräte arbeiten stattdessen mit `DeviceID` plus intern gecachter, aufgelöster Device-Definition.
- Der Configurator darf für den `create`-Block nur stabile Strukturattribute verwenden. Flüchtige Laufzeit- oder Prognosewerte gehören nicht in die CreateConfig, weil Symcon diesen Block für `Als gelesen markiert` wiedererkennt.
- Gemeinsame Entity-Aufbereitung für Configurator und künftige self-resolving Instanzen gehört in `libs/Config`, nicht in modul-lokale Speziallogik.
- Unterschiede zwischen MQTT-Discovery-Quellen gehören in Parser, Template-Reduktion oder vorgeschaltete Normalisierung. Das MQTT Discovery Device arbeitet quellenneutral gegen ein internes Discovery-Modell und darf nicht pro Producer wie Zigbee2MQTT, Tasmota oder ESPHome verzweigen.
- Self-resolving Module wie `Home Assistant Entity` speichern nur stabile Identifikatoren in den Properties. Die eigentliche Entity-Konfiguration wird bei `ApplyChanges()` aus Home Assistant erneut aufgelöst.
- Für normale Lookup- und Fallback-Pfade müssen normalisierte Konfigurations-Entities über `getConfiguredEntities()` bezogen werden.
- Idents werden zentral über `HAIdentNamingTrait` erzeugt. Device-, Entity- und Discovery-Code dürfen keine eigene Benennungspolitik aus `entity_id` oder `object_id` daneben aufbauen.
- Die Ident-Strategie ist instanzlokal und domain-präfixbasiert. Redundante Geräte- oder Instanznamen werden nach Möglichkeit aus dem `object_id` entfernt, damit kurze Idents wie `light_status`, `light_brightness`, `select_power_on_behavior` oder `sensor_last_seen` entstehen.
- Für diese Kürzung werden sowohl der von Home Assistant gelieferte `device_name` als auch der aktuelle Gerätename der Symcon-Instanz berücksichtigt. Falls keine passende Gerätekonfiguration vorliegt, dient dafür auch der echte Symcon-Objektname der Instanz als Redundanzkandidat. Für die Vergleichbarkeit werden solche Namen ASCII-normalisiert, also etwa `Büro` zu `buro`. Ein Präfix wie `backofen_` oder `buro_` soll daher entfallen, wenn es nur den bereits bekannten Instanz- oder Gerätenamen wiederholt.
- `ident` ist die Hauptvariable einer Entity. `ident_prefix` ist die gemeinsame Basis für Attribute, Aktionen und Medienobjekte derselben Entity. Lookup- und Suffix-Logik muss deshalb immer gegen diese zentrale Zuordnung arbeiten.
- Kollisionen werden zentral aufgelöst: zuerst mit einem längeren lokalen Stem, erst danach mit numerischen Suffixen. Die Regel muss in klassischem Device-Pfad und MQTT-Discovery identisch sein.
- Sichtbare Variablennamen werden getrennt von Idents behandelt. Für Hauptvariablen kommt die gemeinsame Routine aus `HAEntityVariableNamingTrait`; Discovery darf hier keine abweichende lokale Namenslogik pflegen.
- `EntityStateCache` wird nur über `HAEntityStoreTrait` gelesen oder geschrieben. Direkte JSON-Zugriffe außerhalb von Bootstrap-Code sollen vermieden werden.
- Neue oder geänderte Textdateien liegen in `UTF-8` ohne BOM mit `LF`-Zeilenenden.
- In menschenlesbaren deutschen Texten werden echte Umlaute verwendet. Transliterationen wie `ae`, `oe` oder `ue` bleiben nur dort zulässig, wo technische Gründe dagegen sprechen, zum Beispiel in Idents, Dateinamen oder ASCII-gebundenen Formaten.
- In menschenlesbaren Texten sind Mojibake- oder sonstige Encoding-Artefakte unzulässig. Deutsche Umlaute und Sonderzeichen sind grundsätzlich korrekt darzustellen.
- Normalisierung kommt vor Domain-Logik. Domain-Code soll nicht erneut Aliase oder Konfigurationsbesonderheiten auflösen.
- Präsentationslogik bleibt möglichst seiteneffektfrei. Variablen- und Medienerzeugung gehören in die Maintenance-Traits.
- Feature- und Schreibbarkeitslogik orientiert sich an den Domain-Definitionen, nicht an verstreuten Literalwerten.
- Echte Sonderfälle bleiben explizit. Wenn eine Domain fachlich anders arbeitet, ist eine kleine spezialisierte Methode besser als ein überdehnter Generic-Helper.
- Modulsichtbare UI-Texte werden lokalisiert. Statische Formular-, Status- und Buttontexte gehören mit englischem Basistext in die jeweilige `locale.json`. Dynamische, für Benutzer sichtbare Texte aus PHP werden über `Translate()` auf dieselben Schlüssel geführt, statt deutschsprachige Literale direkt im Code zu verteilen. Reine Debug-, Trace- und interne Diagnoseausgaben sind davon ausgenommen; sie müssen nicht über `locale.json` und `Translate()` lokalisiert werden.
- Programmdokumentation im Modulcode bleibt knapp und begründet das Warum. Kommentare sind für Hotspots, Invarianten, Lastfallen und bewusst gewählte Abweichungen gedacht; selbsterklärender Ablaufcode bleibt kommentarlos.

## 5. Erweiterungspfad für eine neue Domain

1. Domain-Datei in `libs/Domains` anlegen oder erweitern.
2. Konstanten und Definitionen in `HACommonIncludes.php` und `HADomainCatalog.php` anbinden.
3. Falls nötig Alias- oder Attributnormalisierung in `libs/Config/HAEntityNormalizationTrait` ergänzen.
4. Hauptzustand in `HADomainRegistryTrait` und `HADomainStateHandlersTrait` einhängen.
5. Namen, Präsentation und Wertabbildung in `HAPresentationTrait`, `HADomainValueMappingTrait` und `HAVariableMappingTrait` ergänzen.
6. Zusatzvariablen, Aktionen und Medienobjekte in den Maintenance- oder Special-Action-Traits ergänzen.
7. Manuell prüfen: `ApplyChanges()`, MQTT-State, MQTT-Attribute, `RequestAction()`, `unknown`/`unavailable`, `supported_features`, Namensbildung, Friendly-Name-Verhalten.

## 6. Wartungs-Hotspots

- Configurator und Device müssen dieselben fachlichen Felder gleich interpretieren, insbesondere `name`, `friendly_name`, `device_class`, `supported_features` und `create_var`.
- Teilupdates über MQTT sind ein Hotspot. Attribute dürfen keine Hauptzustände implizit überschreiben, wenn nur unvollständige Daten angekommen sind.
- Zusatzvariablen dürfen nur existieren oder schreibbar sein, wenn Features und Attributlage das wirklich hergeben.
- Medienobjekte und benutzernahe Namen sind regressionsanfällig, weil sie direkt in Symcon sichtbar sind.
- Es gibt aktuell keine versionierten automatisierten Tests. Minimale Absicherung vor Commits: lokaler Lint-/Prüfworkflow und eine gezielte manuelle Prüfrunde gemäß `docs/VERIFIKATION.md`.

### Leitlinie: Zustand und Schreiben

- Zustandsvariablen bleiben fachlich nah an Home Assistant. Kanonische HA-States und Optionen werden als `String` modelliert, auch wenn die Symcon-Präsentation enum-artige Optionen zeigt. `Integer`-Enums sind für lokale Aktionsvariablen gedacht, nicht für den eigentlichen HA-Zustand.
- Lese- und Schreibpfad werden getrennt betrachtet. `mqtt_statestream` ist ein Read-Kanal; geschrieben wird pro Domain über den von Home Assistant fachlich vorgesehenen Pfad, typischerweise per Service-Call. MQTT wird nur als Write-Pfad genutzt, wenn die Domain oder Discovery-Metadaten einen echten Command-Pfad explizit liefern.
- Hauptvariable und Aktionsvariable haben unterschiedliche Aufgaben. Die Hauptvariable bildet den HA-Zustand ab, separate Aktionsvariablen kapseln lokale Bedienlogik und dürfen dafür eigene Integer-Enums verwenden.

## 7. Architektur-Backlog

### MQTT Discovery: DeviceID-only Schnittstelle

Stand:
- Der MQTT Discovery Configurator gibt an das Discovery Device nur noch `DeviceID` und den Symcon-Instanznamen weiter.
- Das Discovery Device wird vollständig self-resolving und lädt Metadaten sowie Entity-Definition ausschließlich über den MQTT Discovery Splitter.
- Doppelte Metadaten in Device-Properties entfallen, damit kein Drift zwischen Configurator, Device und Splitter entstehen kann.

Offene Punkte:
- Formularverhalten, wenn für eine `DeviceID` noch kein Discovery-Cache im Splitter vorhanden ist
- Statusmodell bei fehlendem oder inaktivem Parent
- mögliche Vereinheitlichung mit dem self-resolving Muster von `Home Assistant Entity`

### MQTT Discovery Splitter: Performance-Backlog

Beobachtung:
- Im Live-Betrieb gab es Hinweise auf hohe Last im `Home Assistant MQTT Discovery Splitter` bei starkem MQTT-Traffic.
- Bereits umgesetzt sind gedrosselte Diagnose-Updates und ein Runtime-Cache nur für aktuell referenzierte Topics.
- Weitere Eingriffe werden erst nach Rückmeldung aus dem Live-Test eingeplant.

Geparkte nächste Schritte:
- `TX` aus dem schweren Cache-/Diagnosepfad des Discovery-Splitters herausnehmen
- Cache-Schreibvorgänge nur bei echter Payload-Änderung ausführen
- Kinderverteilung im Splitter weiter eingrenzen statt alle Nachrichten breit weiterzureichen
- Referenzierte Topics und Diagnosestatistik stärker zwischenspeichern
- Volle Diagnose nur bei Bedarf oder Formularnutzung rechnen
- Bei Bedarf einen dedizierten MQTT-Client nur für Discovery empfehlen

### MQTT Discovery Splitter: Bundle-/Simulator-Modus

Zielbild:
- Der `Home Assistant MQTT Discovery Splitter` soll optional ohne echten MQTT-Parent aus einem exportierten Discovery-Bundle arbeiten können.
- Damit sollen `Home Assistant MQTT Discovery Configurator` und `Home Assistant MQTT Discovery Device` während der Entwicklung reproduzierbar gegen gecachte Discovery- und Runtime-Daten laufen.
- Der Bundle-Modus ist ein Entwicklungs- und Analysewerkzeug, kein vollwertiger Ersatz für einen Live-Broker.

Geplanter MVP:
- Interne Quelle `SourceMode = mqtt | bundle` im MQTT Discovery Splitter. Im Formular bleibt standardmäßig nur MQTT sichtbar; Bundle-Modus wird gezielt über öffentliche Funktionen aktiviert.
- Neue Property `BundlePath` für ein zuvor exportiertes Discovery-Bundle.
- Der Bundle-Import erwartet nur das aktuelle Exportformat V2, damit Validierung und Laufzeitpfad schlank bleiben.
- Beim Laden werden `discovery_configs`, `topic_payloads` und referenzierte Topic-Informationen in die bestehenden Splitter-Caches übernommen.
- `GetDiscoveryConfigs` und `GetTopicPayloads` werden im Bundle-Modus direkt aus diesen Caches beantwortet.
- Optionaler Replay-Schritt sendet gecachte Runtime-Topics an Kinder, damit `ApplyChanges()`, Initialwerte und Receive-Filter ohne Live-MQTT testbar bleiben.
- Diagnostics zeigen im Bundle-Modus explizit Quelle, Bundle-Pfad, Export-Zeitpunkt sowie Anzahl Discovery-Configs und Topic-Payloads.

Bewusste Abgrenzung für v1:
- Keine generische Zeitachsen- oder Replay-Engine.
- Keine vollständige Simulation beliebiger `command_topic`-Seiteneffekte.
- Kein Producer-spezifischer Sonderpfad pro Quelle im Discovery Device.
- Schreibvorgänge im Bundle-Modus werden zunächst höchstens protokolliert oder optional verworfen.

Möglicher Ausbau nach dem MVP:
- Einfaches Command-Log für ausgehende `.../set`-Topics.
- Optionale Rückspiegelung einfacher Commands in den Cache, zuerst für robuste Fälle wie `switch` und `light` mit `schema=json`.
- Gezielte Replay-Optionen wie `alle Payloads`, `nur aktuelle Session` oder `nur referenzierte Topics`.

Architekturregel:
- Der Bundle-Modus soll möglichst denselben Splitter-, Configurator- und Device-Pfad nutzen wie der Live-Betrieb.
- Unterschiede zwischen Live-MQTT und Bundle-Datei gehören in die Quellbeschaffung und Cache-Hydrierung des MQTT Discovery Splitters, nicht in Parser, Gruppierung oder das Discovery Device.

Implementierungs-Backlog:
1. Formular und Properties im MQTT Discovery Splitter erweitern
   - interner `SourceMode`, `BundlePath`, optional `BundleCurrentSessionOnly` und `ReplayTopicsOnApply`
   - Bundle-spezifische Diagnostics und Bedienelemente im Formular sichtbar machen
2. Bundle-Datei laden und validieren
   - JSON einlesen, Format prüfen, Mindestfelder absichern
   - Fehlerstatus und Diagnosemeldungen für fehlende oder ungültige Bundle-Dateien definieren
3. Splitter-Caches aus dem Bundle hydrieren
   - `discovery_configs`, `topic_payloads` und referenzierte Topics in die bestehenden Cache-Strukturen überführen
   - Lookup für referenzierte Runtime-Topics daraus neu aufbauen
4. Bundle-Modus in den Laufzeitpfad einziehen
   - `GetDiscoveryConfigs` und `GetTopicPayloads` ohne MQTT-Parent bedienen
   - im Bundle-Modus einen sinnvollen aktiven Status ohne Broker-Verbindung setzen
5. Optionales Replay an Kinder ergänzen
   - gecachte Runtime-Topics kontrolliert an Discovery-Devices weiterreichen
   - Reihenfolge und Filterung bewusst einfach halten, keine Zeitachsen-Simulation

Später, aber nicht Teil des MVP:
- Command-Log für ausgehende `command_topic`-Writes
- einfache Rückspiegelung für `switch` und `light` mit `schema=json`
- feinere Replay-Optionen und eventuell Testhilfen für lokale Entwicklung

### Klassischer Home Assistant Splitter: Performance-Backlog

Beobachtung:
- Auch im klassischen `Home Assistant Splitter` kann hohe Last auftreten, wenn der Parent viel MQTT-Traffic sieht.
- Der Splitter empfängt derzeit breit über `SetReceiveDataFilter('.*')` und reicht `RX`/`TX`-Nachrichten an Kinder weiter.
- Ob hier weiterer Handlungsbedarf besteht, wird erst nach den Discovery-Splitter-Erfahrungen priorisiert.

Geparkte nächste Schritte:
- Breiten Empfang im Splitter gegen den real benötigten Topic-Bereich absichern
- `TX` nur dann an Kinder weiterreichen, wenn dafür ein fachlicher Bedarf besteht
- Broadcast an Kinder weiter eingrenzen statt jede MQTT-Nachricht global durchzureichen
- Diagnose- und Formularupdates auch hier auf mögliche Hotspots prüfen
- Einen dedizierten MQTT-Client mit enger Subscription als Betriebsoption dokumentieren
