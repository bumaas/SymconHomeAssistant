<?php

declare(strict_types=1);

/**
 * Prüft am ECHTEN Home Assistant Device und Entity über den Kernel-Stub, dass der Empfangsfilter genau die
 * Topics der eigenen Entitäten trifft: <base>/<domain>/<object_id> und alles darunter, sonst nichts.
 *
 * Anlass: Der Filter fasste seit 27.06.2026 Objekt-IDs einer Domäne über ihren gemeinsamen Anfang zusammen
 * (ab drei Zeichen, „evcc_[^"]*“), damit das Muster kurz bleibt. Damit trifft er auch Entitäten anderer Geräte,
 * die gleich anfangen. An der eigenen Anlage (27.11.2026, 31 Geräte, 1380 HA-Entitäten) waren das neun bei zwei
 * Geräten; bei einer Anlage, deren Entitäten nach Räumen heißen, kann es jede Meldung des Raums sein. Das MQTT
 * Discovery Device hatte denselben Fehler in groß (build 252: jedes Gerät bekam jede Meldung des Senders) und
 * trifft seitdem genau seine Topics. Das klassische Gerät nutzt jetzt denselben Baustein
 * (HAMqttTopicFilter).
 *
 * Fixture: tests/fixtures/receive_filter_statestream_20261127.json, alle Entitäten der eigenen Anlage, deren
 * Objekt-ID mit „evcc“ oder „bad“ beginnt, mit ihrer Gerätezuordnung (168 Entitäten, 19 Geräte).
 */

require_once __DIR__ . '/device-harness.php';

error_reporting(E_ALL);

$fixture = json_decode((string)file_get_contents(__DIR__ . '/fixtures/receive_filter_statestream_20261127.json'), true, 512, JSON_THROW_ON_ERROR);
$zuordnung = $fixture['entitaeten'];
$temp = [];

/** Konfigurationszeilen eines Geräts, wie sie das Bundle des Geräts führt. */
function zeilen(array $entityIds, string $geraet): array
{
    $zeilen = [];
    foreach ($entityIds as $entityId) {
        [$domain, $objekt] = explode('.', $entityId, 2);
        $zeilen[] = [
            'entity_id'   => $entityId,
            'name'        => $objekt,
            'domain'      => $domain,
            'device_id'   => $geraet,
            'device_name' => $geraet,
            'create_var'  => true,
            'attributes'  => ['friendly_name' => $objekt],
        ];
    }
    return $zeilen;
}

