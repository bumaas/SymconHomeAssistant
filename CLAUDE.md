# SymconHomeAssistant — Projekt-Hinweise

IP-Symcon-Bibliothek (8 Module, alle `IPSModuleStrict`) zur Anbindung von Home Assistant.
Architektur-Details: `docs/ARCHITEKTUR.md`.

## Modul-Familien

- **Klassische Bridge** (bestehende HA-Installation → Symcon):
  `Home Assistant Splitter` (MQTT-statestream-Empfang + REST-Zugang zu HA),
  `Home Assistant Configurator`, `Home Assistant Device`, `Home Assistant Entity`,
  `Home Assistant Discovery`.
- **MQTT Discovery** (Geräte direkt per MQTT, ohne HA-Server):
  `Home Assistant MQTT Discovery Splitter` / `... Configurator` / `... Device`.

## Datenfluss (klassische Bridge)

- Lesen: HA `mqtt_statestream` → MQTT Client/Server → Splitter → Device/Entity-Kinder.
  statestream publiziert **nur bei Zustandsänderung** (retained, QoS 1) — selten wechselnde
  Entitäten kommen daher ggf. nie per MQTT an.
- Initiale Werte: REST `/api/states/<entity_id>` bei `ApplyChanges` (KR_READY,
  Parent-Statuswechsel, manuelles Anwenden) über den Splitter.
- Schreiben: pro Domain über den fachlich vorgesehenen HA-Pfad (i. d. R. REST-Service-Call);
  MQTT nur, wenn ein echter Command-Pfad existiert. `*/set`-Topics behandelt der Splitter
  selbst per REST.

## libs/

Gemeinsame Traits/Klassen; `libs/HACommonIncludes.php` bindet alles ein und wird von allen
Modulen außer `Home Assistant Discovery` verwendet. Unterordner: `Domains/` (eine
Definitionsklasse je HA-Domäne), `Device/` (Laufzeitlogik der Device-Module), `Config/`,
`Discovery/` (MQTT-Discovery-Parser/Runtime).

## Übersetzungen

- Englische `form.json`-Texte sind zugleich Übersetzungsschlüssel; `locale.json` (de) je Modul.
- `Translate()`-Texte aus `libs/`-Traits müssen in der `locale.json` jedes Moduls stehen,
  das sie zur Laufzeit ausgibt.
- Prüfung: `php tests/check_locale.php` (läuft auch in der CI; libs-Texte werden nur als
  Hinweis gemeldet, wenn sie in keiner locale.json vorkommen).

## Tests

`tests/` ist bis auf `check_locale.php` gitignored (Fixtures können private Gerätedaten
enthalten). Lokale Laufzeit-Checks: `php tests/check-*.php` (eigenständige Skripte mit
IPS-Stubs, kein PHPUnit).

## CI / Version

- CI: `.github/workflows/check.yml` — `php -l`, JSON-Validität, Locale-Check.
- Version/Build/Datum in `library.json`; Konvention (Build +1, Unix-Timestamp,
  Commit-Subject `<version> build <NN>: <Beschreibung>`) siehe globale CLAUDE.md.