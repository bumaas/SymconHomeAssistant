<?php

declare(strict_types=1);

/*
 * Regressionstest: Symcon 9.1-952 lehnt bei Integer-Variablen eine Darstellung ab, deren MIN oder
 * MAX kein int ist ("Parameter MAX has wrong type", community.symcon.de/t/144258/519). Die
 * Darstellung wird dann gar nicht gespeichert.
 *
 * Die Regel ist am Kernel belegt (nuc, 9.1-952, 25.09.2026, IPS_SetVariableCustomPresentation an
 * einer temporären Variable):
 *   int-Variable   + MIN/MAX float (auch 100.0)  -> "Der Parameter MAX/MIN hat einen falschen Typ."
 *   int-Variable   + MIN/MAX int                 -> ok
 *   int-Variable   + STEP_SIZE float             -> ok
 *   float-Variable + MIN/MAX int oder float      -> ok
 * Die Attrappe unten bildet genau das nach.
 *
 * Eingaben sind echte HA-Zustände der betroffenen Instanzen (Backofen-number-Entitäten,
 * Sonos media_position), gebaut von den Darstellungs-Bauern des Moduls.
 */

foreach ([
    'VARIABLETYPE_BOOLEAN' => 0,
    'VARIABLETYPE_INTEGER' => 1,
    'VARIABLETYPE_FLOAT' => 2,
    'VARIABLETYPE_STRING' => 3,
    'VARIABLE_PRESENTATION_SWITCH' => 'Switch',
    'VARIABLE_PRESENTATION_VALUE_PRESENTATION' => 'ValuePresentation',
    'VARIABLE_PRESENTATION_DATE_TIME' => 'DateTime',
    'VARIABLE_PRESENTATION_DURATION' => 'Duration',
    'VARIABLE_PRESENTATION_ENUMERATION' => 'Enumeration',
    'VARIABLE_PRESENTATION_LEGACY' => 'Legacy',
    'VARIABLE_PRESENTATION_SLIDER' => 'Slider',
    'VARIABLE_PRESENTATION_COLOR' => 'Color',
    'VARIABLE_PRESENTATION_SHUTTER' => 'Shutter'
] as $name => $value) {
    if (!defined($name)) {
        define($name, $value);
    }
}

require_once __DIR__ . '/harness.php';
require_once dirname(__DIR__) . '/libs/HACommonIncludes.php';
require_once dirname(__DIR__) . '/libs/Device/HAStandardAttributeMaintenance.php';
require_once dirname(__DIR__) . '/libs/Device/HAAttributeActionMapping.php';
require_once dirname(__DIR__) . '/libs/Device/HADomainAttributeMaintenance.php';
require_once dirname(__DIR__) . '/libs/Device/HADomainValueMapping.php';
require_once dirname(__DIR__) . '/libs/Device/HAPresentation.php';

const STATES_FIXTURE = __DIR__ . '/fixtures/ha_states_int_presentation_952.json';

/** Nachbildung der Kernel-Prüfung aus 9.1-952 (siehe Kopfkommentar). */
abstract class Kernel952Attrappe
{
    /** @var list<string> */
    public array $abgelehnt = [];
    /** @var array<string, array|string> */
    public array $gespeichert = [];

    protected function MaintainVariable(string $Ident, string $Name, int $Type, array|string $ProfileOrPresentation, int $Position, bool $Keep): bool
    {
        if (is_array($ProfileOrPresentation) && $Type === VARIABLETYPE_INTEGER) {
            foreach (['MIN', 'MAX'] as $parameter) {
                if (array_key_exists($parameter, $ProfileOrPresentation) && !is_int($ProfileOrPresentation[$parameter])) {
                    $this->abgelehnt[] = "$Ident: Parameter $parameter has wrong type";
                    return false;
                }
            }
        }
        $this->gespeichert[$Ident] = $ProfileOrPresentation;
        return true;
    }

    protected function Translate(string $Text): string
    {
        return $Text;
    }

    protected function debugExpert(string $message, string $label = '', mixed $data = null, bool $force = false): void
    {
    }
}

final class IntPresentationHarness extends Kernel952Attrappe
{
    use HAPresentationTrait;
    use HAStandardAttributeMaintenanceTrait;
    use HADomainAttributeMaintenanceTrait;
    use HAAttributeActionMappingTrait;
    use HADomainValueMappingTrait;

    // wortgleich aus Home Assistant Device/module.php (dort private, kein Trait)
    private function isWriteable(string $domain): bool
    {
        return HADomainCatalog::isMainWritable($this->normalizeDomainAlias($domain));
    }

    public function pflegeEntitaet(array $entity): array
    {
        $domain = explode('.', (string)$entity['entity_id'])[0];
        $type = $this->getVariableType($domain, $entity['attributes']);
        $presentation = $this->getEntityPresentation($domain, $entity + ['domain' => $domain], $type);
        $this->MaintainVariable((string)$entity['entity_id'], 'Test', $type, $presentation, 0, true);
        return [$type, $presentation];
    }

