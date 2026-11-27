# Konzept: Der Discovery-Splitter verteilt nur noch

Stand 27.11.2026, nach dem zweiten Mitschnitt des Anwenders. Entschieden am 26.11.2026, nach beiden Messungen
angepasst (alles unten). Etappe 1 ist mit 1.10 build 251 gebaut, der Empfangsfilter mit build 252, Etappe 4 mit
build 258. Etappe 2 ist gestrichen. Der Rückstau beim Anwender ist seit build 254 weg.

## Leitsatz: je Thema die bessere Lösung (27.11.2026)

Die klassische Bridge und MQTT Discovery lösen dieselben Aufgaben an mehreren Stellen verschieden. Maßstab ist
nicht eine der beiden Familien, sondern je Thema die bessere Lösung, und die gilt dann für beide. Wo es geht, in
gemeinsamem Code.

| Thema | Bessere Lösung | Richtung | Stand |
|---|---|---|---|
| Konfiguration im heißen Pfad | Klassisch: Cache im Instanz-Buffer mit Signatur, nur die benötigten Zeilen werden dekodiert | klassisch → Discovery | gebaut mit build 258 (Etappe 4), gemeinsames Format `libs/HAKeyedBlob.php` seit build 257 |
| Empfangsfilter des Geräts | Discovery: genau die eigenen Topics. Der klassische fasste Objekt-IDs über den gemeinsamen Anfang zusammen (an der eigenen Anlage 9 fremde Treffer bei 1380 Entitäten, im Mitschnitt keine unnötige Zustellung) | Discovery → klassisch | gebaut mit build 259 (Etappe 5) |
| Performance-Messung im Splitter | Klassisch: dieselben Debug-Zeilen, dazu Statistik per Timer und eine Meldung bei langsamen Schritten | klassisch → Discovery | offen (Etappe 6) |
| Attribut je Meldung im Splitter | Keine von beiden: klassisch liest `PendingRestAcks`, Discovery den Sitzungszustand. Besser ist ein Buffer bzw. der Statuswechsel des Parents. | beide | offen (Etappe 6) |
| Anfangswerte neuer Geräte | Beide liefern sie sofort: klassisch per REST aus Home Assistant, Discovery aus dem Zwischenspeicher des Splitters | bleibt | Etappe 2 gestrichen |
| Zustand gebündelt schreiben | Offen. Das klassische Gerät schreibt seinen Zustands-Cache ohne Sperre (Buffer je Meldung, Attribut per Timer), der Discovery-Splitter merkt vor und bündelt mit zwei Sperren. | Sperren bleiben (parallele Aufrufe belegt, 27.11.2026). Das klassische Gerät liest seinen Cache seit build 260 vor jeder Änderung neu | Etappe 3 erledigt |

Ein Unterschied bleibt: Bei der klassischen Bridge hält Home Assistant den Bestand, und alle fragen ihn über den
Splitter per REST ab. Einen Broker kann man nicht abfragen. Der Discovery-Splitter steht deshalb für Home Assistant
und hält die Ankündigungen und zu jedem Topic den letzten Wert.

Schon gleich sind: `LastMQTTMessage` gedrosselt, Empfangsfilter `^$` am Konfigurator, verzögerte Übernahme über
`RequestAction`, Reload-Fenster.

## Anlass

Ein Anwender mit Z-Wave JS UI (72 Geräte, 2384 Ankündigungen, Symcon auf einem Raspberry Pi) bekam Meldungen
um Stunden verspätet. Die Beta 1.10 build 250 mildert das mit einem Umbau im Splitter (Meldungen vormerken,
gebündelt schreiben, zwei Sperren). Das ist ein Workaround: Er lässt die Ursache stehen und bringt
Nebenläufigkeit ins Modul: Das erste Review verlangte einen Folge-Fix (build 249), das Nachreview führt dazu
noch sechs offene Punkte. Dieses Konzept beschreibt den Weg, auf dem er wieder entfällt.

## Befund: Was heute wo liegt

Ein Splitter soll in Symcon Daten zwischen Schnittstelle und Geräten verteilen. Unserer führt daneben den
ganzen Bestand, und alle anderen bedienen sich bei ihm.

