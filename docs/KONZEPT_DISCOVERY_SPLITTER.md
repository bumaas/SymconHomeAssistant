# Konzept: Der Discovery-Splitter verteilt nur noch

Stand 27.11.2026. Entschieden am 26.11.2026, nach der Messung des Anwenders am 27.11.2026 angepasst (beides
unten). Etappe 1 ist mit 1.10 build 251 gebaut, der Empfangsfilter aus der Messung mit build 252. Etappe 2 ist
zurückgestellt.

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

Jeder hält, was er selbst braucht.

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
| 1a | Empfangsfilter des Geräts trifft nur noch die eigenen Topics. Kam erst durch die Messung auf die Liste. | Topics abgewählter Entitäten kommen nicht mehr an | gebaut mit build 252, Wirkung beim Anwender noch nicht gemessen |
| 2 | Splitter speichert keine Werte mehr. Export bekommt die Aufzeichnung. Gerät legt Typen ohne Zwischenspeicher an. | Punkte 1 bis 3 oben | **zurückgestellt** |
| 3 | Register der Ankündigungen einfach führen, Workaround aus build 246 und 249 entfernen | keines | offen, Entscheidung nach dem nächsten Mitschnitt des Anwenders |
| 4 | Gerät baut seinen Verarbeitungskontext nicht mehr bei jeder Meldung neu auf. Kam durch die Messung auf die Liste. | keines | offen, Entscheidung nach dem nächsten Mitschnitt des Anwenders |

Etappe 1 greift die Rollen nicht an und lohnt sich unabhängig davon, was die Messung des Anwenders zeigt.

Warum Etappe 2 zurücksteht: Sie hatte zwei Gründe, die Last und die Rollen. Die Last ist entfallen, seit build 246
kostet das Halten der Werte beim Anwender 0,1 ms je Meldung. Übrig bleibt die Rollenfrage, und dafür allein ändert
sich für Anwender zu viel (Anfangswerte, Export, Diagnose). Die Entscheidungen 1 und 2 unten gelten weiter, sie
kommen erst mit Etappe 2 zum Tragen.

Das hat eine Folge für Etappe 3: Solange der Splitter die Werte hält, kann das Vormerken dafür nicht ersatzlos
entfallen, sonst liest und schreibt er wieder bei jeder Meldung den ganzen Bestand (beim Anwender 86 ms). Etappe 3
ohne Etappe 2 kann also nur das Register der Ankündigungen vereinfachen und muss für die Werte einen eigenen Weg
finden oder das Vormerken dort behalten.

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

## Entscheidungen (26.11.2026)

1. Neu angelegte Geräte bekommen ihre Werte mit der nächsten Meldung, ohne Ersatz für den Zwischenspeicher.
2. Der Bundle-Export liefert Werte künftig als Aufzeichnung auf Knopfdruck.
3. Etappe 1 beginnt sofort, ohne die Messung des Anwenders abzuwarten.

Nach der Messung (27.11.2026):

4. Etappe 2 wird zurückgestellt. Die Entscheidungen 1 und 2 bleiben für den Fall bestehen, dass sie doch kommt.
5. Über Etappe 3 und 4 wird mit dem nächsten Mitschnitt des Anwenders entschieden (nach dem Update auf build 252).

## Nicht geprüft

- Ob der Kernel dem Konfigurator ohne Filter wirklich jede Meldung zustellt und was das kostet. Seit build 251
  hat er einen Filter, gemessen wurde es davor nicht.
- Ob der Kernel mit dem genauen Filter aus build 252 an einer laufenden Anlage richtig zustellt. Im Test ja, an
  der eigenen Anlage gibt es kein Discovery-Gerät mit laufenden Meldungen.
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