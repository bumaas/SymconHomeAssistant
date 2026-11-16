<?php

declare(strict_types=1);

/**
 * Prüft am ECHTEN Device- und Entity-Modul über den Kernel-Stub (samt echtem Splitter als Parent), dass
 * eine leere Antwort aus der Startphase von Home Assistant kein „nicht gefunden" ist.
 *
 * Anlass: nuc 20.10.2026. Die Update-Runde startete HA um 03:00:43 neu (Mosquitto mit), um 03:01:38 war
 * der Splitter wieder verbunden, HA meldete sich erst um 03:03:00 als gestartet. Die Devices fragten
 * dazwischen ihre Entitäten ab, bekamen eine leere Liste, setzten 211 „Gerät nicht in Home Assistant
 * gefunden" und verwarfen ihre Konfiguration samt Empfangsfilter. 30 von 32 Devices übernahmen danach
 * sieben Stunden lang keinen Wert, bis ein ApplyChanges am Splitter sie neu abfragen ließ.
 *
 * Fixture tests/fixtures/ha_startphase_20261020.json: Mitschnitt eines HA-Neustarts am eigenen HA
 * (2026.10.2). Rund 14 s lang beantwortet HA die Gerätevorlage mit HTTP 200 und einer leeren Liste und
 * meldet in /api/config dabei `NOT_RUNNING`; zwischendurch antwortet /api/config gar nicht, die Vorlage
 * schon. Dazu die Vorlagenantwort für dasselbe Gerät im laufenden Betrieb (Gerätename neutralisiert).
 * Die Entity-Vorlage läuft über dieselbe Zustandsliste (`for state in states`), mitgeschnitten ist
 * nur die Gerätevorlage.
 *
 * Seit build 232 zeigt das Kind in dieser Wartezeit 203 „Home Assistant startet noch oder antwortet nicht“.
 * Anlass: nuc 16.11.2026. Nach einem Ausfall des HA-Rechners stand der Splitter um 09:21:22 wieder auf
 * 102, HA Core startete noch. Die Devices bekamen die leere Liste, planten die Nachprüfung, ließen aber
 * das 201 „Kein kompatibler oder aktiver Parent“ vom Ausfall stehen; das sah nach einem Hänger aus, und
 * jedes ApplyChanges von Hand startete die Nachprüfung neu. Die Entities kamen sofort auf 102, sie fanden
 * ihre Helfer schon in der Startphase. Dazu: Scheiterte die Abfrage ganz (HTTP 0 wie im ersten Schritt des
 * Mitschnitts), gab es gar keine Nachprüfung, das Kind wäre auf dem alten Status stehen geblieben.
 */

require_once __DIR__ . '/device-harness.php';

error_reporting(E_ALL);

const STATUS_GERAET_NICHT_GEFUNDEN = 211;
const STATUS_ENTITAET_NICHT_GEFUNDEN = 210;
const STATUS_PARENT_FEHLT = 201;
const STATUS_HA_NICHT_BEREIT = 203;
const SCHNELLE_VERSUCHE = 10;
const NACHPRUEFUNG = 'DeferredApplyTimer';

$fixture = json_decode((string)file_get_contents(__DIR__ . '/fixtures/ha_startphase_20261020.json'), true, 512, JSON_THROW_ON_ERROR);
$deviceId = $fixture['device_id'];
$zeilen = $fixture['vorlage_laufend'];