    public function pflegeMediaAttribut(string $entityId, string $attribute, array $attributes): array
    {
        $meta = HAMediaPlayerDefinitions::ATTRIBUTE_DEFINITIONS[$attribute];
        $presentation = $this->getMediaPlayerAttributePresentation($attribute, $attributes, $meta);
        $this->MaintainVariable($entityId . '_' . $attribute, 'Test', (int)$meta['type'], $presentation, 0, true);
        return [(int)$meta['type'], $presentation];
    }
}

$fixture = json_decode((string)file_get_contents(STATES_FIXTURE), true, 512, JSON_THROW_ON_ERROR);
$states = [];
foreach ($fixture['states'] as $state) {
    $states[$state['entity_id']] = $state;
}
if (!pruefe(count($states) === 8, 'Fixture mit 8 echten HA-Zuständen geladen')) {
    ergebnis();
}

$typName = static fn(int $t): string => [0 => 'bool', 1 => 'int', 2 => 'float', 3 => 'string'][$t] ?? (string)$t;

echo "Backofen (number/sensor, HAEntityStore):\n";
foreach (['number.backofen_dauer', 'number.backofen_start_in_relativ', 'number.backofen_solltemperatur', 'number.backofen_wecker'] as $id) {
    $m = new IntPresentationHarness();
    [$type, $p] = $m->pflegeEntitaet($states[$id]);
    pruefe($type === VARIABLETYPE_INTEGER, "$id wird int-Variable", 'Typ ' . $typName($type));
    pruefe($m->abgelehnt === [], "$id: Darstellung vom Kernel angenommen", implode('; ', $m->abgelehnt));
    pruefe(($m->gespeichert[$id]['MIN'] ?? null) === 0 && ($m->gespeichert[$id]['MAX'] ?? null) === 100, "$id: MIN 0 / MAX 100 bleiben erhalten", json_encode($m->gespeichert[$id] ?? null));
}

// Gegenprobe: float-Variablen dürfen int oder float tragen, an ihnen darf sich nichts ändern.
foreach (['sensor.backofen_programm_fortschritt', 'sensor.backofen_aktuelle_garraumtemperatur'] as $id) {
    $m = new IntPresentationHarness();
    [$type, $p] = $m->pflegeEntitaet($states[$id]);
    pruefe($m->abgelehnt === [], "$id ({$typName($type)}): Darstellung vom Kernel angenommen", implode('; ', $m->abgelehnt));
    pruefe(($m->gespeichert[$id] ?? null) === $p, "$id: Darstellung unverändert durchgereicht");
}

echo "Sonos (media_player-Attribute, HAStandardAttributeMaintenance):\n";
foreach (['media_player.bad', 'media_player.wintergarten'] as $id) {
    $attributes = $states[$id]['attributes'];
    $m = new IntPresentationHarness();
    [$type, $p] = $m->pflegeMediaAttribut($id, 'media_position', $attributes);
    pruefe($type === VARIABLETYPE_INTEGER, "$id media_position ist int-Variable");
    pruefe($m->abgelehnt === [], "$id media_position: Darstellung vom Kernel angenommen", implode('; ', $m->abgelehnt));

    // volume_level ist float - dort bleiben float-Grenzen erlaubt und unverändert.
    $m = new IntPresentationHarness();
    [$type, $p] = $m->pflegeMediaAttribut($id, 'volume_level', $attributes);
    pruefe($type === VARIABLETYPE_FLOAT && $m->abgelehnt === [] && ($m->gespeichert[$id . '_volume_level'] ?? null) === $p, "$id volume_level (float): unverändert angenommen");
}

// Während einer Wiedergabe kennt HA die Dauer: Das Modul führt die Attribute zusammen
// (array_merge wie in HAEntityStore::updateEntityCache), media_position wird zum Slider.
$verlauf = $fixture['verlauf']['eintrag'];
$attributes = array_merge($states['media_player.bad']['attributes'], $verlauf['attributes']);
$m = new IntPresentationHarness();
[$type, $p] = $m->pflegeMediaAttribut('media_player.bad', 'media_position', $attributes);
$gespeichert = $m->gespeichert['media_player.bad_media_position'] ?? [];
pruefe(($p['PRESENTATION'] ?? null) === VARIABLE_PRESENTATION_SLIDER, 'media_player.bad während der Durchsage: media_position ist ein Slider');
pruefe($m->abgelehnt === [], 'media_player.bad während der Durchsage: Darstellung vom Kernel angenommen', implode('; ', $m->abgelehnt));
pruefe(($gespeichert['MIN'] ?? null) === 0 && ($gespeichert['MAX'] ?? null) === 2, 'media_player.bad während der Durchsage: MIN 0 / MAX 2 (media_duration) als int', json_encode($gespeichert));

ergebnis();
