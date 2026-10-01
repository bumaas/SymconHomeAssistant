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

## Erreichbarkeit (Device/Entity, seit 1.5)

- `unavailable`/`unknown` werden nie in die Wertvariablen geschrieben — die behalten den
  letzten echten Wert. Ob ein Gerät noch liefert, zeigt allein die Bool-Variable `reachable`
  (`HAEntityStoreTrait::evaluateReachability`): false, wenn **alle** Entitäten mit
  State-Cache-Eintrag `raw_state = unavailable` tragen, seit mindestens
  `REACHABILITY_DELAY_S` (600 s). Kein Instanzstatus, kein Ausnahmeschalter (bewusst,
  Abstimmung 30.09.2026).
- Die 600 s sind gemessen, nicht geschätzt: Beim nächtlichen HA-Neustart gehen Geräte der
  HA-Homematic- und der Sonos-Integration bis 218 s komplett auf `unavailable`.
- Heißer Pfad: `markReachabilityDirty()` liest nur den Buffer `ReachabilitySince`
  (`'0'` = erreichbar); ausgewertet wird gebündelt im StateCacheFlush bzw. per
  `ReachabilityTimer`. Der Beginn des Ausfalls liegt nur im Buffer — ein Kernel-Neustart
  startet die Entprellung neu (gewollt).
- Die Entprellung gilt nur für den Weg erreichbar → nicht erreichbar. Steht `reachable` schon
  auf false, bleibt es dabei, bis eine gültige Meldung kommt — auch wenn der Buffer leer ist.
  Vorher sprang jedes tote Gerät nach Neuladen/Kernel-Neustart für 600 s auf „erreichbar"
  (Blindtest 01.10.2026 am nuc: Backofen, WLED, MYGGSPRAY) — ein Ereignis auf der Variable
  meldete so bei jedem Start Erholung und zehn Minuten später Ausfall.
- Deshalb setzt `maintainReachableVariable()` eine **neu angelegte** Variable auf true (build 175):
  Mit dem Standardwert false gälte sie als „schon nicht erreichbar", und eine Instanz, die während
  des HA-Neustarts angelegt oder auf 1.5 gehoben wird, übersprünge die Entprellung.
- Bekannte Lücke: Mit `EmulateStatus` überschreibt ein optimistischer Schreibvorgang
  (`applyOptimisticEntityValue`) den `raw_state` einer toten Entität; das Gerät gilt dann bis
  zur nächsten echten `unavailable`-Meldung als erreichbar.
- Test: `tests/check-reachability.php` mit echten HA-Recorder-Verläufen unter
  `tests/fixtures/reachability/` (Extraktion: `tools/fixture_reachability.php`, nicht versioniert).
