# SymconHomeAssistant: Projekt-Hinweise

Symcon-Bibliothek (8 Module, alle `IPSModuleStrict`) zur Anbindung von Home Assistant. Architektur:
`docs/ARCHITEKTUR.md`.

Diese Datei ist das Regelwerk für die Arbeit an der Bibliothek. **Anlass, Messungen, verworfene Wege und
Review-Funde zu jeder Regel stehen in `ENTWICKLUNG.md`**, gegliedert wie hier; dort nachlesen, bevor eine Regel
geändert oder eine verworfene Idee neu aufgegriffen wird. Das Zielbild für die Splitter steht in
`docs/KONZEPT_DISCOVERY_SPLITTER.md`. Forennamen, PN-Nummern und offene Review-Befunde stehen in der
`CLAUDE.local.md`, die die `.gitignore` ausschließt.

**Schreibregel:** Ein neuer Eintrag hier hat höchstens drei Zeilen (Regel, Grund in einem Halbsatz, Test). Anlass,
Zahlen und Zitate gehören in die `ENTWICKLUNG.md`, Anwender und Anlagen in die `CLAUDE.local.md`.

## Modul-Familien

- **Klassische Bridge** (bestehende HA-Installation → Symcon):
  `Home Assistant Splitter` (MQTT-statestream-Empfang + REST-Zugang zu HA),
  `Home Assistant Configurator`, `Home Assistant Device`, `Home Assistant Entity`,
  `Home Assistant Discovery`.
- **MQTT Discovery** (Geräte direkt per MQTT, ohne HA-Server):
  `Home Assistant MQTT Discovery Splitter` / `... Configurator` / `... Device`.
- **Leitsatz:** Wo beide Familien dieselbe Aufgabe verschieden lösen, gilt je Thema die bessere Lösung für beide,
  möglichst in gemeinsamem Code (Tabelle und Etappen im Konzept).

## Grundlagen (beide Familien)

### Datenfluss (klassische Bridge)

- Lesen: HA `mqtt_statestream` → MQTT Client/Server → Splitter → Device/Entity. statestream publiziert nur bei
  Zustandsänderung (retained, QoS 1); selten wechselnde Entitäten kommen per MQTT ggf. nie an.
- Initiale Werte: REST `/api/states/<entity_id>` bei `ApplyChanges` über den Splitter.
- Schreiben: je Domain über den fachlich vorgesehenen HA-Pfad, meist ein REST-Service-Call. `*/set`-Topics führt
  der Splitter selbst per REST aus; MQTT nur bei einem echten Command-Pfad.
- **Jedes Kind des Splitters setzt einen Empfangsfilter**, auch eines, das keine Meldungen auswertet (Konfigurator:
  `^$` in `ApplyChanges`); sonst stellt der Kernel ihm jede Meldung zu. Test: `check-configurator-receive-filter.php`.

### Reload-Fenster

- Beim Neuladen der Bibliothek treffen Meldungen und Timer Instanzen, deren `Create()` gerade läuft: Properties
  und Attribute sind nicht registriert, `ReadAttributeString` liefert false.
- **Außerhalb von `Create()` nie `Register*` aufrufen**; das folgende `Create()` scheitert sonst an „already
  registered“ und die Instanz bleibt auf 105.
- **Jeder Einstiegspunkt prüft zuerst `isInstanceCreated()`**: `ReceiveData`, `RequestAction` (also alle Timer),
  öffentliche Funktionen (`SyncStates`, `UpdateMediaPlayerProgress`), `ApplyChanges`, `MessageSink`-Zweige. Im
  Fenster tun sie nichts. Ausgenommen ist das `ApplyChanges()` der beiden Konfiguratoren: Es setzt nur den
  Empfangsfilter `^$` und liest nichts; kommt dort ein Lesezugriff hinzu, gehört die Prüfung davor.
- Das Merkmal ist die **letzte Registrierung in `Create()`**, neue Registrierungen gehören davor: Device/Entity
  Attribut `ResolvedConfig` (Naht `readResolvedConfigAttribute()`), Splitter Property `MQTTBaseTopic`
  (`getBaseTopicProperty()`), Discovery-Splitter Property `SourceMode` (`isBundleMode`), Discovery-Gerät Attribut
  `TopicProcessingIndex`. Jedes Merkmal-Memo verwirft `ApplyChanges` vorher, das Discovery-Gerät auch
  `writeTopicProcessingIndex()`.
