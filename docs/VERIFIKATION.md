# Verifikation

Diese Datei beschreibt die minimale Pflichtabsicherung fuer Aenderungen im Repository.
Sie deckt Arbeitspaket `D1` aus `docs/WEITERES_VORGEHEN.md` ab.

## 1. Pflicht vor Release oder groesserem Merge

1. PHP-Syntax ueber alle PHP-Dateien pruefen
2. Betroffene Laufzeitpfade manuell gegen die untenstehende Pruefliste verifizieren
3. Auffaellige Abweichungen im passenden Ticket, Commit oder Testprotokoll notieren

## 2. PHP-Lint

Aus dem Repository-Root:

```powershell
powershell -ExecutionPolicy Bypass -File .\tests\lint-php.ps1
```

Erwartung:

- Das Skript prueft alle `*.php` Dateien rekursiv mit `php -l`
- Der Prozess endet mit Exit-Code `0`, wenn keine Syntaxfehler gefunden wurden
- Bei Fehlern werden die betroffenen Dateien am Ende gesammelt ausgegeben

## 3. Manuelle Pruefliste

Die Pruefliste gilt immer fuer die fachlich betroffenen Module, Domains und Write-Pfade.
Nicht jeder Punkt ist fuer jede Aenderung relevant, aber jeder nicht relevante Punkt muss bewusst ausgeschlossen werden.

Vorlage fuer eine kurze Abnahme-Notiz:

- Kontext: Modul, Domain, Entity, Fixture oder Live-Quelle
- Ergebnis: `ok`, `ok mit Einschraenkung` oder `fehlgeschlagen`
- Notiz: Topic, Attribut oder beobachtete Abweichung

Pflichtpunkte:

- `ApplyChanges()`
  - Parent aktiv und kompatibel
  - relevante Instanz startet ohne Fehler
  - erwartete Variablen, Aktionen und Medienobjekte bleiben stabil
- MQTT-State
  - Hauptzustand wird aus Live- oder Cache-Daten korrekt uebernommen
  - Typabbildung bleibt fachlich korrekt
- MQTT-Attribute
  - relevante Attribute landen in den erwarteten Variablen
  - Teilupdates ueberschreiben den Hauptzustand nicht implizit
- `RequestAction()`
  - schreibbare Variablen senden ueber den vorgesehenen MQTT- oder REST-Pfad
  - Ruecklauf aktualisiert den Zustand ohne Drift
- `unknown` und `unavailable`
  - letzter fachlich gueltiger Wert bleibt erhalten
  - Status oder Diagnose bleibt plausibel
- `supported_features`
  - Aktionen und Zusatzvariablen passen zur Bitmaske
  - nicht unterstuetzte Bedienpfade werden nicht versehentlich schreibbar
- Namensbildung und Friendly Names
  - Instanz-, Variablen- und Attributnamen bleiben stabil
  - kein Drift nach `ApplyChanges()`, Reconnect oder Reimport

## 4. Discovery-spezifische Ergaenzung

Fuer MQTT-Discovery-Aenderungen wird die D1-Pruefung durch die discovery-spezifischen Testprotokolle ergaenzt:

- `docs/MQTT_DISCOVERY_A3_TESTPLAN.md`
- `docs/fixtures/README.md`

## 5. Abschlusskriterium fuer D1

`D1` ist fuer eine Aenderung nur dann erfuellt, wenn:

- `tests/lint-php.ps1` erfolgreich lief
- die betroffenen Punkte der manuellen Pruefliste abgearbeitet wurden
- Ergebnis und Einschraenkungen nachvollziehbar notiert sind
