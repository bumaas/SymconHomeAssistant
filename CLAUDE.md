# SymconHomeAssistant — Projekt-Hinweise

Symcon-Bibliothek (8 Module, alle `IPSModuleStrict`) zur Anbindung von Home Assistant.
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

Alle Check-Skripte (`tests/check*.php`) sind versioniert; Fixtures nur nach
Einzelprüfung auf private Gerätedaten (Freischaltung per `.gitignore`-Ausnahme,
Details in `tests/fixtures/README.md`). Laufzeit-Checks: `php tests/check-*.php`
(eigenständige Skripte mit eigenen IPS-Attrappen, kein PHPUnit).

- Jeder Laufzeit-Check bindet `tests/harness.php` ein: Warnings/Notices werden zu Fehlern,
  `pruefe()` zählt jede Prüfung, `ergebnis()` schreibt die Schlusszeile
  „N Prüfungen, M Fehler" (Exit 0/1) — das Format liest `rotgruen.php` für den
  Rot/Grün-Nachweis. Neue Tests genauso aufbauen.
- Hilfsskripte, die keine Tests sind (z. B. Fixture-Extraktion), gehören nach `tools/`
  (nicht versioniert), nicht nach `tests/`.
- Offen: Umstellung auf den offiziellen Kernel-Stub und Ersatz von Logik-Kopien durch
  Modulaufrufe — beim nächsten Anfassen des jeweiligen Tests, nicht als Sammelaktion.

## CI / Version

- CI: `.github/workflows/check.yml` (PHP 8.5, Checkout mit Submodulen) — Code-Stil mit
  php-cs-fixer gegen das Regelwerk im Submodul `.style` (`--dry-run`), `php -l` auf alle
  `*.php`, JSON-Validität, Locale-Check und `tests/check_property_contracts.php` (jede von
  `libs/Device/HADeviceCore.php` gelesene Property muss in Device- und Entity-Modul
  registriert sein), `tests/check_presentations.php` (nur gültige Darstellungsparameter) und
  alle Laufzeit-Checks `tests/check-*.php` (Glob, neue Tests laufen automatisch mit;
  dazu gehört auch die Doku-Sperrklinke `tests/check-readme.php`).
- Version/Build: siehe globale CLAUDE.md, Abschnitt „Symcon: Build-/Versionspflege in Modul-Repos".