- Neue Timer: Code, der sie außerhalb von `Create()` stellt, muss die Bestandsinstanz ohne den Timer aushalten
  (mit `@`, Muster `setReachabilityTimerInterval()`). Besser einen vorhandenen Timer mitnutzen.
- Verzögerte Übernahme: Der Timer `DeferredApply` ruft `IPS_RequestAction(…, "DeferredApply", "")`, `RequestAction`
  ruft `ApplyChanges()`. Nie `IPS_ApplyChanges` auf die eigene Instanz (Store-Richtlinie), auch nicht aus einer
  form.json. Test: `check-deferred-apply.php`.
- Entladephase: Device, Entity und Discovery-Gerät ignorieren den Parent-Status 105
  (`isRelevantParentStatusChange()`), die Splitter nicht (ihr Parent ist der MQTT Client des Kerns).
- Test: `check-reload-window.php` (`$attributeRegistriert = false`, `FensterRahmenTrait`, `neueFensterInstanz()`).

### Parallele Aufrufe in einer Instanz

- Der Kernel lässt mehrere Aufrufe gleichzeitig in dieselbe Instanz. Meldungen untereinander kommen nacheinander
  (der Parent wartet auf `SendDataToChildren`), Timer, `RequestAction` und `ApplyChanges` laufen daneben.
- **Wer einen Buffer oder ein Attribut liest, ändert und zurückschreibt, rechnet mit einer zweiten Ausführung
  dazwischen.** Nie den zu Beginn gemerkten ganzen Bestand zurückschreiben.
- Zustands-Cache von Device und Entity: **Wer ihn ändert, liest ihn über `readEntityStateCacheForUpdate()`**, nicht
  über `readEntityStateCache()`. Test: `check-state-cache-parallel.php` (Naht `HaSendungen::$unterwegs`,
  `HaSendungen::$parentAktiv`, `DeviceHarness::$pufferLesungen`).
- Offen: Zwischen Lesen und Schreiben liegt dort keine Sperre. Mit „Nicht verfügbare Entitäten (JSON)“ kann der
  Flush-Timer eine Meldung für die Erreichbarkeit übergehen.

### Empfangsfilter

- Device, Entity und Discovery-Gerät bauen ihren Filter über `HAMqttTopicFilter`:
  `exactTopicsFilterPattern()` (Discovery, die Topics aus `collectRelevantTopics()`) und
  `subtreeTopicsFilterPattern()` (klassisch, `<base>/<domain>/<object_id>` und alles darunter). **Nie über
  gemeinsame Anfänge zusammenfassen**: Das stellte bei einem Anwender jede Meldung jedem der 72 Geräte zu.
- Bauformen: Schrägstrich als `\\?\/`, Gruppen `(?:…)` und `(?:…)?`, eine Ebene mit Nicht-ASCII-Zeichen als
  `[^"\/]+`, ohne Topics `^$`.
- Wer im Discovery-Gerät eine neue Art Topic auswertet, trägt sie in `collectRelevantTopics()` **und**
  `buildTopicProcessingIndex()` ein. Topics abgewählter Entitäten kommen nicht an.
- Tests: `check-discovery-receive-filter.php`, `check-device-receive-filter.php`.

### Verarbeitungskontext im Buffer

- Unter der Rust-Edition läuft jede Meldung in einer eigenen PHP-Ausführung, ein Memo im Objekt hält nicht. Was
  je Meldung gebraucht wird, liegt in einem Buffer mit Signatur und Zeilenzugriff (`libs/HAKeyedBlob.php`:
  Kopfzeile als JSON, je Eintrag `"<Schlüssel>"` TAB `<Wert als JSON>`). Ob ein Blob gilt, entscheidet der Aufrufer.
- Klassisches Gerät: Konfigurations-Cache mit `getConfiguredEntityById`. Neue Felder verlangen einen neuen
  `CONFIGURED_ENTITIES_CACHE_MARKER`. Test: `check-configured-entities-cache.php`.
- Discovery-Gerät: Buffer `RuntimeContextCache`, `getTopicProcessingSlice()` (Memo, Buffer, voller Aufbau über
  `getRuntimeProcessingContext`), Signatur aus `buildRuntimeContextSignature`. **Wer den Aufbau der normalisierten
  Entität oder des Topic-Index ändert, gibt `RUNTIME_CONTEXT_MARKER` einen neuen Wert.** Kodiert wird mit
  `JSON_PRESERVE_ZERO_FRACTION`. Test: `check-discovery-device-context-cache.php`.
