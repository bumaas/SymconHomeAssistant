# Visualisierung: Standard-Darstellungen statt eigener Kacheln

Diese Datei ist eine interne Wartungs- und Planungsdoku zur Visualisierung. Sie hält die
Entscheidung gegen eigene HTML-Kacheln fest und beschreibt den Umstellungsplan auf die
nativen Symcon-Composite-Darstellungen (Licht, Thermostat, Media Player) sowie die offenen
Lücken (z. B. camera).

## 1. Kontext und Entscheidung

Es stand die Frage im Raum, ob das HA-Modul – analog zum zigbee2mqtt-Modul – eigene
HTML-SDK-Kacheln (`GetVisualizationTile`) je Domain anbieten soll. Nach Recherche und einer
Diskussion im Symcon-Forum lautet die Entscheidung: **keine eigenen HTML-Kacheln**.

Gründe:

- Symcon liefert mit den **zusammengefassten Darstellungen** (Licht, Thermostat, Rollladen,
  Media Player …) bereits gerätespezifische Kacheln – konsistent über alle Integrationen.
- Eigene HTML-Kacheln für genau diese Domains würden den Standard nur nachbauen
  (Inkonsistenz + Wartungslast, Performance-/Bedien-Nachteile in der App).
- Tenor im Forum (u. a. burki24/zigbee2mqtt, KaiS/TileKacheln): zuerst Standards nutzen,
  HTML-SDK nur für echte Sonderfälle.

Forenthread: „Eigene HTML-Kacheln im Modul – sinnvoll oder besser Standard-Darstellungen
nutzen?"

## 2. Wie Symcon-Darstellungen funktionieren

Symcon unterscheidet zwei Ebenen:

- **Variablen-Darstellungen** (per `MaintainVariable`/`IPS_SetVariableCustomPresentation`
  als Array mit `PRESENTATION`-GUID + Parametern): Schalter, Schieberegler, Wertanzeige,
  Farbe, Aufzählung, Datum/Uhrzeit, Dauer, Rollladen (`SHUTTER`) usw. **Das nutzt das Modul
  heute durchgehend** (siehe `libs/Device/HAPresentation.php`).
- **Instanz-Darstellungen** (zusammengefasst, automatisch erkannt): **Licht**, **Thermostat**,
  **Media Player**. Diese werden **nicht** per GUID an eine Variable gehängt, sondern von
  Symcon automatisch gebaut, sobald die **Kindvariablen einer Instanz** die passende
  Darstellung **plus „Verwendung" (`USAGE_TYPE`)** und ggf. eine Variablenaktion besitzen.

### Wichtige Randbedingungen der Composites

- **Nur Kachel-Visualisierung.** Im **WebFront werden sie nicht unterstützt** → Rückfall auf
  „Liste". Innerhalb einer Listen-/Kategorieansicht ebenfalls Rückfall auf „Liste".
- **Instanz-Ebene.** Ein **Device** mit mehreren gemischten Entitäten wird *nicht* zu einer
  sauberen Licht-/Thermostat-Kachel. Der Gewinn liegt praktisch ausschließlich im
  **Entity-Modul** (eine Entität = eine Instanz).

## 3. Auslöse-Bedingungen der Composites

| Composite | Benötigte Kindvariablen (Darstellung · Verwendung · Aktion) |
|---|---|
| **Thermostat** | „Soll": Schieberegler · Temperatur · mit Aktion — „Ist": Wertanzeige · Temperatur |
| **Licht** | „Status": Schalter · An/Aus (Pflicht) — ≥1 optional: Helligkeit (Schieberegler · Intensität), Farbtemperatur (Schieberegler · Farbtemperatur), Farbe (Farbe · mit Aktion) |
| **Media Player** | „Wiedergabe": Integer · Aktion · Profil `~Playback*` (Pflicht) — optional: Lautstärke (Schieberegler · Lautstärke), Stumm (Schalter), Fortschritt (Schieberegler · Fortschritt), Cover (Bild), Titel `~Song`, Interpret `~Artist`, Wiederholung `~Repeat`, Zufall `~Shuffle` |

(Quellen siehe Abschnitt 8.)

## 4. Ist-Zustand des Moduls je Domain

Bewertet gegen `libs/Device/HAPresentation.php` und `libs/Device/HADomainAttributeMaintenance.php`.

