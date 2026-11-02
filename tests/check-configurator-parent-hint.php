<?php

declare(strict_types=1);

/**
 * Prüft an den ECHTEN Modulen (Home Assistant Configurator, Home Assistant MQTT Discovery Configurator)
 * über den offiziellen Kernel-Stub, dass ein fehlender, falscher oder inaktiver Splitter im Formular
 * sichtbar ist (Store-Review Punkt 3, 02.11.2026: fehlende Voraussetzungen im Formular anzeigen).
 *
 * Bis build 215 lieferten beide Konfiguratoren in diesen Fällen das leere Grundformular; die Ursache
 * stand nur im Debug, beim MQTT-Konfigurator obendrein deutsch und unübersetzt. Einen inaktiven Splitter
 * bemerkte der HA-Konfigurator gar nicht.
 *
 * Jetzt steht die Ursache als Label im Aktionsbereich. Beim inaktiven HA-Splitter zeigt der
 * Konfigurator weiter die zuletzt bekannte Liste (der REST-Weg kann trotzdem gehen), beim
 * MQTT-Konfigurator gibt es ohne aktiven Splitter nichts zu lesen.
 *
 * Keine Fixture: geprüft werden nur Formularausgaben zu Instanz-Zuständen des Stubs. Einen
 * unpassenden Splitter lässt der Stub nicht verbinden („Dataflow is incompatible“), dieser Fall
 * (Hinweis „The gateway is not …“) ist deshalb nicht abgedeckt.
 */

require_once __DIR__ . '/device-harness.php';

error_reporting(E_ALL);

/** @return list<string> Captions aller Labels im Aktionsbereich */
function aktionsLabels(IPSModuleStrict $k): array
{
    $form = json_decode($k->GetConfigurationForm(), true, 512, JSON_THROW_ON_ERROR);
    $labels = [];
    foreach ($form['actions'] ?? [] as $element) {
        if (($element['type'] ?? '') === 'Label') {
            $labels[] = (string)($element['caption'] ?? '');
        }
    }
    return $labels;
}

function hatKonfiguratorListe(IPSModuleStrict $k): bool
{
    $form = json_decode($k->GetConfigurationForm(), true, 512, JSON_THROW_ON_ERROR);
    foreach ($form['actions'] ?? [] as $element) {
        if (($element['type'] ?? '') === 'Configurator') {
            return true;
        }
    }
    return false;
}

// --- Home Assistant Configurator -------------------------------------------------------------------

$ha = neueFensterInstanz(ConfiguratorHarness::class);

$labels = aktionsLabels($ha);
pruefe(in_array('No gateway connected. Select a Home Assistant Splitter as gateway.', $labels, true),
    'HA-Konfigurator ohne Gateway: Hinweis im Formular', json_encode($labels));

$splitter = neueFensterInstanz(SplitterHarness::class);
IPS_ConnectInstance($ha->id(), $splitter->id());
ConfiguratorHarness::$parentAktiv = false;
$labels = aktionsLabels($ha);
pruefe(in_array('The Home Assistant Splitter is not active. The list shows the last known state.', $labels, true),
    'HA-Konfigurator, Splitter inaktiv: Hinweis im Formular', json_encode($labels));
pruefe(hatKonfiguratorListe($ha), 'HA-Konfigurator, Splitter inaktiv: Liste bleibt sichtbar');

ConfiguratorHarness::$parentAktiv = true;
$labels = aktionsLabels($ha);
pruefe(!in_array('The Home Assistant Splitter is not active. The list shows the last known state.', $labels, true),
    'HA-Konfigurator, Splitter aktiv: kein Hinweis', json_encode($labels));

// --- Home Assistant MQTT Discovery Configurator ----------------------------------------------------

$mqtt = neueFensterInstanz(DiscoveryConfiguratorHarness::class);
DiscoveryConfiguratorHarness::$splitterAntwort = ['Items' => [], 'Count' => 0, 'DiscoveryPrefix' => 'homeassistant', 'SessionStartedAt' => 0];

$labels = aktionsLabels($mqtt);
pruefe(in_array('No gateway connected. Select a Home Assistant MQTT Discovery Splitter as gateway.', $labels, true),
    'MQTT-Konfigurator ohne Gateway: Hinweis im Formular', json_encode($labels));

$discoverySplitter = neueFensterInstanz(DiscoverySplitterHarness::class);
IPS_ConnectInstance($mqtt->id(), $discoverySplitter->id());
DiscoveryConfiguratorHarness::$parentAktiv = false;
$labels = aktionsLabels($mqtt);
pruefe(in_array('The Home Assistant MQTT Discovery Splitter is not active. Check the splitter and its MQTT client.', $labels, true),
    'MQTT-Konfigurator, Splitter inaktiv: Hinweis im Formular', json_encode($labels));

DiscoveryConfiguratorHarness::$parentAktiv = true;
$labels = aktionsLabels($mqtt);
pruefe(!in_array('The Home Assistant MQTT Discovery Splitter is not active. Check the splitter and its MQTT client.', $labels, true),
    'MQTT-Konfigurator, Splitter aktiv: kein Hinweis', json_encode($labels));
pruefe(hatKonfiguratorListe($mqtt), 'MQTT-Konfigurator, Splitter aktiv: Liste vorhanden');

// --- Übersetzung: Die Hinweise gehen über eine Variable an Translate(), check_locale.php sieht sie nicht.

$hinweise = [
    'Home Assistant Configurator' => [
        'No gateway connected. Select a Home Assistant Splitter as gateway.',
        'The gateway is not a Home Assistant Splitter. Change the gateway to a Home Assistant Splitter.',
        'The Home Assistant Splitter is not active. The list shows the last known state.',
    ],
    'Home Assistant MQTT Discovery Configurator' => [
        'No gateway connected. Select a Home Assistant MQTT Discovery Splitter as gateway.',
        'The gateway is not a Home Assistant MQTT Discovery Splitter. Change the gateway to a Home Assistant MQTT Discovery Splitter.',
        'The Home Assistant MQTT Discovery Splitter is not active. Check the splitter and its MQTT client.',
    ],
];
foreach ($hinweise as $modul => $texte) {
    $de = json_decode((string)file_get_contents(dirname(__DIR__) . '/' . $modul . '/locale.json'), true, 512, JSON_THROW_ON_ERROR)['translations']['de'];
    $quelltext = (string)file_get_contents(dirname(__DIR__) . '/' . $modul . '/module.php');
    foreach ($texte as $text) {
        pruefe(str_contains($quelltext, "'" . $text . "'"), $modul . ': Hinweis steht im Modul', $text);
        pruefe(isset($de[$text]), $modul . ': Hinweis ist übersetzt', $text);
    }
}

ergebnis();