- Symcon kürzt einen Buffer über 512 KB; über `RUNTIME_CONTEXT_MAX_BYTES` legt das Discovery-Gerät keinen Blob an.
  Das klassische Gerät hat seine Grenze noch bei 1 MB (`CONFIGURED_ENTITIES_CACHE_MAX_BYTES`).

### Variablennamen

- Hauptvariablen benennt `HAEntityVariableNamingTrait` (Device, Entity, MQTT Discovery), Regeln in
  `docs/ARCHITEKTUR.md`. Letzter Ausweg vor der `entity_id` ist der ungekürzte eigene Name, auch für Buttons
  (`getSharedButtonVariableName`). Tests: `check-shared-entity-naming.php`, `check-button-caption.php`.
- Keine zwei Variablen einer Instanz heißen gleich. Zusatzvariablen werden zentral beim Anlegen benannt
  (`MaintainVariable()` in `HASharedPresentationTrait` → `scopeCreatedEntityVariableName()`, `childNameCounts`).
  Eine bestehende Variable benennt das Modul nie um. Test: `check-variable-name-collisions.php`.
- Neue Zählschlüssel in `sharedEntityBaseNameCounts` verlangen einen neuen `CONFIGURED_ENTITIES_CACHE_MARKER`.
- Fehlermeldungen an Anwender nennen den Variablennamen, nicht den Ident (`rejectInvalidActionValue`).

### Schieberegler ohne Bereich

- Ein Schieberegler mit MIN >= MAX wird zum Eingabefeld (`fitPresentationToVariableType`, `VALUE_INPUT`); der
  Kernel verwirft ihn sonst und das Übernehmen scheitert. Der Kernel-Stub prüft das nicht. Test:
  `check-slider-min-max.php`.

## Klassische Bridge

### Erreichbarkeit

- `unavailable`/`unknown` kommen nie in die Wertvariablen. Ob ein Gerät liefert, zeigt allein die Bool-Variable
  `reachable` (`HAEntityStoreTrait::evaluateReachability`): false, wenn alle Entitäten mit Cache-Eintrag
  `raw_state = unavailable` tragen, seit mindestens `REACHABILITY_DELAY_S` (600 s, gemessen). Kein Instanzstatus,
  kein Ausnahmeschalter.
- Geschrieben wird nur in `setReachableValue()`; dort steht auch das Log (Ausfall `KL_WARNING`, Erholung
  `KL_MESSAGE`).
- Die Entprellung gilt nur für erreichbar → nicht erreichbar. Steht `reachable` schon auf false, bleibt es bis zur
  nächsten gültigen Meldung. Eine neu angelegte Variable beginnt mit true (`maintainReachableVariable()`).
- Heißer Pfad: `markReachabilityDirty()` liest nur den Buffer `ReachabilitySince`; ausgewertet wird gebündelt im
  StateCacheFlush bzw. per `ReachabilityTimer`, der nur über `setReachabilityTimerInterval()` gestellt wird.
- Bekannte Lücke: Mit `EmulateStatus` überschreibt `applyOptimisticEntityValue` den `raw_state` einer toten
  Entität.
- Test: `check-reachability.php` (Fixtures `tests/fixtures/reachability/`, Extraktion
  `tools/fixture_reachability.php`).

### HA-Startphase

- Eine leere Antwort auf die Entitäten-Abfrage ist kein „nicht gefunden“: `confirmNotFound()` fragt
  `isHomeAssistantRunning()`. Nicht `RUNNING` oder keine Antwort: Konfiguration bleibt, Status 203
  (`STATUS_HA_NOT_READY`), Nachprüfung. `RUNNING`: 211 (Entity 210).
- Eine gescheiterte Abfrage (`null`) läuft über `deferFailedQuery()` genauso. Beide Fälle teilen sich
  `scheduleHaRecheck()`: zehnmal nach 30 s, danach alle 300 s (Buffer `QueryFailures`), zurückgesetzt nur bei einem
  eindeutigen Befund. Nachgeprüft wird über den vorhandenen `DeferredApplyTimer`.
- Test: `check-ha-startphase.php` (Fixture `ha_startphase_20261020.json`, echter Splitter als Parent,
  `SplitterHarness::$haAntwort`).

### Schalten

- Zwei Schaltpfade für die Hauptvariable: `HADeviceCoreTrait::handleMainEntityRequestAction` (Entity) und
  `HomeAssistantDevice::executeMainEntityAction` (Device, eigene `RequestAction`). Änderungen an beiden nachziehen.
  Offen: Mit „Status emulieren“ schreibt nach dem `set`-Topic nur der Pfad der Entity optimistisch.
