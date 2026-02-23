[![Version](https://img.shields.io/badge/Symcon%20Version-8.2%20%3E-green.svg)](https://www.symcon.de/forum/threads/30857-IP-Symcon-5-1-%28Stable%29-Changelog)
# Home Assistant Discovery

Findet Home Assistant Instanzen im Netzwerk per mDNS (Service `_home-assistant._tcp`) und erstellt daraus Configurator-Instanzen.

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

- mDNS/DNS-SD Suche nach Home Assistant Instanzen.
- Erzeugt Configurator-Instanzen aus gefundenen Einträgen.

## 2. Voraussetzungen

- DNS-SD Control (mDNS) in Symcon.
- Home Assistant im selben Netzwerk, mDNS aktiv.

## 3. Installation

- In Symcon `Instanz hinzufügen` und `Home Assistant Discovery` auswählen.

## 4. Funktionsreferenz

Keine öffentlichen Funktionen.

## 5. Konfiguration

- Keine Pflichtfelder.
- Optional: `EnableExpertDebug` für erweiterte Debug-Ausgaben.

## 6. Statusvariablen und Profile

Keine.

## 7. Anhang

### Ablauf

1. Instanz anlegen.
2. Discovery läuft automatisch (kurzer Timer nach dem Öffnen der Konfiguration).
3. Gefundene Instanzen werden angezeigt und können als Configurator erstellt werden.
