<?php

declare(strict_types=1);

/**
 * Prüft am ECHTEN Device- und Entity-Modul (Kernel-Stub), dass eine Instanz das Reload-Fenster
 * aushält: Beim Neuladen der Bibliothek (MC_ReloadModule, Modul-Update) kann eine MQTT-Meldung oder
 * ein Timer die Instanz treffen, während ihr Create() noch läuft. ReadAttributeString liefert dann
 * false.
 *
 * Am nuc 01.10.2026 15:37:56 (1.5 build 167): Der heiße Pfad hielt das für eine Bestandsinstanz ohne
 * Attribut, registrierte ResolvedConfig selbst und schrieb „[]" hinein — das anschließende Create()
 * scheiterte an „Attribute ResolvedConfig is already registered", die evcc-Instanz #36479 blieb auf
 * Status 105, ihre Konfiguration war überschrieben.
 *
 * Im Fenster ist kein einziges Attribut registriert, nicht nur ResolvedConfig: Am nuc endeten
 * dieselben Minuten auch mit trim(false) und sprintf(false, …) aus anderen Attributen
 * (EntityStateCache, LastMQTTMessage, MQTTBaseTopic). Und eine leer gelesene Konfiguration ist
 * keine Auskunft über das Gerät: Die Erreichbarkeitsprüfung hielt ein totes Gerät dann für
 * erreichbar (seen = 0) und brachte den 10-Minuten-Sprung von build 168 zurück.
 *
 * Erwartet: Im Fenster wird nichts registriert, nichts geschrieben und kein Attribut gelesen; nach
 * dem Create() läuft die Instanz mit ihrer alten Konfiguration weiter.
 */

require_once __DIR__ . '/device-harness.php';

error_reporting(E_ALL);

const ATTR = 'ResolvedConfig'; // ATTR_RESOLVED_CONFIG ist in beiden Modulen private

$bundle = tempnam(sys_get_temp_dir(), 'ha_reload_window_');
if ($bundle === false) {
    throw new RuntimeException('Temp-Datei für das Bundle nicht angelegt');
}
$zeile = static fn(string $entityId, bool $anlegen): array => [
    'entity_id'   => $entityId,
    'name'        => 'Testgerät ' . explode('.', $entityId, 2)[1],
    'domain'      => 'sensor',
    'device_id'   => 'devid_reload',
    'device_name' => 'Testgerät',
    'create_var'  => $anlegen,
    'attributes'  => ['unit_of_measurement' => '°C'],
];
file_put_contents($bundle, json_encode(
    [$zeile('sensor.temperatur', true), $zeile('sensor.abgewaehlt', false)],
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
));

/**
 * Führt $schritt in einer neuen Ausführung aus und liefert [Zähler-Delta, Fehlertext|null].
 *
 * @return array{0: array<string, int>, 1: string|null}
 */
function imFenster(DeviceHarness|EntityHarness $instanz, callable $schritt): array
{
    $vorher = KernelZaehler::stand();
    $fehler = null;
    try {
        $schritt(neueAusfuehrung($instanz));
    } catch (Throwable $e) {
        $fehler = $e::class . ': ' . $e->getMessage();
    }
    return [KernelZaehler::seit($vorher), $fehler];
}

function unberuehrt(array $arbeit): bool
{
    foreach ($arbeit as $schluessel => $anzahl) {
        if (str_starts_with($schluessel, 'RegisterAttr:') || str_starts_with($schluessel, 'WriteAttr:')) {
            return false;
        }
    }
    return true;
}

// ---- Teil 1: Device — MQTT-Meldung im Reload-Fenster ----

$g = neuesGeraet([
    'SourceMode' => 'bundle',
    'BundlePath' => $bundle,
    'DeviceName' => 'Testgerät',
    'DeviceID'   => 'devid_reload',
]);
$konfiguration = $g->attribut(ATTR);
pruefe(str_contains($konfiguration, 'sensor.abgewaehlt'), 'Setup: ResolvedConfig enthält auch die abgewählte Entität');