- Ein ungültiger Wert (`formatPayloadForMqtt` liefert `null`) geht über `rejectInvalidActionValue()` als
  `E_USER_WARNING` hinaus, nie als Ausnahme. Still enden unbekannter Ident, nicht schreibbare Entität und
  fehlgeschlagener REST-Aufruf.
- Ungültig sind: Option außerhalb von `options`, `number` nicht numerisch oder außerhalb von `min`/`max` (am
  ungekürzten Wert geprüft), leerer Wert außer bei `input_text`. Geprüft wird gegen
  `resolveMainEntityActionAttributes()` (Konfiguration, überlagert vom State-Cache).
- Die Aktion der Hauptvariable wird im vollen Pfad (`maintainEntityVariable`) immer abgeglichen, im heißen Pfad nur
  für `select` und für jede Variable, die `MaintainVariable` neu angelegt hat (Rückgabewert, `syncEntityPresentation`;
  ein Typwechsel zählt als angelegt).
- Die Laufzeit-Entität kommt in jedem Pfad aus der Konfiguration (`rehydrateRuntimeEntity()`), Minimalmetadaten
  nur für nicht konfigurierte Entitäten.
- `…/set` im Splitter: Auswahl und Text (`TEXT_PAYLOAD_DOMAINS`) gehen unverändert an HA, nicht durch
  `normalizePayload()`. Ein sichtbares `set`-Topic heißt, der REST-Weg ist gescheitert. Test:
  `check-rest-set-payload.php` (`performCurl`, `SendDataToParent` im `SplitterHarness`).
- Test: `check-action-contract.php` (Teil 10 hält die Kosten einer Attributmeldung fest, Teil 12 das Löschen).

### Attribute im heißen Pfad

- statestream meldet Zustand und Attribute als getrennte Topics, den Zustand zuerst. **Wer im heißen Pfad Attribute
  braucht, liest Konfiguration und State-Cache** (Muster `resolveMainEntityActionAttributes`,
  `getStoredAttributeTopicAttributes()`); `$this->entities` allein ist der Stand des letzten `ApplyChanges`.
- Cover und Ventil: `extractPositionEntityUpdateContext()`. Licht: `handleLightAttributeTopic` →
  `updateLightAttributeValues`; ein `null`-Topic schreibt 0 bzw. `''` und steht im Cache.
- Tests: `check-cover-position-statestream.php`, `check-light-attributes-statestream.php`.

### Veraltete Variablen

- Was das Modul nicht mehr versorgt, löscht es nicht: `markVariableAsLegacy`
  (`libs/HALegacyVariableMigration.php`) nimmt nur die Aktion weg und führt den Ident im Attribut
  `ObsoleteVariables`. Name und Position bleiben, entscheidend ist allein der Ident. Gilt für Device, Entity und
  MQTT Discovery Device.
- Der Knopf „Veraltete Variablen löschen“ (`DeleteObsoleteVariables`) löscht nur, was das Formular gezeigt hat: Er
  trägt die Idents als JSON-Wert, `handleObsoleteVariablesAction` löscht die Schnittmenge. Tests drücken Knöpfe
  über `drueckeKnopf()`.
- Rückkehr: Das zentrale `MaintainVariable` nimmt im vollen Pfad jeden wieder gepflegten Ident aus der Liste; wer
  den Zustand vorher braucht, fragt `isObsoleteIdent()`. Im heißen Pfad fasst niemand die Liste an
  (`pauseObsoleteBookkeeping()`, `resumeObsoleteBookkeeping()`); `getObsoleteVariables()` lässt weg, was nach der
  Kennzeichnung aktualisiert wurde.
- `cleanupManagedEntityObjects()` ordnet jede Variable per Longest-Prefix einer Entität zu. **Keine Liste der
  gepflegten Idents als Maßstab**, Attributvariablen entstehen auch erst im heißen Pfad. Entfallene Entitäten
  erkennt nur `UpdateConfiguration()` (alte `ResolvedConfig` vor dem Überschreiben an `processEntities()`). Die
  Entity hat einen eigenen Pfad (`cleanupRenamedSharedEntityObjects`).
- Medienobjekte: Name und Position nur beim Anlegen (`ensureManagedMediaObject` reicht `$created`). Die
  `position_base` aus `assignEntityPositionBases()` steht im Konfigurations-Cache, damit auch der heiße Pfad
  richtig anlegt.
- Tests: `check-legacy-idents.php`, `check-removed-entities.php`, `check-obsolete-variables-entity.php`,
  `check-media-object-meta.php`, `check-media-cover-position.php`.

