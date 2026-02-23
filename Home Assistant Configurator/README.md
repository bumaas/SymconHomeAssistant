[![Version](https://img.shields.io/badge/Symcon%20Version-8.2%20%3E-green.svg)](https://www.symcon.de/forum/threads/30857-IP-Symcon-5-1-%28Stable%29-Changelog)
# Home Assistant Configurator

Listet Home Assistant Geräte und Entitäten und legt daraus Device-Instanzen in Symcon an.

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

- Lädt Entitäten per REST.
- Gruppiert Geräte nach Bereich.
- Erzeugt Device-Instanzen inkl. Konfiguration.

## 2. Voraussetzungen

- Verbunden mit einem Home Assistant Splitter als Parent.
- `HAUrl` und `HAToken` im Splitter gesetzt (REST Zugriff auf `/api/template`).

## 3. Installation

- In Symcon `Instanz hinzufügen` und `Home Assistant Configurator` auswählen.

## 4. Funktionsreferenz

Keine öffentlichen Funktionen.

## 5. Konfiguration

- `IncludeDomains`: Liste der erlaubten Domains (z.B. `light`, `switch`, `sensor`, `media_player`).
- `Statusvariablen automatisch anlegen`: legt Statusvariablen bei neu erzeugten Devices automatisch an.
- Optional: `EnableExpertDebug`.

## 6. Statusvariablen und Profile

Keine.

## 7. Anhang

### Ablauf

1. Configurator öffnen, Entitäten werden per REST geladen.
2. Geräte nach Bereich sortieren.
3. Gewünschte Geräte anlegen (Device-Instanzen).
