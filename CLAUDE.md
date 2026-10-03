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
- Jeder Wechsel steht im Log (build 185, MCP-Regel 11, Abstimmung 03.10.2026): Ausfall als
  `KL_WARNING` mit Frist und nächstem Schritt, Erholung als `KL_MESSAGE`. Geschrieben wird nur
  in `setReachableValue()`, dem einzigen Ort des Wechsels — das Anlegen der Variable und ein
  über Neustart gehaltener Ausfall laufen nicht dort durch und loggen deshalb nichts.
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
- **Entladephase** (seit build 180): Beim Reload geht unser Splitter auf **105 (`IS_NOTCREATED`)**,
  bevor seine Kinder entladen werden (Mitschnitt nuc 01.10.2026 18:06:27). Bis build 179 plante
  jedes Kind daraufhin ein `DeferredApply` und fuhr `ApplyChanges` (Status 201), wen das
  Entladen mittendrin traf, endete mit einem Fatal (`Translate()` → false; bei drei Reloads
  dreimal). Jetzt ignorieren Device, Entity und MQTT Discovery Device den Parent-Status 105
  (`isRelevantParentStatusChange()`); die Splitter nicht, ihr Parent ist der MQTT Client des Kerns.
- **Stub-Fehler:** `tests/stubs` (SymconStubs `bf2950f`) definiert `IS_NOTCREATED` als **201** —
  Befehlsreferenz (`IPS_GetInstance`) und beide Anlagen sagen 105. In Tests fällt es damit auf
  unseren Status 201 („Parent nicht aktiv"): Ein Test, der einem Kind den Parent-Status 201
  meldet, sähe das Kind fälschlich nicht reagieren.
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
- Die Laufzeit-Entität einer Ausführung kommt in jedem Pfad aus der Konfiguration
  (`rehydrateRuntimeEntity()`: Zustand, Attribut-Topic, `ensureStoredEntity`); Minimalmetadaten
  nur für nicht konfigurierte Entitäten. Bis build 180 legte der Attribut-Pfad sie mit der
  Objekt-ID als Namen und ohne Attribute an: Eine bei einem Typwechsel neu angelegte Variable
  hieß `test_zahl` statt „Test Zahl" (Gegencheck nuc 01.10.2026), und ohne REST-Antwort legten
  die retained Attribut-Topics eine `number` zweimal neu an (Integer → Float → Integer).
  `MaintainVariable` benennt eine **bestehende** Variable nie um — ein falscher Name beim
  Anlegen bleibt also stehen, bis der Anwender ihn ändert.
- Test: `tests/check-action-contract.php` am echten Device- und Entity-Modul über den
  Kernel-Stub (`tests/device-harness.php`).

## Position bei Rollladen und Ventil (statestream)

- statestream meldet Zustand und Attribute als getrennte Topics, den Zustand zuerst. Ein
  „state = open" trägt also keine Position; `extractPositionEntityUpdateContext()` (Cover und
  Ventil) liest sie deshalb aus dem State-Cache, überlagert über die gespeicherten Attribute.
- Bis build 183 las es nur `$this->entities` — nach einer frischen PHP-Ausführung (Rust, jede
  Meldung) also die Konfiguration mit der Position des letzten `ApplyChanges`. Am nuc
  (03.10.2026, Gast-Rollladen) stand dort 100: Jedes „state = open" setzte die Hauptvariable auf
  100, erst das folgende `current_position` korrigierte sie. Ohne Position in der Konfiguration
  wäre es über `normalizeCoverStateToLevel('open')` ebenfalls 100 geworden.
- **Allgemein:** Wer im heißen Pfad Attribute braucht, liest Konfiguration **und** State-Cache
  (Muster `resolveMainEntityActionAttributes`); `$this->entities` allein ist der Stand des letzten
  `ApplyChanges`.
- Test: `tests/check-cover-position-statestream.php` (echter Mitschnitt, Fixtures
  `cover_*_gast_20261003.*`). Für Ventile gibt es keinen Mitschnitt; sie laufen durch dieselbe
  Funktion.

## Veraltete Variablen (Device)

- Was das Modul nicht mehr versorgt, wird nicht gelöscht, sondern „(veraltet)": Zusatz am Namen,
  Aktion weg (`markVariableAsLegacy`). Entscheidend ist allein der Ident, nie der Name — vom
  Anwender geänderte Namen behalten ihren Text.
- `cleanupManagedEntityObjects()` (Device `module.php`) läuft bei jedem `ApplyChanges` und ordnet
  jede Variable per Longest-Prefix dem Präfix einer Entität zu: aktiv → bleibt, nur altes/inaktives
  Präfix → veraltet. Variablen ohne Ident oder außerhalb jedes Entitäts-Namensraums bleiben tabu.
