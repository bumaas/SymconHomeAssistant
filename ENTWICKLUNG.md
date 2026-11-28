# SymconHomeAssistant: Entwicklung und Begründungen

Das Regelwerk für die Arbeit an der Bibliothek ist die `CLAUDE.md`. Hier steht zu jeder Regel, woher sie kommt:
Anlass, Messungen an echten Anlagen, verworfene Wege und Funde aus Reviews. Die Abschnitte folgen der Gliederung der
`CLAUDE.md`. Gelesen wird diese Datei bei Bedarf, etwa bevor eine Regel geändert oder eine verworfene Idee neu
aufgegriffen wird.

Ergänzt wird jeder Eintrag beim Abschnitt seines Themas. Anwender, deren Meldung Anlass einer Änderung war, heißen
hier „ein Anwender“, mit Datum und dem öffentlichen Forenbeitrag, falls es einen gibt.

## Grundlagen (beide Familien)

### Datenfluss (klassische Bridge)

- Lesen: HA `mqtt_statestream` → MQTT Client/Server → Splitter → Device/Entity-Kinder.
  statestream publiziert **nur bei Zustandsänderung** (retained, QoS 1) — selten wechselnde
  Entitäten kommen daher ggf. nie per MQTT an.
- Initiale Werte: REST `/api/states/<entity_id>` bei `ApplyChanges` (KR_READY,
  Parent-Statuswechsel, manuelles Anwenden) über den Splitter.
- Schreiben: pro Domain über den fachlich vorgesehenen HA-Pfad (i. d. R. REST-Service-Call);
  MQTT nur, wenn ein echter Command-Pfad existiert. `*/set`-Topics behandelt der Splitter
  selbst per REST.
- **Jedes Kind des Splitters setzt einen Empfangsfilter**, auch eines, das keine Meldungen auswertet. Der
  Konfigurator trägt dieselbe Datenfluss-ID wie die Geräte und bekam bis build 255 jede Meldung zugestellt, weil
  er keinen Filter setzte; seit build 256 setzt sein `ApplyChanges()` `^$`. Messung am nuc (27.11.2026, 31 Geräte,
  6 Entitäten, ein Konfigurator, rund 600 Meldungen je Minute an `SendDataToChildren`): `sendToChildren` im Schnitt 12,1 ms statt 19 bis 26 ms. Ausreißer
  über 100 ms je zehn Minuten: bei Meldungen, die kein Gerät braucht, 1,8 statt 11,9, bei Meldungen mit Empfänger
  19,6 statt 27,8 (eine Stunde vorher gegen elf Minuten danach). Die verbleibenden liegen in den Geräten.
  Test: `tests/check-configurator-receive-filter.php` (Fixture `configurator_statestream_20261127.txt`).

### Reload-Fenster

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
  (der Anwender kann `SourceMode` im Formular oder per Skript geändert haben), das Device zusätzlich
  `writeTopicProcessingIndex()`.
- **Verzögerte Übernahme** (build 220): Der Timer `DeferredApply` ruft in allen vier Modulen mit
  Laufzeitlogik `IPS_RequestAction(…, "DeferredApply", "")`, `RequestAction` prüft das Reload-Fenster und
  ruft `ApplyChanges()` selbst. Nie `IPS_ApplyChanges` auf die eigene Instanz (Store-Review Punkt 13); bis
  build 219 taten das MQTT Discovery Device und Splitter. Der Knopf „Reload bundle“ des Discovery-Splitters
  geht seit build 223 über denselben Ident (vorher `IPS_ApplyChanges($id)` in der form.json). Test:
  `check-deferred-apply.php`, prüft auch alle form.json.
- **Regel für neue Module/Registrierungen:** Das Merkmal bleibt die letzte Registrierung in
  `Create()`; ein neuer Einstiegspunkt (öffentliche Funktion, Timer-Ziel, `MessageSink`-Zweig)
  prüft zuerst `isInstanceCreated()`. Ausgenommen ist das `ApplyChanges()` der beiden Konfiguratoren
  (build 251 und 256): Es setzt nur den Empfangsfilter `^$` und liest nichts; kommt dort ein Lesezugriff
  hinzu, gehört die Prüfung davor (Code-Review 27.11.2026, Befund 6).
- **Entladephase** (seit build 180): Beim Reload geht unser Splitter auf **105 (`IS_NOTCREATED`)**,
  bevor seine Kinder entladen werden (Mitschnitt nuc 01.10.2026 18:06:27). Bis build 179 plante
  jedes Kind daraufhin ein `DeferredApply` und fuhr `ApplyChanges` (Status 201), wen das
  Entladen mittendrin traf, endete mit einem Fatal (`Translate()` → false; bei drei Reloads
  dreimal). Jetzt ignorieren Device, Entity und MQTT Discovery Device den Parent-Status 105
  (`isRelevantParentStatusChange()`); die Splitter nicht, ihr Parent ist der MQTT Client des Kerns.
- Test: `tests/check-reload-window.php`; der Harness stellt das Fenster mit
  `$attributeRegistriert = false` nach: Jeder Attributzugriff warnt, ResolvedConfig liefert über
  die Naht `readResolvedConfigAttribute()` false (der Stub deklariert `ReadAttributeString` als
  `: string`). Properties simuliert er bei Device/Entity im Fenster nicht. Splitter und MQTT
  Discovery laufen über `FensterRahmenTrait` (Properties, Attribute und Timer warnen im Fenster;
  das Merkmal liefert über die Naht des Moduls false), angelegt mit `neueFensterInstanz()`.

### Parallele Aufrufe in einer Instanz

Eingeführt: 1.10 build 260.

- **Der Kernel lässt mehrere Aufrufe gleichzeitig in dieselbe Instanz.** Meldungen untereinander kommen
  nacheinander (der Parent wartet auf `SendDataToChildren`), aber Timer, `RequestAction` und `ApplyChanges`
  laufen daneben. Am nuc gemessen (27.11.2026, Rust-Edition, Gerät mit 110 Entitäten): Während ein Übernehmen
  3,6 s lief, kehrten fünf `StateCacheFlush` derselben Instanz in je 8 bis 19 ms zurück, und der Splitter meldete
  keine verzögerte Meldung des Geräts.
- Bis build 259 las jede Ausführung den ganzen Zustands-Cache einmal (`entityStateCacheMemory`) und schrieb bei
  jeder Änderung den ganzen gemerkten Stand zurück. Wartete sie dazwischen auf Home Assistant, löschte sie, was
  eine Meldung inzwischen eingetragen hatte: beim Übernehmen und `SyncStates` (eine REST-Abfrage je Entität), in
  der Entity-Instanz beim Schalten mit „Status emulieren“, im Gerät bei `number`, `input_text`, `datetime` und
  `input_datetime` mit „Status emulieren“ und beim Klima-Modus, dazu beim Timer für die Quelle des Mediaplayers.
- Folgen, im Testrahmen nachgestellt (an der Anlage nicht, der Cache ist von außen nicht lesbar): Kam eine
  Entität während des Übernehmens von „unavailable“ zurück, führte der Cache sie weiter als ausgefallen; standen
  die anderen auch so da, galt das Gerät nach zehn Minuten als nicht erreichbar. In der Entity-Instanz sprang
  die gerade gemeldete Helligkeit beim nächsten Attribut-Topic auf 0.
- Jetzt liest jede Änderung den Buffer vorher neu (`readEntityStateCacheForUpdate`, `HAEntityStore`) und setzt
  nur den eigenen Eintrag hinein; dekodiert wird nur, wenn eine andere Ausführung geschrieben hat. **Wer den
  Cache ändert, liest ihn über diese Funktion, nicht über `readEntityStateCache()`.** Kosten: ein
  Buffer-Lesezugriff je Änderung, im Testrahmen zwei statt einem je Meldung. Am nuc nicht messbar: `sendToChildren`
  des Splitters im ersten Fenster danach 3,9 ms je Meldung, in den vier Fenstern davor 4,4 bis 6,0 ms.
- Was bleibt: Zwischen Lesen und Schreiben liegt keine Sperre (so kurz wie die zwei Kernel-Aufrufe). Mit der
  Variable „Nicht verfügbare Entitäten (JSON)“ liest der Flush-Timer den Cache schon für diese Variable und
  wertet die Erreichbarkeit mit demselben Stand aus; eine Meldung dazwischen bleibt unausgewertet. Eine Meldung,
  die jünger ist als die REST-Antwort zur selben Entität und vor ihr verarbeitet wird, überschreibt die Antwort.
- Dass im Gerät das Schalten der Hauptvariable über das `set`-Topic den Cache nicht berührt, liegt am
  Unterschied der beiden Schaltpfade (Abschnitt „Schalten“).
- Test: `tests/check-state-cache-parallel.php` (Fixtures `ha_states_test_entities.json`, `hue_aurelle_*`). Die
  zweite Ausführung läuft über die Naht `HaSendungen::$unterwegs` mitten in der ersten, während deren Anfrage an
  Home Assistant unterwegs ist. Welche Meldungen in diese Zeit fallen, ist angenommen. Für `SyncStates` ohne
  Parent stellt der Test `HaSendungen::$parentAktiv`, die Lesezugriffe zählt `DeviceHarness::$pufferLesungen`.

### Empfangsfilter

Eingeführt: 1.10 build 252, klassische Bridge build 256 und 259.

- Anlass: Performance-Mitschnitt eines Anwenders (26.11.2026, Z-Wave JS UI, 72 Geräte, Raspberry Pi).
  Der Splitter brauchte je Meldung im Median 1277 ms, davon 1189 ms für `SendDataToChildren`; in 332 s verarbeitete
  er 259 Meldungen und war die ganze Zeit beschäftigt. Die beiden mitgeschnittenen Geräte bekamen jede Meldung,
  auch die fremder Geräte, und brauchten je 8 bzw. 14 ms dafür (`getRuntimeProcessingContext`, nie aus dem Memo).
  **Das war der Rückstau**, nicht die Zwischenspeicher: build 246 nahm je Meldung 86 ms weg
  (`updateTopicPayloadCache`), nach dem Update auf build 250 lag der Median noch bei 1092 ms.
- Ursache: `buildReceiveFilterRegexParts()` fasste seit 27.06.2026 (`3dbebc8`) Topics mit mindestens drei
  gemeinsamen Anfangszeichen zu „Anfang und irgendetwas“ zusammen. Liegt alles eines Senders unter einer Wurzel,
  reicht dafür das availability-Topic der Bridge neben den Topics des Geräts: Der Filter jedes Geräts lautete
  `Z-Wave-JS/…`. Dasselbe bei EMS-ESP (`ems-esp/…`) und Zigbee2MQTT (`zigbee2mqtt/…`, Fixture mit 88 Lampen:
  jede Meldung an alle 88).
- Jetzt baut `HAMqttTopicFilter::exactTopicsFilterPattern()` den Filter als Baum über die Topic-Ebenen: genau die
  Topics aus `collectRelevantTopics()`, kein Anfang und keine Verlängerung. Mit dem Bundle des Anwenders und den 400
  Meldungen seines Mitschnitts im Harness: vorher 28.800 Zustellungen (jede an 72 Geräte), jetzt 55 (345 Meldungen
  an kein Gerät, 55 an je eines). Der Filter eines Geräts ist dort im Median 367 Zeichen lang.