| Domain | Heute | Bewertung |
|---|---|---|
| **cover** | `SHUTTER` (Rollladen) bei Position + passender device_class (`HAPresentation.php:601`) | ✅ Composite genutzt – Referenzfall |
| **climate** | Ist-Temp als Value `USAGE_TYPE=1` = Temperatur ✅; Soll-/Temp-Slider jetzt `USAGE_TYPE=0` = Temperatur + `GRADIENT_TYPE=1` ✅; Writability feature-bewusst (Slider nur mit Aktion, sonst Wertanzeige) ✅ | ✅ behoben (s. Abschnitt 6) |
| **light** | `brightness`-Slider `USAGE_TYPE=2` = Intensität ✅; `color_temp`/`color_temp_kelvin` `USAGE_TYPE=1` = Farbtemperatur + `GRADIENT_TYPE=2` ✅; Farbe (`rgb_color`) mit Aktion; Status = Schalter (Default An/Aus) | ✅ behoben (s. Abschnitt 6) |
| **media_player** (Slider/Schalter) | Lautstärke `usage_type=3` ✅, Fortschritt `usage_type=4` ✅, Stumm `usage_type=1` ✅ (`HAMediaPlayerDefinitions.php:128/141/164`) | ✅ Teil-Tags korrekt – scheitert nur an der Wiedergabe-Hauptvariable |
| **media_player** | Status = `VALUE_PRESENTATION`-Aufzählung statt Integer+`~Playback`+Aktion | ⚠️ Composite löst nicht aus – größerer Umbau |
| **camera** | Preview- + Stream-**Medienobjekte** vorhanden; Statusvariable nicht im Dispatch (roher Text) | ◐ Stream konzeptionell geklärt (s. Abschnitt 9); Status optional kosmetisch |
| **lock** | Value-Anzeige mit Optionen; Bedienung über separate Action-Variable | ◐ kein Composite vorhanden; State/Action getrennt |
| **vacuum** | Value-Anzeige mit State-Optionen+Icons; Aktionen separat | ◐ kein Composite vorhanden |
| **fan** | Main = Switch; percentage/preset/oszillation separat | ✅ vertretbar (kein Composite vorhanden) |
| **humidifier** | Main = Switch; Ziel-/Ist-Feuchte Slider, mode-Enum | ✅ vertretbar (kein Composite vorhanden) |
| **input_number** (=number) | Slider bei min/max, sonst Value+Digits | ✅ meist gut; schwach nur ohne min/max |
| **input_datetime** | `DATE_TIME` mit Capability-Erkennung | ✅ meist gut; schwach nur bei fehlenden Attributen |

## 5. `USAGE_TYPE` (Verwendung): pro Darstellung verschieden

Die numerischen Werte sind **je Darstellung unterschiedlich** und in der SDK-Entwicklerdoku
(Abschnitt 8) wörtlich dokumentiert. Maßgeblich:

| Darstellung | `USAGE_TYPE`-Werte |
|---|---|
| **Schieberegler** | `0`=Temperatur, `1`=Farbtemperatur, `2`=Intensität, `3`=Lautstärke, `4`=Fortschritt, `5`=Keine |
| **Wertanzeige** | `0`=Keine, `1`=Temperatur |
| **Schalter** | `0`=An/Aus, `1`=Stumm schalten, `2`=Keine |
| **Jalousie** | `0`=Offen, `1`=Rotation |

Ergänzend beim Schieberegler `GRADIENT_TYPE`: `0`=Standard, `1`=Temperatur, `2`=Farbtemperatur,
`3`=Benutzerdefiniert.

### Konsequenzen für den aktuellen Code

- **Behoben:** `getClimateSliderPresentation` setzte am Temperatur-**Schieberegler**
  `USAGE_TYPE=1`. Bei einem Schieberegler ist `1` = **Farbtemperatur**, nicht Temperatur (= `0`).
  Der Wert wurde vermutlich von der **Wertanzeige** übernommen (dort ist `1` = Temperatur,
  korrekt). Jetzt auf `0` korrigiert (+ `GRADIENT_TYPE=1`).
- **Behoben:** `getClimateAttributePresentation` wählte den Slider anhand der statischen
  `$meta['writable']`, während die Aktion über das feature-bewusste
  `isWritableClimateAttribute` gesetzt wurde. Bei fehlendem Feature-Bit entstand ein
  Schieberegler **ohne** Variablenaktion → Symcon-Fehler „Diese Darstellung ist nur für
  Variablen mit einer Variablenaktion verfügbar" (z. B. `target_temperature_low` ohne Bit 2).
  Beide nutzen jetzt `isWritableClimateAttribute` → nicht beschreibbare Temperaturen werden
  zur **Wertanzeige**.
