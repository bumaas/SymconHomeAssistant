# Verifikation

Diese Datei beschreibt die minimale Pflichtabsicherung für Änderungen im Repository.
Sie deckt Arbeitspaket `D1` aus `docs/WEITERES_VORGEHEN.md` ab.

## 1. Pflicht vor Release oder größerem Merge

1. PHP-Syntax über alle PHP-Dateien prüfen
2. Betroffene Laufzeitpfade manuell gegen die untenstehende Prüfliste verifizieren
3. Auffällige Abweichungen im passenden Ticket, Commit oder Testprotokoll notieren

## 2. PHP-Lint

Aus dem Repository-Root:

```powershell
lokaler Lint-/Prüfworkflow aus dem unversionierten Testbereich
```

Erwartung:

- Der lokale Lint-Workflow prüft alle `*.php` Dateien rekursiv mit `php -l`
- Der Prozess endet mit Exit-Code `0`, wenn keine Syntaxfehler gefunden wurden
- Bei Fehlern werden die betroffenen Dateien am Ende gesammelt ausgegeben

## 3. Manuelle Prüfliste

Die Prüfliste gilt immer für die fachlich betroffenen Module, Domains und Write-Pfade.
Nicht jeder Punkt ist für jede Änderung relevant, aber jeder nicht relevante Punkt muss bewusst ausgeschlossen werden.

Vorlage für eine kurze Abnahme-Notiz:

- Kontext: Modul, Domain, Entity, Fixture oder Live-Quelle
- Ergebnis: `ok`, `ok mit Einschränkung` oder `fehlgeschlagen`
- Notiz: Topic, Attribut oder beobachtete Abweichung

Pflichtpunkte:

- `ApplyChanges()`
  - Parent aktiv und kompatibel
  - relevante Instanz startet ohne Fehler
  - erwartete Variablen, Aktionen und Medienobjekte bleiben stabil
- MQTT-State
  - Hauptzustand wird aus Live- oder Cache-Daten korrekt übernommen
  - Typabbildung bleibt fachlich korrekt
- MQTT-Attribute
  - relevante Attribute landen in den erwarteten Variablen
  - Teilupdates überschreiben den Hauptzustand nicht implizit
- `RequestAction()`
  - schreibbare Variablen senden über den vorgesehenen MQTT- oder REST-Pfad
  - Rücklauf aktualisiert den Zustand ohne Drift
- `unknown` und `unavailable`
  - letzter fachlich gültiger Wert bleibt erhalten
  - Status oder Diagnose bleibt plausibel
- `supported_features`
  - Aktionen und Zusatzvariablen passen zur Bitmaske
  - nicht unterstützte Bedienpfade werden nicht versehentlich schreibbar
- Namensbildung und Friendly Names
  - Instanz-, Variablen- und Attributnamen bleiben stabil
  - kein Drift nach `ApplyChanges()`, Reconnect oder Reimport

## 4. Discovery-spezifische Ergänzung

Für MQTT-Discovery-Änderungen wird die D1-Prüfung durch die discovery-spezifischen Testprotokolle ergänzt:

- `docs/MQTT_DISCOVERY_A3_TESTPLAN.md`
- lokale Fixture-Doku aus dem unversionierten Testbereich

## 5. Abschlusskriterium für D1

`D1` ist für eine Änderung nur dann erfüllt, wenn:

- der lokale Lint-/Prüfworkflow erfolgreich lief
- die betroffenen Punkte der manuellen Prüfliste abgearbeitet wurden
- Ergebnis und Einschränkungen nachvollziehbar notiert sind
