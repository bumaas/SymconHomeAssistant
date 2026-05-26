[![Version](https://img.shields.io/badge/Symcon%20Version-9.0%20%3E-green.svg)](https://www.symcon.de/forum/threads/30857-IP-Symcon-5-1-%28Stable%29-Changelog)
# Home Assistant Entity

Stellt genau eine Entität aus einer bestehenden Home-Assistant-Installation in Symcon dar, unabhängig davon, ob sie einem Gerät zugeordnet ist oder nicht, zum Beispiel Helper, Gruppen oder Wetter-Entitäten.

## Dokumentation

Interne Wartungsdoku: [Architektur](../docs/ARCHITEKTUR.md)

**Inhaltsverzeichnis**

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Funktionsreferenz](#4-funktionsreferenz)
5. [Konfiguration](#5-konfiguration)
6. [Variablen und Medienobjekte](#6-variablen-und-medienobjekte)
7. [Domain-spezifisches Verhalten](#7-domain-spezifisches-verhalten)
8. [Home Assistant mqtt_statestream](#8-home-assistant-mqtt_statestream)

## 1. Funktionsumfang

- Gehört zum klassischen Bridge-Pfad und übernimmt eine vorhandene Home-Assistant-Entität nach Symcon.
- Legt die Hauptvariable der Entität an und abonniert deren MQTT-Topics.
- Schreibt Werte aus `state`- und Attribut-Topics in Symcon-Variablen.
- Sendet Steuerbefehle an `*/set`-Topics oder, falls vorgesehen, per REST über den Splitter.
- Pflegt Präsentationen, Optionen, Schreibbarkeit und Zusatzvariablen je Domain.
- Erzeugt bei Bedarf Medienobjekte für Kamera-, Image- und Media-Player-Vorschauen.

## 2. Voraussetzungen

- Parent: Home Assistant Splitter.
- Bestehende Home-Assistant-Installation als Quelle der Entität.
- `EntityID` wird vom Configurator gesetzt oder manuell gepflegt.
- Home Assistant `mqtt_statestream` ist aktiv.

## 3. Installation

- In Symcon `Instanz hinzufügen` und `Home Assistant Entity` auswählen.
- Konfiguration über den Configurator oder manuell setzen.

## 4. Funktionsreferenz

Keine öffentlichen Funktionen.

## 5. Konfiguration

- `EntityID`
  Die vollständige Home-Assistant-ID der Entität, zum Beispiel `light.living_room`.
  Die konkrete Laufzeitkonfiguration wird bei `ApplyChanges()` automatisch über diese `EntityID` aus Home Assistant aufgelöst.
- `EnableExpertDebug`
  Aktiviert zusätzliche Debug-Ausgaben.
- `ShowUnavailableEntitiesJson`
  Blendet optional die Expertenvariable `Unavailable entities JSON` ein.
- `OutputBufferSize`
  Erhöht bei Bedarf den Ausgabepuffer für Bilddownloads über den Splitter.

## 6. Variablen und Medienobjekte

- Es wird die Hauptvariable für die Entität angelegt.
- Je nach Domain kommen Zusatzvariablen hinzu, zum Beispiel `Power`, `Aktion`, `Lüfterstufe`, `Playback` oder `Event Type`.
- Namen orientieren sich an `name`, `friendly_name` und, falls vorgesehen, an der `device_class`.
- Details zu den Domains siehe `Home Assistant Device`.

## 7. Domain-spezifisches Verhalten

Verhält sich identisch zum `Home Assistant Device`, ist jedoch auf genau eine Entität beschränkt. Siehe dort für Details zu den einzelnen Domains.

## 8. Home Assistant mqtt_statestream

Siehe Haupt-Dokumentation.

### Spenden

Die Nutzung des Moduls ist kostenfrei. Niemand sollte sich verpflichtet fühlen, aber wenn das Modul gefällt, dann freue ich mich über eine Spende.

<a href="https://www.paypal.me/bumaas" target="_blank"><img src="https://www.paypalobjects.com/de_DE/DE/i/btn/btn_donate_LG.gif" border="0" /></a>