### Mediaplayer

- „Wiedergabe“ (`~PlaybackPreviousNext`), „Titel“ (`~Song`) und „Interpret“ (`~Artist`) tragen Systemprofile über
  `VARIABLE_PRESENTATION_LEGACY`. **Nicht auf Darstellungen umstellen**: Die Kachel-Visualisierung erkennt einen
  Mediaplayer nur daran. Lautstärke, Stumm und Fortschritt gehen über Darstellungen mit `USAGE_TYPE`.
- Die Lautstärke (0…1, Anzeige 0…100 per `ConversionFactor`) trägt das Suffix „ %“. Test: `check-volume-suffix.php`.
- Die Auswahl `source` bietet nur `source_list` an, ohne Zusatzoption. Eine Quelle außerhalb zeigt die Anzeige
  `current_source` (`mapMediaPlayerAttributeAliases`, `handleMediaPlayerAttributeTopic`). Test:
  `check-current-source.php`.
- Weggefallene Attribute meldet statestream nie. Für die Quelle fragt der vorhandene `MediaRefreshTimer` per REST
  nach (`scheduleMediaPlayerSourceCheck()`, `processPendingSourceChecks`, im Test `HaSendungen::$zustaende`);
  andere Attribute bleiben nach dem Stoppen stehen.

### Kamera

- Der Zustand bleibt als Rohwert in der Variable, die Darstellung ist eine Wertanzeige mit Optionen
  (`HACameraDefinitions::STATE_OPTIONS`, `getStateOptionsPresentation()`). Keine Aufzählung, die verlangt eine
  Variablenaktion (`check-readonly-enum-presentation.php`).
- Rotierende Schlüssel (`access_token`, `token=` in `entity_picture`/`media_image_url`) kommen nie in
  Konfiguration, Bundle oder Variablen: `removeSecretAttributes()`, `normalizeCameraAttributes()`,
  `removeAccessTokenFromUrl()` (`handleMediaPlayerImageAttributeTopic`). Tests: `check-camera-state-token.php`,
  `check-media-image-token.php`.

### Buttons und Diagnose

- Den Druck eines Buttons zeigt die „Letzte Aktualisierung“ seiner Variable. `touchButtonOnNewPress()` schreibt
  sie nur, wenn der HA-Zeitpunkt neuer ist und höchstens `BUTTON_PRESS_MAX_AGE_S` alt (Device aus
  `applyTriggerEntityStateUpdate`, Entity aus `updateEntityValue`). Test: `check-button-last-press.php`.
- Diagnosetexte laufen über `Translate()` (`HADiagnosticsTrait`). Test: `check-diagnostic-labels.php`
  (`DeviceHarness::$formularFelder`).

### Nachkommastellen

- HA führt die Anzeigegenauigkeit nur in der Entity-Registry. Der Splitter holt sie per WebSocket
  (`HAWebSocketClient`, `config/entity_registry/list_for_display`, Feld `dp`) und hält sie 900 s im Buffer
  `DisplayPrecisionCache`, auch einen Fehlschlag. Kinder fragen per `ForwardData` mit `DisplayPrecision`.
- Device und Entity tragen sie in `UpdateConfiguration()` als `display_precision` ein (`applyDisplayPrecisions`),
  `getNumericDigits()` gibt ihr Vorrang. Ohne Angabe: Stellen aus dem Wert, nur zunehmend, höchstens 3
  (`getObservedFloatDigits`).
- Test: `check-display-precision.php` (`HaSendungen::$anzeigeGenauigkeit`, `SplitterHarness::$registryAntwort`).

### Selbsttest

- „MQTT-Daten empfangen“ und „Empfangene Domänen“ zählen nur statestream (`getStatestreamDomain`,
  `recordStatestreamMessage()`): Form `<base>/<domain>/<object_id>/<attribut>`, eine Domäne, die das Modul kennt
  (`HADomainCatalog::isDomainSupported`), nie `…/config` oder `…/set`.
- Topics zerlegt allein `topicPartsBelowBase()`. Der Stand liegt im Buffer `StatestreamSeen`
  (`recordStatestreamMessage()`), dazu alle 60 s im Attribut `LastStatestreamMessage` (mit `@`). Wechselt
  `MQTTBaseTopic`, vergisst `ApplyChanges` den alten Stand (`forgetStatestreamOfOtherBaseTopic()`).
- Test: `check-selftest-foreign-topics.php` (`pufferVerwerfen()`).

## MQTT Discovery

### Jeder bekommt nur, was er braucht

