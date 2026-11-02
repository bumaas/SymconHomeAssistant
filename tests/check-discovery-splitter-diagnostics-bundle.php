<?php

declare(strict_types=1);

/**
 * Prüft am ECHTEN Modul (Home Assistant MQTT Discovery Splitter) über den offiziellen Kernel-Stub, dass
 * die Diagnose im Formular auch im Bundle-Modus gefüllt wird.
 *
 * GetConfigurationForm() liefert die Diagnose-Labels mit den Platzhaltern aus der form.json
 * („Instance: - | Status -“, „Source: MQTT“ …) und stößt einen Timer an, der sie gleich danach per
 * UpdateFormField füllt (RefreshDiscoveryDiagnostics). Seit Mai 2026 (c0c10be) kehrte dieser Refresh
 * im Bundle-Modus sofort zurück. Die Platzhalter blieben stehen, das Formular meldete „Quelle: MQTT“
 * und „Bundle: nicht aktiv“, während der Splitter ein Bundle abspielte (nuc 02.11.2026, #54459 mit
 * burki_bundle.json, Hardcopy von Burkhard).
 *
 * Fixture: echtes v2-Bundle tests/fixtures/ha_mqtt_discovery_bundle_zigbee2mqtt_light_current_v2.json.
 */

require_once __DIR__ . '/device-harness.php';

error_reporting(E_ALL);

const BUNDLE = 'ha_mqtt_discovery_bundle_zigbee2mqtt_light_current_v2.json';

$splitter = neueFensterInstanz(DiscoverySplitterHarness::class);
IPS_SetProperty($splitter->id(), 'SourceMode', 'bundle');
IPS_SetProperty($splitter->id(), 'BundlePath', __DIR__ . '/fixtures/' . BUNDLE);
IPS_ApplyChanges($splitter->id());
// ApplyChanges bricht im Testrahmen vor dem Laden ab (kein Parent); das Laden selbst wie dort.
$splitter->rufe('applyBundleMode');

// Formular öffnen: Platzhalter im Formular, der Refresh ist angestoßen.
$splitter = neueAusfuehrung($splitter);
$form = json_decode($splitter->GetConfigurationForm(), true, 512, JSON_THROW_ON_ERROR);
pruefe($splitter->timer('DiagnosticsRefresh') > 0, 'Öffnen des Formulars stößt den Diagnose-Refresh an', (string)$splitter->timer('DiagnosticsRefresh'));

// Der Timer läuft in einer neuen Ausführung.
DiscoverySplitterHarness::$formularFelder = [];
$splitter = neueAusfuehrung($splitter);
$splitter->RefreshDiscoveryDiagnostics();
$felder = DiscoverySplitterHarness::$formularFelder;

pruefe(($felder['DiagSource.caption'] ?? null) === 'Source: Bundle', 'Bundle-Modus: Quelle zeigt das Bundle',
    var_export($felder['DiagSource.caption'] ?? null, true));
pruefe(isset($felder['DiagInstance.caption']) && $felder['DiagInstance.caption'] !== 'Instance: - | Status -',
    'Bundle-Modus: Instanzzeile ist gefüllt', var_export($felder['DiagInstance.caption'] ?? null, true));
pruefe(isset($felder['DiagBundle.caption']) && str_contains((string)$felder['DiagBundle.caption'], BUNDLE),
    'Bundle-Modus: Bundle-Zeile nennt die Datei', var_export($felder['DiagBundle.caption'] ?? null, true));
pruefe(isset($felder['DiagDiscovery.caption']) && !str_contains((string)$felder['DiagDiscovery.caption'], '0/0/0'),
    'Bundle-Modus: Discovery-Zähler zeigen das Bundle', var_export($felder['DiagDiscovery.caption'] ?? null, true));

// Ohne neuen Anlass (kein Öffnen, keine Meldung) bleibt der Refresh still.
DiscoverySplitterHarness::$formularFelder = [];
$splitter = neueAusfuehrung($splitter);
$splitter->RefreshDiscoveryDiagnostics();
pruefe(DiscoverySplitterHarness::$formularFelder === [], 'Zweiter Refresh ohne Anlass schreibt nichts',
    json_encode(array_keys(DiscoverySplitterHarness::$formularFelder)));

ergebnis();