/** Phasen aus dem Mitschnitt: Zustand in /api/config (null = keine Antwort) und ob die Vorlage leer ist. */
$phasen = [];
foreach ($fixture['verlauf'] as $schritt) {
    if ($schritt['vorlage_http'] !== 200) {
        continue;
    }
    $phasen[] = ['state' => $schritt['config_http'] === 200 ? $schritt['config_state'] : null, 'leer' => $schritt['entitaeten'] === 0];
}
$startLeer      = $phasen[0];                                   // NOT_RUNNING, Vorlage leer
$laufend        = $phasen[array_key_last($phasen)];             // RUNNING, Vorlage voll
pruefe($startLeer === ['state' => 'NOT_RUNNING', 'leer' => true], 'Fixture: erste Antwort nach dem Neustart ist NOT_RUNNING mit leerer Vorlage', json_encode($startLeer));
pruefe($laufend === ['state' => 'RUNNING', 'leer' => false], 'Fixture: zuletzt RUNNING mit voller Vorlage', json_encode($laufend));
$configStumm = array_values(array_filter($phasen, static fn(array $p): bool => $p['state'] === null))[0] ?? null;
pruefe($configStumm !== null, 'Fixture: /api/config antwortete zwischendurch nicht');
$ganzStumm = array_values(array_filter($fixture['verlauf'], static fn(array $s): bool => $s['vorlage_http'] === 0))[0] ?? null;
pruefe($ganzStumm !== null && $ganzStumm['config_http'] === 0, 'Fixture: direkt nach dem Neustart antworten weder Vorlage noch /api/config');

/**
 * Stellt HA im gegebenen Zustand nach. $leer = Vorlage liefert eine leere Liste (Startphase oder Gerät
 * gelöscht); $state null = /api/config antwortet nicht (Abbruch wie im Mitschnitt, HTTP 0).
 */