$g->attributeRegistriert = false;
[$arbeit, $abbruch] = imFenster($g, static fn($m) => $m->ReceiveData(mqttMeldung('statestream/sensor/temperatur/state', '21.5')));
pruefe($abbruch === null, 'Device, Meldung im Fenster: kein Fehler', (string)$abbruch);
pruefe(unberuehrt($arbeit), 'Device, Meldung im Fenster: kein Attribut registriert oder geschrieben', json_encode($arbeit));

// Nach einem Kernel-Neustart ist der State-Cache-Buffer leer — gelesen würde das Attribut.
$g->pufferVerwerfen();
[$arbeit, $abbruch] = imFenster($g, static fn($m) => $m->ReceiveData(mqttMeldung('statestream/sensor/temperatur/state', '21.5')));
pruefe($abbruch === null, 'Device, Meldung im Fenster bei leerem Buffer: kein Fehler', (string)$abbruch);
pruefe(unberuehrt($arbeit), 'Device, Meldung im Fenster bei leerem Buffer: kein Attribut registriert oder geschrieben', json_encode($arbeit));

[$arbeit, $abbruch] = imFenster($g, static fn($m) => $m->UpdateMediaPlayerProgress());
pruefe($abbruch === null, 'Device, Media-Timer im Fenster: kein Fehler', (string)$abbruch);
pruefe($g->attribut(ATTR) === $konfiguration, 'Device, Meldung im Fenster: Konfiguration unverändert');

// ---- Teil 2: Device — ApplyChanges im Reload-Fenster (DeferredApply, KR_READY) ----

[$arbeit, $abbruch] = imFenster($g, static fn($m) => $m->ApplyChanges());
pruefe($abbruch === null, 'Device, ApplyChanges im Fenster: kein Fehler', (string)$abbruch);
pruefe(unberuehrt($arbeit), 'Device, ApplyChanges im Fenster: kein Attribut registriert oder geschrieben', json_encode($arbeit));
pruefe($g->attribut(ATTR) === $konfiguration, 'Device, ApplyChanges im Fenster: Konfiguration unverändert');

// ---- Teil 3: Nach dem Create() läuft das Device mit seiner Konfiguration weiter ----

$g->attributeRegistriert = true;
neueAusfuehrung($g)->ApplyChanges();
neueAusfuehrung($g)->ReceiveData(mqttMeldung('statestream/sensor/temperatur/state', '22.5'));
neueAusfuehrung($g)->RequestAction(HADeviceConstants::ACTION_STATE_CACHE_FLUSH, '');
$werte = array_map(static fn(string $ident): mixed => $g->wert($ident), $g->idents());
pruefe(in_array(22.5, $werte, true), 'Device nach Create(): Meldung wird verarbeitet', json_encode(array_combine($g->idents(), $werte)));
pruefe(str_contains($g->attribut(ATTR), 'sensor.abgewaehlt'), 'Device nach Create(): abgewählte Entität steht weiter in der Konfiguration');

// ---- Teil 4: Entity — dieselben Wege ----