| Wer | Was passiert heute | Kosten mit 2384 Ankündigungen |
|---|---|---|
| Splitter, jede Meldung | hält alle Ankündigungen und zu jedem Topic den letzten Wert in je einem JSON-Attribut | bis build 245 je Meldung alles lesen und schreiben: im Test 37 bis 209 ms. Seit build 246 gebündelt. |
| Gerät, jedes Übernehmen | holt **alle** Ankündigungen vom Splitter und wertet alle aus, um die eigenen zu finden (`resolveRuntimeDeviceDefinition`) | bis build 250 im Test 440 ms je Gerät (Splitter 47 ms für die Antwort von 3,1 MB, Gerät 393 ms). Seit build 251 68 ms. |
| Gerät, jedes Übernehmen | holt die letzten Werte seiner Topics vom Splitter und spielt sie ein (`applyCachedTopicPayloads`) | klein, aber Quelle zweier Fehler (Zeitstempel in build 206, Typwechsel in build 248) |
| Gerät, jede Meldung | schreibt ein Attribut (`LastMQTTMessage`) und baut unter der Rust-Edition seine Entitäten neu auf | nicht gemessen |
| Konfigurator | setzt keinen Empfangsfilter; hängt aber am selben Datenfluss wie die Geräte | bekommt vermutlich jede Meldung zugestellt, ohne etwas damit zu tun. Nicht gemessen. |

Alle Zahlen stammen aus dem Test-Harness auf einem PC, nur PHP, ohne Kernel. Auf einem Raspberry Pi ist mit
einem Vielfachen zu rechnen.

Das Übernehmen läuft in allen Geräten zugleich, sobald der Splitter seinen Status wechselt: beim Kernel-Start,
bei jedem Modul-Update und bei jedem Neuverbinden mit dem Broker. Zwischen den beiden Bundles des Anwenders
(25.11. 10:35 bis 26.11. 10:04) hat der Splitter zehnmal neu verbunden, also zehn Runden mit je 72 Geräten und
3,3 MB.

Welcher Posten bei ihm den Rückstau gemacht hat, ist **nicht belegt**. Am Tag des Rückstaus selbst lief nur
eine Sitzung, eine Übernahme-Runde gab es also höchstens zu deren Beginn. Das spricht eher für das Schreiben je
Meldung: Ist sein Raspberry Pi um den Faktor 30 bis 50 langsamer als der Test-PC, erklären 37 bis 209 ms je
Meldung die beobachteten Sekunden. Das Abholen aller Ankündigungen ist dann der zweite große Posten, der bei
jedem Neustart und Neuverbinden dazukommt. Seine Messung soll das klären.

Nachtrag 27.11.2026: Die Messung hat es geklärt, und die Vermutung in diesem Absatz war falsch. Der Rückstau kam
aus einem Posten, der in der Tabelle fehlt: Jedes Gerät bekam jede Meldung zugestellt (Abschnitt „Messung beim
Anwender“).

## Zielbild

Jeder hält, was er selbst braucht. Seit 27.11.2026 gilt das mit einer Ausnahme: Der Splitter hält weiter zu jedem
Topic den letzten Wert, damit ein neu angelegtes Gerät sofort Werte hat (Abschnitt „Leitsatz“). Die Tabelle zeigt
das ursprüngliche Zielbild vom 26.11.; ihre Zeile zum Splitter ist insoweit überholt.

| Wer | hält | bekommt es von |
|---|---|---|
| Gerät | seine eigene Definition (heute schon gespeichert) und seine Werte (die Variablen) | Splitter liefert auf Anfrage **nur die Ankündigungen dieses Geräts** |
| Konfigurator | nichts dauerhaft; die Liste der angekündigten Geräte beim Öffnen | Register des Splitters |
| Splitter | ein Register der Ankündigungen, sonst nichts. Eine Wert-Meldung reicht er nur weiter. | MQTT |