- **Keine Liste der gepflegten Idents als Maßstab:** Attributvariablen entstehen auch erst im heißen
  Pfad (erstes MQTT-Attribut); eine Liste aus `ApplyChanges` würde lebende Variablen kennzeichnen.
- Altes Schema (vor `ab131d3`, 18.05.2026): voller Entitätsname im Ident. Ist das Präfix seither
  gekürzt, liegt das alte unter dem eigenen neuen — bis build 185 wurde es deshalb übersprungen,
  am nuc blieben rund 45 tote, schaltbare Doppelgänger stehen (Denon, Sonos, homematic-ccu3 …).
  Seit build 186 zählt es als veraltet, außer es liegt unter dem Präfix einer **anderen** aktiven
  Entität oder umschließt ein aktives Präfix.
- **Entfallene Entitäten** (in HA gelöscht oder umbenannt) erkennt nur `UpdateConfiguration()`:
  Es liest die alte `ResolvedConfig` vor dem Überschreiben und gibt sie an `processEntities()`.
  Bis build 186 kam der Vorstand allein aus `$this->entities` — unter Rust in jeder Ausführung
  leer, die Variablen entfallener Entitäten blieben schaltbar stehen (nuc 03.10.2026,
  homematic-ccu3, zehn verwaiste HA-Entitäten). Die alte Konfiguration dient nur dem Aufräumen,
  nicht dem Zusammenführen der Attribute. Ist die neue Konfiguration einmal geschrieben, ist der
  Vorstand weg — ein späteres `ApplyChanges` holt das nicht nach.
- Tests: `tests/check-legacy-idents.php` (Konfiguration und Variablen vom nuc, Fixture
  `legacy_idents_20261003.json`), `tests/check-removed-entities.php` (Fixture
  `removed_entities_ccu3_20261003.json`). Das Entity-Modul hat einen eigenen Pfad
  (`cleanupRenamedSharedEntityObjects`), am nuc ohne Doppelgänger.

## Buttons und Diagnose (build 189, MCP-Punkt H5)

- Ein Button meldet als Zustand den Zeitpunkt des letzten Drückens. Den Druck zeigt die
  „Letzte Aktualisierung" der Button-Variable (Abstimmung 03.10.2026: keine eigene Variable).
  `touchButtonOnNewPress()` (HADeviceCore) schreibt sie nur, wenn der HA-Zeitpunkt neuer ist als
  die letzte Aktualisierung und höchstens `BUTTON_PRESS_MAX_AGE_S` (300 s) alt. Device: aus
  `applyTriggerEntityStateUpdate`, Entity: aus `updateEntityValue` (dort wurde die Variable vorher
  bei jeder Meldung geschrieben, auch beim REST-Abgleich). Test: `tests/check-button-last-press.php`.
- Diagnosetexte laufen über `Translate()`; `HADiagnosticsTrait` schrieb sie fest deutsch (drei
  der fünf Funktionen waren ungenutzt und sind entfernt). `LastRestTimeout` trägt den Zeitpunkt.
  Test: `tests/check-diagnostic-labels.php`; der Testrahmen schneidet dafür `UpdateFormField` mit
  (`DeviceHarness::$formularFelder`).

## Variablennamen

- Hauptvariablen benennt `HAEntityVariableNamingTrait` (Device, Entity, MQTT Discovery), Regeln in
  `docs/ARCHITEKTUR.md`. Letzter Ausweg vor der `entity_id` ist der ungekürzte eigene Name
  (build 171; Anlass: MCP-Blindtest, Hauptvariable hieß `input_number.test_zahl`) — aber nur,
  wenn ihn keine zweite Entität der Instanz ebenso als Ausweg nimmt (build 173, sonst gleichnamige
  Variablen). Neue Zählschlüssel in `sharedEntityBaseNameCounts` verlangen einen neuen
  `CONFIGURED_ENTITIES_CACHE_MARKER`, weil der Konfigurations-Cache die Zähler mitspeichert.
  Test: `tests/check-shared-entity-naming.php`.
- Keine zwei Variablen einer Instanz heißen gleich (build 188, MCP-Regel 14, Abstimmung 03.10.2026):
  Entitätsname vor Zusatzvariablen bei mehreren Entitäten derselben Art oder schon vergebenem Namen,
  Art-Hinweis bei gleichem HA-Namen verschiedener Domänen, ungekürzter Name statt „Status (FAN)".
  Zusatzvariablen werden **zentral beim Anlegen** benannt (`MaintainVariable()` in
  `HASharedPresentationTrait` → `scopeCreatedEntityVariableName()`), nicht an den einzelnen
  Stellen — eine neue Art Zusatzvariable braucht dafür nichts. Bestehende Variablen benennt das
  nicht um. Test: `tests/check-variable-name-collisions.php` (Fixture
  `naming_collisions_20261003.json`; der Testrahmen übersetzt nicht, erwartet sind englische Schlüssel).

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