# Architektur

Diese Datei ist eine interne Wartungsdoku. Sie beschreibt die Struktur des Moduls, die Verantwortung der Kernbausteine und die Leitplanken für Erweiterungen.

## 1. Modulgrenzen

- `Home Assistant Discovery`
  Sucht Home-Assistant-Instanzen per mDNS und legt bei Bedarf Configurator-Instanzen an.
- `Home Assistant Configurator`
  Liest Geräte- und Entity-Daten aus Home Assistant, gruppiert sie zu Symcon-Geräten und erzeugt daraus `DeviceConfig`.
  Für den Symcon-`create`-Block wird dabei nur eine stabile CreateConfig mit strukturellen Attributen erzeugt, damit volatile Live-Daten keine neuen Configurator-Einträge vortäuschen.
- `Home Assistant MQTT Discovery Splitter`
  Ist der Transportknoten fuer `homeassistant/.../config` Topics. Er cached MQTT-Discovery-Payloads, reicht MQTT an Kinder weiter und stellt den Cache fuer Discovery-Module bereit.
- `Home Assistant MQTT Discovery Configurator`
  Liest gecachte MQTT-Discovery-Configs aus dem MQTT Discovery Splitter, parst die Payloads in eine interne Transportstruktur und gruppiert sie zu Geraetekandidaten fuer Zigbee2MQTT und aehnliche Discovery-Quellen.
- `Home Assistant MQTT Discovery Device`
  Ist der schlanke Laufzeitpfad fuer MQTT-Discovery-Geraete. Er loest seine Entities ueber `DeviceID` aus dem MQTT Discovery Splitter auf, cached die aufgeloeste Device-Definition intern und verarbeitet `state_topic`, `command_topic` sowie `availability`.
- `libs/Config`
  Enthält die gemeinsame Aufbereitungsschicht für Configurator und spätere self-resolving Module. Loader lädt Rohdaten aus HA, Builder normalisiert die Entity-Konfiguration und Grouping bündelt Entities für die Geräteansicht.
- `libs/Discovery`
  Enthaelt die vorbereitende MQTT-Discovery-Schicht. Parser und Grouping normalisieren `homeassistant/.../config` Topics in eine transportfeste interne Struktur, ohne den bestehenden Statestream-Pfad zu vermischen.
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
  Reduziert die fuer v1 unterstuetzten MQTT-Discovery-Templates auf eine kleine interne Struktur, damit Runtime-Code keine freien Jinja-Ausdruecke auswerten muss.
- `libs/Discovery/HAMqttDiscoveryParser`
  Uebersetzt einzelne `homeassistant/.../config` Payloads in normalisierte Discovery-Entities mit expliziten Topics, Availability und Schreibmetadaten.
- `libs/Discovery/HAMqttDiscoveryGrouping`
  Gruppiert normalisierte Discovery-Entities ueber `device.identifiers` zu Symcon-Geraetekandidaten und baut daraus eine stabile Discovery-Device-Definition fuer Configurator und Laufzeitmodul.
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
- `EntityStateCache` wird nur über `HAEntityStoreTrait` gelesen oder geschrieben. Direkte JSON-Zugriffe außerhalb von Bootstrap-Code sollen vermieden werden.
- Neue oder geänderte Textdateien liegen in `UTF-8` ohne BOM mit `LF`-Zeilenenden.
- In menschenlesbaren deutschen Texten werden echte Umlaute verwendet. Transliterationen wie `ae`, `oe` oder `ue` bleiben nur dort zulässig, wo technische Gründe dagegen sprechen, zum Beispiel in Idents, Dateinamen oder ASCII-gebundenen Formaten.
- Normalisierung kommt vor Domain-Logik. Domain-Code soll nicht erneut Aliase oder Konfigurationsbesonderheiten auflösen.
- Präsentationslogik bleibt möglichst seiteneffektfrei. Variablen- und Medienerzeugung gehören in die Maintenance-Traits.
- Feature- und Schreibbarkeitslogik orientiert sich an den Domain-Definitionen, nicht an verstreuten Literalwerten.
- Echte Sonderfälle bleiben explizit. Wenn eine Domain fachlich anders arbeitet, ist eine kleine spezialisierte Methode besser als ein überdehnter Generic-Helper.

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
- Es gibt aktuell keine versionierten automatisierten Tests. Minimale Absicherung vor Commits: lokaler Lint-/Pruefworkflow und eine gezielte manuelle Pruefrunde gemaess `docs/VERIFIKATION.md`.

### Leitlinie: Zustand und Schreiben

- Zustandsvariablen bleiben fachlich nah an Home Assistant. Kanonische HA-States und Optionen werden als `String` modelliert, auch wenn die Symcon-PrÃ¤sentation enum-artige Optionen zeigt. `Integer`-Enums sind fÃ¼r lokale Aktionsvariablen gedacht, nicht fÃ¼r den eigentlichen HA-Zustand.
- Lese- und Schreibpfad werden getrennt betrachtet. `mqtt_statestream` ist ein Read-Kanal; geschrieben wird pro Domain Ã¼ber den von Home Assistant fachlich vorgesehenen Pfad, typischerweise per Service-Call. MQTT wird nur als Write-Pfad genutzt, wenn die Domain oder Discovery-Metadaten einen echten Command-Pfad explizit liefern.
- Hauptvariable und Aktionsvariable haben unterschiedliche Aufgaben. Die Hauptvariable bildet den HA-Zustand ab, separate Aktionsvariablen kapseln lokale Bedienlogik und dÃ¼rfen dafÃ¼r eigene Integer-Enums verwenden.

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