Das Register bleibt beim Splitter, weil nur er alle Ankündigungen vorbeikommen sieht und der Konfigurator sie
nicht selbst beim Broker abholen kann. Es ist aber kein Bestand mehr, den Geräte im laufenden Betrieb lesen:
Ankündigungen kommen selten, fast immer unverändert und in Schüben beim Start des Senders. Das Register darf
deshalb einige Sekunden hinterherhinken und wird nach einem Schub einmal geschrieben. Die strenge Regel des
Workarounds („wer liest, sieht jede Meldung“) und damit Bündeln vor dem Lesen und die Sperre um die
Zwischenspeicher entfallen.

## Was sich für Anwender ändert

Mit der gestrichenen Etappe 2 entfallen die Punkte 1 bis 3: Für Anwender ändert sich nichts. Sie bleiben hier
stehen, weil sie zeigen, was die Etappe gekostet hätte.

1. **Anfangswerte neuer Geräte.** Heute hat ein frisch angelegtes Gerät sofort Werte aus dem Zwischenspeicher.
   Künftig kommen sie mit der nächsten Meldung des Geräts. Ein Batteriesensor, der einmal am Tag meldet, bleibt
   so lange leer. Home Assistant verhält sich bei Meldungen ohne Retain genauso.
2. **Bundle-Export.** Er liefert heute Ankündigungen und letzte Werte aus dem Bestand. Künftig: Ankündigungen
   wie bisher, Werte als Aufzeichnung auf Knopfdruck über einige Minuten. Für den Support ist der Export
   unverzichtbar, er muss mindestens so brauchbar bleiben wie heute.
3. **Diagnose im Splitter.** Die Zeilen zu fehlenden und veralteten Wert-Topics entfallen oder beziehen sich
   auf die Aufzeichnung.
4. **Sonst nichts.** Variablen, Idents, Typen und Darstellungen bleiben.

## Bestehende Anlagen

- Jedes Gerät hat seine Definition gespeichert und läuft ohne Zutun weiter.
- Die beiden großen Attribute des Splitters werden nach dem Update einmal geleert bzw. auf das Register
  verkleinert. Die `settings.json` wird dadurch kleiner (bei dem Anwender um mehrere MB).
- Keine Property ändert sich, keine Variable wird neu angelegt.

## Etappen

Jede Etappe ist für sich veröffentlichbar und wird an echten Daten gemessen.

| Etappe | Inhalt | Verhalten für Anwender | Stand 27.11.2026 |
|---|---|---|---|
| 0 | Messung des Anwenders (Performance-Timing in Splitter und zwei Geräten) | keines | erledigt, Ergebnis im Abschnitt „Messung beim Anwender“ |
| 1 | Gerät fragt nur seine eigenen Ankündigungen ab. Konfigurator bekommt einen Empfangsfilter. Gerät schreibt `LastMQTTMessage` gedrosselt. | keines | gebaut mit build 251 |
| 1a | Empfangsfilter des Geräts trifft nur noch die eigenen Topics. Kam erst durch die Messung auf die Liste. | Topics abgewählter Entitäten kommen nicht mehr an | gebaut mit build 252, beim Anwender gemessen (Abschnitt „Zweite Messung“) |
| 2 | Splitter speichert keine Werte mehr. Export bekommt die Aufzeichnung. Gerät legt Typen ohne Zwischenspeicher an. | Punkte 1 bis 3 oben | **gestrichen** (27.11.2026) |
| 3 | Vormerken und Sperren aus build 246 und 249 so weit vereinfachen, wie es ohne Sperre sicher ist | keines | Sperren bleiben (27.11.2026, Abschnitt „Parallele Aufrufe in einer Instanz“). Das klassische Gerät liest seinen Zustands-Cache seit build 260 vor jeder Änderung neu |
| 4 | Gerät baut seinen Verarbeitungskontext nicht mehr bei jeder Meldung neu auf. Kam durch die Messung auf die Liste. | keines | gebaut mit build 258 |
| 5 | Klassisches Gerät bekommt den genauen Empfangsfilter (`HAMqttTopicFilter`) | keines | gebaut mit build 259 |
| 6 | Gemeinsamer Code für beide Splitter (Topic-Statistik, Performance-Messung, Teile des Selbsttests), Buffer statt Attribut je Meldung | Discovery-Splitter bekommt die Performance-Statistik des klassischen | offen |

