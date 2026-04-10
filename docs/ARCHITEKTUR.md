# Architektur

Diese Datei ist eine interne Wartungsdoku. Sie beschreibt die Struktur des Moduls, die Verantwortung der Kernbausteine und die Leitplanken für Erweiterungen.

## 1. Modulgrenzen

- `Home Assistant Discovery`
  Sucht Home-Assistant-Instanzen per mDNS und legt bei Bedarf Configurator-Instanzen an.
- `Home Assistant Configurator`
  Liest Geräte- und Entity-Daten aus Home Assistant, gruppiert sie zu Symcon-Geräten und erzeugt daraus `DeviceConfig`.
- `Home Assistant Splitter`
  Ist der zentrale Transportknoten. Er verteilt MQTT-Nachrichten an Kinder und kapselt REST- sowie Bildabrufe.
- `Home Assistant Device`
  Ist das eigentliche Laufzeitmodell eines Home-Assistant-Geräts. Hier entstehen Variablen, Medienobjekte, Aktionen, Präsentationen und Domain-spezifische Logik.

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

- `HAEntityNormalizationTrait`
  Normalisiert Konfigurations- und Laufzeitdaten, pflegt Alias-Mappings und liefert gefilterte Konfigurations-Entities.
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
- `libs/Domains/*Definitions.php`
  Sind die fachliche Quelle für Domain-Konstanten, Features, Zustände und Attributdefinitionen.

## 4. Architekturregeln

- `DeviceConfig` als Roh-JSON darf nur dort direkt gelesen werden, wo die unveränderte Konfiguration gebraucht wird, zum Beispiel in `ApplyChanges()` oder beim Formularaufbau.
- Für normale Lookup- und Fallback-Pfade müssen normalisierte Konfigurations-Entities über `getConfiguredEntities()` bezogen werden.
- `EntityStateCache` wird nur über `HAEntityStoreTrait` gelesen oder geschrieben. Direkte JSON-Zugriffe außerhalb von Bootstrap-Code sollen vermieden werden.
- Normalisierung kommt vor Domain-Logik. Domain-Code soll nicht erneut Aliase oder Konfigurationsbesonderheiten auflösen.
- Präsentationslogik bleibt möglichst seiteneffektfrei. Variablen- und Medienerzeugung gehört in die Maintenance-Traits.
- Feature- und Schreibbarkeitslogik orientiert sich an den Domain-Definitionen, nicht an verstreuten Literalwerten.
- Echte Sonderfälle bleiben explizit. Wenn eine Domain fachlich anders arbeitet, ist eine kleine spezialisierte Methode besser als ein überdehnter Generic-Helper.

## 5. Erweiterungspfad für eine neue Domain

1. Domain-Datei in `libs/Domains` anlegen oder erweitern.
2. Konstanten und Definitionen in `HACommonIncludes.php` und `HADomainCatalog.php` anbinden.
3. Falls nötig Alias- oder Attributnormalisierung in `HAEntityNormalizationTrait` ergänzen.
4. Hauptzustand in `HADomainRegistryTrait` und `HADomainStateHandlersTrait` einhängen.
5. Namen, Präsentation und Wertabbildung in `HAPresentationTrait`, `HADomainValueMappingTrait` und `HAVariableMappingTrait` ergänzen.
6. Zusatzvariablen, Aktionen und Medienobjekte in den Maintenance- oder Special-Action-Traits ergänzen.
7. Manuell prüfen: `ApplyChanges()`, MQTT-State, MQTT-Attribute, `RequestAction()`, `unknown`/`unavailable`, `supported_features`, Namensbildung, Friendly-Name-Verhalten.

## 6. Wartungs-Hotspots

- Configurator und Device müssen dieselben fachlichen Felder gleich interpretieren, insbesondere `name`, `friendly_name`, `device_class`, `supported_features` und `create_var`.
- Teilupdates über MQTT sind ein Hotspot. Attribute dürfen keine Hauptzustände implizit überschreiben, wenn nur unvollständige Daten angekommen sind.
- Zusatzvariablen dürfen nur existieren oder schreibbar sein, wenn Features und Attributlage das wirklich hergeben.
- Medienobjekte und benutzernahe Namen sind regressionsanfällig, weil sie direkt in Symcon sichtbar sind.
- Es gibt aktuell keine automatisierten Tests. Minimale Absicherung vor Commits: `php -l` über alle PHP-Dateien und eine gezielte manuelle Prüfrunde der betroffenen Domains.
