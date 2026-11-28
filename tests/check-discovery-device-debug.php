<?php

declare(strict_types=1);

/**
 * Prüft am ECHTEN Modul (Home Assistant MQTT Discovery Device) über den Kernel-Stub, was das erweiterte Debug bei
 * eingehenden Meldungen zeigt: jede Meldung mit Topic und Payload („Eingangsdaten“, wie beim klassischen Gerät) und
 * jeden Wert, den das Gerät daraufhin in eine Variable schreibt („Wert gesetzt“ mit Ident und Wert).
 *
 * Anlass: ein Anwender, Forum t/139168 #27 (27.11.2026). Bei einem Dimmer aus Z-Wave JS UI wechselte der Status
 * beim Umschalten ein- bis zweimal zwischen Aus und An, die Helligkeit blieb stehen. Status und Helligkeit hängen
 * dort am selben Topic (currentValue): 0 heißt aus, die Helligkeit übergeht eine 0. Ob zwischendurch eine 0 ankam,
 * ließ sich im Debug des Geräts nicht sehen, es zeigte nur ausgehende Befehle.
 *
 * Fixture: tests/fixtures/discovery_zwavejs_20261125.json (Anwender-Bundle, neutralisiert), Qubino Flush Dimmer
 * Plus. Die Meldungen sind die echte currentValue-Meldung aus dem Bundle mit anderem Wert.
 */

require_once __DIR__ . '/device-harness.php';

error_reporting(E_ALL);

$zwave = json_decode((string)file_get_contents(__DIR__ . '/fixtures/discovery_zwavejs_20261125.json'), true, 512, JSON_THROW_ON_ERROR);
const DIMMER = 'Z-Wave-JS/Raum/Bild/switch_multilevel/endpoint_0/';

DiscoveryDeviceHarness::$splitterAntwort = [
    'Items'            => $zwave['discovery_configs'],
    'Count'            => count($zwave['discovery_configs']),
    'DiscoveryPrefix'  => 'homeassistant',
    'SessionStartedAt' => time() - 3600,
];
DiscoveryDeviceHarness::$topicPayloadAntwort = ['Items' => $zwave['topic_payloads']];
DiscoveryDeviceHarness::$parentAktiv = true;

/** Ein Gerät „Raum-Bild“ an eigenem Splitter. */
function bildInstanz(bool $expertenDebug, bool $emulieren = false): DiscoveryDeviceHarness
{
    $splitter = neueFensterInstanz(DiscoverySplitterHarness::class);
    $geraet = neueFensterInstanz(DiscoveryDeviceHarness::class);
    IPS_ConnectInstance($geraet->id(), $splitter->id());
    $geraet->SetProperty('DeviceID', 'zwavejs2mqtt_0x00000001_node23');
    $geraet->SetProperty('EnableExpertDebug', $expertenDebug);
    $geraet->SetProperty('EmulateStatus', $emulieren);
    neueAusfuehrung($geraet)->ApplyChanges();
    return $geraet;
}

/** @return array<string, int> Ident => Variablen-ID */
function variablenNachIdent(int $instanz): array
{
    $ergebnis = [];
    foreach (IPS_GetChildrenIDs($instanz) as $id) {
        if (IPS_VariableExists($id)) {
            $ergebnis[IPS_GetObject($id)['ObjectIdent']] = $id;
        }
    }
    return $ergebnis;
}