- **Behoben (domänenübergreifend):** Auswahl-Attribute erhielten `ENUMERATION` (Aufzählung),
  sobald Optionen vorlagen – auch wenn die Variable **keine** Variablenaktion bekommt. Die
  Aktion wird aber writability-/feature-bewusst über `isWritableXxxAttribute` gesetzt. Ohne
  Aktion meldet Symcon „Diese Darstellung ist nur für Variablen mit einer Variablenaktion
  verfügbar". Betroffen waren u. a. climate `hvac_action` (immer read-only), fan
  `current_direction` (read-only) sowie alle Mode-Attribute **ohne** unterstütztes Feature-Bit;
  humidifier `action` war bereits korrekt als Wertanzeige umgesetzt. Statt pro Domain wurde ein
  zentraler Helfer `buildOptionPresentation(options, writable, captionResolver)` eingeführt:
  beschreibbar → `ENUMERATION` (mit Aktion), read-only → `VALUE_PRESENTATION` mit Optionen (wie
  bei lock/cover/valve/event). Climate, fan, media_player (`source`/`sound_mode`), humidifier
  (`mode`) und light (`effect`) nutzen ihn jetzt einheitlich; sie übergeben dieselbe
  `isWritable`-Entscheidung, die auch die Aktion steuert. Die Optionsschemata unterscheiden sich
  (`Color` vs. `ColorActive`/`ColorValue`) und werden vom Helfer passend erzeugt.
- **Korrekt getaggt:** number-Slider Intensität=`2` (`:828`), media Lautstärke=`3`,
  Fortschritt=`4`, Stumm-Schalter=`1` (`HAMediaPlayerDefinitions.php`).

**Empfehlung:** Die Werte als benannte, **darstellungs-spezifische** Konstanten zentral ablegen
(z. B. `SLIDER_USAGE_TEMPERATURE = 0`, `VALUE_USAGE_TEMPERATURE = 1`), damit der Slider/Value-
Unterschied nicht erneut zu Verwechslungen führt.

## 6. Umstellungsplan (priorisiert)

1. **Thermostat reparieren (Bugfix + größter Hebel). — ERLEDIGT**
   `getClimateSliderPresentation`: `USAGE_TYPE` `1`→`0` (Temperatur) + `GRADIENT_TYPE=1`.
   `getClimateAttributePresentation`: `$isWritable` über `isWritableClimateAttribute`
   (feature-bewusst) → Slider nur mit Aktion, sonst Wertanzeige (behebt den Aktions-Fehler bei
   `target_temperature_low`/`high` ohne Feature-Bit 2). Offen: in einer climate-Entity-Instanz
   prüfen, ob die Kachel als Thermostat erscheint; ggf. Dopplung aus Haupt-Slider und
   Soll-Temp-Attributvariable auflösen.
2. **Überflüssige Attribut-Variablen vermeiden (domainübergreifend). — ERLEDIGT**
   Das Feature-Gating sitzt zentral in der gemeinsamen Pipeline
   (`HAStandardAttributeMaintenance.php`): `attributeFeatureGateBlocks` prüft `requires_features`
   gegen `supported_features` für **alle** definitionsbasierten Domains (climate, light,
   media_player, fan, humidifier, cover). `maintainStandardAttributeVariables` überspringt nicht
   unterstützte Attribute **und** entfernt bereits angelegte über
   `removeUnsupportedAttributeVariable`; `ensureStandardAttributeVariable` blockt die
   Laufzeit-Anlage. Greift nur, wenn `supported_features` bekannt ist (kein Churn). Behebt die
   leeren „Nie"-Variablen (z. B. climate `target_temperature_low/high`, `target_humidity`,
   `preset_mode`, `fan_mode`, `swing_mode`, `swing_horizontal_mode`), die HA teils als `null`
   mitliefert.
3. **Licht aktivieren. — ERLEDIGT**
   In `getLightAttributePresentation`: `brightness`-Slider `USAGE_TYPE=2` (Intensität);
   `getLightBoundedSliderPresentation` (für `color_temp`/`color_temp_kelvin`) `USAGE_TYPE=1`
   (Farbtemperatur) + `GRADIENT_TYPE=2`. Status (Schalter) trägt per Default `USAGE_TYPE=0`
   (An/Aus); `rgb_color` erhält die Aktion über `isWritableLightAttribute`. Offen: in einer
   light-Entity-Instanz prüfen, ob die Kachel als „Licht" erscheint (Status + ≥1 optionale
   Variable wie Helligkeit/Farbe).