- Bauformen des Musters: Schrägstrich als `\\?\/` wie im Filter der klassischen Bridge, Gruppen `(?:…)` und
  `(?:…)?`. Zeichen außerhalb von ASCII ersetzt `[^"\/]+` für die Ebene, damit das Muster auch passt, falls sie in
  der Meldung als `\uXXXX` stehen; ob der Kernel das je tut, ist nicht gemessen (der Splitter sendet mit
  `JSON_UNESCAPED_UNICODE`).
- **Topics abgewählter Entitäten kommen nicht mehr an.** Der Topic-Index führt ihr Zustands-Topic unter `topics`
  und `warnings`, der Filter nimmt wie vor dem 27.06. nur `collectRelevantTopics()` (angewählte Entitäten). Die
  Warnung in der Entitätenliste gleicht das Formular beim Öffnen aus dem Zwischenspeicher des Splitters ab. Wer
  eine neue Art Topic auswertet, trägt sie in `collectRelevantTopics()` **und** `buildTopicProcessingIndex()` ein;
  der Test vergleicht beide.
- **Beim Anwender belegt** (Mitschnitt 27.11.2026 mit build 254, Splitter und drei Geräte, 15 Minuten): Der
  Splitter braucht je Meldung im Median 4,0 ms statt 1092 ms und ist 1,3 % der Zeit beschäftigt (1936 Meldungen in
  931 s). Die drei Geräte bekamen 15, 46 und 14 Meldungen, alle zu eigenen Topics; was unter ihrer Wurzel
  zusätzlich lief, steht in keiner Ankündigung. Ein Topic mit Umlauten kommt an, die Klasse für Nicht-ASCII-Zeichen
  trägt also am echten Kernel. Am nuc hängen nur zwei Geräte an einem Splitter mit MQTT-Quelle, beide ohne
  laufende Meldungen.