Etappe 1 greift die Rollen nicht an und lohnt sich unabhängig davon, was die Messung des Anwenders zeigt.

Warum Etappe 2 gestrichen ist: Sie hatte zwei Gründe, die Last und die Rollen. Die Last ist entfallen, seit build
246 kostet das Halten der Werte beim Anwender 0,1 ms je Meldung und das Bündeln rund 1 % der Zeit. Übrig blieb die
Rollenfrage, und die beantwortet der Leitsatz anders: Neue Geräte haben in beiden Familien sofort Werte, klassisch
per REST, bei Discovery aus dem Zwischenspeicher. Ohne ihn stünde Discovery schlechter da als die klassische
Bridge. Die Entscheidungen 1 und 2 unten sind damit hinfällig.

Für Etappe 3 heißt das: Das Vormerken bleibt, sonst läse und schriebe der Splitter wieder bei jeder Meldung den
ganzen Bestand (beim Anwender 86 ms). Im Aufbau gleicht es dem Zustands-Cache des klassischen Geräts (Buffer im
heißen Pfad, Timer schreibt das Attribut). Anders sind die zwei Sperren und die Regel „vor jedem Lesen bündeln“.
Die Sperren bleiben: Der Kernel lässt mehrere Aufrufe gleichzeitig in dieselbe Instanz (nächster Abschnitt). Offen
ist nur noch die Frage an den Hersteller, wie ein Modul einige MB veränderlichen Zustand halten soll.

### Parallele Aufrufe in einer Instanz (27.11.2026)

Die Meldungen einer Instanz kommen nacheinander. Der MQTT Client wartet auf den Splitter und der Splitter auf
seine Geräte, weil `SendDataToChildren` erst zurückkehrt, wenn alle Kinder fertig sind. Im ersten Mitschnitt des
Anwenders liefen 259 Meldungen in 332 s bei 1,28 s je Meldung, also ohne Überlappung.

Alles andere läuft daneben, in eigenen Threads: Timer, `RequestAction` (Schalten, Knöpfe im Formular),
`ApplyChanges`, Anfragen der Kinder. Zwei Belege:

- Zweiter Mitschnitt des Anwenders, Discovery-Splitter: Ein Bündeln von 122,5 ms endet mitten in einem
  `ReceiveData`, das insgesamt 5,3 ms dauert. Das war 1 von 100 Bündel-Läufen bei einem Splitter, der 1,3 % der
  Zeit beschäftigt ist.
- Versuch an der eigenen Anlage (Rust-Edition), klassisches Gerät mit 110 Entitäten: Während ein Übernehmen
  3,6 s lief (`RequestAction` mit `DeferredApply`), kehrten fünf Aufrufe von `StateCacheFlush` derselben Instanz
  in je 8 bis 19 ms zurück. Der Splitter meldete in dieser Zeit keinen Ausreißer über 100 ms, obwohl das Gerät gut
  zwei Meldungen je Sekunde bekommt. Seine `ReceiveData` warteten also nicht auf das Übernehmen.

Für den Discovery-Splitter heißt das: Vormerk-Sperre und Sperre der Zwischenspeicher sind nötig.

Für das klassische Gerät (und die Entity) ergab das Lesen des Codes (im Testrahmen nachgestellt, an der Anlage
nicht):

- **Timer gegen Meldung ist durch die Reihenfolge sicher.** Der Timer schreibt den Buffer nicht, er kopiert ihn
  ins Attribut. Die Meldung schreibt erst den Cache, setzt dann den Merker und stellt zuletzt den Timer; der
  Timer stellt sich zuerst ab und löscht den Merker vor dem Auswerten. Ausnahme: Mit der Variable „Nicht
  verfügbare Entitäten (JSON)“ liest der Timer den Cache schon für diese Variable und wertet die Erreichbarkeit danach
  mit demselben Stand aus. Eine Meldung, die dazwischen ankommt, wird dann nicht ausgewertet, ihr Merker aber
  gelöscht.
