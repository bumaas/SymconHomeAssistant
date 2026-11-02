<?php

declare(strict_types=1);

/**
 * Home Assistant Discovery und die Kerninstanz DNS-SD Control (Kernel-Stub, symcon/SymconStubs).
 *
 * Anlass: Store-Vorab-Review 02.11.2026, Punkte 6 und 13. Fehlte eine DNS-SD-Control-Instanz,
 * legte die Discovery sie selbst an, benannte sie und rief IPS_ApplyChanges auf, ausgelöst schon
 * durch das Öffnen des Formulars (die Suche startet dann per Timer). DNS-SD Control ist eine
 * Kerninstanz und wird als vorhanden vorausgesetzt. Fehlt sie, legt die Discovery nichts an,
 * sucht nicht und zeigt im Formular einen Hinweis. Muster: WLED 1.1 build 47.
 *
 * Keine Fixture: Die ZC-Funktionen bringt der Stub nicht mit, sie werden hier nur mitgezählt
 * (kein Home Assistant im Netz).
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/stubs/autoload.php';

const DNSSD_ID = '{780B2D48-916C-4D59-AD35-5A429B2355A5}';
const HINWEIS = 'No DNS-SD Control instance found. The search needs this core instance of Symcon.';

/** @var list<array{0: string, 1: int}> Aufrufe der DNS-SD-Funktionen */
$zcAufrufe = [];

function ZC_QueryServiceType(int $InstanceID, string $ServiceType, string $Domain): array
{
    global $zcAufrufe;
    $zcAufrufe[] = ['QueryServiceType', $InstanceID];
    return [];
}

function ZC_QueryService(int $InstanceID, string $Name, string $Type, string $Domain): array
{
    global $zcAufrufe;
    $zcAufrufe[] = ['QueryService', $InstanceID];
    return [];
}

require_once dirname(__DIR__) . '/Home Assistant Discovery/module.php';

final class HaDiscoveryHarness extends HomeAssistantDiscovery
{
    /** @var array<string, int> letztes SetTimerInterval je Timer */
    public array $timer = [];

    protected function SetTimerInterval(string $Ident, int $Milliseconds): bool
    {
        $this->timer[$Ident] = $Milliseconds;
        return parent::SetTimerInterval($Ident, $Milliseconds);
    }
}

/** Frischer Kernel mit dem Kernmodul DNS-SD Control (ohne Instanz) und einer Discovery. */
function neueDiscovery(): HaDiscoveryHarness
{
    global $zcAufrufe;
    $zcAufrufe = [];
    IPS\Kernel::reset();
    IPS\ModuleLoader::loadLibrary(__DIR__ . '/stubs/CoreStubs/library.json');
    $id = IPS\ObjectManager::registerObject(OBJECTTYPE_INSTANCE);
    IPS\InstanceManager::createInstance($id, [
        'ModuleID'   => '{C36FEFA4-4732-CBD6-0216-A1DB30D036CF}', // Home Assistant Discovery/module.json
        'ModuleName' => 'Home Assistant Discovery',
        'ModuleType' => MODULETYPE_DISCOVERY,
        'Class'      => HaDiscoveryHarness::class,
    ]);
    return IPS\InstanceManager::getInstanceInterface($id);
}

/** Aktionselement des Formulars mit diesem Namen, sonst null. */
function formElement(HaDiscoveryHarness $d, string $name): ?array
{
    $form = json_decode($d->GetConfigurationForm(), true, 512, JSON_THROW_ON_ERROR);
    foreach ($form['actions'] as $element) {
        if (($element['name'] ?? '') === $name) {
            return $element;
        }
    }
    return null;
}

function sichtbar(?array $element): bool
{
    return $element !== null && ($element['visible'] ?? true);
}

echo "Ohne DNS-SD-Control-Instanz\n";
$d = neueDiscovery();
$hinweis = formElement($d, 'mdnsMissing');
pruefe(sichtbar($hinweis), 'Formular zeigt den Hinweis auf die fehlende DNS-SD-Control-Instanz');
pruefe(($hinweis['caption'] ?? '') === HINWEIS, 'Hinweistext', (string)($hinweis['caption'] ?? ''));
pruefe(!sichtbar(formElement($d, 'searchingInfo')), 'Formular zeigt keinen Suchfortschritt');
pruefe(($d->timer['DiscoveryTimer'] ?? 0) === 0, 'Öffnen des Formulars startet keine Suche');
$d->RequestAction('discover', '');
pruefe(IPS_GetInstanceListByModuleID(DNSSD_ID) === [], 'Suche legt keine DNS-SD-Control-Instanz an');
pruefe(count(IPS_GetInstanceList()) === 1, 'Suche legt überhaupt keine Instanz an');
pruefe($zcAufrufe === [], 'Suche ruft keine ZC-Funktion auf');

echo "Mit DNS-SD-Control-Instanz\n";
$d = neueDiscovery();
$zcID = IPS_CreateInstance(DNSSD_ID);
pruefe(!sichtbar(formElement($d, 'mdnsMissing')), 'Formular zeigt keinen Hinweis');
pruefe(($d->timer['DiscoveryTimer'] ?? 0) > 0, 'Öffnen des Formulars startet die Suche');
$d->RequestAction('discover', '');
pruefe(IPS_GetInstanceListByModuleID(DNSSD_ID) === [$zcID], 'Suche nutzt die vorhandene Instanz und legt keine weitere an');
pruefe($zcAufrufe !== [] && array_unique(array_column($zcAufrufe, 1)) === [$zcID], 'ZC-Abfragen gehen an die vorhandene Instanz');

$de = json_decode((string)file_get_contents(dirname(__DIR__) . '/Home Assistant Discovery/locale.json'), true, 512, JSON_THROW_ON_ERROR)['translations']['de'];
pruefe(isset($de[HINWEIS]), 'Hinweis ist übersetzt');

ergebnis();