- Der `ReachabilityTimer` wird nur über `setReachabilityTimerInterval()` gestellt (mit `@`):
  Beim Neuladen des Moduls läuft `DeferredApply → ApplyChanges` noch in den alten Instanzen,
  aber schon mit dem neuen Code — ein in `Create()` neu registrierter Timer existiert dort nicht
  (30.09.2026 am nuc: 27 von 39 Instanzen „Timer ReachabilityTimer does not exist").
  **Gilt für jeden künftig neuen Timer:** Code, der ihn außerhalb von `Create()` stellt, muss
  die Bestandsinstanz im Reload-Fenster aushalten.

## Reload-Fenster (Neuladen der Bibliothek, Modul-Update)

- Beim Neuladen treffen Meldungen und Timer Instanzen, deren `Create()` gerade läuft:
  Properties und Attribute sind dann nicht registriert, `ReadAttributeString` liefert false
  (Rust-Edition, nuc 01.10.2026 15:37–15:41: `trim(false)`, `sprintf(false, …)` als Fatals).
- **Außerhalb von `Create()` nie `Register*` aufrufen.** Bis build 168 registrierte der heiße
  Pfad ResolvedConfig selbst, wenn das Lesen false lieferte („Bestandsinstanz ohne Attribut",
  seit build 139 überflüssig, weil jeder Kernel-Start `Create()` ruft) und schrieb `[]` hinein —
  das folgende `Create()` scheiterte an „already registered", Instanz #36479 blieb auf 105.
- **Jeder Einstiegspunkt prüft zuerst `isInstanceCreated()`** (seit build 172): `ReceiveData`,
  `RequestAction` (also auch alle Timer), `UpdateMediaPlayerProgress`, `SyncStates`,
  `ApplyChanges`. Im Fenster tun sie nichts — kein Lesen, kein Schreiben, kein Auswerten.
  Bis build 171 war nur ResolvedConfig geschützt: `EntityStateCache`, `LastMQTTMessage` und
  `MQTTBaseTopic` liefen weiter ungeschützt, und die Erreichbarkeitsprüfung las eine leere
  Konfiguration als „keine Entität gesehen" — ein totes Gerät sprang auf erreichbar.
- Merkmal ist ResolvedConfig, **deshalb registriert `Create()` es als Letztes** (nach Properties
  und Timern): Ist es da, ist alles da. Neue Registrierungen gehören davor. Der gelesene Inhalt
  bleibt im Memo, die Prüfung kostet im heißen Pfad keinen zusätzlichen Kernel-Aufruf.
- **Splitter** (seit build 178) genauso über eigenes `isInstanceCreated()`: Merkmal ist die
  Property `MQTTBaseTopic`, die `Create()` als Letztes registriert; der Wert bleibt im Memo
  (`getBaseTopicProperty()`), jede Meldung liest ihn ohnehin. Bookkeeping-Topics kehren vor der
  Prüfung zurück und lesen bis dahin nur mit `@`. Anlass: Reload am nuc 01.10.2026 17:39:42,
  `trim(false)` in `recordSeenDomain`.
- **MQTT Discovery** (seit build 179) nach demselben Muster: Splitter mit Merkmal Property
  `SourceMode` (jede Meldung liest es über `isBundleMode`), Device mit Merkmal Attribut
  `TopicProcessingIndex` (jede Meldung liest es in `getRuntimeProcessingContext`). Beide werden in
  `Create()` als Letztes registriert. **Jedes Merkmal-Memo verwirft `ApplyChanges` vorher**
  (`ActivateBundleMode` ändert `SourceMode` und ruft `IPS_ApplyChanges`), das Device zusätzlich
  `writeTopicProcessingIndex()`.
- **Regel für neue Module/Registrierungen:** Das Merkmal bleibt die letzte Registrierung in
  `Create()`; ein neuer Einstiegspunkt (öffentliche Funktion, Timer-Ziel, `MessageSink`-Zweig)
  prüft zuerst `isInstanceCreated()`.
- Offen: Entladephase. Läuft beim Entladen noch eine Ausführung im alten Objekt, verschwindet das
  Instanz-Interface mittendrin (`Translate()` → false, nuc 17:39:40, Device #54477 in
  `maintainUnavailableEntitiesJsonVariable`). Die Eingangsprüfung greift dort nicht.
- Test: `tests/check-reload-window.php`; der Harness stellt das Fenster mit
  `$attributeRegistriert = false` nach: Jeder Attributzugriff warnt, ResolvedConfig liefert über
  die Naht `readResolvedConfigAttribute()` false (der Stub deklariert `ReadAttributeString` als
  `: string`). Properties simuliert er bei Device/Entity im Fenster nicht. Splitter und MQTT
  Discovery laufen über `FensterRahmenTrait` (Properties, Attribute und Timer warnen im Fenster;
  das Merkmal liefert über die Naht des Moduls false), angelegt mit `neueFensterInstanz()`.

## Schalten (Device/Entity)

- Zwei Schaltpfade für die Hauptvariable mit gleicher Logik: `HADeviceCoreTrait::
  handleMainEntityRequestAction` (Entity) und `HomeAssistantDevice::executeMainEntityAction`
  (Device, eigene `RequestAction`). Änderungen an beiden nachziehen.
- Ein ungültiger Wert (`formatPayloadForMqtt` liefert `null`) wird über
  `rejectInvalidActionValue()` als `E_USER_WARNING` gemeldet — der Aufrufer von
  `RequestAction()` bekommt damit einen Fehler statt `true`. Eine Ausnahme ergäbe im Kernel
  „Fatal error: Uncaught …" samt Stacktrace, deshalb die Warnung. Weiterhin still (nur
  Debug) enden: unbekannter Ident, nicht schreibbare Entität, fehlgeschlagener REST-Aufruf.