- **Übernehmen und Schalten überschrieben den Cache mit einem alten Stand** (bis build 259). Jede Ausführung las
  den ganzen Cache einmal (`entityStateCacheMemory`) und schrieb bei jeder Änderung den ganzen Stand zurück. Wo
  eine Ausführung zwischen Lesen und Schreiben auf Home Assistant wartet:
  - Übernehmen und `SyncStates`, eine REST-Abfrage je Entität, zusammen Sekunden.
  - Entity-Instanz: Schalten der Hauptvariable mit „Status emulieren“ (das `set`-Topic führt der Splitter per
    REST aus).
  - Gerät: Schalten von `number`, `input_text`, `datetime` und `input_datetime` mit „Status emulieren“, dazu der
    Klima-Modus. Die Hauptvariable anderer Domänen schreibt im Gerät nach dem Senden nichts in den Cache, auch
    mit „Status emulieren“ nicht (anders als in der Entity-Instanz).
  - Der Timer, der die Quelle eines Mediaplayers per REST nachfragt.
- Die Variablen stimmen zunächst, sie bekommen ihren Wert aus der Meldung. Falsch wurde, was später aus dem Cache
  abgeleitet wird. Im Testrahmen nachgestellt: Kam eine Entität während des Übernehmens von „unavailable“ zurück,
  führte der Cache sie weiter als ausgefallen, und standen die anderen auch so da, galt das Gerät nach zehn
  Minuten als nicht erreichbar. In der Entity-Instanz sprang die gerade gemeldete Helligkeit eines Lichts beim
  nächsten Attribut-Topic auf 0.

**Seit build 260** liest jede Änderung den Buffer vorher neu (`readEntityStateCacheForUpdate`) und setzt nur den
eigenen Eintrag hinein. Das kostet einen Buffer-Lesezugriff je Änderung, im Testrahmen zwei statt einem je
Meldung. Was bleibt:

- Zwischen dem Lesen und dem Schreiben liegt weiter keine Sperre. Die Lücke ist so kurz wie die zwei
  Kernel-Aufrufe.
- Die Ausnahme beim Timer mit „Nicht verfügbare Entitäten (JSON)“ oben.
- Eine Meldung, die jünger ist als die REST-Antwort zur selben Entität und vor ihr verarbeitet wird, wird von der
  älteren Antwort überschrieben.

### Stand Etappe 4 (1.10 build 258)

Das Gerät hält seinen Verarbeitungskontext zusätzlich im Instanz-Buffer, im Format des klassischen Geräts
(`libs/HAKeyedBlob.php`, seit build 257 gemeinsam): eine Kopfzeile mit Signatur, je Topic eine Zeile mit den
betroffenen Entitäten, je Entität eine Zeile. Eine Meldung liest die Definition einmal für die Signatur und
dekodiert nur die Zeilen ihres Topics. Bis build 257 las sie die Definition zweimal, dekodierte und normalisierte
alle Entitäten und baute den Topic-Index neu auf.

Gemessen im Test-Harness mit dem Bundle des Anwenders (72 Geräte, je 30 Meldungen in frischer Ausführung, nur PHP):

| | bis build 257 | seit build 258 |
|---|---|---|
| Zeit je Meldung, Mittel | 6,9 ms | 0,24 ms |
| langsamstes Gerät | 25,7 ms (121 Entitäten) | 0,63 ms (91 Entitäten) |

Der Ausschnitt aus dem Buffer gleicht dem aus dem vollen Aufbau, geprüft an 18 echten Bundles mit 528 Geräten und
5378 Topics. Ein Gerät davon (476 Entitäten, Kontext über 500 KB) bekommt keinen Cache, weil Symcon Buffer über
512 KB kürzt; es baut weiter je Meldung auf. Am Raspberry Pi des Anwenders ist die Wirkung nicht gemessen.

### Stand Etappe 1 (1.10 build 251)

Gemessen im Test-Harness mit den 2384 Ankündigungen des Anwenders, je Gerät und Übernehmen:

| | bis build 250 | seit build 251 |
|---|---|---|
| Splitter beantwortet die Anfrage | 47 ms, 3,1 MB | 56 ms, 0,04 MB |
| Gerät wertet die Antwort aus | 393 ms | 12 ms |
| zusammen | 440 ms | 68 ms |

Der Splitter liest für jede Anfrage weiter sein ganzes Register; das ist der Rest, den Etappe 3 angeht. Die
Definition jedes Geräts ist dieselbe wie vorher, geprüft an 18 echten Bundles mit zusammen 528 Geräten.
Das Gerät schreibt `LastMQTTMessage` höchstens alle 5 s, der Konfigurator hat einen Empfangsfilter.

### Stand Etappe 5 (1.10 build 259)

Klassisches Gerät und Entity setzen ihren Filter über `HAMqttTopicFilter::subtreeTopicsFilterPattern()`: je
Entität `<base>/<domain>/<object_id>` und alles darunter, kein Topic, das nur gleich anfängt. Gegen den Bestand der
eigenen Anlage (31 Geräte, 1380 HA-Entitäten): vorher 9 fremde Treffer bei zwei Geräten, jetzt 0, und kein eigenes
Topic fehlt. Der Filter wird länger (Median 433 statt 263 Zeichen, der längste 3877 statt 623; er gehört zum Gerät
mit 110 Entitäten, das vorher mit 279 Zeichen auskam).

## Entscheidungen (26.11.2026)

1. Neu angelegte Geräte bekommen ihre Werte mit der nächsten Meldung, ohne Ersatz für den Zwischenspeicher.
2. Der Bundle-Export liefert Werte künftig als Aufzeichnung auf Knopfdruck.
3. Etappe 1 beginnt sofort, ohne die Messung des Anwenders abzuwarten.

Nach der Messung (27.11.2026):

4. Etappe 2 wird zurückgestellt. Die Entscheidungen 1 und 2 bleiben für den Fall bestehen, dass sie doch kommt.
5. Über Etappe 3 und 4 wird mit dem nächsten Mitschnitt des Anwenders entschieden (nach dem Update auf build 252).

Nach dem zweiten Mitschnitt (27.11.2026):

6. Maßstab ist je Thema die bessere Lösung aus beiden Familien (Abschnitt „Leitsatz“), nicht die Last beim Anwender
   allein. Die ist weg.
7. Etappe 2 wird gestrichen. Die Entscheidungen 1 und 2 sind hinfällig.
8. Etappe 4 wird gebaut, mit gemeinsamem Code für beide Geräte. Danach der genaue Filter für das klassische Gerät
   (Etappe 5) und der gemeinsame Code für beide Splitter (Etappe 6). Etappe 3 wartet auf die Antwort des Herstellers.

## Nicht geprüft

- Ob der Kernel dem Konfigurator ohne Filter wirklich jede Meldung zustellt und was das kostet. Seit build 251
  hat er einen Filter, gemessen wurde es davor nicht.
- Erledigt: Ob der Kernel mit dem genauen Filter aus build 252 an einer laufenden Anlage richtig zustellt. Der
  zweite Mitschnitt des Anwenders zeigt es, auch für ein Topic mit Umlauten.
- Erledigt: Ob `ReceiveData`-Aufrufe einer Instanz nacheinander oder nebeneinander laufen. Untereinander
  nacheinander, gegenüber Timer, `RequestAction` und `ApplyChanges` nebeneinander (Abschnitt „Parallele Aufrufe
  in einer Instanz“).
- Ob der Zustands-Cache des klassischen Geräts an einer laufenden Anlage wirklich Meldungen verlor. Nachgestellt
  ist es nur im Testrahmen (`check-state-cache-parallel.php`); der Cache ist von außen nicht lesbar.
- Ob das Gerät den Variablentyp ohne zwischengespeicherten Wert überall sinnvoll festlegen kann. Heute hängt er
  bei Sensoren, `number` und `cover` am Wert.
- Wie ein Modul nach Symcons Vorstellung einige MB veränderlichen Zustand halten soll. Vor Etappe 3 beim
  Hersteller nachfragen.
- Alle Kosten am echten Kernel und auf langsamer Hardware.

## Messung beim Anwender (26.11.2026) und was daraus folgt