function geraetMit(array $entityIds, string $geraet, string $baseTopic = 'statestream'): DeviceHarness
{
    global $temp;
    $datei = tempnam(sys_get_temp_dir(), 'ha_filter_check_');
    if ($datei === false) {
        throw new RuntimeException('Temp-Datei für das Bundle nicht angelegt');
    }
    $temp[] = $datei;
    file_put_contents($datei, json_encode(zeilen($entityIds, $geraet), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $g = neuesGeraet(['SourceMode' => 'bundle', 'BundlePath' => $datei, 'DeviceName' => $geraet, 'DeviceID' => $geraet], ['MQTTBaseTopic' => $baseTopic]);
    neueAusfuehrung($g)->ApplyChanges();
    return $g;
}

/** Trifft der Filter das Datenpaket, wie es der Splitter an seine Kinder schickt? */
function trifft(string $filter, string $topic, bool $maskiert): bool
{
    $paket = json_encode(['DataID' => HAIds::DATA_SPLITTER_TO_DEVICE, 'Topic' => $topic, 'Payload' => bin2hex('on')], JSON_THROW_ON_ERROR | ($maskiert ? 0 : JSON_UNESCAPED_SLASHES));
    $ergebnis = @preg_match('/' . $filter . '/', $paket);
    if ($ergebnis === false) {
        throw new RuntimeException('Filter ist kein gültiges Muster: ' . substr($filter, 0, 120));
    }
    return $ergebnis === 1;
}

/** Die Topics, die HA zu einer Entität veröffentlicht: Zustand, ein Attribut, ein Attribut mit Unterebene. */
function topicsVon(string $entityId, string $baseTopic = 'statestream'): array
{
    [$domain, $objekt] = explode('.', $entityId, 2);
    $wurzel = "$baseTopic/$domain/$objekt";
    return [$wurzel . '/state', $wurzel . '/friendly_name', $wurzel . '/last_changed', $wurzel];
}

// --- Teil 1: Die beiden Geräte der Fixture bekommen alles Eigene und nichts Fremdes.
foreach (['evcc' => [110, 6], 'bad' => [8, 3]] as $geraet => [$erwartetEigen, $domaenen]) {
    $eigene = array_keys(array_filter($zuordnung, static fn(string $g): bool => $g === $geraet));
    $fremde = array_keys(array_filter($zuordnung, static fn(string $g): bool => $g !== $geraet));
    pruefe(count($eigene) === $erwartetEigen && count(array_unique(array_map(static fn(string $e): string => explode('.', $e)[0], $eigene))) === $domaenen,
        "Fixture: Gerät „{$geraet}“ mit $erwartetEigen Entitäten in $domaenen Domänen", (string)count($eigene));

    $g = geraetMit($eigene, $geraet);
    $filter = $g->GetReceiveDataFilter();
    pruefe($filter !== '' && $g->attribut('CurrentFilter') === $filter, "$geraet: Filter gesetzt, die Diagnose zeigt denselben", strlen($filter) . ' Zeichen');

    $fehlt = [];
    foreach ($eigene as $entityId) {
        foreach (topicsVon($entityId) as $topic) {
            foreach ([true, false] as $maskiert) {
                if (!trifft($filter, $topic, $maskiert)) {
                    $fehlt[] = $topic . ($maskiert ? ' (maskiert)' : '');
                }
            }
        }
    }
    pruefe($fehlt === [], "$geraet: jedes eigene Topic kommt an, mit und ohne maskierten Schrägstrich", count($fehlt) . ' fehlen, z. B. ' . implode(', ', array_slice($fehlt, 0, 3)));

    $fremdTreffer = [];
    foreach ($fremde as $entityId) {
        foreach (topicsVon($entityId) as $topic) {
            if (trifft($filter, $topic, true) || trifft($filter, $topic, false)) {
                $fremdTreffer[$entityId] = true;
            }
        }
    }
    pruefe($fremdTreffer === [], "$geraet: keine Entität eines anderen Geräts kommt an (" . count($fremde) . ' geprüft)',
        count($fremdTreffer) . ' fremde, z. B. ' . implode(', ', array_slice(array_keys($fremdTreffer), 0, 4)));
}

// --- Teil 2: Grenzen des Musters an einem kleinen Gerät.
$g = geraetMit(['media_player.bad', 'switch.bad_licht_spiegel', 'number.bad_bass'], 'bad');
$filter = $g->GetReceiveDataFilter();
foreach ([
    'statestream/media_player/bad/state'                 => true,
    'statestream/media_player/bad/media_title'           => true,
    'statestream/media_player/bad'                       => true,
    'statestream/media_player/bad_2/state'               => false, // Objekt-ID, die nur gleich anfängt
    'statestream/media_player/ba/state'                  => false, // Anfang der Objekt-ID
    'statestream/switch/bad_licht_spiegel_2/state'       => false,
    'statestream/switch/bad_licht/state'                 => false,
    'statestream/sensor/bad_bass/state'                  => false, // richtige Objekt-ID, andere Domäne
    'statestream/number/bad_bass/state'                  => true,
    'anderes/media_player/bad/state'                     => false, // anderes Base-Topic
    'statestream2/media_player/bad/state'                => false,
    'x/statestream/media_player/bad/state'               => false, // Topic, das nur so endet
] as $topic => $soll) {
    $ist = trifft($filter, $topic, true);
    pruefe($ist === $soll && trifft($filter, $topic, false) === $soll, ($soll ? 'kommt an: ' : 'kommt nicht an: ') . $topic, var_export($ist, true));
}

// --- Teil 3: Base-Topic mit mehreren Ebenen und einem Zeichen, das im Muster eine Bedeutung hätte.
$g = geraetMit(['switch.bad_licht_spiegel'], 'bad', 'haus/ha.stream');
$filter = $g->GetReceiveDataFilter();
pruefe(trifft($filter, 'haus/ha.stream/switch/bad_licht_spiegel/state', true), 'Mehrstufiges Base-Topic mit Punkt: eigenes Topic kommt an', $filter);
pruefe(!trifft($filter, 'haus/haXstream/switch/bad_licht_spiegel/state', true), 'Der Punkt im Base-Topic steht für einen Punkt, nicht für ein beliebiges Zeichen', $filter);

// --- Teil 4: Ohne Entitäten lässt der Filter nichts durch.
$leer = geraetMit([], 'leer');
$filter = $leer->GetReceiveDataFilter();
pruefe($filter === '^$', 'Gerät ohne Entitäten: Filter „^$“ wie beim Konfigurator', $filter);
pruefe(!trifft($filter, 'statestream/switch/bad_licht_spiegel/state', true), 'Gerät ohne Entitäten: nichts kommt an');
pruefe($leer->attribut('CurrentFilter') === $filter, 'Gerät ohne Entitäten: die Diagnose zeigt denselben Filter', $leer->attribut('CurrentFilter'));

// --- Teil 5: Die Entity-Instanz setzt ihren Filter über dieselbe Funktion und trifft genau ihre Entität.
// Ohne Splitter als Parent kommt ihr ApplyChanges nicht bis zum Filter; deshalb der Aufruf mit dem Topic, das
// processEntities() für die Entität liefert.
$e = neueEntitaet(['EntityID' => 'media_player.bad']);
neueAusfuehrung($e)->rufe('updateReceiveFilter', ['statestream/media_player/bad']);
$filter = $e->GetReceiveDataFilter();
pruefe($filter !== '' && $filter !== '^$', 'Entity: Filter gesetzt', $filter);
pruefe(trifft($filter, 'statestream/media_player/bad/state', true) && trifft($filter, 'statestream/media_player/bad/volume_level', false), 'Entity: eigene Topics kommen an', $filter);
pruefe(!trifft($filter, 'statestream/media_player/bad_2/state', true) && !trifft($filter, 'statestream/media_player/bad_2/state', false), 'Entity: „bad_2“ kommt bei „bad“ nicht an', $filter);

foreach ($temp as $datei) {
    @unlink($datei);
}
ergebnis();