/** Die echte currentValue-Meldung aus dem Bundle mit anderem Wert. */
function dimmerMeldung(array $zwave, int $wert): string
{
    $roh = array_find($zwave['topic_payloads'], static fn(array $p): bool => $p['topic'] === DIMMER . 'currentValue')['payload'];
    $json = json_decode($roh, true, 512, JSON_THROW_ON_ERROR);
    $json['value'] = $wert;
    return json_encode($json, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

/**
 * Schickt eine Meldung an das Gerät und liefert die Debug-Zeilen, die dabei entstanden sind.
 *
 * @return list<array{Message: string, Data: string, Format: int}>
 */
function meldeMitDebug(DiscoveryDeviceHarness $g, string $topic, string $payload): array
{
    $vorher = count(IPS\DebugServer::getDebugMessages($g->id()));
    neueAusfuehrung($g)->ReceiveData(json_encode(['Topic' => $topic, 'Payload' => bin2hex($payload)], JSON_THROW_ON_ERROR));
    return array_slice(IPS\DebugServer::getDebugMessages($g->id()), $vorher);
}

/**
 * Die Zeilen mit einem bestimmten Meldungstext, der Kontext dekodiert.
 *
 * @return list<array{kategorie: string, nr: int, kontext: array<string, mixed>}>
 */
function zeilen(array $debug, string $text): array
{
    $ergebnis = [];
    foreach ($debug as $nr => $zeile) {
        if (!str_starts_with($zeile['Data'], $text . ' | ')) {
            continue;
        }
        $kontext = json_decode(substr($zeile['Data'], strlen($text . ' | ')), true);
        $ergebnis[] = ['kategorie' => $zeile['Message'], 'nr' => $nr, 'kontext' => is_array($kontext) ? $kontext : []];
    }
    return $ergebnis;
}

/** Der in „Wert gesetzt“ gemeldete Wert zu einem Ident; „fehlt“, wenn es keine solche Zeile gibt. */
function gesetzterWert(array $debug, string $ident): mixed
{
    foreach (zeilen($debug, 'Wert gesetzt') as $zeile) {
        if (($zeile['kontext']['Ident'] ?? null) === $ident) {
            return $zeile['kontext']['Value'] ?? null;
        }
    }
    return 'fehlt';
}

$kurz = static fn(array $debug): string => json_encode(array_map(static fn(array $z): string => $z['Message'] . ': ' . mb_strimwidth($z['Data'], 0, 90, '…'), $debug),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

$bild = bildInstanz(true);
$vars = variablenNachIdent($bild->id());
$dimmerIdent = array_find(array_keys($vars), static fn(string $i): bool => str_starts_with($i, 'light_') && !str_ends_with($i, 'brightness')) ?? '';
$helligkeitIdent = array_find(array_keys($vars), static fn(string $i): bool => str_starts_with($i, $dimmerIdent) && str_ends_with($i, 'brightness')) ?? '';
pruefe($dimmerIdent !== '' && $helligkeitIdent !== '', 'Dimmer und Helligkeit angelegt', json_encode(array_keys($vars)));

// --- Teil 1: Meldung mit Wert 50. Das Debug zeigt die Meldung und beide geschriebenen Werte.
$meldung = dimmerMeldung($zwave, 50);
$debug = meldeMitDebug($bild, DIMMER . 'currentValue', $meldung);
$eingang = zeilen($debug, 'Eingangsdaten');
pruefe(count($eingang) === 1, 'Wert 50: eine Zeile „Eingangsdaten“', $kurz($debug));
pruefe(($eingang[0]['kategorie'] ?? null) === 'ReceiveData', 'Eingangsdaten stehen unter „ReceiveData“ wie beim klassischen Gerät',
    var_export($eingang[0]['kategorie'] ?? null, true));
pruefe(($eingang[0]['kontext']['Topic'] ?? null) === DIMMER . 'currentValue', 'Eingangsdaten nennen das Topic',
    var_export($eingang[0]['kontext']['Topic'] ?? null, true));
pruefe(($eingang[0]['kontext']['Payload'] ?? null) === $meldung, 'Eingangsdaten enthalten den Payload unverändert',
    var_export($eingang[0]['kontext']['Payload'] ?? null, true));
pruefe(gesetzterWert($debug, $dimmerIdent) === true, 'Wert 50: „Wert gesetzt“ für den Status mit true', var_export(gesetzterWert($debug, $dimmerIdent), true));
pruefe(gesetzterWert($debug, $helligkeitIdent) === 129, 'Wert 50: „Wert gesetzt“ für die Helligkeit mit 129',
    var_export(gesetzterWert($debug, $helligkeitIdent), true));
$gesetzt = zeilen($debug, 'Wert gesetzt');
pruefe($eingang !== [] && $gesetzt !== [] && $eingang[0]['nr'] < $gesetzt[0]['nr'], 'Eingangsdaten stehen vor den gesetzten Werten', $kurz($debug));

// --- Teil 2: Meldung mit Wert 0, der Fall des Anlasses. Der Status geht auf aus, für die Helligkeit gibt es keine
// Zeile: Sie übergeht die 0 wie HA, und das soll man im Debug sehen.
$debug = meldeMitDebug($bild, DIMMER . 'currentValue', dimmerMeldung($zwave, 0));
$eingang = zeilen($debug, 'Eingangsdaten');
$payload = json_decode((string)($eingang[0]['kontext']['Payload'] ?? ''), true);
pruefe(is_array($payload) && ($payload['value'] ?? null) === 0, 'Wert 0: steht in den Eingangsdaten', $kurz($debug));
pruefe(gesetzterWert($debug, $dimmerIdent) === false, 'Wert 0: „Wert gesetzt“ für den Status mit false', var_export(gesetzterWert($debug, $dimmerIdent), true));
pruefe(gesetzterWert($debug, $helligkeitIdent) === 'fehlt', 'Wert 0: keine Zeile für die Helligkeit', var_export(gesetzterWert($debug, $helligkeitIdent), true));
pruefe(GetValue($vars[$helligkeitIdent]) === 129, 'Wert 0: Helligkeit bleibt 129', var_export(GetValue($vars[$helligkeitIdent]), true));

// --- Teil 3 (angenommen, kein Mitschnitt): Ein Payload, der kein UTF-8 ist (Bilddaten eines image-Topics), steht
// nicht im Debug, nur seine Länge. Sonst stünden dort Ersatzzeichen.
$debug = meldeMitDebug($bild, DIMMER . 'currentValue', "\xFF\xD8\xFF\xE0");
$eingang = zeilen($debug, 'Eingangsdaten');
pruefe(($eingang[0]['kontext']['Payload'] ?? null) === '(binär, 4 Bytes)', 'Binärer Payload: nur die Länge im Debug', $kurz($debug));
pruefe(zeilen($debug, 'Wert gesetzt') === [], 'Binärer Payload: kein Wert gesetzt', $kurz($debug));

// --- Teil 4: Mit „Status emulieren“ zeigt das Debug auch die Werte, die das Gerät beim Schalten selbst setzt. Sonst
// sähe ein Wechsel im Debug aus wie eine Meldung des Senders.
$emuliert = bildInstanz(true, true);
$vorher = count(IPS\DebugServer::getDebugMessages($emuliert->id()));
neueAusfuehrung($emuliert)->RequestAction($helligkeitIdent, 128);
$debug = array_slice(IPS\DebugServer::getDebugMessages($emuliert->id()), $vorher);
pruefe(zeilen($debug, 'Eingangsdaten') === [], 'Schalten: keine Eingangsdaten', $kurz($debug));
pruefe(gesetzterWert($debug, $helligkeitIdent) === 128, 'Schalten mit „Status emulieren“: „Wert gesetzt“ für die Helligkeit mit 128',
    var_export(gesetzterWert($debug, $helligkeitIdent), true));
pruefe(gesetzterWert($debug, $dimmerIdent) === true, 'Schalten mit „Status emulieren“: „Wert gesetzt“ für den Status mit true',
    var_export(gesetzterWert($debug, $dimmerIdent), true));

// --- Teil 5: Ohne erweitertes Debug bleibt der heiße Pfad still, die Werte kommen trotzdem an.
$still = bildInstanz(false);
$stillVars = variablenNachIdent($still->id());
$debug = meldeMitDebug($still, DIMMER . 'currentValue', dimmerMeldung($zwave, 50));
pruefe($debug === [], 'Ohne erweitertes Debug: keine Debug-Zeile je Meldung', $kurz($debug));
pruefe(GetValue($stillVars[$dimmerIdent]) === true && GetValue($stillVars[$helligkeitIdent]) === 129, 'Ohne erweitertes Debug: Werte übernommen',
    var_export([GetValue($stillVars[$dimmerIdent]), GetValue($stillVars[$helligkeitIdent])], true));

// --- Teil 6: „Eingangsdaten“ kommt für das klassische Gerät, die Entity und das Discovery-Gerät aus demselben Code
// (ModuleDebugTrait::debugIncomingMessage). Am klassischen Gerät mit einer echten statestream-Meldung (Mitschnitt
// vom nuc, 19.10.2026), danach (angenommen, kein Mitschnitt) mit einem Payload, der kein UTF-8 ist: Bis build 262
// warf das Debug dort eine JsonException mitten in ReceiveData, erst bei „Eingangsdaten“, dann bei „Non-JSON payload
// stored as string“ (HAAttributeHandlers). Deshalb ersetzt debugExpert ungültiges UTF-8 jetzt selbst.
// Das Attribut ist „brightness“, weil dessen Wert nicht in den Zustands-Cache geht: Der wirft bei ungültigem UTF-8
// unabhängig vom Debug (offen, kein Anlass).
$bundleDatei = tempnam(sys_get_temp_dir(), 'ha_debug_check_');
if ($bundleDatei === false) {
    throw new RuntimeException('Temp-Datei für das Bundle nicht angelegt');
}
copy(__DIR__ . '/fixtures/hue_aurelle_config_20261019.json', $bundleDatei);
$konfig = json_decode((string)file_get_contents($bundleDatei), true, 512, JSON_THROW_ON_ERROR)[0];
$klassisch = neuesGeraet([
    'SourceMode'        => 'bundle',
    'BundlePath'        => $bundleDatei,
    'DeviceName'        => $konfig['device_name'],
    'DeviceID'          => $konfig['device_id'],
    'EnableExpertDebug' => true,
]);
$ersteMeldung = null;
foreach (file(__DIR__ . '/fixtures/hue_aurelle_statestream_20261019.txt', FILE_IGNORE_NEW_LINES) as $zeile) {
    if ($zeile !== '' && $zeile[0] !== '#' && preg_match('/^\S+ (\S+)(?: \[retain])? = (.*)$/', $zeile, $treffer) === 1) {
        $ersteMeldung = ['topic' => 'statestream/light/hue_aurelle/' . $treffer[1], 'payload' => $treffer[2]];
        break;
    }
}
pruefe($ersteMeldung !== null, 'Klassisch: Meldung aus dem Mitschnitt gelesen');

/** @return array{0: list<array{Message: string, Data: string, Format: int}>, 1: ?string} Debug-Zeilen und Text einer Ausnahme */
function meldeKlassisch(DeviceHarness $g, string $topic, string $payload): array
{
    $vorher = count(IPS\DebugServer::getDebugMessages($g->id()));
    $ausnahme = null;
    try {
        neueAusfuehrung($g)->ReceiveData(mqttMeldung($topic, $payload));
    } catch (Throwable $e) {
        $ausnahme = get_class($e) . ': ' . $e->getMessage();
    }
    return [array_slice(IPS\DebugServer::getDebugMessages($g->id()), $vorher), $ausnahme];
}

[$debug, $ausnahme] = meldeKlassisch($klassisch, (string)$ersteMeldung['topic'], (string)$ersteMeldung['payload']);
$eingang = zeilen($debug, 'Eingangsdaten');
pruefe($ausnahme === null && count($eingang) === 1 && $eingang[0]['kategorie'] === 'ReceiveData', 'Klassisch: eine Zeile „Eingangsdaten“ unter „ReceiveData“',
    $ausnahme ?? $kurz($debug));
pruefe(($eingang[0]['kontext'] ?? null) === ['Topic' => $ersteMeldung['topic'], 'Payload' => $ersteMeldung['payload']],
    'Klassisch: Topic und Payload unverändert', json_encode($eingang[0]['kontext'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

[$debug, $ausnahme] = meldeKlassisch($klassisch, 'statestream/light/hue_aurelle/brightness', "\xFF\xD8\xFF\xE0");
$eingang = zeilen($debug, 'Eingangsdaten');
pruefe($ausnahme === null, 'Klassisch: binärer Payload wirft im Debug keine Ausnahme', (string)$ausnahme);
pruefe(($eingang[0]['kontext']['Payload'] ?? null) === '(binär, 4 Bytes)', 'Klassisch: binärer Payload nur mit der Länge im Debug', $ausnahme ?? $kurz($debug));
@unlink($bundleDatei);

// --- Teil 7 (angenommen, kein Mitschnitt): Scheitert das Schreiben einer Variable (SetValue liefert false, am Kernel
// nach einer Warnung), steht im Debug kein „Wert gesetzt“ für sie. Die Zeile entsteht deshalb erst nach dem Schreiben
// und heißt dann „Wert nicht gesetzt“ (Code-Review 28.11.2026, Befund 1).
$gestoert = bildInstanz(true);
$gestoertVars = variablenNachIdent($gestoert->id());
DiscoveryDeviceHarness::$schreibfehler = [$helligkeitIdent];
$debug = meldeMitDebug($gestoert, DIMMER . 'currentValue', dimmerMeldung($zwave, 50));
DiscoveryDeviceHarness::$schreibfehler = [];
$nichtGesetzt = array_column(array_column(zeilen($debug, 'Wert nicht gesetzt'), 'kontext'), 'Value', 'Ident');
pruefe(GetValue($gestoertVars[$helligkeitIdent]) !== 129, 'Schreibfehler: Helligkeit ist nicht geschrieben', var_export(GetValue($gestoertVars[$helligkeitIdent]), true));
pruefe(gesetzterWert($debug, $helligkeitIdent) === 'fehlt', 'Schreibfehler: kein „Wert gesetzt“ für die Helligkeit', $kurz($debug));
pruefe(($nichtGesetzt[$helligkeitIdent] ?? null) === 129, 'Schreibfehler: „Wert nicht gesetzt“ für die Helligkeit mit 129', $kurz($debug));
pruefe(gesetzterWert($debug, $dimmerIdent) === true, 'Schreibfehler: „Wert gesetzt“ für den Status bleibt', $kurz($debug));

// --- Teil 8: Ein nicht endlicher Zahlenwert. Aus 1e999 macht PHP INF (json_decode, ebenso der Cast eines Textes),
// json_encode wirft bei INF, und in build 263 brach die Debug-Zeile zum geschriebenen Wert damit die Meldung ab
// (Nachreview 28.11.2026, Befund 1). Die Meldung ist die echte Meldung des Leistungssensors aus dem Bundle mit
// anderem Wert; dass ein Gerät 1e999 sendet, ist angenommen, kein Mitschnitt. Wie der Kernel INF behandelt, ist an
// den Anlagen gemessen und im Harness nachgebildet (DiscoveryDeviceHarness::$nichtEndlich), der Stub wirft dort.
const LEISTUNG = 'Z-Wave-JS/Raum/Bild/meter/endpoint_0/value/66049';

/** Die echte Meldung des Leistungssensors aus dem Bundle, der Wert als JSON-Zahl im Wortlaut (etwa „1e999“). */
function leistungsMeldung(array $zwave, string $zahl): string
{
    $roh = array_find($zwave['topic_payloads'], static fn(array $p): bool => $p['topic'] === LEISTUNG)['payload'];
    $json = json_decode($roh, true, 512, JSON_THROW_ON_ERROR);
    $json['value'] = '__WERT__';
    return str_replace('"__WERT__"', $zahl, json_encode($json, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}

/** @return array{0: list<array{Message: string, Data: string, Format: int}>, 1: ?string} Debug-Zeilen und Text einer Ausnahme */
function meldeOhneAbbruch(DiscoveryDeviceHarness $g, string $topic, string $payload): array
{
    $vorher = count(IPS\DebugServer::getDebugMessages($g->id()));
    $ausnahme = null;
    try {
        neueAusfuehrung($g)->ReceiveData(json_encode(['Topic' => $topic, 'Payload' => bin2hex($payload)], JSON_THROW_ON_ERROR));
    } catch (Throwable $e) {
        $ausnahme = get_class($e) . ': ' . $e->getMessage();
    }
    return [array_slice(IPS\DebugServer::getDebugMessages($g->id()), $vorher), $ausnahme];
}

/** Der Wert zu einem Ident aus den Zeilen mit dem Text $text; „fehlt“, wenn es keine solche Zeile gibt. */
function wertInZeile(array $debug, string $text, string $ident): mixed
{
    foreach (zeilen($debug, $text) as $zeile) {
        if (($zeile['kontext']['Ident'] ?? null) === $ident) {
            return $zeile['kontext']['Value'] ?? null;
        }
    }
    return 'fehlt';
}

// Im Bundle steht der Sensor auf 0 und würde Integer. Mit 12.5 im Zwischenspeicher des Splitters wird er Float.
$mitKommawert = $zwave['topic_payloads'];
foreach ($mitKommawert as $nr => $eintrag) {
    if ($eintrag['topic'] === LEISTUNG) {
        $mitKommawert[$nr]['payload'] = leistungsMeldung($zwave, '12.5');
    }
}
DiscoveryDeviceHarness::$topicPayloadAntwort = ['Items' => $mitKommawert];
$messer = bildInstanz(true);
DiscoveryDeviceHarness::$topicPayloadAntwort = ['Items' => $zwave['topic_payloads']];
$messerVars = variablenNachIdent($messer->id());
$leistungIdent = array_find(array_keys($messerVars), static fn(string $i): bool => str_contains($i, 'electric_w')) ?? '';
pruefe($leistungIdent !== '' && IPS_GetVariable($messerVars[$leistungIdent])['VariableType'] === VARIABLETYPE_FLOAT, 'Leistungssensor als Float angelegt',
    json_encode(array_keys($messerVars)));

// Rust (Testbox 9.1, 28.11.2026): SetValue liefert true ohne Warnung, gespeichert wird 0.0.
DiscoveryDeviceHarness::$nichtEndlich = 'rust';
[$debug, $ausnahme] = meldeOhneAbbruch($messer, LEISTUNG, leistungsMeldung($zwave, '1e999'));
pruefe($ausnahme === null, 'INF, Kernel schreibt (Rust): die Meldung bricht nicht ab', (string)$ausnahme);
pruefe(wertInZeile($debug, 'Wert gesetzt', $leistungIdent) === 'INF', 'INF, Kernel schreibt (Rust): „Wert gesetzt“ nennt INF', $kurz($debug));
[$debug, $ausnahme] = meldeOhneAbbruch($messer, LEISTUNG, leistungsMeldung($zwave, '-1e999'));
pruefe($ausnahme === null, '-INF, Kernel schreibt (Rust): die Meldung bricht nicht ab', (string)$ausnahme);
pruefe(wertInZeile($debug, 'Wert gesetzt', $leistungIdent) === '-INF', '-INF, Kernel schreibt (Rust): „Wert gesetzt“ nennt -INF', $kurz($debug));

// C++ (Neustadt 9.0, 28.11.2026): SetValue warnt („NaN/INF Werte werden nicht unterstützt“) und liefert false.
DiscoveryDeviceHarness::$nichtEndlich = null;
meldeOhneAbbruch($messer, LEISTUNG, leistungsMeldung($zwave, '7.5'));
DiscoveryDeviceHarness::$nichtEndlich = 'cpp';
[$debug, $ausnahme] = meldeOhneAbbruch($messer, LEISTUNG, leistungsMeldung($zwave, '1e999'));
DiscoveryDeviceHarness::$nichtEndlich = null;
pruefe($ausnahme === null, 'INF, Kernel lehnt ab (C++): die Meldung bricht nicht ab', (string)$ausnahme);
pruefe(wertInZeile($debug, 'Wert nicht gesetzt', $leistungIdent) === 'INF', 'INF, Kernel lehnt ab (C++): „Wert nicht gesetzt“ nennt INF', $kurz($debug));
pruefe(GetValue($messerVars[$leistungIdent]) === 7.5, 'INF, Kernel lehnt ab (C++): der alte Wert 7.5 bleibt', var_export(GetValue($messerVars[$leistungIdent]), true));

// Eine endliche Zahl steht weiter als Zahl im Debug, nicht als Text.
[$debug] = meldeOhneAbbruch($messer, LEISTUNG, leistungsMeldung($zwave, '8.25'));
pruefe(wertInZeile($debug, 'Wert gesetzt', $leistungIdent) === 8.25, 'Endliche Zahl: „Wert gesetzt“ nennt sie als Zahl',
    var_export(wertInZeile($debug, 'Wert gesetzt', $leistungIdent), true));

ergebnis();