- Ungültig sind: Option außerhalb von `options`, nicht numerischer Wert oder Zahl außerhalb von
  `min`/`max` bei `number`, leerer Wert bei allen Domains außer `input_text` (dort heißt leer
  „Text leeren"; deshalb `null` und nicht `''` als Kennzeichen). Ein Text geht auf REST- und
  MQTT-Weg unverändert hinaus (bis build 173 kürzte der REST-Weg: „   " leerte den Text).
  Der Zahlenbereich wird am ungekürzten Wert geprüft, erst danach wird für eine Ganzzahl
  abgeschnitten (bis build 173 ging 100.9 bei max 100 als 100 durch). Nicht geprüft werden
  Textlänge und `pattern` bei `input_text` sowie Datumswerte.
- Geprüft wird gegen `resolveMainEntityActionAttributes()`: Konfiguration (Stand des letzten
  `ApplyChanges`), überlagert vom State-Cache (Stand der letzten MQTT-Meldung). Ohne das galt
  eine in HA erweiterte Optionsliste bis zum nächsten `ApplyChanges` als ungültig.
- Die Aktion der Hauptvariable wird im vollen Pfad (`maintainEntityVariable`, also bei
  `ApplyChanges`) immer abgeglichen, im heißen Pfad nur für `select` — und für jede Variable,
  die `MaintainVariable` wegen eines Typwechsels neu angelegt hat (ID-Vergleich in
  `syncEntityPresentation`). Vorher wurde sie nur beim Anlegen gesetzt; am nuc standen deshalb
  38 Slider-Variablen ohne Aktion da (01.10.2026).
- Ob `MaintainVariable` die Variable (neu) erstellt hat, sagt ihr Rückgabewert (`IPSModuleStrict`,
  laut Doku „ob die Variable erstellt wurde"; seit build 176 statt Vorher/Nachher-ID-Vergleich).
  Dass ein Typwechsel als „erstellt" zählt, sagt die Doku nicht ausdrücklich; **am nuc belegt**
  (01.10.2026, 9.1 Rust, Entity #46169 „Test Zahl"): Schrittweite in HA 1 → 0,5 → 1 legte die
  Variable zweimal unter neuer ID an (#56984 → #31609 → #41500), beide Male mit Aktion, Schalten
  über die neue Variable lief bis HA durch. Der Harness liefert den Wert wie dokumentiert (der
  Stub selbst immer true). Die Altnamen-Prüfung (`IPS_GetObject`) läuft nur noch im vollen Pfad.
  `tests/check-action-contract.php` Teil 10 hält die Kosten einer Attributmeldung fest
  (höchstens 3 `GetIDForIdent`).
- Offen: Der Attribut-Pfad (`tryHandleAttributeFromTopic`) legt die Laufzeit-Entität ohne die
  konfigurierten Attribute an. Fehlen sie auch im State-Cache (ApplyChanges ohne REST-Antwort),
  rechnet der Typ einer `number` mit unvollständigen Attributen: Beim Einspielen der retained
  Attribut-Topics wird die Variable dann zweimal neu angelegt (Integer → Float → Integer, neue
  ID). Am Stub nachgestellt 01.10.2026, an einer Anlage nicht belegt.
- Test: `tests/check-action-contract.php` am echten Device- und Entity-Modul über den
  Kernel-Stub (`tests/device-harness.php`).

## Variablennamen

- Hauptvariablen benennt `HAEntityVariableNamingTrait` (Device, Entity, MQTT Discovery), Regeln in
  `docs/ARCHITEKTUR.md`. Letzter Ausweg vor der `entity_id` ist der ungekürzte eigene Name
  (build 171; Anlass: MCP-Blindtest, Hauptvariable hieß `input_number.test_zahl`) — aber nur,
  wenn ihn keine zweite Entität der Instanz ebenso als Ausweg nimmt (build 173, sonst gleichnamige
  Variablen). Neue Zählschlüssel in `sharedEntityBaseNameCounts` verlangen einen neuen
  `CONFIGURED_ENTITIES_CACHE_MARKER`, weil der Konfigurations-Cache die Zähler mitspeichert.
  Test: `tests/check-shared-entity-naming.php`.

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
(eigenständige Skripte, kein PHPUnit).

- Jeder Laufzeit-Check bindet `tests/harness.php` ein: Warnings/Notices werden zu Fehlern,
  `pruefe()` zählt jede Prüfung, `ergebnis()` schreibt die Schlusszeile
  „N Prüfungen, M Fehler" (Exit 0/1) — das Format liest `rotgruen.php` für den
  Rot/Grün-Nachweis. Neue Tests genauso aufbauen.
- Neue Tests an Device oder Entity laufen über `tests/device-harness.php`: das echte Modul am
  offiziellen Kernel-Stub (`symcon/SymconStubs`, Submodul `tests/stubs`, gepinnt auf `bf2950f`).
  `neuesGeraet()`/`neueEntitaet()` legen Instanzen an, `neueAusfuehrung()` liefert ein frisches
  Modul-Objekt mit dem Kernel-Zustand des alten (der Stub hält Properties, Attribute, Buffer und
  Timer im Objekt, Symcon im Kernel), `KernelZaehler` zählt Kernel-Aufrufe, `HaSendungen`
  zeichnet REST- und MQTT-Sendungen auf. Das globale `IPS_GetObject()` des Stubs lässt sich
  nicht mitzählen. Umgestellt: `check-action-contract`, `check-configured-entities-cache`,
  `check-reachability`, `check-reload-window`.
- Hilfsskripte, die keine Tests sind (z. B. Fixture-Extraktion), gehören nach `tools/`
  (nicht versioniert), nicht nach `tests/`.
- Offen: Die übrigen Laufzeit-Checks tragen noch eigene Attrappen. Umstellung auf den
  Kernel-Stub und Ersatz von Logik-Kopien durch Modulaufrufe beim nächsten Anfassen des
  jeweiligen Tests, nicht als Sammelaktion.

## CI / Version

- CI: `.github/workflows/check.yml` (PHP 8.5, Checkout mit Submodulen) — Code-Stil mit
  php-cs-fixer gegen das Regelwerk im Submodul `.style` (`--dry-run`), `php -l` auf alle
  `*.php`, JSON-Validität (beides ohne `tests/stubs`), Locale-Check und `tests/check_property_contracts.php` (jede von
  `libs/Device/HADeviceCore.php` gelesene Property muss in Device- und Entity-Modul
  registriert sein), `tests/check_presentations.php` (nur gültige Darstellungsparameter) und
  alle Laufzeit-Checks `tests/check-*.php` (Glob, neue Tests laufen automatisch mit;
  dazu gehört auch die Doku-Sperrklinke `tests/check-readme.php`).
- Version/Build: siehe globale CLAUDE.md, Abschnitt „Symcon: Build-/Versionspflege in Modul-Repos".