- Der Splitter verteilt, führt die Ankündigungen und hält die letzten Werte, damit neue Geräte sofort Werte haben
  (Etappen und Entscheidungen im Konzept).
- Das Gerät fragt mit seiner Geräte-ID (`GetDiscoveryConfigs` mit `DeviceID`), der Splitter liefert nur dessen
  Ankündigungen (`filterDiscoveryRecordsByDevice`). Die Zuordnung trifft
  `HAMqttDiscoveryParser::resolveRecordDeviceId()` wie der Gruppenschlüssel in `HAMqttDiscoveryGrouping`. **Wer
  den Gruppenschlüssel ändert, ändert beide Stellen.**
- Das Gerät schreibt `LastMQTTMessage` gedrosselt (`touchLastMqttMessage`), der Konfigurator setzt `^$`.
- Test: `check-discovery-device-own-configs.php` (`DiscoveryDeviceHarness::$anfragen`).

### Splitter: Vormerken und Sperren

- `ReceiveData` merkt eine Meldung nur vor (`queueCacheUpdate`, Buffer `PendingCacheUpdates<n>`).
  `flushPendingCacheUpdates()` schreibt gebündelt in die Attribute: per Timer `DiagnosticsRefresh`
  (`RefreshDiscoveryDiagnostics()`), bei vollem Puffer und **vor jedem Lesen**.
- **Die Attribute der Zwischenspeicher nie direkt lesen**, nur über `readDiscoveryCache()`,
  `readTopicPayloadCache()` und `readReferencedTopicLookup()`.
- Zwei Semaphoren, beide nötig (parallele Aufrufe): die Vormerk-Sperre um Anhängen und Entnehmen
  (`PENDING_LOCK_WAIT_MS`), die Sperre der Zwischenspeicher um das Bündeln und jedes Lesen-Ändern-Schreiben.
  **Neue Schreiber gehören in `runWithCacheLock()`** (`pruneCaches` und die Knöpfe laufen schon dort). Eine
  Semaphore ist nicht wiedereintrittsfähig, deshalb `cacheLockDepth`.
- Die Diagnose (`buildDiagnosticsState()`) entsteht nur bei offenem Formular (`BUFFER_FORM_OPENED_AT`).
- Grenze: Was vorgemerkt ist, geht bei einem Kernel-Absturz verloren; die Variablen der Geräte nicht.
- Tests: `check-discovery-splitter-backlog.php` (`FensterRahmenTrait::$attributZugriffe`),
  `check-discovery-splitter-locks.php` (Naht `enterSemaphore`, `$sperrAnfragen`).

### Nicht mehr angekündigte Geräte

- Kennt der Splitter das Gerät nicht, gilt die gespeicherte Definition (`resolveRuntimeDeviceDefinition()`).
  `markNotAnnounced()` und `evaluateAnnouncementStatus()` setzen nach `NOT_ANNOUNCED_GRACE_S` Status 202 und eine
  Warnung (einmal, Attribut `NotAnnouncedWarned`); nachgeprüft wird über den Timer `DeferredApply`.
- Keine Antwort ist kein Befund (`markAnnouncementUnknown()`, `announced = null`). Im Bundle-Modus meldet der
  Splitter `SessionStartedAt = 0` (`buildDiscoveryResponse`), dann urteilt das Gerät nicht.
- `ApplyChanges` schreibt nur geänderte Werte (`setReceivedValue()`, `writeOnlyChangedValues`,
  `applyCachedTopicPayloads()`), `ReceiveData` immer. Test: `check-discovery-replay-timestamp.php`.
- Test: `check-discovery-not-announced.php` (`DiscoveryDeviceHarness::$splitterAntwort`, `$parentAktiv`).

### Erreichbar

- Geräte mit availability-Topic bekommen `reachable`: nicht erreichbar, wenn alle Entitäten mit bekanntem Stand
  offline melden. Keine Entprellung, Status bleibt 102. Nicht angekündigt heißt nicht erreichbar
  (`evaluateReachability($entities, $announced)`), deshalb wird der Status vorher bestimmt.
- **Bundle-Modus des Splitters:** Er spielt eine Momentaufnahme ab. Befunde an Geräten unter einem Bundle-Splitter
  sind keine Befunde über die Anlage; vorher den Modus prüfen (`HAMD_RunSelfTest`: „Quelle: Bundle“). Fixtures
  daraus sind Anwenderdaten und werden neutralisiert.
- `cleanupObsoleteVariables()` kennt nur Entitäts-Idents. Jede andere Variable wird dort ausdrücklich als aktiv
  übergeben, aber nur solange sie versorgt wird (`reachable` nur bei `hasAvailabilityEntities()`).
