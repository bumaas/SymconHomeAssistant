# Home Assistant Configurator

Listet Home Assistant Geräte und Entitäten und legt daraus Device Instanzen in Symcon an.

## Voraussetzungen

- Verbunden mit einem Home Assistant Splitter als Parent.
- `HAUrl` und `HAToken` im Splitter gesetzt (REST Zugriff auf `/api/template`).

## Konfiguration

- `IncludeDomains`: Liste der erlaubten Domains (z.B. `light`, `switch`, `sensor`).
- Optional: `EnableExpertDebug`.

## Ablauf

1. Configurator öffnen, die Entitäten werden über REST geladen.
2. Geräte nach Bereich sortiert anzeigen lassen.
3. Gewünschte Geräte anlegen (Device Instanzen).