4. **camera.** Stream-Konzept geklärt (s. Abschnitt 9) – kein Code nötig. Optional offen:
   Statusvariable in den Dispatch aufnehmen (Optionen/Icons `idle`/`recording`/`streaming`).
5. **Media Player (nur falls 1–4 tragen).**
   Hauptvariable auf Integer + `~Playback*` + Aktion umstellen, Lautstärke/Fortschritt mit
   Verwendung versehen, Titel/Interpret/Cover-Profile setzen.

## 7. Offene Punkte / Verifikation

- `USAGE_TYPE`-Werte sind geklärt (Abschnitt 5); empfohlen ist nur noch das Ablegen als
  darstellungs-spezifische Konstanten.
- Thermostat-Auslösung (nach Climate-Bugfix) in einer realen climate-Entity-Instanz prüfen.
- Licht-Auslösung (nach Schritt 3) in einer light-Entity-Instanz prüfen
  (Status + ≥1 optionale Variable).
- Geltungsbereich beachten: Composites greifen nur in der Kachel-Visualisierung und nur im
  Entity-Modul sinnvoll, nicht in Multi-Entity-Device-Instanzen.

## 8. Quellen

- Objekt-Darstellung (Übersicht): https://www.symcon.de/de/service/dokumentation/komponenten/objekt-darstellung/
- Licht: https://www.symcon.de/de/service/dokumentation/komponenten/objekt-darstellung/licht/
- Thermostat: https://www.symcon.de/de/service/dokumentation/komponenten/objekt-darstellung/thermostat/
- Media Player: https://www.symcon.de/de/service/dokumentation/komponenten/objekt-darstellung/media-player/
- Schieberegler (Verwendung/Parameter): https://www.symcon.de/de/service/dokumentation/komponenten/objekt-darstellung/schieberegler/
- SDK-PHP Darstellungen: https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/darstellungen/
- MaintainVariable (Presentation als Array, ab v8.0): https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/maintainvariable/
- HTML-SDK: https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/html-sdk/
- Kamera/RTSP in der Kachel-Visu: https://community.symcon.de/t/hikvision-stream-einbindung-in-kachel-visualisierung/140069

## 9. Kamera-Streams (RTSP)

**Kernpunkt:** Symcons Kachel-Visualisierung spielt Kamera-Streams ausschließlich als
**RTSP/RTSPS (H264) über WebRTC** ab – kein HLS, kein MJPEG. Das Stream-Medienobjekt
(`MEDIATYPE_STREAM`) benötigt eine echte `rtsp://…`-Adresse.

**Warum HA den Stream nicht automatisch liefert:** Home Assistant gibt die RTSP-URL aus
Sicherheitsgründen nicht in den Entity-Attributen heraus (z. B. Hikvision-Integration). Die
Attribute enthalten nur `access_token` + `entity_picture` für den **Snapshot** über
`…/api/camera_proxy/<entity_id>` – das liefert nur Standbilder, keinen abspielbaren Stream.
`resolveCameraStreamUrl` liest `stream_source`/`rtsp_url`; fehlen diese, bleibt das
Stream-Objekt leer.

**Vorgehen (kein Code nötig):** Das Modul legt das Stream-Medienobjekt ohnehin an und
**überschreibt es nicht**, solange HA keinen `stream_source` liefert (`ensureManagedMediaObject`
setzt nur Name/Position/Parent, nie die `MediaFile`). Der Anwender trägt die RTSP-URL der Kamera
direkt am Stream-Medienobjekt ein, z. B.:

```
rtsp://<user>:<pass>@<kamera-ip>:554/Streaming/Channels/101
```

Symcon verbindet sich damit **direkt** mit der Kamera (P2P/WebRTC), an HA vorbei. HA bleibt
für den Snapshot (Preview) zuständig.

**Caveats:**
- Manuell gesetzte URL übersteht normales `ApplyChanges`, **nicht** aber ein Umbenennen/
  Neuaufsetzen der Entität (`cleanupRenamedSharedEntityObject` löscht dann das Medienobjekt).
- Liefert eine andere Integration doch `stream_source`/`rtsp_url` (generic/ONVIF/ffmpeg), setzt
  das Modul die URL automatisch und übersteuert eine manuelle Eingabe.
- RTSP-Stream-Verteilung unterliegt edition­abhängigen Limitierungen in Symcon.
