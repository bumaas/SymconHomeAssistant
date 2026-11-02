<?php

declare(strict_types=1);

/**
 * Medienobjekte: Name und Position gehören nach dem Anlegen dem Anwender (Store-Review Punkt 11,
 * 02.11.2026). Am ECHTEN Device- und MQTT-Discovery-Device-Modul über den offiziellen Kernel-Stub.
 *
 * Bis build 220 setzten syncEntityPreviewMeta (Kamera-/Bildvorschau), syncMediaPlayerCoverMeta (Cover),
 * syncCameraStreamMeta (Kamera-Stream) und syncImagePreviewMediaMeta (Discovery-Bild) bei JEDER Pflege
 * Name, Position und Parent neu, das Discovery-Bild sogar bei jedem Bild-Update. Ein umbenanntes oder
 * verschobenes Objekt sprang beim nächsten Durchlauf zurück. Jetzt setzt das Modul beides nur beim
 * Anlegen; danach pflegt es nur Datei und Inhalt.
 *
 * Keine Fixture: Die Objekte legt das Modul selbst an; geprüft wird nur, was es danach anfasst.
 */

require_once __DIR__ . '/device-harness.php';

error_reporting(E_ALL);

/** Medienobjekt mit Ident unter der Instanz. */
function medium(IPSModuleStrict $m, string $ident): ?int
{
    $id = @IPS_GetObjectIDByIdent($ident, $m->id());
    return is_int($id) && $id > 0 ? $id : null;
}

/**
 * Legt an, benennt und verschiebt wie ein Anwender, pflegt erneut und prüft Name und Position.
 *
 * @param callable(IPSModuleStrict): void $pflege
 */
function pruefeMedium(string $titel, IPSModuleStrict $m, string $ident, callable $pflege): void
{
    $pflege(neueAusfuehrung($m));
    $id = medium($m, $ident);
    pruefe($id !== null, $titel . ': Modul legt das Medienobjekt an');
    if ($id === null) {
        return;
    }
    pruefe(IPS_GetName($id) !== '' && IPS_GetParent($id) === $m->id(), $titel . ': beim Anlegen Name und Parent gesetzt', IPS_GetName($id));

    IPS_SetName($id, 'Mein Bild');
    IPS_SetPosition($id, 999);
    $pflege(neueAusfuehrung($m));
    pruefe(medium($m, $ident) === $id, $titel . ': erneute Pflege legt kein zweites Objekt an');
    pruefe(IPS_GetName($id) === 'Mein Bild', $titel . ': Name des Anwenders bleibt', IPS_GetName($id));
    pruefe(IPS_GetObject($id)['ObjectPosition'] === 999, $titel . ': Position des Anwenders bleibt', (string)IPS_GetObject($id)['ObjectPosition']);
}

// --- Device: Cover, Kamera-Vorschau, Kamera-Stream ---
$g = neuesGeraet(['SourceMode' => 'bundle', 'DeviceID' => 'test_geraet', 'DeviceName' => 'Testgerät']);

pruefeMedium('Cover', $g, 'media_player_wohnzimmer_media_cover',
    static fn(IPSModuleStrict $m) => $m->rufe('maintainMediaPlayerCoverMedia', 'media_player.wohnzimmer', 100));
pruefeMedium('Kamera-Vorschau', $g, 'camera_einfahrt_preview',
    static fn(IPSModuleStrict $m) => $m->rufe('maintainEntityPreviewMedia', 'camera.einfahrt', '_preview', 200, 'Vorschau', 'CameraPreview', 'ha_camera_'));
pruefeMedium('Kamera-Stream', $g, 'camera_einfahrt_camera_stream',
    static fn(IPSModuleStrict $m) => $m->rufe('ensureCameraStreamMedia', 'camera.einfahrt', 200));

// --- MQTT Discovery Device: Bildvorschau beim Anlegen und bei jedem Bild-Update ---
$d = neueFensterInstanz(DiscoveryDeviceHarness::class);
$entitaet = [
    'ident'        => 'image_kamera',
    'ident_prefix' => 'image_kamera',
    'component'    => 'image',
    'name'         => 'Kamera',
    'metadata'     => ['content_type' => 'image/png'],
];
$bildIdent = $d->rufe('buildImagePreviewIdent', 'image_kamera');
pruefeMedium('Discovery-Bild', $d, $bildIdent,
    static fn(IPSModuleStrict $m) => $m->rufe('ensureImagePreviewMedia', $bildIdent, 'Kamera', 100, 'png'));

$id = medium($d, $bildIdent);
if ($id !== null) {
    $png = (string)base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMBA0b9XQAAAABJRU5ErkJggg==');
    neueAusfuehrung($d)->rufe('applyImagePayload', $entitaet, $png, 1700000000);
    pruefe(IPS_GetName($id) === 'Mein Bild', 'Discovery-Bild: Bild-Update lässt den Namen', IPS_GetName($id));
    pruefe(IPS_GetObject($id)['ObjectPosition'] === 999, 'Discovery-Bild: Bild-Update lässt die Position', (string)IPS_GetObject($id)['ObjectPosition']);
    pruefe(IPS_GetMedia($id)['MediaSize'] > 0, 'Discovery-Bild: Bild-Update schreibt den Inhalt');
}

ergebnis();
