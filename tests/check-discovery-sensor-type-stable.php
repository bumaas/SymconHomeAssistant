<?php

declare(strict_types=1);

/**
 * Prüft am ECHTEN Modul (Home Assistant MQTT Discovery Device) über den Kernel-Stub, dass die Variable eines
 * Sensors ihren Typ behält, auch wenn beim Übernehmen mal ein Wert im Zwischenspeicher liegt und mal nicht.
 *
 * Anlass: herbertf, PN t/145051 (26.11.2026). Nach „Änderungen übernehmen“ hatten Batterie und Helligkeit seines
 * Bewegungsmelders neue Variablen-IDs, aus Float war Integer geworden. Der Typ
 * richtete sich zuerst nach dem gerade zwischengespeicherten Wert (ganze Zahl → Integer, Kommazahl → Float) und
 * nur ohne Wert nach Einheit und state_class (→ Float). Beim ersten Übernehmen lag noch kein Wert vor, beim
 * zweiten die ganze Zahl 60. Ein anderer Typ heißt in Symcon: Variable neu anlegen, mit neuer ID und ohne Archiv.
 *
 * Erwartet: Eine bestehende Float-Variable bleibt Float, auch wenn der Wert gerade ganzzahlig ist. Fehlt der
 * Wert, behält eine bestehende Zahlenvariable ihren Typ. Nur eine Kommazahl an einer Integer-Variable stellt
 * noch um, weil die Variable sie sonst nicht halten kann.
 *
 * Fixture: tests/fixtures/discovery_zwavejs_20261125.json (herbertfs Bundle, neutralisiert): Leistungssensor
 * „electric_w_value“ mit Einheit W, echter Wert 0.
 */

require_once __DIR__ . '/device-harness.php';

error_reporting(E_ALL);

const LEISTUNG = 'Z-Wave-JS/Raum/Bild/meter/endpoint_0/value/66049';

