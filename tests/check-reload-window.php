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
 * Erwartet: Im Fenster wird nichts registriert und nichts geschrieben; nach dem Create() läuft die
 * Instanz mit ihrer alten Konfiguration weiter.
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
    return ($arbeit['RegisterAttr:' . ATTR] ?? 0) === 0 && ($arbeit['WriteAttr:' . ATTR] ?? 0) === 0;
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

$g->resolvedConfigRegistriert = false;
[$arbeit, $abbruch] = imFenster($g, static fn($m) => $m->ReceiveData(mqttMeldung('statestream/sensor/temperatur/state', '21.5')));
pruefe($abbruch === null, 'Device, Meldung im Fenster: kein Fehler', (string)$abbruch);
pruefe(unberuehrt($arbeit), 'Device, Meldung im Fenster: ResolvedConfig weder registriert noch geschrieben', json_encode($arbeit));
pruefe($g->attribut(ATTR) === $konfiguration, 'Device, Meldung im Fenster: Konfiguration unverändert');

// ---- Teil 2: Device — ApplyChanges im Reload-Fenster (DeferredApply, KR_READY) ----

[$arbeit, $abbruch] = imFenster($g, static fn($m) => $m->ApplyChanges());
pruefe($abbruch === null, 'Device, ApplyChanges im Fenster: kein Fehler', (string)$abbruch);
pruefe(unberuehrt($arbeit), 'Device, ApplyChanges im Fenster: ResolvedConfig weder registriert noch geschrieben', json_encode($arbeit));
pruefe($g->attribut(ATTR) === $konfiguration, 'Device, ApplyChanges im Fenster: Konfiguration unverändert');

// ---- Teil 3: Nach dem Create() läuft das Device mit seiner Konfiguration weiter ----

$g->resolvedConfigRegistriert = true;
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

$e->resolvedConfigRegistriert = false;
[$arbeit, $abbruch] = imFenster($e, static fn($m) => $m->ReceiveData(mqttMeldung('statestream/sensor/temperatur/state', '21.5')));
pruefe($abbruch === null, 'Entity, Meldung im Fenster: kein Fehler', (string)$abbruch);
pruefe(unberuehrt($arbeit), 'Entity, Meldung im Fenster: ResolvedConfig weder registriert noch geschrieben', json_encode($arbeit));

[$arbeit, $abbruch] = imFenster($e, static fn($m) => $m->ApplyChanges());
pruefe($abbruch === null, 'Entity, ApplyChanges im Fenster: kein Fehler', (string)$abbruch);
pruefe(unberuehrt($arbeit), 'Entity, ApplyChanges im Fenster: ResolvedConfig weder registriert noch geschrieben', json_encode($arbeit));
pruefe($e->attribut(ATTR) === $konfiguration, 'Entity im Fenster: Konfiguration unverändert');

@unlink($bundle);

ergebnis();
