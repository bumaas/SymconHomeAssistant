# Home Assistant Discovery

Findet Home Assistant Instanzen im Netzwerk per mDNS (Service `_home-assistant._tcp`) und erstellt daraus Configurator Instanzen.

## Voraussetzungen

- DNS-SD Control (mDNS) in Symcon
- Home Assistant im selben Netzwerk, mDNS aktiv

## Konfiguration

- Keine Pflichtfelder.
- Optional: `EnableExpertDebug` für erweiterte Debug-Ausgaben.

## Ablauf

1. Instanz anlegen.
2. Discovery läuft automatisch (kurzer Timer nach dem öffnen der Konfiguration).
3. Gefundene Instanzen werden angezeigt und können als Configurator erstellt werden.