$bundle = json_decode((string)file_get_contents(__DIR__ . '/fixtures/discovery_zwavejs_20261125.json'), true, 512, JSON_THROW_ON_ERROR);
$text = static fn(mixed $p): string => is_string($p) ? $p : json_encode($p, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

$mitWert = $bundle['topic_payloads'];
$ohneWert = array_values(array_filter($bundle['topic_payloads'], static fn(array $p): bool => $p['topic'] !== LEISTUNG));
$echt = json_decode($text(array_find($bundle['topic_payloads'], static fn(array $p): bool => $p['topic'] === LEISTUNG)['payload']), true, 512, JSON_THROW_ON_ERROR);
pruefe($echt['value'] === 0, 'Fixture: echter Messwert ist die ganze Zahl 0', var_export($echt['value'], true));

/** Zwischenspeicher mit einem anderen Messwert für den Leistungssensor. */
$mitMesswert = static function (int|float $wert) use ($bundle, $echt): array {
    return array_map(static function (array $p) use ($wert, $echt): array {
        if ($p['topic'] === LEISTUNG) {
            $p['payload'] = json_encode(['value' => $wert] + $echt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }
        return $p;
    }, $bundle['topic_payloads']);
};

DiscoveryDeviceHarness::$splitterAntwort = [
    'Items'            => $bundle['discovery_configs'],
    'Count'            => count($bundle['discovery_configs']),
    'DiscoveryPrefix'  => 'homeassistant',
    'SessionStartedAt' => time() - 3600,
];
DiscoveryDeviceHarness::$parentAktiv = true;
$splitter = neueFensterInstanz(DiscoverySplitterHarness::class);

function neuesGeraetMit(DiscoverySplitterHarness $splitter, array $zwischenspeicher): DiscoveryDeviceHarness
{
    DiscoveryDeviceHarness::$topicPayloadAntwort = ['Items' => $zwischenspeicher];
    $g = neueFensterInstanz(DiscoveryDeviceHarness::class);
    IPS_ConnectInstance($g->id(), $splitter->id());
    $g->SetProperty('DeviceID', 'zwavejs2mqtt_0x00000001_node23');
    neueAusfuehrung($g)->ApplyChanges();
    return $g;
}

function uebernehmen(DiscoveryDeviceHarness $g, array $zwischenspeicher): void
{
    DiscoveryDeviceHarness::$topicPayloadAntwort = ['Items' => $zwischenspeicher];
    neueAusfuehrung($g)->ApplyChanges();
}

/** @return array{0: int, 1: string, 2: mixed} ID, Typ, Wert der Sensorvariable */
function sensor(DiscoveryDeviceHarness $g): array
{
    foreach (IPS_GetChildrenIDs($g->id()) as $id) {
        if (IPS_VariableExists($id) && str_contains(IPS_GetObject($id)['ObjectIdent'], 'electric_w_value')) {
            return [$id, ['Boolean', 'Integer', 'Float', 'String'][IPS_GetVariable($id)['VariableType']], GetValue($id)];
        }
    }
    return [0, 'fehlt', null];
}

// --- Teil 1: herbertfs Fall. Erst ohne Wert angelegt (Float), dann liegt eine ganze Zahl im Zwischenspeicher.
$g = neuesGeraetMit($splitter, $ohneWert);
[$id, $typ] = sensor($g);
pruefe($typ === 'Float', 'Ohne Wert angelegt: Float (Einheit W)', $typ);
uebernehmen($g, $mitWert);
[$idDanach, $typDanach, $wert] = sensor($g);
pruefe($typDanach === 'Float', 'Mit ganzer Zahl im Zwischenspeicher: bleibt Float', $typDanach);
pruefe($idDanach === $id, 'Variable behält ihre ID', "$id → $idDanach");
pruefe($wert === 0.0, 'Wert 0 übernommen', var_export($wert, true));

// --- Teil 2: Gegenrichtung. Mit ganzer Zahl angelegt (Integer), dann fehlt der Wert im Zwischenspeicher.
$g = neuesGeraetMit($splitter, $mitWert);
[$id, $typ] = sensor($g);
pruefe($typ === 'Integer', 'Mit ganzer Zahl angelegt: Integer (wie bisher)', $typ);
uebernehmen($g, $ohneWert);
[$idDanach, $typDanach] = sensor($g);
pruefe($typDanach === 'Integer', 'Ohne Wert im Zwischenspeicher: bleibt Integer', $typDanach);
pruefe($idDanach === $id, 'Variable behält ihre ID', "$id → $idDanach");

// --- Teil 3: Eine Kommazahl braucht Float. Das stellt einmal um, danach nie wieder zurück.
uebernehmen($g, $mitMesswert(12.5));
[$idFloat, $typDanach, $wert] = sensor($g);
pruefe($typDanach === 'Float' && $wert === 12.5, 'Kommazahl an Integer-Variable: wird Float mit 12.5', "$typDanach " . var_export($wert, true));
uebernehmen($g, $mitMesswert(13));
[$idDanach, $typDanach, $wert] = sensor($g);
pruefe($typDanach === 'Float' && $wert === 13.0, 'Danach wieder ganze Zahl: bleibt Float', "$typDanach " . var_export($wert, true));
pruefe($idDanach === $idFloat, 'Variable behält ihre ID', "$idFloat → $idDanach");
uebernehmen($g, $ohneWert);
[$idDanach, $typDanach] = sensor($g);
pruefe($typDanach === 'Float' && $idDanach === $idFloat, 'Und ohne Wert: bleibt Float mit derselben ID', "$typDanach, $idFloat → $idDanach");

// --- Teil 4: Mehrfaches Übernehmen im Wechsel legt nie neu an.
$g = neuesGeraetMit($splitter, $ohneWert);
[$id] = sensor($g);
$ids = [];
foreach ([$mitWert, $ohneWert, $mitMesswert(7), $ohneWert, $mitWert] as $zwischenspeicher) {
    uebernehmen($g, $zwischenspeicher);
    $ids[] = sensor($g)[0];
}
pruefe(array_unique($ids) === [$id], 'Fünfmal übernehmen im Wechsel: immer dieselbe Variable', $id . ' → ' . implode(', ', $ids));

// --- Teil 5: Sensor ohne Einheit, state_class und device_class. Herbertfs Bundle vom 26.11.2026 hat sechs davon
// mit Zahlenwert (notification_access_control_door_state = 23 u. a.). Die Fixture enthält keinen; hier dient
// der Leistungssensor ohne die drei Angaben, sonst unverändert. Ohne Wert fiel er bisher auf Text zurück.
$ohneAngaben = array_map(static function (array $c): array {
    if (!str_contains($c['topic'], 'electric_w_value')) {
        return $c;
    }
    $p = is_string($c['payload']) ? json_decode($c['payload'], true, 512, JSON_THROW_ON_ERROR) : $c['payload'];
    unset($p['unit_of_measurement'], $p['state_class'], $p['device_class']);
    $c['payload'] = json_encode($p, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    return $c;
}, $bundle['discovery_configs']);
DiscoveryDeviceHarness::$splitterAntwort['Items'] = $ohneAngaben;

$g = neuesGeraetMit($splitter, $mitMesswert(23));
[$id, $typ] = sensor($g);
pruefe($typ === 'Integer', 'Ohne Einheit, mit ganzer Zahl angelegt: Integer (wie bisher)', $typ);
uebernehmen($g, $ohneWert);
[$idDanach, $typDanach] = sensor($g);
pruefe($typDanach === 'Integer' && $idDanach === $id, 'Ohne Einheit und ohne Wert: bleibt Integer mit derselben ID', "$typDanach, $id → $idDanach");
// Ein Text im Zwischenspeicher braucht dagegen eine Textvariable.
$mitText = array_map(static function (array $p): array {
    if ($p['topic'] === LEISTUNG) {
        $p['payload'] = '{"value":"offen"}';
    }
    return $p;
}, $bundle['topic_payloads']);
uebernehmen($g, $mitText);
[, $typDanach, $wert] = sensor($g);
pruefe($typDanach === 'String' && $wert === 'offen', 'Ohne Einheit, Text im Zwischenspeicher: wird Text', "$typDanach " . var_export($wert, true));
// Neu angelegt ohne Wert bleibt es beim Text wie bisher.
$g = neuesGeraetMit($splitter, $ohneWert);
pruefe(sensor($g)[1] === 'String', 'Ohne Einheit und ohne Wert neu angelegt: Text (wie bisher)', sensor($g)[1]);

DiscoveryDeviceHarness::$splitterAntwort = null;
DiscoveryDeviceHarness::$topicPayloadAntwort = null;
DiscoveryDeviceHarness::$parentAktiv = false;
ergebnis();
