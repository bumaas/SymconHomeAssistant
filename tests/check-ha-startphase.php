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
 */

require_once __DIR__ . '/device-harness.php';

error_reporting(E_ALL);

const STATUS_GERAET_NICHT_GEFUNDEN = 211;
const STATUS_ENTITAET_NICHT_GEFUNDEN = 210;
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

/**
 * Stellt HA im gegebenen Zustand nach. $leer = Vorlage liefert eine leere Liste (Startphase oder Gerät
 * gelöscht); $state null = /api/config antwortet nicht (Abbruch wie im Mitschnitt, HTTP 0).
 */
$stelleHa = static function (?string $state, bool $leer) use ($zeilen): void {
    SplitterHarness::$haAntwort = static function (string $url, mixed $daten) use ($state, $leer, $zeilen): ?array {
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
pruefe($g->status() === IS_ACTIVE, 'Device: bleibt in der HA-Startphase aktiv', 'Status ' . $g->status());
pruefe($konfigurierteEntitaeten($g) === $anzahl, 'Device: Konfiguration bleibt in der HA-Startphase erhalten', (string)$konfigurierteEntitaeten($g));
$warten = $g->timer(NACHPRUEFUNG);
pruefe($warten > 0 && $warten <= 60000, 'Device: Nachprüfung in der HA-Startphase binnen einer Minute geplant', (string)$warten);

// /api/config antwortet nicht, die Vorlage ist leer: Befund unbekannt, nicht „nicht gefunden".
$stelleHa(null, true);
$g = $nachpruefen($g);
pruefe($g->status() === IS_ACTIVE, 'Device: ohne Antwort von /api/config kein „nicht gefunden"', 'Status ' . $g->status());
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
pruefe($e->status() === IS_ACTIVE, 'Entity: leere Antwort in der HA-Startphase ist kein „nicht gefunden"', 'Status ' . $e->status());
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

SplitterHarness::$haAntwort = null;
ergebnis();