- **Klassisches Gerät und Entity nutzen denselben Baustein seit build 259** (Etappe 5 im Konzept):
  `HAMqttTopicFilter::subtreeTopicsFilterPattern()` trifft je Entität `<base>/<domain>/<object_id>` und alles
  darunter (die Attribut-Topics), aber kein Topic, das nur gleich anfängt (`bad_2` bei `bad`). Bis build 258 fasste
  `HADeviceCore::buildReceiveFilterRegexParts` die Objekt-IDs einer Domäne über ihren gemeinsamen Anfang zusammen
  („evcc_[^"]*“). Am nuc (27.11.2026, 31 Geräte) lief im Mitschnitt damit nichts Unnötiges (1224 Zustellungen),
  statisch gegen alle 1380 HA-Entitäten trafen aber zwei Geräte neun fremde; bei einer Anlage, deren Entitäten nach
  Räumen heißen, kann das jede Meldung eines Raums sein. Mit dem neuen Baustein: 0 fremde, 0 fehlende Treffer.
  Der Filter wird länger, das Verteilen im Splitter kostet dadurch am nuc nicht mehr (Zahlen im Konzept, „Stand
  Etappe 5“). Ohne Entitäten setzt das Gerät `^$` wie der Konfigurator. Das Muster des Discovery-Geräts
  (`exactTopicsFilterPattern`) ist unverändert, an 229 Topic-Listen aus den lokalen Bundles verglichen.
- Test für das klassische Gerät: `tests/check-device-receive-filter.php` (Fixture
  `receive_filter_statestream_20261127.json`, 168 Entitäten der eigenen Anlage mit Gerätezuordnung). Die
  Entity-Instanz kommt ohne Splitter nicht bis zum Filter, der Test ruft dort `updateReceiveFilter()` direkt.
- Test für das Discovery-Gerät: `tests/check-discovery-receive-filter.php` (Fixtures
  `discovery_ems_esp_20261116.json`, `ha_mqtt_discovery_bundle_zigbee2mqtt_light_current_v2.json`,
  `discovery_zwavejs_20261125.json`; die fremden Meldungen in Teil 2 aus dem Mitschnitt, Namen ersetzt; der Fall
  mit Umlaut ist eine Variante der Fixture).

### Verarbeitungskontext im Buffer

Eingeführt: 1.10 build 257 und 258.

- Anlass: zweiter Mitschnitt eines Anwenders (27.11.2026, Z-Wave JS UI, Raspberry Pi). Jede Meldung läuft dort in
  einer eigenen PHP-Ausführung, das Memo im Objekt hielt nie (`cached: false` bei allen 75 Meldungen). Das
  Discovery-Gerät las je Meldung seine Definition zweimal, dekodierte und normalisierte alle Entitäten und baute
  den Topic-Index neu auf: 8 bis 14 ms bei 18 bis 32 Entitäten.
- Das klassische Gerät löst dasselbe seit build 147 und 165 über einen Buffer mit Signatur und Zeilenzugriff
  (`getConfiguredEntityById`). **Build 257** nimmt das Format dafür aus `HADeviceCore` heraus in
  `libs/HAKeyedBlob.php`: Kopfzeile als JSON, je Eintrag `"<Schlüssel>"` TAB `<Wert als JSON>`, Zeilenzugriff ohne
  Volldekodierung. Reiner Umbau, das klassische Gerät schreibt Byte für Byte denselben Blob (an 18 Entitätslisten
  aus den Fixtures und 349 Zeilenzugriffen gegen den alten Code verglichen). Die Klasse kennt weder Signatur noch
  Inhalt; ob ein Blob gilt, entscheidet der Aufrufer am Kopf.
- **Build 258:** Das Discovery-Gerät hält seinen Kontext zusätzlich im Buffer `RuntimeContextCache`. Kopf mit
  Signatur und Namenszählern, Zeilen `t:<Topic>` (Index-Einträge des Topics je Art) und `e:<entity_key>` (die
  normalisierte Entität, jede einmal). `getTopicProcessingSlice()` liefert je Meldung nur, was das Topic braucht,
  in drei Stufen: Memo der Ausführung, Buffer, voller Aufbau mit neuem Blob. `ReceiveData` holt den vollen Kontext
  (`getRuntimeProcessingContext`) nur noch, wenn sich Verfügbarkeit oder Warnungen geändert haben.
- Die Signatur (`buildRuntimeContextSignature`) geht über die Rohtexte der Definition und des Topic-Index, die
  Properties `EntitySelection` und `DeviceID` und den Marker `RUNTIME_CONTEXT_MARKER`. Die Definition wird dafür
  je Meldung einmal gelesen, aber nicht dekodiert. `ApplyChanges` leert den Buffer zusätzlich. **Wer den Aufbau
  der normalisierten Entität oder des Topic-Index ändert, gibt dem Marker einen neuen Wert**, sonst gilt nach
  einem Update der Blob des alten Codes weiter.
- Kodiert wird mit `JSON_PRESERVE_ZERO_FRACTION`, damit `min`, `max` und `step` Kommazahlen bleiben. Der Test
  vergleicht den Ausschnitt aus dem Buffer mit `===` gegen den aus dem vollen Aufbau.
- Grenze: Symcon kürzt einen Buffer über 512 KB auf die neuesten 512 KB, dem Blob fehlte dann der Kopf. Über
  `RUNTIME_CONTEXT_MAX_BYTES` (500.000) legt das Gerät deshalb keinen Blob an und baut weiter je Meldung auf. In
  den 18 lokalen Bundles (528 Geräte) trifft das ein Gerät mit 476 Entitäten; der größte Blob darunter hat 434 KB
  (121 Entitäten). Das klassische Gerät hat seine Grenze noch bei 1 MB (`CONFIGURED_ENTITIES_CACHE_MAX_BYTES`),
  ein Blob zwischen 512 KB und 1 MB würde dort bei jeder Meldung neu geschrieben; dafür gibt es keinen Fall.
- Gemessen im Harness mit dem Bundle des Anwenders (72 Geräte, je 30 Meldungen in frischer Ausführung, nur PHP):
  im Mittel 6,9 ms je Meldung vorher, 0,24 ms danach. Am Raspberry Pi des Anwenders nicht gemessen. Der Messpunkt
  `ReceiveData.getRuntimeProcessingContext` heißt weiter so, trägt jetzt `source` (memo, buffer, rebuild), und
  `entities` zählt die Entitäten des Topics statt aller.
- Test: `tests/check-discovery-device-context-cache.php` (Fixtures `discovery_zwavejs_20261125.json` und
  `discovery_ems_esp_20261116.json`): Lesezugriffe je Meldung, Gleichheit für jedes Topic der vier Geräte,
  gleiche Werte mit und ohne Cache, geänderte Ankündigung, Blob mit fremder Signatur, beschädigte Zeile,
  Erreichbarkeit, Reload-Fenster. Das klassische Gerät prüft weiter `check-configured-entities-cache.php`.

### Variablennamen

- Hauptvariablen benennt `HAEntityVariableNamingTrait` (Device, Entity, MQTT Discovery), Regeln in
  `docs/ARCHITEKTUR.md`. Letzter Ausweg vor der `entity_id` ist der ungekürzte eigene Name
  (build 171; Anlass: MCP-Blindtest, Hauptvariable hieß `input_number.test_zahl`) — aber nur,
  wenn ihn keine zweite Entität der Instanz ebenso als Ausweg nimmt (build 173, sonst gleichnamige
  Variablen). Neue Zählschlüssel in `sharedEntityBaseNameCounts` verlangen einen neuen
  `CONFIGURED_ENTITIES_CACHE_MARKER`, weil der Konfigurations-Cache die Zähler mitspeichert.
  Test: `tests/check-shared-entity-naming.php`. Buttons nehmen denselben Ausweg (build 198,
  `getSharedButtonVariableName`; Test `tests/check-button-caption.php`).
- Fehlermeldungen an Anwender nennen den Variablennamen, nicht den Ident (`rejectInvalidActionValue`,
  build 198); fehlt die Variable, bleibt es beim Ident.
- Keine zwei Variablen einer Instanz heißen gleich (build 188, MCP-Regel 14, Abstimmung 03.10.2026):
  Entitätsname vor Zusatzvariablen bei mehreren Entitäten derselben Art oder schon vergebenem Namen,
  Art-Hinweis bei gleichem HA-Namen verschiedener Domänen, ungekürzter Name statt „Status (FAN)".
  Zusatzvariablen werden **zentral beim Anlegen** benannt (`MaintainVariable()` in
  `HASharedPresentationTrait` → `scopeCreatedEntityVariableName()`), nicht an den einzelnen
  Stellen — eine neue Art Zusatzvariable braucht dafür nichts. Die Namen der Kinder liest es einmal je
  Ausführung (`childNameCounts`, build 205, Code-Review: vorher je neuer Variable alle Namen einzeln —
  quadratisch beim ersten Abgleich großer Geräte); „doppelt" bestätigt es an der Anlage, weil der Merker
  ersetzte Variablen weiterzählt. Im Testrahmen meldet der Stub bei `MaintainVariable` immer „angelegt",
  dort läuft die Benennung also auch für bestehende Variablen. Bestehende Variablen benennt das
  nicht um. Test: `tests/check-variable-name-collisions.php` (Fixture
  `naming_collisions_20261003.json`; der Testrahmen übersetzt nicht, erwartet sind englische Schlüssel).

### Schieberegler ohne Bereich

Eingeführt: 1.9 build 236.

- HA erlaubt bei `number` min = max (EMS-ESP „Maximale Brennerleistung“ 100/100). Der Kernel verwirft dann
  den Schieberegler („Maximum Value must be higher than Minimum Value“), die Variable steht mit Aktion, aber
  ohne Darstellung da. Am nuc nachgestellt (16.11.2026, Bundle-Splitter #54459 mit dem Bundle des Anwenders): Jedes
  „Änderungen übernehmen“ endete mit „Fehler beim Übernehmen der Änderungen … (Code: -32603)“, danach scheiterte
  der Testbereich der Konsole mit „Uncaught Unexpected presentation when trying to determine minimum“. Der Anwender
  meldete -32603 mit anderem Text („Parameter type of Value does not match“); dass es dieselbe Ursache ist,
  ist wahrscheinlich, aber nicht belegt.
- `fitPresentationToVariableType()` (`HASharedPresentationTrait`, also beide Familien) macht aus einem
  Schieberegler mit MIN >= MAX ein Eingabefeld (`VALUE_INPUT`, nur PREFIX/SUFFIX übernommen), geprüft nach dem
  Runden auf Ganzzahlen. Der Kernel-Stub prüft MIN < MAX nicht; deshalb prüft es der Test selbst.
- Eine Textvariable mit Aktion braucht keine Darstellung: Der Testbereich zeigt sie als Textfeld (nuc,
  „Wartungsdatum“).
- Test: `tests/check-slider-min-max.php` (Fixture `discovery_ems_esp_20261116.json`).

## Klassische Bridge

### Erreichbarkeit

Eingeführt: seit 1.5.

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

### HA-Startphase

Eingeführt: build 209.

- Während HA startet, beantwortet es `/api/template` schon mit HTTP 200, aber `states` ist noch leer:
  Die Entitäten-Abfrage liefert `[]`. `/api/config` meldet dabei `state = NOT_RUNNING` (nicht
  `STARTING`, das kommt erst kurz vor `RUNNING`; Mitschnitt 20.10.2026).
- Bis build 208 setzte das Device darauf 211 (Entity 210), verwarf `ResolvedConfig` samt Empfangsfilter
  und fragte nie wieder. nuc 20.10.2026: Update-Runde startet HA mit Mosquitto neu, Splitter 03:01:38
  wieder verbunden, HA erst 03:03:00 gestartet, 30 von 32 Devices 7 h ohne Werte. Ein HA-Neustart ohne
  Mosquitto-Neustart löst bei den Kindern nichts aus (der Splitter bleibt 102), half also nicht.
- Jetzt fragt `confirmNotFound()` (HADeviceCore) bei leerer Antwort `isHomeAssistantRunning()`: nicht
  `RUNNING` oder keine Antwort → Konfiguration bleibt, Nachprüfung nach 30 s; `RUNNING` → 211/210 wie
  bisher, Nachprüfung alle 300 s. Nachgeprüft wird über den vorhandenen `DeferredApplyTimer`
  (kein neuer Timer, Reload-Fenster).
- **Status in der Wartezeit** (build 232): 203 „Home Assistant startet noch oder antwortet nicht“
  (`STATUS_HA_NOT_READY` in `HADeviceConstants`). Bis build 231 blieb der letzte Status stehen: nuc 16.11.2026
  nach Ausfall des HA-Rechners Splitter wieder 102, die Devices aber auf dem 201 vom Ausfall („Kein aktiver
  Parent“). Es sah nach einem Hänger aus, war aber die 30-s-Nachprüfung; jedes ApplyChanges von Hand startete
  sie neu. Die Entities kamen sofort, ihre Helfer kennt HA schon beim Start.
- **Gescheiterte Abfrage** (`null`: keine Antwort, HTTP-Fehler, Kernel bricht ab): `deferFailedQuery()` setzt
  ebenfalls 203 und plant die Nachprüfung, zehnmal nach 30 s, danach alle 300 s (Zähler im Buffer
  `QueryFailures`). Bis build 231 gab es hier gar keine Nachprüfung.
- **Ein Zähler für beide Fälle** (build 237, Code-Review 16.11.2026 Befund 3): Startphase (`confirmNotFound()`
  ohne `RUNNING`) und gescheiterte Abfrage laufen über `scheduleHaRecheck()`. Zurückgesetzt wird nur bei einem
  eindeutigen Befund (Konfiguration geliefert oder HA läuft und meldet „nicht gefunden“), nicht schon bei jeder
  Antwort, sonst setzte eine leere Antwort der Startphase den Zähler jedes Mal zurück. Bis build 236 prüfte die
  Startphase ohne Ende alle 30 s nach, obwohl die README fünf Minuten nach zehn Versuchen nennt.
- Test: `tests/check-ha-startphase.php` (Fixture `ha_startphase_20261020.json`, Teil 3 die Statusfolge vom
  16.11.); läuft über einen echten Splitter als Parent, dessen `performCurl` HA nachstellt
  (`SplitterHarness::$haAntwort`).

### Schalten

- Zwei Schaltpfade für die Hauptvariable: `HADeviceCoreTrait::handleMainEntityRequestAction` (Entity) und
  `HomeAssistantDevice::executeMainEntityAction` (Device, eigene `RequestAction`). Änderungen an beiden
  nachziehen. Deckungsgleich sind sie nicht: Mit „Status emulieren“ schreibt nach dem `set`-Topic nur der Pfad der
  Entity den Wert optimistisch (`applyOptimisticEntityValue`), der des Geräts nicht; über REST
  (`trySendMainEntityValueViaRest`) tun es beide. Ob der Unterschied gewollt ist, ist offen.
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
- **`…/set` im Splitter** (build 207): Auswahl und Text (`select`, `input_select`, `input_text`,
  `TEXT_PAYLOAD_DOMAINS`) gehen unverändert an HA. Bis build 206 lief jeder Befehl durch
  `normalizePayload()`: Die Option „on" wurde `"1"` (HA: 400 „Option 1 is not valid"), „off" und
  „null" wurden leer — beide Male fiel der Befehl auf MQTT zurück, wo ihn niemand liest (Anwender mit
  Samsung-Wärmepumpe, 15.10.2026; am nuc betroffen: Hue-Wiedereinschaltverhalten, evcc „Mit min. laden",
  „Fahrzeug"). Ein sichtbares `set`-Topic heißt immer: der REST-Weg ist gescheitert.
  Test: `tests/check-rest-set-payload.php` (Fixture `rest_set_select_20261015.json`); der
  `SplitterHarness` fängt dafür `performCurl` (seitdem protected) und `SendDataToParent` ab.

### Attribute im heißen Pfad

- statestream meldet Zustand und Attribute als getrennte Topics, den Zustand zuerst. Ein
  „state = open" trägt also keine Position; `extractPositionEntityUpdateContext()` (Cover und
  Ventil) liest sie deshalb aus dem State-Cache, überlagert über die gespeicherten Attribute.
- Bis build 183 las es nur `$this->entities` — nach einer frischen PHP-Ausführung (Rust, jede
  Meldung) also die Konfiguration mit der Position des letzten `ApplyChanges`. Am nuc
  (03.10.2026, Gast-Rollladen) stand dort 100: Jedes „state = open" setzte die Hauptvariable auf
  100, erst das folgende `current_position` korrigierte sie. Ohne Position in der Konfiguration
  wäre es über `normalizeCoverStateToLevel('open')` ebenfalls 100 geworden.
- **Licht** (build 208, t/142973 #373): statestream schickt bei jeder Änderung auch
  `supported_color_modes` und `supported_features`; jedes dieser Topics gleicht alle Lichtvariablen ab
  (`handleLightAttributeTopic` → `updateLightAttributeValues`). Bis build 207 lieferte
  `getStoredAttributeTopicAttributes()` dafür nur `$this->entities`: War das Licht beim letzten
  `ApplyChanges` aus, sprang die gerade gemeldete Helligkeit auf 0, Farben wurden leer (am nuc nachgestellt,
  Küche.Deckenpanel). Seitdem überlagert der Helfer den State-Cache, das gilt auch für Staubsauger,
  Device Tracker und `refreshStatefulAttributeValues`. Ein `null`-Topic (Licht aus) schreibt der Lichtzweig
  seitdem als 0 bzw. `''` und hält es im Cache fest, wie der volle Pfad; vorher verwarf er es, und erst der
  Fehler oben setzte die Werte zufällig zurück. Test: `tests/check-light-attributes-statestream.php`
  (Fixtures `hue_aurelle_*_20261019.*`).
- **Allgemein:** Wer im heißen Pfad Attribute braucht, liest Konfiguration **und** State-Cache
  (Muster `resolveMainEntityActionAttributes`); `$this->entities` allein ist der Stand des letzten
  `ApplyChanges`.
- Test: `tests/check-cover-position-statestream.php` (echter Mitschnitt, Fixtures
  `cover_*_gast_20261003.*`). Für Ventile gibt es keinen Mitschnitt; sie laufen durch dieselbe
  Funktion.

### Veraltete Variablen

- Was das Modul nicht mehr versorgt, löscht es nicht von sich aus: `markVariableAsLegacy`
  (`libs/HALegacyVariableMigration.php`) nimmt nur die Aktion weg und führt den Ident im Attribut
  `ObsoleteVariables`; das Log nennt ihn einmal (`KL_MESSAGE`). **Name und Position bleiben**
  (Store-Review Punkt 11, build 221; bis build 220 „(veraltet)" am Namen und Rückbenennung bei
  Rückkehr). Das Formular zählt die Variablen auf, der Knopf „Veraltete Variablen löschen“ löscht nach
  Rückfrage (`confirm`, Aktion `DeleteObsoleteVariables`). Das ersetzt die Abstimmung vom 03.10.2026
  („kein Löschknopf“), Entscheidung Burkhard 02.11.2026, Muster Roborock 2.6 build 112. Gilt für
  Device, Entity und MQTT Discovery Device. Entscheidend ist allein der Ident, nie der Name.
- **Gelöscht wird nur, was das Formular gezeigt hat** (build 224, Code-Review 02.11.2026): Der Knopf trägt die
  angezeigten Idents als JSON-Wert, `handleObsoleteVariablesAction` löscht die Schnittmenge mit der aktuellen
  Liste. Bis build 223 löschte er die Liste zum Zeitpunkt des Klicks, auch Variablen, die ein ApplyChanges im
  Hintergrund nach dem Öffnen gekennzeichnet hatte. Ein anderer Wert (`true` aus einem alten Formular) löscht
  nichts. Von Hand gelöschte Variablen fallen dabei aus der Liste. Tests drücken Knöpfe über
  `drueckeKnopf()` (device-harness), also mit dem Wert aus `onClick`.
- **Rückkehr:** Das zentrale `MaintainVariable` (`HASharedPresentationTrait`) nimmt im vollen Pfad jeden
  Ident aus der Liste, den das Modul wieder pflegt oder löscht. Wer den Rückkehr-Zustand vor
  `MaintainVariable` braucht (Aktion wieder setzen), fragt vorher `isObsoleteIdent()`. Das Attribut wird in
  `Create()` vor dem Merkmal registriert und nur mit `@` gelesen (Reload-Fenster).
- **Heißer Pfad** (build 233, Code-Review 02.11.2026 Befund 7): `ReceiveData` ruft
  `pauseObsoleteBookkeeping()`, `ApplyChanges` `resumeObsoleteBookkeeping()`; dazwischen liest und schreibt
  niemand die Liste (vorher ein `ReadAttributeString` je MQTT-Meldung). Stattdessen führt die Liste je Ident
  den Zeitpunkt der Kennzeichnung (`{"ident": unixzeit}`, bis build 232 eine Liste der Idents, die weiter
  gelesen wird). `getObsoleteVariables()` (Formular und Löschknopf) lässt jede Variable weg, deren
  `VariableUpdated` danach liegt, und nimmt sie aus der Liste. Das schließt auch eine alte Lücke: Ein neuer
  Zustand im heißen Pfad läuft über `SetValue`, nicht über `MaintainVariable`; bis build 232 bot der Knopf
  eine so wieder beschriebene Hauptvariable weiter an und löschte sie samt Archiv
  (`check-action-contract.php` Teil 12).
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
  `legacy_idents_20261003.json`, auch Formular und Löschen), `tests/check-removed-entities.php` (Fixture
  `removed_entities_ccu3_20261003.json`, auch Rückkehr), `tests/check-obsolete-variables-entity.php`.
  Das Entity-Modul hat einen eigenen Pfad (`cleanupRenamedSharedEntityObjects`), am nuc ohne Doppelgänger.
- **Medienobjekte** (Vorschau, Stream, Cover, Discovery-Bild): Name und Position nur beim Anlegen;
  `ensureManagedMediaObject` reicht `$created` an den Meta-Callback (build 221). Vorher bei jeder
  Pflege, beim Discovery-Bild bei jedem Bild-Update. Test: `tests/check-media-object-meta.php`.
- **Position im heißen Pfad** (build 226, Code-Review 02.11.2026): Weil das Modul die Position nur noch beim
  Anlegen setzt, muss jeder Pfad, der anlegt, sie richtig kennen. `position_base` errechnet
  `assignEntityPositionBases()` (Device: Blöcke je Domäne, Entity: Zehnerschritte) im vollen Pfad und beim
  Aufbau des Konfigurations-Caches; die rehydrierte Entität trägt sie also auch im heißen Pfad. Bis build 225
  fehlte sie dort, `getEntityPosition()` lieferte 10, das Cover kam mit Basis 0: Bei einem Player, der nicht
  die erste Entität ist, lagen Cover und neu angelegte Attributvariablen vor den Variablen des Players. Neue
  Felder im Cache verlangen einen neuen `CONFIGURED_ENTITIES_CACHE_MARKER` (jetzt `b226`). Test:
  `tests/check-media-cover-position.php` (Fixture `media_cover_wintergarten_20261102.json`).

### Mediaplayer

- „Wiedergabe" (`~PlaybackPreviousNext`), „Titel" (`~Song`) und „Interpret" (`~Artist`) tragen
  Systemprofile über `VARIABLE_PRESENTATION_LEGACY`. Nicht auf Darstellungen umstellen: Die
  Kachel-Visualisierung erkennt eine Instanz nur daran als Mediaplayer (Doku „Objekt-Darstellung",
  geprüft 03.10.2026; so seit build 29). Lautstärke, Stumm und Fortschritt gehen über Darstellungen
  mit `USAGE_TYPE`.
- Die Lautstärke (0…1, Anzeige 0…100 per `ConversionFactor`) hängt im Anzeigeintervall „ %" an
  (build 192): sonst lautete der formatierte Wert — den MCP zeigt — nur „60". Die Kachel zeigt das
  „%" trotzdem nur einmal (am nuc mit eigener Darstellung an Sonos erprobt). Test:
  `tests/check-volume-suffix.php`.
- **Aktuelle Quelle** (build 199, Blindtest 04.10.2026, Abstimmung Burkhard): Die Auswahl `source`
  bietet nur `source_list` an; eine Quelle außerhalb (Sonos „Spotify Connect") zeigt sie als „-" —
  HA selbst zeigt sie dann auch nicht (Frontend `more-info-media_player.ts`, keine Markierung).
  Deshalb die Anzeige `current_source` (Text, nur lesen): abgeleitet in
  `mapMediaPlayerAttributeAliases` (nur mit `source_list`, ohne `source` leer), im heißen Pfad
  schreibt `handleMediaPlayerAttributeTopic` sie bei jeder `source`-Meldung mit. Die Auswahl bekommt
  bewusst keine Zusatzoption (wäre auswählbar, HA lehnt ab). Test: `tests/check-current-source.php`
  (Fixture `sonos_wintergarten_20261004.json`).
- **Weggefallene Attribute meldet statestream nie** (HA `mqtt_statestream._state_publisher` veröffentlicht
  nur vorhandene Attribute, retained — das alte Topic bleibt stehen). Für die Quelle (build 200): Auf eine
  Zustandsmeldung ohne Attribute reiht `scheduleMediaPlayerSourceCheck()` den Player ein, solange der
  State-Cache eine Quelle führt; der vorhandene `MediaRefreshTimer` fragt dann per REST
  (`processPendingSourceChecks`) und leert `source` und `current_source`. Kein neuer Timer (Reload-Fenster).
  Im vollen Pfad setzt die Normalisierung beide auf `''`. Andere Attribute (Titel, Interpret …) bleiben
  nach dem Stoppen weiterhin stehen. Der Testrahmen bildet die REST-Abfrage über
  `HaSendungen::$zustaende` nach.

### Kamera

Eingeführt: build 193.

- Der Kamera-Zustand bleibt als Rohwert (`idle`/`recording`/`streaming`) in der Variable; die
  Darstellung ist eine Wertanzeige mit diesen Optionen (`HACameraDefinitions::STATE_OPTIONS`,
  gemeinsamer Helfer `getStateOptionsPresentation()` wie beim Mediaplayer-Status). Keine Aufzählung:
  Die verlangt in Symcon eine Variablenaktion (siehe `check-readonly-enum-presentation.php`).
- Der rotierende Kamera-Schlüssel (`access_token`, `token=` in `entity_picture`) kommt nicht in die
  Konfiguration und nicht ins Config-Bundle: `removeSecretAttributes()` beim Schreiben in
  `UpdateConfiguration()` und beim Export, `normalizeCameraAttributes()` für REST-Zustände
  (`mergeStateAttributes`). Bis build 192 stand er am nuc in der Konfiguration der HIKVISION.
- Test: `tests/check-camera-state-token.php` (Fixture `camera_hikvision_20261003.json`, Schlüssel
  durch Platzhalter ersetzt).
- **Mediaplayer-Cover** (build 198, Blindtest 04.10.2026): `entity_picture`/`media_image_url` tragen
  `token=` — damit liefert `/api/media_player_proxy` das Bild **ohne Anmeldung** (eigenes HA geprüft:
  mit token= 200, ohne token= und ohne Anmeldung 403). `removeAccessTokenFromUrl()` entfernt ihn in
  `mapMediaPlayerAttributeAliases` (REST/Konfiguration), in `removeSecretAttributes` (gespeicherte
  Konfiguration, Export) und im Attribut-Topic (`handleMediaPlayerImageAttributeTopic`); `cache=` bleibt.
  Test: `tests/check-media-image-token.php`.

### Buttons und Diagnose

Eingeführt: build 189, MCP-Punkt H5.

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

### Nachkommastellen

Eingeführt: build 201, Blindtest-Befund 8.

- HA führt die Anzeigegenauigkeit (`suggested_display_precision` der Integration, `display_precision` des
  Anwenders) **nur in der Entity-Registry** — weder Zustand noch statestream noch Templates kennen sie
  (Anzeigeeinstellung, kein Zustand). Sie ist je Sensor gewählt, nicht aus Einheit oder Wert ableitbar
  (nuc 04.10.2026, Spannung: Homematic 0, Marstek AC 1 / Batterie 2 / Zelle 3, Matter Netz 0 / Batterie 2).
- Der Splitter holt sie per WebSocket (`HAWebSocketClient`, eigener RFC-6455-Client, einmalige Anfrage
  `config/entity_registry/list_for_display`, Feld `dp`; keine Adminrechte nötig) und hält sie 900 s im
  Buffer `DisplayPrecisionCache` — auch einen Fehlschlag, damit 60 Kinder beim Start nicht 60 Versuche
  auslösen. Kinder fragen per `ForwardData` mit `DisplayPrecision` (Liste ihrer Entitäten).
- Device/Entity tragen sie in `UpdateConfiguration()` als Attribut `display_precision` ein
  (`applyDisplayPrecisions`, nicht im Bundle-Modus); ohne Antwort bleiben die Werte der letzten
  Konfiguration. `getNumericDigits()` gibt ihr Vorrang vor allem Abgeleiteten.
- **Ohne Angabe** (am nuc 90 Float-Sensoren, HA zeigt dort den ungerundeten Wert): Stellen aus dem Wert,
  nur zunehmend, höchstens 3 (`getObservedFloatDigits`, liest die bestehende Variable). Feste 2 Stellen
  verworfen: 75 der 90 haben glatte Werte („80.00 %"). Bis build 200 gab es hier 0 Stellen — Symcon
  schneidet ab, 2.98 V erschien als „2 V".
- Test: `tests/check-display-precision.php` (Fixture `display_precision_20261004.json`: echte Zustände,
  Registry-Einträge und WebSocket-Rahmen). Testrahmen: `HaSendungen::$anzeigeGenauigkeit`,
  `SplitterHarness::$registryAntwort`.

### Selbsttest

Eingeführt: build 228.

- „MQTT-Daten empfangen“ und „Empfangene Domänen“ im Selbsttest des Splitters zählen nur
  statestream-Topics (`getStatestreamDomain`: genau `<base>/<domain>/<object_id>/<attribut>`, nicht `…/config`).
  Bis build 227 zählte jedes Topic unter dem Base-Topic. Mit dem Standard `homeassistant` liegen dort auch
  Discovery-Konfigurationen anderer Geräte und die Startmeldung `homeassistant/status`: Ein Anwender (t/142973 #381,
  14.11.2026) hatte `base_topic: homeassistant_stream` in HA, der Selbsttest meldete „Empfangene Domänen:
  sensor, status, switch“ und „alles in Ordnung“, es kam aber kein einziger Wert an.
- Die Form allein reicht nicht (build 230, Code-Review 14.11.2026): Ein LinkTap-Gateway legt eigene Topics als
  `homeassistant/linktap/<id>/state` und `…/set` ab. Als statestream zählt deshalb nur eine Domäne, die das Modul
  kennt (`HADomainCatalog::isDomainSupported`, gefragt in `recordSeenDomain()` nur, solange die Domäne noch nicht
  im Buffer steht), und nie ein `…/set` (der eigene Befehl nach abgelehntem REST-Aufruf kommt über das Abo zurück).
  „Empfangene Domänen“ nennt damit nur noch Domänen, die das Modul verarbeitet (kein `automation`, `person`, `sun`).
  Bekannte Lücke: ein fremdes Gerät, das unter einer HA-Domäne in derselben Form sendet
  (`homeassistant/binary_sensor/garden/state` aus der HA-Doku); dafür gibt es keinen Mitschnitt.
- Seit build 231 (Code-Review 14.11.2026, Befunde 3 bis 8) liegt der Stand in einem Buffer `StatestreamSeen`
  (`<epoch>|<persistiert>|<domäne>|…`, `recordStatestreamMessage()`): eine Meldung liest zwei Buffer statt drei.
  Das Attribut trägt `<epoch>|<base_topic>`; stellt der Anwender `MQTTBaseTopic` um, vergisst `ApplyChanges` Empfang
  und Domänen des alten (`forgetStatestreamOfOtherBaseTopic()`, nur bei einem Wechsel, weil `ApplyChanges` auch bei
  jedem Kernel-Start läuft). Topics zerlegt allein `topicPartsBelowBase()` (Selbsttest, REST-Quittung,
  Laufzeitmessung); vorher drei Zerlegungen mit eigenen Regeln, ein Base-Topic mit Leerzeichen am Ende lieferte
  Werte, der Selbsttest sah aber nichts.
- Kommt außerhalb des statestream gerade etwas an (`LastMQTTMessage` jünger als 300 s), sagt der Selbsttest
  „MQTT-Meldungen kommen an, aber keine von mqtt_statestream“ bzw. „andere MQTT-Meldungen kommen weiter an“ statt
  „Verbindung evtl. unterbrochen“. Das ist der Fall des Anwenders beim Namen genannt.
- Gemessen wird über den Buffer (alle 5 s) und das Attribut `LastStatestreamMessage`
  (alle 60 s, mit `@` gelesen und geschrieben). Bis build 229 nur der Buffer: Nach einem Modul-Update bleibt der
  MQTT Client verbunden, es gibt keinen retained Replay, und der Selbsttest einer laufenden Anlage warnte bis zur
  nächsten Zustandsänderung „Noch keine MQTT-Daten empfangen“. Das Attribut `LastMQTTMessage` (Diagnose „Letzte
  MQTT-Nachricht“) und die Zugangsdaten-Prüfung sehen weiter jede Meldung: Ein Broker, der anonym etwas liefert,
  akzeptiert anonym, auch ohne statestream.
- Test: `tests/check-selftest-foreign-topics.php` (Fixture `selftest_fremde_topics_20261114.json`, Topics aus
  echten Mitschnitten; LinkTap aus einem Anwender-Bundle, Seriennummer ersetzt). Der `FensterRahmenTrait` kennt
  dafür `pufferVerwerfen()`.

## MQTT Discovery

### Jeder bekommt nur, was er braucht

Eingeführt: 1.10 build 251.

- Ausgangspunkt war das Zielbild vom 26.11.2026 in `docs/KONZEPT_DISCOVERY_SPLITTER.md` (der Splitter hält keine
  Werte mehr). Build 251 ist Etappe 1 daraus und ändert die Rollen nicht.
- **Leitsatz seit 27.11.2026:** Wo klassische Bridge und MQTT Discovery dieselbe Aufgabe verschieden lösen, gilt
  je Thema die bessere Lösung für beide, möglichst in gemeinsamem Code (Tabelle im Konzept). Etappe 2 (Splitter
  hält keine Werte mehr) ist deshalb gestrichen: Neue Geräte haben in beiden Familien sofort Werte, klassisch per
  REST, bei Discovery aus dem Zwischenspeicher des Splitters. Das Vormerken und die beiden Sperren bleiben: Der
  Kernel lässt mehrere Aufrufe gleichzeitig in dieselbe Instanz (Abschnitt „Splitter: Vormerken und Sperren“). Etappe 3 ist damit
  abgeschlossen.
- **Gerät fragt mit seiner Geräte-ID** (`GetDiscoveryConfigs` mit `DeviceID`), der Splitter liefert nur dessen
  Ankündigungen (`filterDiscoveryRecordsByDevice`). Bis build 250 bekam jedes Gerät bei jedem Übernehmen alle
  und wertete alle aus, um die eigenen zu finden: mit 2384 Ankündigungen im Harness 440 ms je Gerät (Splitter 47,
  Gerät 393), jetzt 68 ms (56 und 12). Das Übernehmen läuft in allen Geräten, sobald der Splitter seinen Status
  wechselt (Kernel-Start, Update, Neuverbinden). Der Konfigurator fragt weiter ohne Geräte-ID.
- Die Zuordnung trifft `HAMqttDiscoveryParser::resolveRecordDeviceId()`: erster Eintrag der `identifiers` des
  Geräts, wie der Gruppenschlüssel in `HAMqttDiscoveryGrouping`. **Wer den Gruppenschlüssel ändert, ändert beide
  Stellen**; `check-discovery-device-own-configs.php` Teil 1 vergleicht sie. Die Funktion löst bewusst nur `dev`
  auf und nicht alle Kurzformen (`normalizeConfigAliases` kostete je Ankündigung rund 0,1 ms, zusammen 270 ms je
  Anfrage). Für eine ID „entity:…“ (Entität ohne Gerät) gehen alle Ankündigungen ohne Gerät hinaus; dafür gibt es
  in keinem der 18 lokalen Bundles einen Fall.
- Gleichheit: Die Definition jedes Geräts aus den gefilterten Ankündigungen gleicht der aus allen, geprüft an 18
  echten Bundles mit zusammen 528 Geräten (lokal, die meisten Bundles sind nicht versioniert).
- **Gerät schreibt `LastMQTTMessage` gedrosselt** (`touchLastMqttMessage`, höchstens alle 5 s, Zeitpunkt im
  Buffer `LastMqttTouchEpoch`); bis build 250 ein Attribut-Schreiben je Meldung.
- **Konfigurator setzt den Empfangsfilter `^$`** in `ApplyChanges`. Er hängt am Datenfluss der Geräte, wertet
  aber keine Meldung aus. Dass der Kernel ihm vorher jede Meldung zustellte, ist hier aus dem Code geschlossen;
  gemessen ist es am Konfigurator der klassischen Bridge (Abschnitt „Datenfluss“).
- Test: `tests/check-discovery-device-own-configs.php` (Fixtures `discovery_ems_esp_20261116.json` mit drei
  Geräten und `discovery_zwavejs_20261125.json`). Der Geräte-Rahmen schneidet dafür die Anfragen an den Splitter
  mit (`DiscoveryDeviceHarness::$anfragen`).

### Splitter: Vormerken und Sperren

Eingeführt: 1.10 build 246 und 249.

- Anlass: ein Anwender (26.11.2026), Z-Wave JS UI mit 2384 Ankündigungen (jeder Geräteparameter ist eine
  Entität). Sein Bundle zeigt den Rückstau: Status-Meldungen mit Sendezeit 10:28 kamen beim Splitter zwischen 12:02
  und 17:12 an, in Sendereihenfolge, am Abend lag der Verzug bei 435 Minuten. Schon am Vortag trafen 918
  Ankündigungen über 107 Minuten verteilt ein.
- Ursache im Modul: Die Zwischenspeicher für Ankündigungen und Werte sind je ein Attribut mit dem ganzen Bestand als
  JSON. Bis build 245 las und schrieb `ReceiveData` sie bei jeder Meldung komplett, eine Ankündigung baute dazu die
  Referenzliste aus allen Ankündigungen neu. Bei laufendem Verkehr lief außerdem jede Sekunde
  `buildDiagnosticsState()`, auch ohne offenes Formular. Im Harness (nur PHP, ohne Kernel) kosteten die 2384
  Ankündigungen 187 s und 2473 Wert-Meldungen 60 s, danach 1,2 s und 0,5 s. An der Anlage des Anwenders war das
  aber nicht der Engpass: Dort nahm build 246 je Meldung 86 ms von rund 1,2 s weg, der Rückstau kam aus dem
  Empfangsfilter der Geräte (Abschnitt „Empfangsfilter“).
- Jetzt merkt `ReceiveData` die Meldung nur vor (`queueCacheUpdate`): eine Zeile mit Topic, Payload (hex),
  Empfangszeit und Sitzung in Buffern `PendingCacheUpdates<n>` zu je höchstens 32 KB. `flushPendingCacheUpdates()`
  schreibt sie gebündelt in die Attribute, mit demselben Ergebnis wie vorher je Meldung (an den 4857
  Meldungen gegen den alten Code verglichen). Es läuft per Timer, ab 16 vollen Abschnitten sofort und **vor jedem
  Lesen**: `readDiscoveryCache()`, `readTopicPayloadCache()` und `readReferencedTopicLookup()` rufen es zuerst.
  Wer die Zwischenspeicher über diese drei liest, sieht also jede empfangene Meldung. **Die Attribute nie direkt
  lesen.**
- Der Timer ist der vorhandene `DiagnosticsRefresh` (kein neuer, Reload-Fenster): `RefreshDiscoveryDiagnostics()`
  bündelt zuerst und baut die Diagnose nur, wenn das Formular sie angefordert hat oder vor höchstens 600 s geöffnet
  wurde (`BUFFER_FORM_OPENED_AT`). Der Abstand ist 5 s, auf langsamer Hardware das Zwanzigfache der letzten Dauer,
  höchstens 60 s.
- Zwei Semaphoren (seit build 249 nach dem Review vom 26.11.2026, Befunde 1 bis 3):
  - **Vormerk-Sperre** `HAMD_PendingCacheUpdates_<id>` nur um Anhängen und Entnehmen der Zeilen (ReceiveData und
    Timer laufen in getrennten Threads). Wartezeit `PENDING_LOCK_WAIT_MS` 10 s; bekommt `ReceiveData` sie nicht,
    fehlt die Meldung im Zwischenspeicher und es gibt eine Warnung im Meldungsfenster (bis build 248 nach 1 s nur
    eine Debug-Zeile). Die Geräte bekommen die Meldung trotzdem.
  - **Sperre der Zwischenspeicher** `HAMD_CacheFlush_<id>` um das Bündeln **und um jedes Lesen-Ändern-Schreiben**
    über `runWithCacheLock()`: `pruneCaches` (ApplyChanges, Verbindung auf und ab), „Veraltete entfernen“,
    Leeren und Laden eines Bundles. Bis build 248 schrieben diese einen Stand zurück, den sie ohne die Sperre
    gelesen hatten, und konnten so das Ergebnis eines gleichzeitigen Bündelns überschreiben. Innerhalb der Sperre
    läuft verschachtelte Arbeit direkt (`cacheLockDepth`), weil eine Semaphore nicht wiedereintrittsfähig
    ist (im Stub wie an drei Anlagen mit 9.1 Rust und 9.0 C++, 26.11.2026; dort auch belegt: Wartezeit 0 kehrt sofort
    zurück, und der Kernel gibt eine Semaphore frei, wenn ihr Skript-Thread ohne Leave endet). Wird sie nicht frei: `pruneCaches` entfällt bis zum nächsten Mal, „Veraltete entfernen“ bittet den
    Anwender, es gleich noch einmal zu versuchen, Leeren und Bundle schreiben trotzdem (sie ersetzen den Bestand
    ganz). Ein reiner Leser liest nach 5 s ohne Sperre den Stand ohne die gerade gebündelten Zeilen; zurückschreiben
    darf er ihn nicht.
  - Bei vollem Vormerk-Puffer bündelt `ReceiveData` nur, wenn die Sperre **sofort** frei ist
    (`flushPendingCacheUpdates(0)`); bis build 248 wartete jede weitere Meldung bis zu 5 s auf ein laufendes
    Bündeln.
  - Neue Schreiber der Zwischenspeicher gehören in `runWithCacheLock()`.
  - **Die Sperren sind nötig, belegt am 27.11.2026:** Meldungen einer Instanz kommen nacheinander, aber Timer,
    `RequestAction` und `ApplyChanges` laufen daneben. Im Mitschnitt eines Anwenders endet ein Bündeln von 122 ms
    mitten in einem `ReceiveData` von 5 ms; am nuc kehrten fünf `StateCacheFlush` eines klassischen Geräts in je 8
    bis 19 ms zurück, während sein Übernehmen 3,6 s lief. Für das klassische Gerät siehe den Abschnitt
    „Parallele Aufrufe in einer Instanz“.
- Grenzen: Was vorgemerkt ist, geht bei einem Kernel-Absturz verloren (höchstens ein Timer-Abstand; die Variablen
  der Geräte sind davon nicht betroffen, sie bekommen jede Meldung sofort). Das Bündeln selbst liest und schreibt
  weiter die ganzen Attribute; wer das verkleinern will, muss die Zwischenspeicher aufteilen.
- Test: `tests/check-discovery-splitter-backlog.php` (Fixture `discovery_zwavejs_20261125.json`). Der Test-Rahmen
  zählt dafür Zugriffe auf String-Attribute (`FensterRahmenTrait::$attributZugriffe`). Die Sperren prüft
  `tests/check-discovery-splitter-locks.php`: „ein anderer Thread hält die Sperre“ heißt dort, der Name steht in
  der Semaphorenliste des Stubs; die Wartezeiten zeigt die Naht `enterSemaphore` (`$sperrAnfragen`).

### Nicht mehr angekündigte Geräte

Eingeführt: build 194.

- Kennt der Splitter das Gerät nicht, fällt `resolveRuntimeDeviceDefinition()` auf die gespeicherte
  Definition zurück — das ist für Neustarts gewollt, hatte aber kein Ende: Ein verschwundenes Gerät blieb
  dauerhaft auf 102.
- Jetzt kennzeichnet `markNotAnnounced()` den Rückfall samt `SessionStartedAt` aus der Splitter-Antwort
  (`buildDiscoveryResponse`); `evaluateAnnouncementStatus()` setzt 202 + eine Warnung, wenn die Sitzung
  seit `NOT_ANNOUNCED_GRACE_S` (600 s) läuft, sonst 102 mit Nachprüfung nach Ablauf. Nachgeprüft wird
  über den Timer `DeferredApply` (ruft `ApplyChanges` über `RequestAction`), denn eine neue Ankündigung löst sonst kein
  `ApplyChanges` aus. Die Kennzeichen werden nicht mit der Definition gespeichert.
- **Keine Antwort ist kein Befund** (build 205, Code-Review): Ist der Parent inaktiv oder antwortet der
  Splitter nicht, kennzeichnet `markAnnouncementUnknown()` die Definition mit `announced = null`;
  `evaluateAnnouncementStatus()` behält dann 202 samt Nachprüfung. Vorher galt die gespeicherte
  Definition als angekündigt — ein verschwundenes Gerät meldete „wieder angekündigt" und die
  Nachprüfung endete.
- **`ApplyChanges` schreibt nur geänderte Werte** (build 206): Alle Wertzugriffe laufen über
  `setReceivedValue()`; während `ApplyChanges` (Flag `writeOnlyChangedValues`) überspringt es gleiche
  Werte einer schon einmal geschriebenen Variable. Anlass: `applyCachedTopicPayloads()` spielt bei jedem
  Übernehmen den Zwischenspeicher des Splitters ein, dazu feste Konfigurationswerte und Taster-Rücksetzer —
  bei nicht angekündigten Geräten alle 10 Minuten. „ID.4 Pro" (VW-Dienst stillgelegt, letzter Wert
  26.06.2026) trug dadurch eine Aktualisierung von vor Minuten. `ReceiveData` schreibt weiter immer.
  „Erreichbar" geht direkt über `SetValue`. Test: `tests/check-discovery-replay-timestamp.php`.
- „Schon gewarnt" steht im Attribut `NotAnnouncedWarned` (build 202), nicht nur im Status: Der beginnt
  nach Reload und Kernel-Neustart neu, und jedes schon nicht angekündigte Gerät warnte erneut (nuc
  04.10.2026, Reload 10:51: vier Geräte). Gelesen und geschrieben mit `@` (Reload-Fenster).
- Test: `tests/check-discovery-not-announced.php`; `DiscoveryDeviceHarness` gibt dafür die
  Splitter-Antwort (`$splitterAntwort`) und einen aktiven Parent (`$parentAktiv`) vor.

### Erreichbar

Eingeführt: build 195.

- Discovery-Geräte mit availability-Topic bekommen `reachable` (`maintainReachableVariable`,
  `evaluateReachability`): nicht erreichbar, wenn alle Entitäten mit bekanntem Stand offline melden,
  unbekannt zählt nicht; Wechsel als `KL_WARNING`/`KL_MESSAGE`; keine Entprellung (die Bridge hat ihre
  eigene Frist); Status bleibt 102. Ausgewertet in `ApplyChanges` (nach den gecachten Payloads) und in
  `ReceiveData`, wenn `diagnostics_changed`.
- **Bundle-Modus des Splitters:** Er spielt eine Momentaufnahme ab (am nuc: Anwender-Bundle zum Testen).
  Werte, Verfügbarkeit und „nicht angekündigt" zeigen dann den Stand des Bundles, nicht die Gegenwart.
  Befunde an Geräten unter einem Bundle-Splitter sind keine Befunde über die Anlage — vorher den Modus
  prüfen (`HAMD_RunSelfTest`: „Quelle: Bundle"). Fixtures daraus sind Anwenderdaten: neutralisieren.
  Die Diagnose im Formular zeigt die Quelle erst seit build 219: Bis dahin übersprang
  `RefreshDiscoveryDiagnostics` den Bundle-Modus, das Formular behielt die Platzhalter der form.json
  („Quelle: MQTT“, „Bundle: nicht aktiv“). Test: `check-discovery-splitter-diagnostics-bundle.php`.
  Seit build 204 meldet der Splitter im Bundle-Modus `SessionStartedAt = 0` (`buildDiscoveryResponse`):
  Der Sitzungsbeginn des Bundles ließ die 10-Minuten-Frist sofort ablaufen, Geräte außerhalb des Bundles
  gingen auf 202 und warnten (Blindtest-Befund 4). Bei 0 urteilt das Gerät nicht.
- **`cleanupObsoleteVariables()` kennt nur Entitäts-Idents** — jede Variable, die nicht
  `maintainEntityVariables` pflegt, muss dort ausdrücklich als aktiv übergeben werden, sonst wird sie
  beim nächsten `ApplyChanges` veraltet (so bei `reachable` im Test gefunden). Aber nur, solange sie
  versorgt wird: `reachable` nur bei `hasAvailabilityEntities()` (build 205, Code-Review) — kündigt sich
  ein Gerät ohne availability-Topic neu an, wird sie veraltet statt mit dem letzten Wert stehen zu bleiben.
- Test: `tests/check-discovery-reachability.php` (Fixture `discovery_availability_20261003.json`).
- **Z-Wave JS UI** (build 247, Anwender mit Z-Wave JS UI): je Entität drei availability-Topics (Knoten, MQTT-Client des
  Gateways, Treiber), Modus `latest`. Knoten und Gateway tragen ein Template mit dem bloßen Feld als Bedingung
  (`{{'true' if value_json.value else 'false'}}`, beim Gateway mit `'online'`/`'offline'`). Bis build 246 kannte `HAMqttDiscoveryTemplate::parseGuard` das
  nicht, `evaluateAvailabilityEntry` verglich dann den rohen JSON-Text mit „true“: Alle Geräte standen auf „Nicht
  erreichbar“. Jetzt gibt es die Prüfung `truthy` mit dem Wahrheitswert von Python (der Text „0“ ist wahr, anders
  als in PHP); sie gilt für jedes bedingte Template, nicht nur für availability. `or` und `not` bleiben nicht
  unterstützt. Ein schlafender Knoten meldet `value` true und gilt als erreichbar. Das geparste Template steht in
  der gespeicherten Definition; neu geparst wird beim nächsten `ApplyChanges` mit Antwort des Splitters. Test:
  `tests/check-discovery-zwavejs-availability.php` (Fixture `discovery_zwavejs_20261125.json`; ein Mitschnitt
  eines ausgefallenen Knotens fehlt, dafür dient die echte Meldung mit `value` false).
- **Nicht angekündigt heißt nicht erreichbar** (build 198, Blindtest 04.10.2026: Gerät auf 202, aber
  „Erreichbar" = true): `evaluateReachability($entities, $announced)` setzt bei `false` still auf nicht
  erreichbar (die 202-Warnung meldet es schon); deshalb wird der Status vor der Erreichbarkeit bestimmt.
  Test: `tests/check-discovery-not-announced.php`.

### Sensortyp bleibt stabil

Eingeführt: 1.10 build 248.

- Anlass: ein Anwender (26.11.2026). Nach „Änderungen übernehmen“ hatten Batterie und Helligkeit eines
  Bewegungsmelders neue Variablen-IDs, aus Float war Integer geworden.
- `determineSensorVariableType()` nahm bis build 247 zuerst den Wert aus dem Zwischenspeicher des Splitters (ganze
  Zahl → Integer, Kommazahl → Float) und nur ohne Wert Einheit, `state_class` und `device_class` (→ Float, Integer
  oder Text). Der Typ hing damit vom Augenblick des Übernehmens ab, und ein anderer Typ heißt Variable neu anlegen
  (`recreateVariableIfTypeChanged`), mit neuer ID und ohne Archiv.
- Jetzt zählt die bestehende Variable mit (`getExistingVariableType`): Eine Float-Variable bleibt bei einer ganzen
  Zahl Float. Liegt kein Wert vor, behält eine Zahlenvariable ihren Typ, auch wenn sonst Text geraten würde (im
  Bundle des Anwenders sechs Sensoren ohne Einheit, z. B. `notification_access_control_door_state`). Umgestellt wird
  nur noch, wenn die Variable den Wert nicht halten kann: Kommazahl an Integer, Text an einer Zahlenvariable.
- Die Regel beim ersten Anlegen ist unverändert. Bestehende Variablen wechseln durch den Fix nicht den Typ.
- Offen: Ein Sensor, der mit einer ganzen Zahl angelegt wird (Temperatur gerade 18), ist Integer und rundet
  Kommawerte im heißen Pfad, bis ein Übernehmen mit Kommazahl ihn einmal auf Float umstellt. Abhilfe wäre Float
  für jeden Sensor mit Einheit schon beim Anlegen; dafür fehlt dem Discovery-Pfad die Stellenlogik der Bridge
  (Float zeigt fest zwei Nachkommastellen, „60,00 %“). `number` hat dieselbe Wertabhängigkeit über
  `inferNumberVariableType` (Kommazahl → Float, sonst Integer), dafür gibt es keinen Mitschnitt.
- Test: `tests/check-discovery-sensor-type-stable.php` (Fixture `discovery_zwavejs_20261125.json`; der Fall ohne
  Einheit nimmt den Leistungssensor ohne die drei Angaben, die Fixture hat keinen solchen Sensor).

### Klimageräte, Kurzformen, Templates

Eingeführt: 1.7 build 211.

- Anlass: ein Anwender (t/142973 #376, 22.10.2026), Mitsubishi mit mitsubishi2mqtt. Hauptvariable
  „0 C“, nie aktualisiert, ohne Aktion; Modus und Lüfter fehlten. Fixture `discovery_mitsubishi2mqtt_20261022.json`.
- **Kurzformen** stehen vollständig in `libs/Discovery/HAMqttDiscoveryAbbreviations.php`, erzeugt aus HA Core
  `mqtt/abbreviations.py` (nicht von Hand pflegen, bei Bedarf neu erzeugen). Vorher kannte der Parser nur die
  allgemeinen (`stat_t`, `cmd_t` …); `temp_cmd_t` usw. fielen still durch.
- **Klimakanäle:** `HAMqttDiscoveryParser::CLIMATE_CHANNELS` liefert je Attribut State-/Command-Topic, Template
  und Optionen (Vorgabelisten und Feature-Regeln aus HA `mqtt/climate.py`), durchgereicht als Zeilenschlüssel
  `climate`. Das Device legt dafür Variablen mit den Attributnamen aus `HAClimateDefinitions::ATTRIBUTE_DEFINITIONS`
  an (wie die Bridge) und verteilt Meldungen über den eigenen Index-Bucket `climate`. Wie in HA sendet jeder
  Kanal nur an sein Topic; `power_command_topic` gehört in HA zu `turn_on`/`turn_off`, nicht zum Modus, und
  ist deshalb nicht abgebildet. Zielfeuchte und Bereichs-Solltemperatur (`temperature_low/high_*`) fehlen noch.
- **Optionsdarstellung** (`buildSharedOptionPresentation`, `getClimateOptionCaption`) liegt im
  `HASharedPresentationTrait` und gilt für beide Familien; Übersetzung reicht der Aufrufer als Callback herein.
  Neue Beschriftungen gehören in die `locale.json` von Device **und** MQTT Discovery Device.
- **Templates:** `parseConditionalJsonTemplate` erkennt `{{ value_json.X if … else 'Y' }}` (Rückfallwert
  `default`) und `{% if %}`-Blöcke, die nur ein Feld und Zahlen ausgeben. Die Bedingung des Ausdrucks steht als
  `guards` im Template und wird in `HAMqttDiscoveryTemplate::resolveJsonPathValue` ausgewertet (build 212,
  Code-Review): mit `and` verknüpft `is defined`, `|length`, Vergleich mit Text oder Zahl, optional `|int`/`|float`.
  Alles andere (`or`, `not`, Funktionen, `else` mit Ausdruck) ist nicht unterstützt; bis build 211 galt jede
  Bedingung als erfüllt (Licht aus, Helligkeit 120). Beim Block bleibt die Bedingung unausgewertet. Ein nicht
  unterstütztes Template auf einem JSON-Objekt schreibt nichts mehr (vorher das ganze JSON, „Up Time“).
- **Nachgezogen in build 212** (Code-Review von build 211): Device-Discovery übernimmt alle Kanalschlüssel aus
  `CLIMATE_CHANNELS` als gemeinsame Angaben (`extractDeviceDiscoverySharedConfig`, vorher fehlten Lüfter,
  Lamellen, Preset, Feuchte). Ein nur lesbarer Kanal mit nicht unterstütztem Template bekommt keine Variable
  (`isClimateChannelReadable`), ein schaltbarer zeigt den gesendeten Wert. Der Positionsabstand zählt nur
  Kanal-Attribute (alle elf überschritten `ENTITY_POSITION_STEP`). Ungültiger Wert beim Schalten ist ein
  `E_USER_WARNING` wie `rejectInvalidActionValue`, nicht schreibbar bleibt still wie in der Bridge. „medium“
  hat eine Beschriftung.
- `temperature_unit` `C`/`F` wird zu `°C`/`°F`. Der Splitter zählt jedes `…command_topic`/`…cmd_t` als
  `command` (vorher nur `cmd_t`, `bri_cmd_t`, `rgb_cmd_t`), seit build 213 auch `set_position_topic` und
  `set_fan_speed_topic` samt Kurzformen (die einzigen Befehls-Topics in `abbreviations.py` ohne diese Endung).
- Heißer Pfad (build 213): `applyClimateChannelPayload` dekodiert das JSON einmal je Meldung und nimmt den
  Variablentyp aus `ATTRIBUTE_DEFINITIONS` statt `IPS_GetVariable`; die Kanäle baut der Parser einmal und reicht
  sie an `buildClimateSupportedFeatures` weiter.
- Test: `tests/check-discovery-climate.php`, bedingte Fälle in `check-mqtt-discovery-template-parser.php`;
  der `DiscoveryDeviceHarness` schneidet MQTT-Sendungen mit (`$mqttSendungen`).

### Komponente `text`

Eingeführt: 1.9 build 235.

- Anlass: ein Anwender (t/142973 #386, 16.11.2026), EMS-ESP 3.9.1 kündigt Schaltzeiten, Urlaubs- und
  Feiertage, Wartungsdatum und Reglerzeit als `text` an. Parser und Device kannten die Komponente nicht.
- `HATextDefinitions` (nur DOMAIN und Variablentyp String) steht in den `SUPPORTED_COMPONENTS` von Parser und
  Device. Lesen läuft über den allgemeinen Pfad (`castSensorValue` auf String), Schreiben über
  `buildCommandPayload`: der Text unverändert, auch leer (HA: `min` = 0 als Vorgabe), mit `command_template`,
  falls angekündigt. Länge (`min`/`max`) und `pattern` prüft das Modul nicht, wie beim `input_text` der Bridge.
- Mit „Status emulieren“, `optimistic` oder ohne `state_topic` schreibt `applyOptimisticValue` den gesendeten
  Text sofort in die Variable (build 237, Code-Review 16.11.2026 Befund 1; vorher fehlte der Zweig, die Variable
  behielt den alten Text). Eine neue schaltbare Komponente braucht dort immer einen eigenen Zweig.
- Die klassische Bridge kennt die HA-Domäne `text` weiterhin nicht (nur `input_text`).
- Test: `tests/check-discovery-text.php` (Fixture `discovery_ems_esp_20261116.json`).

### Licht im Standard-Schema

Eingeführt: 1.10 build 240.

- Anlass: ein Anwender (t/142973 #397, 25.11.2026), Dimmer aus Z-Wave JS UI kam nur als Ein/Aus an. Z-Wave JS kündigt
  Dimmer im Standard-Schema von HA an (`schema` fehlt): Helligkeit über `brightness_state_topic`/
  `brightness_command_topic`. Der Discovery-Pfad kannte nur das JSON-Schema (`brightness: true`, alles im JSON des
  State-Topics) und schickte auch Ein/Aus als `{"state":"ON"}`, was ein Gerät im Standard-Schema nicht versteht.
- Der Parser liefert dafür den Zeilenschlüssel `light` (`buildLightBasicSchema`, leer bei `json` und `template`),
  durchgereicht wie `climate`. Vorgaben wie HA (`mqtt/const.py`): `ON`/`OFF`, Skala 255, `on_command_type` `last`.
  Das Device legt die Helligkeit genau dann an, wenn ein `brightness_command_topic` angekündigt ist (HA
  `schema_basic.py`), liest sie über den Index-Bucket `light` und rechnet wie HA um: Zustand Wert / Skala * 255
  (0 und leer ignoriert), Befehl Wert / 255 * Skala, mindestens 1, beides mit `PHP_ROUND_HALF_EVEN` wie Python.
  Ohne `brightness_state_topic` zeigt die Variable den gesendeten Wert. Andere Lichtattribute legt das Device im
  Standard-Schema nicht an (`basic_schema` im Attributkontext), sie würden nie versorgt.
- **Z-Wave JS UI** (build 241, Bundle des Anwenders): Zustand und Helligkeit kommen auf demselben Topic
  (`…/currentValue`, JSON mit `value` 0…99). Der Zustand läuft über `state_value_template` (Parser nimmt es für
  Lichter im Standard-Schema vor `value_template`) in der Form `{{ "OFF" if value_json.value == 0 else "ON" }}`;
  `HAMqttDiscoveryTemplate` versteht dafür festen Text je Bedingung (`then`/`default`, gleiche Bedingungen wie beim
  Feld-Template). `on_command_type: brightness`: Einschalten sendet wie HA nur die Helligkeit an
  `brightness_command_topic`, die zuletzt bekannte (die Variable ignoriert 0) oder 255. Ausschalten sendet wie HA
  `OFF` an `command_topic`, auch bei Z-Wave JS (dort `targetValue/set`).
- **Helligkeit stellen** (build 243, Code-Review 25.11.2026 Befunde 1 und 2): Wie HA `async_turn_on` schickt der
  Regler bei `on_command_type` `first` vorher `payload_on`, bei `last` (Vorgabe) danach, bei `brightness` nur die
  Helligkeit. Danach gilt das Licht optimistisch als an (Status emulieren, `optimistic`, ohne `state_topic`).
  Helligkeit 0 schaltet aus wie in HA (`light/__init__.py`), die Helligkeitsvariable bleibt dabei stehen. Bis
  build 242 ging nur die Helligkeit hinaus (eine ausgeschaltete AWTRIX-Matrix blieb dunkel), und 0 wurde zu 1.
  Eine Helligkeit, die keine Zahl ist, meldet `E_USER_WARNING`; Werte außerhalb von 0…255 begrenzt das Modul wie
  HA (`vol.Clamp`), statt sie abzulehnen (build 244, Befund 5).
- **Wert wie HA vorbereiten** (build 245, Nachreview 25.11.2026 Befunde 1 bis 3): `coerceBasicSchemaBrightness()`
  begrenzt auf 0…255 und schneidet ab wie `vol.Coerce(int)`, bevor irgendetwas gesendet oder geschrieben wird. 0.4
  schaltet also aus, 254.7 geht als 254 hinaus, und „Status emulieren“ schreibt bei 300 den gesendeten Wert 255 (bis
  build 244 kappte nur das Senden, die Variable bekam 300). Den Payload baut `buildBasicSchemaBrightnessPayload()`
  vor jeder Sendung; kann das Modul das `brightness_command_template` nicht umsetzen, geht gar nichts hinaus, auch
  kein `payload_on`, und nichts wird optimistisch gesetzt. Bis build 244 schaltete die Hauptvariable bei
  `on_command_type` `brightness` dann trotzdem auf an.
- Im Standard-Schema liest das Modul kein `brightness` aus dem JSON des State- oder Attribut-Topics
  (`extractLightAttributesFromPayload` liefert leer, build 244, Befund 3); bis build 243 schrieb die Auswertung des
  JSON-Schemas den Wert ungeskaliert in dieselbe Variable. Dafür gibt es keinen Mitschnitt, der Test nimmt es an.
- Offen: Farben und Farbtemperatur über eigene Topics (`rgb_stat_t` usw., AWTRIX und die Smart Switch 7 im
  Bundle des Anwenders haben sie).
- Spalte „Modus“ der Entitätenliste (build 241): `buildEntityAccessModeLabel` prüft mit `create_var = true`. Vorher
  zeigte jede abgewählte Entität „Nur lesen“, weil `isEntityWritable` sie für die Laufzeit verneint (t/142973 #397,
  `config_switch` sind in Z-Wave JS von Haus aus abgewählt).
- Test: `tests/check-discovery-light-basic.php` (Fixtures `discovery_awtrix_20260513.json`,
  `discovery_zwavejs_20261125.json`).

### Debug des Geräts

Eingeführt: 1.10 build 263.

- Anlass: ein Anwender (t/139168 #27, 27.11.2026), Dimmer aus Z-Wave JS UI. Beim Umschalten wechselte der Status
  ein- bis zweimal zwischen Aus und An, die Helligkeit blieb stehen. Status und Helligkeit hängen dort am selben
  Topic (`currentValue`): 0 heißt aus, die Helligkeit übergeht eine 0. Flackern bei stehender Helligkeit heißt
  also, dass zwischendurch eine 0 ankommt. Im Debug des Geräts war das nicht zu sehen, es zeigte nur ausgehende
  Befehle. Das klassische Gerät gibt eingehende Meldungen schon aus („Eingangsdaten“, „Wert empfangen“).
- „Eingangsdaten“ (Topic und Payload) schreibt `debugIncomingMessage()` im `ModuleDebugTrait`, aufgerufen aus dem
  `ReceiveData` von Device, Entity und Discovery-Gerät. Device und Entity hatten die Zeile schon, jede für sich;
  ihre Ausgabe ist unverändert.
- „Wert gesetzt“ (Ident und Wert) steht in `setReceivedValue()` des Discovery-Geräts. Dort laufen alle Werte von
  Entitäten durch, auch die optimistischen („Status emulieren“); das Debug trennt so einen selbst gesetzten Wert
  von einer Meldung des Senders. Die Meldung einer Entität ohne folgende Zeile „Wert gesetzt“ hat keinen Wert
  geschrieben. `reachable` hat eigene Schreibstellen und steht im Meldungsprotokoll, Bilder gehen in ein
  Medienobjekt; beide haben keine Zeile. Die klassische Bridge hat keine solche gemeinsame Schreibstelle, ihre
  Zeilen „Wert empfangen“ und „State empfangen“ bleiben, wie sie sind.
- Die Zeile entsteht nach `SetValue` und heißt „Wert nicht gesetzt“, wenn es false liefert (Code-Review 28.11.2026:
  Vor dem Schreiben ausgegeben, sah ein gescheitertes Schreiben im Debug wie ein gesetzter Wert aus). Ein solcher
  Fall ist an keiner Anlage mitgeschnitten, der Test stellt ihn über `DiscoveryDeviceHarness::$schreibfehler` nach.
- Beide Zeilen gibt es nur mit erweitertem Debug (`isExpertDebugEnabled()`, je Ausführung einmal gelesen). Das
  klassische Gerät gibt „Wert empfangen“ immer aus (Kategorie `MQTT`); das wurde nicht übernommen, ohne den Haken
  kostet eine Meldung so keinen Aufruf von `SendDebug`.
- Ungültiges UTF-8: `debugExpert` kodiert den Kontext als JSON und warf bis build 262 bei einem Payload, der kein
  UTF-8 ist, mitten in `ReceiveData` (mit erweitertem Debug im klassischen Gerät bei „Eingangsdaten“ und bei
  „Non-JSON payload stored as string“). Jetzt ersetzt es solche Bytes (`JSON_INVALID_UTF8_SUBSTITUTE`), für alle
  Module. „Eingangsdaten“ und „Wert gesetzt“ schreiben statt der Bytes nur die Länge (`debugPrintable()`), sonst
  stünden Bilddaten eines `image`-Topics als Ersatzzeichen im Debug. Dafür gibt es keinen Mitschnitt, der Test
  nimmt es an.
- Offen: die Ursache des Flackerns selbst, die Rückmeldung des Anwenders steht aus. Offen auch: Der Zustands-Cache
  des klassischen Geräts (`writeEntityStateCache`) wirft bei einem Zustand oder Attribut mit ungültigem UTF-8
  unabhängig vom Debug; beim Test aufgefallen, kein Anlass aus einer Anlage.
- Test: `tests/check-discovery-device-debug.php` (Fixture `discovery_zwavejs_20261125.json`).

## Arbeitsweise

### libs/

Gemeinsame Traits/Klassen; `libs/HACommonIncludes.php` bindet alles ein und wird von allen
Modulen außer `Home Assistant Discovery` verwendet. Die Discovery setzt die Kerninstanz DNS-SD Control
voraus und legt sie nie selbst an (build 217, Store-Review Punkte 6/13); fehlt sie, zeigt sie den
Hinweis `mdnsMissing` und sucht nicht (Test `tests/check-discovery-dnssd.php`). Unterordner: `Domains/` (eine
Definitionsklasse je HA-Domäne), `Device/` (Laufzeitlogik der Device-Module), `Config/`,
`Discovery/` (MQTT-Discovery-Parser/Runtime).

### Übersetzungen

- Englische `form.json`-Texte sind zugleich Übersetzungsschlüssel; `locale.json` (de) je Modul.
- `Translate()`-Texte aus `libs/`-Traits müssen in der `locale.json` jedes Moduls stehen,
  das sie zur Laufzeit ausgibt.
- Prüfung: `php tests/check_locale.php` (läuft auch in der CI). Seit build 214 prüft er je Modul
  auch die Texte aller Traits, die die Modulklasse einbindet (Reflection am Kernel-Stub). Vorher galt
  ein libs-Text als übersetzt, sobald er in irgendeiner locale.json stand; dem Entity-Modul fehlten
  so neun Texte, dem Splitter einer (Store-Review Punkt 2, 02.11.2026). libs-Texte, die kein Modul
  einbindet, bleiben ein Hinweis.

### Dokumentation

- `README.md` (deutsch) und `README.en.md` (englische Übersicht) verweisen aufeinander; die englische
  Store-Lokalisierung verlinkt `README.en.md`. Die Modul-READMEs gibt es nur deutsch (Entscheidung Burkhard
  02.11.2026, Store-Review Punkte 2/14: eine Übersicht statt neun Übersetzungen). Was die Übersicht
  berührt (Einrichtung, Verhalten für Anwender, Fehlersuche), in beiden Fassungen nachziehen.
- Anwender, deren Meldung, Bundle oder Mitschnitt Anlass einer Änderung war, heißen in allen versionierten Dateien
  (diese Datei, Kommentare, Tests, Fixtures) nur „ein Anwender“, mit Datum und dem öffentlichen Forenbeitrag, falls
  es einen gibt. Forennamen und PN-Nummern stehen in der `CLAUDE.local.md` (seit 1.10 build 254).

### Tests

Alle Check-Skripte (`tests/check*.php`) sind versioniert; Fixtures nur nach
Einzelprüfung auf private Gerätedaten (Freischaltung per `.gitignore`-Ausnahme,
Details in `tests/fixtures/README.md`). Laufzeit-Checks: `php tests/check-*.php`
(eigenständige Skripte, kein PHPUnit).

- Jeder Laufzeit-Check bindet `tests/harness.php` ein: Warnings/Notices werden zu Fehlern,
  `pruefe()` zählt jede Prüfung, `ergebnis()` schreibt die Schlusszeile
  „N Prüfungen, M Fehler" (Exit 0/1) — das Format liest `rotgruen.php` für den
  Rot/Grün-Nachweis. Neue Tests genauso aufbauen.
- Neue Tests an Device oder Entity laufen über `tests/device-harness.php`: das echte Modul am
  offiziellen Kernel-Stub (`symcon/SymconStubs`, Submodul `tests/stubs`, gepinnt auf `be1e942`).
  `neuesGeraet()`/`neueEntitaet()` legen Instanzen an, `neueAusfuehrung()` liefert ein frisches
  Modul-Objekt mit dem Kernel-Zustand des alten (der Stub hält Properties, Attribute, Buffer und
  Timer im Objekt, Symcon im Kernel), `KernelZaehler` zählt Kernel-Aufrufe, `HaSendungen`
  zeichnet REST- und MQTT-Sendungen auf. Das globale `IPS_GetObject()` des Stubs lässt sich
  nicht mitzählen. Die meisten Tests an Device und Entity laufen inzwischen darüber.
- Der MQTT Discovery Configurator läuft über `DiscoveryConfiguratorHarness` (Splitter-Antwort
  `$splitterAntwort`, aktiver Parent `$parentAktiv`). Properties gesetzter Instanzen sieht
  `IPS_GetProperty` im Stub erst nach `ApplyChanges` (vorher stehen sie als „Pending“).
  Test: `check-discovery-configurator-orphans.php` (verwaiste und doppelte Instanzen, Instanzen ohne `DeviceID`
  als eigene Zeile, bei ausgeblendeten Bridges keine ihrer Instanzen).
  Der HA-Konfigurator läuft über `ConfiguratorHarness`. Einen unpassenden Splitter lässt der Stub
  nicht verbinden („Dataflow is incompatible“), der Fall „falsches Gateway“ ist nicht testbar.
- Konfiguratoren zeigen einen fehlenden oder inaktiven Splitter als Label im Aktionsbereich
  (build 216, Store-Review Punkt 3). Der HA-Konfigurator zeigt bei inaktivem Splitter weiter die
  Liste, weil der REST-Weg auch ohne MQTT gehen kann. Die Hinweise laufen über eine Variable durch
  `Translate()`; ihre Übersetzung prüft deshalb `check-configurator-parent-hint.php`, nicht
  `check_locale.php`.
- Hilfsskripte, die keine Tests sind (z. B. Fixture-Extraktion), gehören nach `tools/`
  (nicht versioniert), nicht nach `tests/`.
- Offen: Die übrigen Laufzeit-Checks tragen noch eigene Attrappen. Umstellung auf den
  Kernel-Stub und Ersatz von Logik-Kopien durch Modulaufrufe beim nächsten Anfassen des
  jeweiligen Tests, nicht als Sammelaktion.

### CI / Version

- CI: `.github/workflows/check.yml` (PHP 8.5, Checkout mit Submodulen) — Code-Stil mit
  php-cs-fixer gegen das Regelwerk im Submodul `.style` (`--dry-run`), `php -l` auf alle
  `*.php`, JSON-Validität (beides ohne `tests/stubs`), Locale-Check und `tests/check_property_contracts.php` (jede von
  `libs/Device/HADeviceCore.php` gelesene Property muss in Device- und Entity-Modul
  registriert sein), `tests/check_presentations.php` (nur gültige Darstellungsparameter) und
  alle Laufzeit-Checks `tests/check-*.php` (Glob, neue Tests laufen automatisch mit;
  dazu gehört auch die Doku-Sperrklinke `tests/check-readme.php`), zuletzt die statischen MCP-Regeln
  über die Action `bumaas/symcon-mcp-check@v1` (öffentliches Repo — aus einem privaten können öffentliche Repos keine Action laden; Befunde als
  Anmerkungen, scheitert nur an Fehlern — die Warnungen sind offene Punkte aus `mcp-tauglichkeit.md`).
- Version/Build: siehe globale CLAUDE.md, Abschnitt „Build, Version und Store-Freigabe“.
