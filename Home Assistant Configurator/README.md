[![Version](https://img.shields.io/badge/Symcon%20Version-9.0%20%3E-green.svg)](https://www.symcon.de/forum/threads/30857-IP-Symcon-5-1-%28Stable%29-Changelog)
# Home Assistant Configurator

Lädt Geräte und Entitäten aus einer bestehenden Home-Assistant-Installation und legt daraus Device- oder Entity-Instanzen in Symcon an.

> Teil des Projekts **Home Assistant für IP-Symcon** — [Gesamtübersicht, Betriebsarten und Fehlersuche](../README.md).

## Dokumentation

**Inhaltsverzeichnis**

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Funktionsreferenz](#4-funktionsreferenz)
5. [Konfiguration](#5-konfiguration)
6. [Statusvariablen und Profile](#6-statusvariablen-und-profile)
7. [Anhang](#7-anhang)

## 1. Funktionsumfang

- Lädt Entitäten und Gerätemetadaten per REST aus einer bestehenden Home-Assistant-Installation.
- Gruppiert Geräte nach Bereich.
- Erzeugt Device- oder Entity-Instanzen inklusive stabiler Create-Konfiguration.
- Zeigt zusätzlich eine Diagnose-Summary für nicht unterstützte oder parserseitig übersprungene Entitäten an.

## 2. Voraussetzungen

- Verbunden mit einem Home Assistant Splitter als Parent.
- Bestehende Home-Assistant-Installation als Quelle.
- `HAUrl` und `HAToken` im Splitter gesetzt.

## 3. Installation

- In Symcon `Instanz hinzufügen` und `Home Assistant Configurator` auswählen.

## 4. Funktionsreferenz

Keine öffentlichen Funktionen.

## 5. Konfiguration

- Standard: alle unterstützten Domains werden geladen.
- `EnableDomainFilter`: aktiviert optional einen expliziten Domain-Filter.
- `IncludeDomains`: Liste der erlaubten Domains. Ist `EnableDomainFilter` aktiv und die Liste leer, werden keine Entitäten geladen.
- `EnableExpertDebug`: aktiviert zusätzliche Debug-Ausgaben.

## Hinweis

- Der Configurator führt im Symcon-`create`-Block nur noch stabile Strukturattribute.
- Flüchtige Live-Werte und Prognosedaten werden dort bewusst nicht gespiegelt.
- Device-Instanzen werden im `create`-Block nur noch mit ihrer stabilen `DeviceID` angelegt; die Entity-Liste löst das Device später selbst zur Laufzeit auf.
- Entity-Instanzen werden mit ihrer `EntityID` angelegt und lösen ihre Laufzeitkonfiguration ebenfalls selbst.

## 6. Statusvariablen und Profile

Keine.

## 7. Anhang

### Ablauf

1. Configurator öffnen, Entitäten werden per REST geladen.
2. Geräte nach Bereich sortieren.
3. Gewünschte Geräte oder Entitäten anlegen.

### Spenden

Die Nutzung des Moduls ist kostenfrei. Niemand sollte sich verpflichtet fühlen, aber wenn das Modul gefällt, dann freue ich mich über eine Spende.

<a href="https://www.paypal.me/bumaas" target="_blank"><img src="https://www.paypalobjects.com/de_DE/DE/i/btn/btn_donate_LG.gif" border="0" /></a>