- availability-Templates mit bloßem Feld als Bedingung wertet `HAMqttDiscoveryTemplate::parseGuard` als `truthy`
  aus, mit dem Wahrheitswert von Python (`evaluateAvailabilityEntry`). `or` und `not` sind nicht unterstützt.
- Tests: `check-discovery-reachability.php`, `check-discovery-zwavejs-availability.php`,
  `check-discovery-splitter-diagnostics-bundle.php`.

### Sensortyp bleibt stabil

- `determineSensorVariableType()` zählt die bestehende Variable mit (`getExistingVariableType`): Umgestellt wird
  nur, wenn sie den Wert nicht halten kann, denn ein Typwechsel legt die Variable neu an
  (`recreateVariableIfTypeChanged`, neue ID, ohne Archiv). Test: `check-discovery-sensor-type-stable.php`.
- Offen: Ein Sensor, der mit einer ganzen Zahl angelegt wird, ist Integer; `number` hängt über
  `inferNumberVariableType` genauso am Wert.

### Klimageräte, Kurzformen, Templates

- Kurzformen stehen vollständig in `libs/Discovery/HAMqttDiscoveryAbbreviations.php`, erzeugt aus HA Core
  `mqtt/abbreviations.py`. Nicht von Hand pflegen.
- Klimakanäle: `HAMqttDiscoveryParser::CLIMATE_CHANNELS` liefert je Attribut Topics, Template und Optionen
  (Zeilenschlüssel `climate`); Variablen nach `HAClimateDefinitions::ATTRIBUTE_DEFINITIONS`, Index-Bucket `climate`
  (`applyClimateChannelPayload`, `isClimateChannelReadable`, `buildClimateSupportedFeatures`). Jeder Kanal sendet
  nur an sein Topic. Offen: Zielfeuchte, Bereichs-Solltemperatur.
- Templates: `parseConditionalJsonTemplate` versteht `{{ value_json.X if … else 'Y' }}`, festen Text je Bedingung
  und einfache `{% if %}`-Blöcke; Bedingungen stehen als `guards` im Template
  (`HAMqttDiscoveryTemplate::resolveJsonPathValue`). Was das Modul nicht versteht, schreibt nichts.
- Optionsdarstellung (`buildSharedOptionPresentation`, `getClimateOptionCaption`) gilt für beide Familien. Neue
  Beschriftungen gehören in die `locale.json` von Device **und** MQTT Discovery Device.
- Der Splitter zählt jedes `…command_topic`/`…cmd_t` als `command`, dazu `set_position_topic` und
  `set_fan_speed_topic`.
- Tests: `check-discovery-climate.php`, `check-mqtt-discovery-template-parser.php` (`$mqttSendungen`).

### Komponente `text`

- `HATextDefinitions` steht in den `SUPPORTED_COMPONENTS` von Parser und Device. Der Text geht unverändert hinaus
  (`buildCommandPayload`), auch leer; Länge und `pattern` prüft das Modul nicht.
- **Eine neue schaltbare Komponente braucht in `applyOptimisticValue` einen eigenen Zweig.** Test:
  `check-discovery-text.php`.

### Licht im Standard-Schema

- Fehlt `schema`, liefert der Parser den Zeilenschlüssel `light` (`buildLightBasicSchema`) mit den Vorgaben von HA
  (`mqtt/const.py`). Die Helligkeit gibt es nur mit `brightness_command_topic`; umgerechnet wird wie in HA, mit
  `PHP_ROUND_HALF_EVEN`. Der Zustand nimmt `state_value_template` vor `value_template`.
- Schalten wie HA `async_turn_on`: `on_command_type` `first`, `last` oder `brightness`; Helligkeit 0 schaltet aus.
  `coerceBasicSchemaBrightness()` bereitet den Wert vor, `buildBasicSchemaBrightnessPayload()` baut den Payload vor
  jeder Sendung; lässt sich das `brightness_command_template` nicht umsetzen, geht nichts hinaus.
- Im Standard-Schema liest das Modul kein `brightness` aus dem JSON (`extractLightAttributesFromPayload`,
  `basic_schema`). Offen: Farben und Farbtemperatur über eigene Topics.
- Die Spalte „Modus“ der Entitätenliste prüft mit `create_var = true` (`buildEntityAccessModeLabel`).
- Test: `check-discovery-light-basic.php`.

### Debug des Geräts