$e = neueEntitaet(['EntityID' => 'sensor.temperatur']);
$e->attributSetzen(ATTR, json_encode([$zeile('sensor.temperatur', true)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$konfiguration = $e->attribut(ATTR);

$e->attributeRegistriert = false;
$e->pufferVerwerfen();
[$arbeit, $abbruch] = imFenster($e, static fn($m) => $m->ReceiveData(mqttMeldung('statestream/sensor/temperatur/state', '21.5')));
pruefe($abbruch === null, 'Entity, Meldung im Fenster: kein Fehler', (string)$abbruch);
pruefe(unberuehrt($arbeit), 'Entity, Meldung im Fenster: kein Attribut registriert oder geschrieben', json_encode($arbeit));

[$arbeit, $abbruch] = imFenster($e, static fn($m) => $m->ApplyChanges());
pruefe($abbruch === null, 'Entity, ApplyChanges im Fenster: kein Fehler', (string)$abbruch);
pruefe(unberuehrt($arbeit), 'Entity, ApplyChanges im Fenster: kein Attribut registriert oder geschrieben', json_encode($arbeit));

[$arbeit, $abbruch] = imFenster($e, static fn($m) => $m->RequestAction(HADeviceConstants::ACTION_STATE_CACHE_FLUSH, ''));
pruefe($abbruch === null, 'Entity, Flush-Timer im Fenster: kein Fehler', (string)$abbruch);
pruefe(unberuehrt($arbeit), 'Entity, Flush-Timer im Fenster: kein Attribut registriert oder geschrieben', json_encode($arbeit));
pruefe($e->attribut(ATTR) === $konfiguration, 'Entity im Fenster: Konfiguration unverändert');

// ---- Teil 5: Totes Gerät — Erreichbarkeits- und Flush-Timer im Fenster ----
// Ein Gerät, das schon als nicht erreichbar gilt, darf im Fenster nicht auf „erreichbar" springen:
// Die leer gelesene Konfiguration hieße sonst „keine Entität gesehen" = erreichbar, und das folgende
// ApplyChanges begänne die 600-s-Entprellung von vorn (ein Ereignis auf der Variable meldete
// Erholung und zehn Minuten später erneut den Ausfall).

$tot = neuesGeraet([
    'SourceMode' => 'bundle',
    'BundlePath' => $bundle,
    'DeviceName' => 'Testgerät',
    'DeviceID'   => 'devid_reload',
]);
DeviceHarness::$jetzt = 1000;
neueAusfuehrung($tot)->ReceiveData(mqttMeldung('statestream/sensor/temperatur/state', 'unavailable'));
neueAusfuehrung($tot)->RequestAction(HADeviceConstants::ACTION_STATE_CACHE_FLUSH, '');
DeviceHarness::$jetzt = 1000 + HADeviceConstants::REACHABILITY_DELAY_S;
neueAusfuehrung($tot)->RequestAction(HADeviceConstants::ACTION_REACHABILITY_CHECK, '');
$reachable = HADeviceConstants::REACHABLE_IDENT;
pruefe($tot->wert($reachable) === false, 'Setup: totes Gerät gilt als nicht erreichbar');

$tot->attributeRegistriert = false;
DeviceHarness::$jetzt = 5000;
foreach ([HADeviceConstants::ACTION_REACHABILITY_CHECK => 'Erreichbarkeits-Timer', HADeviceConstants::ACTION_STATE_CACHE_FLUSH => 'Flush-Timer'] as $aktion => $text) {
    [$arbeit, $abbruch] = imFenster($tot, static function ($m) use ($aktion): void {
        if ($aktion === HADeviceConstants::ACTION_STATE_CACHE_FLUSH) {
            $m->rufe('markReachabilityDirty', 'unavailable');
        }
        $m->RequestAction($aktion, '');
    });
    pruefe($abbruch === null, 'Totes Gerät, ' . $text . ' im Fenster: kein Fehler', (string)$abbruch);
    pruefe($tot->wert($reachable) === false, 'Totes Gerät, ' . $text . ' im Fenster: bleibt nicht erreichbar');
    pruefe(($arbeit['SetValue:' . $reachable] ?? 0) === 0, 'Totes Gerät, ' . $text . ' im Fenster: reachable nicht geschrieben', json_encode($arbeit));
}

$tot->attributeRegistriert = true;
neueAusfuehrung($tot)->ApplyChanges();
pruefe($tot->wert($reachable) === false, 'Totes Gerät nach Create(): bleibt nicht erreichbar');
pruefe($tot->timer(HADeviceConstants::TIMER_REACHABILITY) === 0, 'Totes Gerät nach Create(): keine neue Entprellung');

// ---- Teil 6: Splitter — Meldungen, Befehle und Timer im Fenster ----
// Reload 167 → 177 am nuc, 01.10.2026 17:39:42: „Property MQTTBaseTopic not found" und
// trim(false) in recordSeenDomain (Splitter #37202). Der Splitter hatte keinen Schutz.

/** Führt $schritt in einer neuen Ausführung aus; liefert den Fehlertext oder null. */
function splitterImFenster(SplitterHarness|DiscoverySplitterHarness|DiscoveryDeviceHarness $s, callable $schritt): ?string
{
    try {
        $schritt(neueAusfuehrung($s));
    } catch (Throwable $e) {
        return $e::class . ': ' . $e->getMessage();
    }
    return null;
}

$splitterMeldung = static fn(string $topic, string $payload): string => json_encode([
    'DataID'  => HAIds::DATA_MQTT_RX,
    'Topic'   => $topic,
    'Payload' => bin2hex($payload),
], JSON_THROW_ON_ERROR);

$s = neueFensterInstanz(SplitterHarness::class);
$s->attributeRegistriert = false;
$wege = [
    'Zustandsmeldung'      => static fn($m) => $m->ReceiveData($splitterMeldung('homeassistant/sensor/temperatur/state', '21.5')),
    'Bookkeeping-Meldung'  => static fn($m) => $m->ReceiveData($splitterMeldung('homeassistant/sensor/temperatur/last_changed', '"2026-10-01T17:39:42+00:00"')),
    'Befehl eines Kindes'  => static fn($m) => $m->ForwardData(json_encode(['DataID' => HAIds::DATA_DEVICE_TO_SPLITTER, 'PacketType' => 3, 'Topic' => 'homeassistant/light/flur/set', 'Payload' => bin2hex('ON')], JSON_THROW_ON_ERROR)),
    'ApplyChanges'         => static fn($m) => $m->ApplyChanges(),
    'KR_READY'             => static fn($m) => $m->MessageSink(0, 0, IPS_KERNELMESSAGE, [KR_READY]),
    'Timer RestAck'        => static fn($m) => $m->CheckRestAcks(),
    'Timer Topic-Statistik' => static fn($m) => $m->DumpTopicStatistics(),
    'Timer Performance'    => static fn($m) => $m->DumpPerformanceStatistics(),
    'Timer Diagnose'       => static fn($m) => $m->RefreshDiagnostics(),
];
foreach ($wege as $weg => $schritt) {
    $abbruch = splitterImFenster($s, $schritt);
    pruefe($abbruch === null, 'Splitter, ' . $weg . ' im Fenster: kein Fehler', (string)$abbruch);
}

$s->attributeRegistriert = true;
$abbruch = splitterImFenster($s, static fn($m) => $m->ReceiveData($splitterMeldung('homeassistant/sensor/temperatur/state', '22.5')));
pruefe($abbruch === null, 'Splitter nach Create(): Meldung wird ohne Fehler verarbeitet', (string)$abbruch);
pruefe(
    str_contains($s->puffer('SeenDomains'), 'sensor'),
    'Splitter nach Create(): die Domäne der Meldung wird erfasst',
    $s->puffer('SeenDomains')
);

// ---- Teil 7 und 8: MQTT Discovery Splitter und Device ----
// Beim Reload am nuc ohne Meldung davongekommen (2 Splitter, 14 Devices); der Code hatte aber
// denselben ungeschützten Einstieg. Der Discovery-Splitter bekommt den ganzen Broker-Verkehr
// (~13 Meldungen/s) und ist damit am ehesten im Fenster getroffen.

$discoveryMeldung = static fn(string $dataId, string $topic, string $payload): string => json_encode([
    'DataID'  => $dataId,
    'Topic'   => $topic,
    'Payload' => bin2hex($payload),
], JSON_THROW_ON_ERROR);

$ds = neueFensterInstanz(DiscoverySplitterHarness::class);
$ds->attributeRegistriert = false;
$wege = [
    'Zustandsmeldung'      => static fn($m) => $m->ReceiveData($discoveryMeldung(HAIds::DATA_MQTT_RX, 'zigbee2mqtt/flur', '{"state":"ON"}')),
    'Discovery-Config'     => static fn($m) => $m->ReceiveData($discoveryMeldung(HAIds::DATA_MQTT_RX, 'homeassistant/light/flur/light/config', '{"name":"Flur","state_topic":"zigbee2mqtt/flur"}')),
    'Bookkeeping-Meldung'  => static fn($m) => $m->ReceiveData($discoveryMeldung(HAIds::DATA_MQTT_RX, 'homeassistant/sensor/x/last_changed', '"2026-10-01T17:39:42+00:00"')),
    'Befehl eines Kindes'  => static fn($m) => $m->ForwardData($discoveryMeldung(HAIds::DATA_MQTT_DISCOVERY_DEVICE_TO_SPLITTER, 'zigbee2mqtt/flur/set', '{"state":"OFF"}')),
    'ApplyChanges'         => static fn($m) => $m->ApplyChanges(),
    'KR_READY'             => static fn($m) => $m->MessageSink(0, 0, IPS_KERNELMESSAGE, [KR_READY]),
    'FM_CONNECT'           => static fn($m) => $m->MessageSink(0, 0, FM_CONNECT, [0]),
    'Timer Diagnose'       => static fn($m) => $m->RefreshDiscoveryDiagnostics(),
    'Timer Topic-Statistik' => static fn($m) => $m->DumpTopicStatistics(),
];
foreach ($wege as $weg => $schritt) {
    $abbruch = splitterImFenster($ds, $schritt);
    pruefe($abbruch === null, 'Discovery-Splitter, ' . $weg . ' im Fenster: kein Fehler', (string)$abbruch);
}
$ds->attributeRegistriert = true;
$abbruch = splitterImFenster($ds, static fn($m) => $m->ReceiveData($discoveryMeldung(HAIds::DATA_MQTT_RX, 'zigbee2mqtt/flur', '{"state":"ON"}')));
pruefe($abbruch === null, 'Discovery-Splitter nach Create(): Meldung wird ohne Fehler verarbeitet', (string)$abbruch);

$dd = neueFensterInstanz(DiscoveryDeviceHarness::class);
$dd->attributeRegistriert = false;
$wege = [
    'Meldung'        => static fn($m) => $m->ReceiveData($discoveryMeldung(HAIds::DATA_MQTT_DISCOVERY_SPLITTER_TO_DEVICE, 'zigbee2mqtt/flur', '{"state":"ON"}')),
    'Schaltbefehl'   => static fn($m) => $m->RequestAction('light_flur', true),
    'ApplyChanges'   => static fn($m) => $m->ApplyChanges(),
    'KR_READY'       => static fn($m) => $m->MessageSink(0, 0, IPS_KERNELMESSAGE, [KR_READY]),
    'FM_CONNECT'     => static fn($m) => $m->MessageSink(0, 0, FM_CONNECT, [0]),
];
foreach ($wege as $weg => $schritt) {
    $abbruch = splitterImFenster($dd, $schritt);
    pruefe($abbruch === null, 'Discovery-Device, ' . $weg . ' im Fenster: kein Fehler', (string)$abbruch);
}
$dd->attributeRegistriert = true;
$abbruch = splitterImFenster($dd, static fn($m) => $m->ReceiveData($discoveryMeldung(HAIds::DATA_MQTT_DISCOVERY_SPLITTER_TO_DEVICE, 'zigbee2mqtt/flur', '{"state":"ON"}')));
pruefe($abbruch === null, 'Discovery-Device nach Create(): Meldung wird ohne Fehler verarbeitet', (string)$abbruch);

@unlink($bundle);

ergebnis();