$stelleHa = static function (?string $state, bool $leer, bool $vorlageStumm = false) use ($zeilen): void {
    SplitterHarness::$haAntwort = static function (string $url, mixed $daten) use ($state, $leer, $vorlageStumm, $zeilen): ?array {
        if ($vorlageStumm && str_ends_with($url, '/api/template')) {
            return ['response' => false, 'httpCode' => 0, 'error' => 'Operation timed out'];
        }
        if (str_ends_with($url, '/api/config')) {
            return $state === null
                ? ['response' => false, 'httpCode' => 0, 'error' => 'Operation timed out']
                : ['response' => json_encode(['state' => $state, 'version' => '2026.10.2'])];
        }
        if (str_ends_with($url, '/api/template')) {
            $vorlage = (string)($daten['template'] ?? '');
            $treffer = $leer ? [] : array_values(array_filter(
                $zeilen,
                static fn(array $z): bool => str_contains($vorlage, '"' . $z['entity_id'] . '"') || str_contains($vorlage, '"' . $z['device_id'] . '"')
            ));
            return ['response' => json_encode($treffer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
        }
        return null;
    };
};

$splitter = neueFensterInstanz(SplitterHarness::class);
$splitter->SetProperty('HAUrl', 'http://127.0.0.1:9');
$splitter->SetProperty('HAToken', 'test');
$splitter->SetProperty('MQTTBaseTopic', 'homeassistant');
$splitter->ApplyChanges();
$splitter->rufe('SetStatus', IS_ACTIVE);

/** Gleiche Anzahl Entitäten wie im laufenden Betrieb in der gespeicherten Konfiguration? */
$konfigurierteEntitaeten = static fn(DeviceHarness|EntityHarness $m): int => count(json_decode($m->attribut('ResolvedConfig'), true) ?: []);

/** Die Nachprüfung feuert: so, wie der Timer es tut (frische PHP-Ausführung). */
$nachpruefen = static function (DeviceHarness|EntityHarness $m): DeviceHarness|EntityHarness {
    $neu = neueAusfuehrung($m);
    $neu->RequestAction('DeferredApply', '');
    return $neu;
};

// ---- Teil 1: Device ----
$stelleHa('RUNNING', false);
$g = neuesGeraet(['DeviceID' => $deviceId], []);
IPS_ConnectInstance($g->id(), $splitter->id());
$g = neueAusfuehrung($g);
$g->ApplyChanges();
$anzahl = count($zeilen);
pruefe($g->status() === IS_ACTIVE, 'Device: im laufenden Betrieb aktiv', 'Status ' . $g->status());
pruefe($konfigurierteEntitaeten($g) === $anzahl, 'Device: ' . $anzahl . ' Entitäten konfiguriert', (string)$konfigurierteEntitaeten($g));
pruefe($g->timer(NACHPRUEFUNG) === 0, 'Device: im laufenden Betrieb keine Nachprüfung geplant', (string)$g->timer(NACHPRUEFUNG));

// HA startet neu, der Splitter ist wieder da, das Device fragt in der Startphase ab.
$stelleHa('NOT_RUNNING', true);
$g = neueAusfuehrung($g);
$g->ApplyChanges();
pruefe($g->status() !== STATUS_GERAET_NICHT_GEFUNDEN, 'Device: leere Antwort in der HA-Startphase ist kein „nicht gefunden"', 'Status ' . $g->status());
pruefe($g->status() === STATUS_HA_NICHT_BEREIT, 'Device: zeigt in der HA-Startphase 203', 'Status ' . $g->status());
pruefe($konfigurierteEntitaeten($g) === $anzahl, 'Device: Konfiguration bleibt in der HA-Startphase erhalten', (string)$konfigurierteEntitaeten($g));
$warten = $g->timer(NACHPRUEFUNG);
pruefe($warten > 0 && $warten <= 60000, 'Device: Nachprüfung in der HA-Startphase binnen einer Minute geplant', (string)$warten);

// /api/config antwortet nicht, die Vorlage ist leer: Befund unbekannt, nicht „nicht gefunden".
$stelleHa(null, true);
$g = $nachpruefen($g);
pruefe($g->status() === STATUS_HA_NICHT_BEREIT, 'Device: ohne Antwort von /api/config 203, kein „nicht gefunden"', 'Status ' . $g->status());
pruefe($konfigurierteEntitaeten($g) === $anzahl, 'Device: Konfiguration bleibt ohne Antwort von /api/config erhalten', (string)$konfigurierteEntitaeten($g));
pruefe($g->timer(NACHPRUEFUNG) > 0, 'Device: ohne Antwort von /api/config wieder Nachprüfung geplant', (string)$g->timer(NACHPRUEFUNG));

// HA ist oben: Die Nachprüfung findet das Gerät und plant nichts mehr.
$stelleHa('RUNNING', false);
$g = $nachpruefen($g);
pruefe($g->status() === IS_ACTIVE, 'Device: nach dem HA-Start aktiv', 'Status ' . $g->status());
pruefe($g->timer(NACHPRUEFUNG) === 0, 'Device: nach dem HA-Start keine weitere Nachprüfung', (string)$g->timer(NACHPRUEFUNG));

// Gerät in HA gelöscht (HA läuft, Vorlage leer): weiterhin 211, aber mit Nachprüfung.
$stelleHa('RUNNING', true);
$g = neueAusfuehrung($g);
$g->ApplyChanges();
pruefe($g->status() === STATUS_GERAET_NICHT_GEFUNDEN, 'Device: bei laufendem HA und leerer Antwort 211', 'Status ' . $g->status());
$warten = $g->timer(NACHPRUEFUNG);
pruefe($warten > 60000, 'Device: bei 211 Nachprüfung in größerem Abstand geplant', (string)$warten);

// Das Gerät kommt zurück (z. B. Integration nach dem Start fertig): die Nachprüfung findet es.
$stelleHa('RUNNING', false);
$g = $nachpruefen($g);
pruefe($g->status() === IS_ACTIVE, 'Device: zurückgekehrtes Gerät wird bei der Nachprüfung wieder aktiv', 'Status ' . $g->status());
pruefe($konfigurierteEntitaeten($g) === $anzahl, 'Device: zurückgekehrtes Gerät wieder mit allen Entitäten', (string)$konfigurierteEntitaeten($g));
pruefe($g->timer(NACHPRUEFUNG) === 0, 'Device: zurückgekehrtes Gerät ohne weitere Nachprüfung', (string)$g->timer(NACHPRUEFUNG));

// ---- Teil 2: Entity (gleiche Zustandsliste, Status 210) ----
$entityId = 'event.schlafzimmer_schlafen_taster_links_taste_1';
$stelleHa('RUNNING', false);
$e = neueEntitaet(['EntityID' => $entityId], []);
IPS_ConnectInstance($e->id(), $splitter->id());
$e = neueAusfuehrung($e);
$e->ApplyChanges();
pruefe($e->status() === IS_ACTIVE, 'Entity: im laufenden Betrieb aktiv', 'Status ' . $e->status());
pruefe($konfigurierteEntitaeten($e) === 1, 'Entity: konfiguriert', (string)$konfigurierteEntitaeten($e));

$stelleHa('NOT_RUNNING', true);
$e = neueAusfuehrung($e);
$e->ApplyChanges();
pruefe($e->status() === STATUS_HA_NICHT_BEREIT, 'Entity: leere Antwort in der HA-Startphase ist kein „nicht gefunden", sondern 203', 'Status ' . $e->status());
pruefe($konfigurierteEntitaeten($e) === 1, 'Entity: Konfiguration bleibt in der HA-Startphase erhalten', (string)$konfigurierteEntitaeten($e));
$warten = $e->timer(NACHPRUEFUNG);
pruefe($warten > 0 && $warten <= 60000, 'Entity: Nachprüfung in der HA-Startphase binnen einer Minute geplant', (string)$warten);

$stelleHa('RUNNING', false);
$e = $nachpruefen($e);
pruefe($e->status() === IS_ACTIVE && $e->timer(NACHPRUEFUNG) === 0, 'Entity: nach dem HA-Start aktiv, keine weitere Nachprüfung', 'Status ' . $e->status() . ', Timer ' . $e->timer(NACHPRUEFUNG));

$stelleHa('RUNNING', true);
$e = neueAusfuehrung($e);
$e->ApplyChanges();
pruefe($e->status() === STATUS_ENTITAET_NICHT_GEFUNDEN, 'Entity: bei laufendem HA und leerer Antwort 210', 'Status ' . $e->status());
pruefe($e->timer(NACHPRUEFUNG) > 60000, 'Entity: bei 210 Nachprüfung in größerem Abstand geplant', (string)$e->timer(NACHPRUEFUNG));

$stelleHa('RUNNING', false);
$e = $nachpruefen($e);
pruefe($e->status() === IS_ACTIVE && $e->timer(NACHPRUEFUNG) === 0, 'Entity: zurückgekehrte Entität wird bei der Nachprüfung wieder aktiv', 'Status ' . $e->status() . ', Timer ' . $e->timer(NACHPRUEFUNG));

// ---- Teil 3: Statusfolge vom 16.11.2026, das Kind kommt von 201 ----
$splitterStatus = static function (int $status) use ($splitter): void {
    $splitter->rufe('SetStatus', $status);
};
$neueKinder = [
    'Device' => static fn(): DeviceHarness => neuesGeraet(['DeviceID' => $deviceId], []),
    'Entity' => static fn(): EntityHarness => neueEntitaet(['EntityID' => $entityId], []),
];

foreach ($neueKinder as $art => $neu) {
    $stelleHa('RUNNING', false);
    $splitterStatus(IS_ACTIVE);
    $k = $neu();
    IPS_ConnectInstance($k->id(), $splitter->id());
    $k = neueAusfuehrung($k);
    $k->ApplyChanges();
    pruefe($k->status() === IS_ACTIVE, $art . ' 16.11.: vor dem Ausfall aktiv', 'Status ' . $k->status());

    // HA-Rechner fällt aus: Der Splitter verliert seinen Parent, das Kind geht auf 201.
    $splitterStatus(STATUS_PARENT_FEHLT);
    $k = $nachpruefen($k);
    pruefe($k->status() === STATUS_PARENT_FEHLT, $art . ' 16.11.: während des Ausfalls 201', 'Status ' . $k->status());

    // Splitter wieder 102, HA Core startet noch.
    $splitterStatus(IS_ACTIVE);
    $stelleHa('NOT_RUNNING', true);
    $k = $nachpruefen($k);
    pruefe($k->status() === STATUS_HA_NICHT_BEREIT, $art . ' 16.11.: Splitter 102, HA startet: 203 statt des alten 201', 'Status ' . $k->status());
    $warten = $k->timer(NACHPRUEFUNG);
    pruefe($warten > 0 && $warten <= 60000, $art . ' 16.11.: Nachprüfung binnen einer Minute', (string)$warten);

    // Vorlage und /api/config antworten gar nicht (erster Schritt des Mitschnitts): Abfrage gescheitert.
    $stelleHa(null, true, true);
    $k = $nachpruefen($k);
    pruefe($k->status() === STATUS_HA_NICHT_BEREIT, $art . ' 16.11.: gescheiterte Abfrage zeigt 203', 'Status ' . $k->status());
    $warten = $k->timer(NACHPRUEFUNG);
    pruefe($warten > 0 && $warten <= 60000, $art . ' 16.11.: gescheiterte Abfrage plant Nachprüfung binnen einer Minute', (string)$warten);
    pruefe($konfigurierteEntitaeten($k) > 0, $art . ' 16.11.: gescheiterte Abfrage verwirft die Konfiguration nicht', (string)$konfigurierteEntitaeten($k));

    // Bleibt HA stumm, wird der Abstand nach einigen schnellen Versuchen größer.
    for ($i = 1; $i < SCHNELLE_VERSUCHE; $i++) {
        $k = $nachpruefen($k);
    }
    pruefe($k->timer(NACHPRUEFUNG) > 0 && $k->timer(NACHPRUEFUNG) <= 60000, $art . ' 16.11.: ' . SCHNELLE_VERSUCHE . ' schnelle Versuche', (string)$k->timer(NACHPRUEFUNG));
    $k = $nachpruefen($k);
    pruefe($k->timer(NACHPRUEFUNG) > 60000, $art . ' 16.11.: danach Nachprüfung in größerem Abstand', (string)$k->timer(NACHPRUEFUNG));
    pruefe($k->status() === STATUS_HA_NICHT_BEREIT, $art . ' 16.11.: weiter 203, solange HA stumm ist', 'Status ' . $k->status());

    // HA ist oben: aktiv, keine Nachprüfung mehr.
    $stelleHa('RUNNING', false);
    $k = $nachpruefen($k);
    pruefe($k->status() === IS_ACTIVE && $k->timer(NACHPRUEFUNG) === 0, $art . ' 16.11.: nach dem HA-Start aktiv, keine weitere Nachprüfung', 'Status ' . $k->status() . ', Timer ' . $k->timer(NACHPRUEFUNG));

    // Ein späterer Fehlschlag beginnt wieder mit schnellen Versuchen.
    $stelleHa(null, true, true);
    $k = neueAusfuehrung($k);
    $k->ApplyChanges();
    $warten = $k->timer(NACHPRUEFUNG);
    pruefe($k->status() === STATUS_HA_NICHT_BEREIT && $warten > 0 && $warten <= 60000, $art . ' 16.11.: neuer Fehlschlag nach Erfolg wieder schnell nachgeprüft', 'Status ' . $k->status() . ', Timer ' . $warten);
}

// Formular und Übersetzung kennen 203.
foreach (['Home Assistant Device', 'Home Assistant Entity'] as $modul) {
    $form = json_decode((string)file_get_contents(__DIR__ . '/../' . $modul . '/form.json'), true, 512, JSON_THROW_ON_ERROR);
    $eintrag = array_values(array_filter($form['status'] ?? [], static fn(array $s): bool => ($s['code'] ?? 0) === STATUS_HA_NICHT_BEREIT))[0] ?? null;
    pruefe($eintrag !== null, $modul . ': form.json kennt Status 203');
    $locale = json_decode((string)file_get_contents(__DIR__ . '/../' . $modul . '/locale.json'), true, 512, JSON_THROW_ON_ERROR);
    pruefe($eintrag !== null && isset($locale['translations']['de'][$eintrag['caption']]), $modul . ': Status 203 übersetzt');
}

SplitterHarness::$haAntwort = null;
ergebnis();