Der Performance-Mitschnitt des Anwenders (Splitter und zwei Geräte, je rund fünf Minuten vor und drei Minuten nach
dem Update von build 244 auf build 250) zeigt eine Ursache, die in der Tabelle oben fehlt:

| Messpunkt im Splitter, je Meldung (Median) | build 244 | build 250 |
|---|---|---|
| Verteilen an die Geräte (`SendDataToChildren`) | 1189 ms | 1091 ms |
| Zwischenspeicher (`updateTopicPayloadCache` bzw. Vormerken) | 86 ms | 0,1 ms |
| zusammen | 1277 ms | 1092 ms |

Vor dem Update verarbeitete der Splitter 259 Meldungen in 332 s und war die ganze Zeit beschäftigt. Beide Geräte
bekamen jede Meldung, auch die fremder Geräte, und brauchten dafür je 8 bzw. 14 ms. Der Empfangsfilter der Geräte
fasste Topics über ihren gemeinsamen Anfang zusammen und ließ bei diesem Sender alles durch; 72 Geräte mal
8 bis 14 ms sind die gemessene Sekunde.

Mit 1.10 build 252 trifft der Filter genau die Topics des Geräts. Mit dem Bundle des Anwenders und den 400
Meldungen seines Mitschnitts im Test: vorher 28.800 Zustellungen, jetzt 55.

Für das Zielbild heißt das:

- Der Rückstau kam aus dem Verteilen, nicht aus den Zwischenspeichern. Das Vormerken aus build 246 hat beim
  Anwender 7 % der Zeit je Meldung gespart. Es bleibt ein Workaround, den Etappe 3 ersetzt, ist aber nicht mehr
  dringend.
- Etappe 1 bestätigt sich am Gerät des Anwenders: `resolveRuntimeDeviceDefinition` kostete dort mit build 250 je
  Gerät 1430 und 1462 ms (im Test am PC 440 ms).
- Neu auf der Liste: Der Verarbeitungskontext eines Geräts wird unter Rust bei jeder Meldung neu aufgebaut
  (8 bis 14 ms). Mit dem genauen Filter trifft das nur noch das Gerät, das die Meldung braucht.
- Ob build 252 den Rückstau beim Anwender beseitigt, zeigt erst ein neuer Mitschnitt von dort.

## Zweite Messung beim Anwender (27.11.2026, build 254)

Splitter und drei Geräte, 15 Minuten. Der Rückstau ist weg.

| Splitter | build 250 | build 254 |
|---|---|---|
| je Meldung, Median | 1092 ms | 4,0 ms (99 % unter 25 ms, größter Wert 65 ms) |
| Durchsatz | 259 Meldungen in 332 s (build 244) | 1936 Meldungen in 931 s |
| beschäftigt | durchgehend | 1,3 % der Zeit |

- Die drei Geräte bekamen 15, 46 und 14 Meldungen, alle zu eigenen Topics. Was der Splitter unter ihrer Wurzel
  zusätzlich sah (`lastActive`, `basic/…/currentValue`, `alarmType`, `alarmLevel`), steht in keiner Ankündigung.
- Ein Topic mit Umlauten kommt beim Gerät an. Die Klasse für Zeichen außerhalb von ASCII im Filter trägt also am
  echten Kernel.
- Verteilen (`SendDataToChildren`) je Meldung: ohne Empfänger 1,0 bis 3,5 ms, mit Empfänger 13 ms. Die 13 ms sind
  das Gerät, das seinen Kontext je Meldung neu aufbaute (8 bis 14 ms, nie aus dem Memo). Das ist Etappe 4.
- Bündeln im Splitter: 100 Läufe, zusammen 9,6 s, rund 1 % der Zeit. Ein Lauf kostet rund 11 ms, wenn nur Topics
  ohne Ankündigung vorgemerkt waren, und rund 123 ms, sobald ein Wert in den Zwischenspeicher muss.
- Gut die Hälfte der Meldungen (1012 von 1936) sind Ereignis-Topics von Z-Wave JS UI (`…/_EVENTS/…`), die kein
  Gerät braucht.