- Mit erweitertem Debug zeigt das Gerät jede Meldung („Eingangsdaten“, `debugIncomingMessage()`, für Device, Entity
  und Discovery-Gerät gleich) und jeden Wert einer Entität nach dem Schreiben („Wert gesetzt“). **Werte von Entitäten
  nur über `setReceivedValue()` schreiben**, sonst fehlen sie dort. Test: `check-discovery-device-debug.php`.

## Arbeitsweise

### libs/

Gemeinsame Traits und Klassen; `libs/HACommonIncludes.php` bindet alles ein und wird von allen Modulen außer
`Home Assistant Discovery` verwendet. Unterordner: `Domains/` (eine Definitionsklasse je HA-Domäne), `Device/`
(Laufzeitlogik der Device-Module), `Config/`, `Discovery/` (MQTT-Discovery-Parser und Runtime).

- Die Discovery setzt die Kerninstanz DNS-SD Control voraus und legt sie nie selbst an; fehlt sie, zeigt sie den
  Hinweis `mdnsMissing`. Test: `check-discovery-dnssd.php`.

### Übersetzungen

- Englische `form.json`-Texte sind zugleich Übersetzungsschlüssel; je Modul eine `locale.json` (de).
- `Translate()`-Texte aus `libs/`-Traits stehen in der `locale.json` jedes Moduls, das sie zur Laufzeit ausgibt.
  Prüfung: `php tests/check_locale.php` (je Modul über die eingebundenen Traits).
- Hinweise der Konfiguratoren laufen über eine Variable durch `Translate()`; sie prüft
  `check-configurator-parent-hint.php`.

### Dokumentation

- `README.md` (deutsch) und `README.en.md` (englische Übersicht) verweisen aufeinander; was Einrichtung, Verhalten
  oder Fehlersuche berührt, in beiden nachziehen. Die Modul-READMEs gibt es nur deutsch. Sperrklinke:
  `tests/check-readme.php`.
- Anwender hinter einem Anlass heißen in allen versionierten Dateien „ein Anwender“, mit Datum und dem
  öffentlichen Forenbeitrag. Namen und PN-Nummern stehen in der `CLAUDE.local.md`.

### Tests

- Alle `tests/check*.php` sind versioniert, eigenständige Skripte ohne PHPUnit. Fixtures stammen aus echten Daten
  und werden einzeln freigeschaltet (`.gitignore`-Ausnahme, `tests/fixtures/README.md`); Anwenderdaten
  neutralisieren.
- Jeder Laufzeit-Check bindet `tests/harness.php` ein: Warnungen werden zu Fehlern, `pruefe()` zählt, `ergebnis()`
  schreibt „N Prüfungen, M Fehler“ für `rotgruen.php`.
- Tests an Device oder Entity laufen über `tests/device-harness.php` am Kernel-Stub (`symcon/SymconStubs`, Submodul
  `tests/stubs`, gepinnt auf `be1e942`): `neuesGeraet()`, `neueEntitaet()`, `neueAusfuehrung()` (frisches Objekt,
  gleicher Kernel-Zustand), `KernelZaehler`, `HaSendungen`, `drueckeKnopf()`.
- Splitter und MQTT Discovery laufen über `FensterRahmenTrait` mit `SplitterHarness`, `DiscoveryDeviceHarness`,
  `DiscoveryConfiguratorHarness` und `ConfiguratorHarness`. Test: `check-discovery-configurator-orphans.php`.
- Der Stub ist kein Kernel: Er prüft MIN < MAX nicht, meldet bei `MaintainVariable` immer „angelegt“, zeigt
  Properties erst nach `ApplyChanges` und lässt keinen unpassenden Splitter verbinden. `IPS_GetObject()` lässt sich
  nicht mitzählen.
- Hilfsskripte, die keine Tests sind, gehören nach `tools/` (nicht versioniert).
- Offen: Einige Checks tragen noch eigene Attrappen; Umstellung beim nächsten Anfassen, nicht als Sammelaktion.

### CI / Version

- CI: `.github/workflows/check.yml` (PHP 8.5, Checkout mit Submodulen). Stil mit php-cs-fixer gegen das Submodul
  `.style`, `php -l`, JSON-Validität, `tests/check_locale.php`, `tests/check_property_contracts.php`,
  `tests/check_presentations.php`, alle `tests/check-*.php` (Glob, neue Tests laufen automatisch mit) und die
  MCP-Regeln über `bumaas/symcon-mcp-check@v1`.
- Version und Build: globale `CLAUDE.md`, Abschnitt „Build, Version und Store-Freigabe“.
