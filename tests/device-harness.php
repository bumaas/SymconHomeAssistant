<?php

declare(strict_types=1);

/**
 * Testrahmen für Checks, die die ECHTEN Module „Home Assistant Device" und „Home Assistant Entity"
 * am offiziellen Kernel-Stub laufen lassen (symcon/SymconStubs, Submodul tests/stubs, gepinnt).
 * Objektbaum, Variablen, Aktionen und Instanzstatus trägt der Stub; der Rahmen zeichnet nur auf,
 * was der Stub nicht beobachtbar macht (Zähler je Kernel-Aufruf), und stellt die Uhr.
 *
 * Einbinden mit require_once __DIR__ . '/device-harness.php'; Instanzen über neuesGeraet() bzw.
 * neueEntitaet(). Kein eigener Test.
 *
 * Zwei Eigenheiten des Stubs, die der Rahmen ausgleicht:
 * - Properties, Attribute, Buffer und Timer hält der Stub im Modul-Objekt, Symcon im Kernel.
 *   neueAusfuehrung() erzeugt deshalb ein frisches Modul-Objekt (leere PHP-Felder wie in einer
 *   neuen PHP-Ausführung) und hängt den Kernel-Zustand des alten um.
 * - GetTimerInterval() liefert im Stub die Restlaufzeit gegen getTime(). Die Uhr des Stubs steht
 *   deshalb still; die Zeit des Moduls läuft über reachabilityNow().
 */

require_once __DIR__ . '/harness.php';
require_once __DIR__ . '/stubs/autoload.php';
require_once dirname(__DIR__) . '/Home Assistant Device/module.php';
require_once dirname(__DIR__) . '/Home Assistant Entity/module.php';

// ---------------------------------------------------------------------------
// Zähl-Registry: Arbeit statt Zeit messen
// ---------------------------------------------------------------------------
final class KernelZaehler
{
    /** @var array<string, int> */
    public static array $zaehler = [];

    public static function zaehle(string $schluessel): void
    {
        self::$zaehler[$schluessel] = (self::$zaehler[$schluessel] ?? 0) + 1;
    }

    /** @return array<string, int> */
    public static function stand(): array
    {
        return self::$zaehler;
    }

    /** @return array<string, int> Nur positive Deltas seit dem Stand */
    public static function seit(array $stand): array
    {
        $diff = [];
        foreach (self::$zaehler as $schluessel => $wert) {
            $delta = $wert - ($stand[$schluessel] ?? 0);
            if ($delta > 0) {
                $diff[$schluessel] = $delta;
            }
        }
        return $diff;
    }
}

// ---------------------------------------------------------------------------
// Netz-Naht: was das Modul an Home Assistant abgesetzt hat (REST-Service über den Parent,
// MQTT-Set-Topic). Ohne Parent kommt nichts davon an - aufgezeichnet wird der Versuch.
// ---------------------------------------------------------------------------
final class HaSendungen
{
    /** @var list<array{0: 'rest', 1: string, 2: string, 3: array}|array{0: 'mqtt', 1: string, 2: string}> */
    public static array $liste = [];

    /** Antwort des Parents auf einen REST-Service-Aufruf; null = echter Weg (ohne Parent: Fehlschlag). */
    public static ?bool $restAntwort = null;

    /** @return list<array> Sendungen seit dem letzten Aufruf */
    public static function abholen(): array
    {
        $liste = self::$liste;
        self::$liste = [];
        return $liste;
    }
}

// ---------------------------------------------------------------------------
// Gemeinsamer Teil beider Modul-Rahmen. Die Overrides übernehmen die Signaturen aus
// tests/stubs/ModuleStrictStubs.php und reichen unverändert an den Stub weiter.
// ---------------------------------------------------------------------------
trait ModulRahmenTrait
{
    /** Zeit des Moduls (reachabilityNow), je Test gestellt. */
    public static int $jetzt = 0;

    /** false = Bestandsinstanz im Reload-Fenster: Create() hat den ReachabilityTimer noch nicht registriert. */
    public bool $erreichbarkeitsTimerRegistriert = true;

    /** @var array<string, true> je Instanz-ID die gesetzten Buffer (der Stub listet sie nicht zuverlässig) */
    private static array $pufferNamen = [];

    public function id(): int
    {
        return $this->InstanceID;
    }

    public function variablenId(string $ident): ?int
    {
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        return $id === false ? null : $id;
    }

    public function wert(string $ident): mixed
    {
        $id = $this->variablenId($ident);
        return $id === null ? null : GetValue($id);
    }

    public function hatAktion(string $ident): bool
    {
        $id = $this->variablenId($ident);
        return $id !== null && IPS_GetVariable($id)['VariableAction'] === $this->InstanceID;
    }

    /** Aktion der Variable am Modul vorbei entfernen (Zustand wie am nuc: VariableAction = 0). */
    public function aktionEntfernen(string $ident): void
    {
        IPS\VariableManager::setVariableAction((int)$this->variablenId($ident), 0);
    }

    /** Variablenwert am Modul vorbei setzen. */
    public function wertSetzen(string $ident, mixed $wert): void
    {
        SetValue((int)$this->variablenId($ident), $wert);
    }

    /** @return list<string> Idents aller Kinder der Instanz */
    public function idents(): array
    {
        $idents = [];
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $id) {
            $idents[] = IPS_GetObject($id)['ObjectIdent'];
        }
        sort($idents);
        return $idents;
    }

    public function status(): int
    {
        return IPS_GetInstance($this->InstanceID)['InstanceStatus'];
    }

    public function timer(string $ident): int
    {
        return parent::GetTimerInterval($ident);
    }

    public function attribut(string $name): string
    {
        return parent::ReadAttributeString($name);
    }

    public function attributSetzen(string $name, string $wert): void
    {
        parent::WriteAttributeString($name, $wert);
    }

    public function puffer(string $name): string
    {
        return parent::GetBuffer($name);
    }

    /** Kernel-Neustart: Alle Instanz-Buffer sind weg. */
    public function pufferVerwerfen(): void
    {
        foreach (array_keys(self::$pufferNamen[$this->InstanceID] ?? []) as $name) {
            parent::SetBuffer($name, '');
        }
    }

    /** Ruft eine private Methode des Moduls auf. */
    public function rufe(string $methode, mixed ...$argumente): mixed
    {
        return Closure::bind(fn() => $this->{$methode}(...$argumente), $this, get_parent_class($this))();
    }

    /** Liest ein privates Feld des Moduls. */
    public function lies(string $feld): mixed
    {
        return Closure::bind(fn() => $this->{$feld}, $this, get_parent_class($this))();
    }

    protected function getTime(): int
    {
        return 0;
    }

    protected function reachabilityNow(): int
    {
        return self::$jetzt;
    }

    protected function sendServiceRequestToParent(string $domain, string $service, array $data): bool
    {
        HaSendungen::$liste[] = ['rest', $domain, $service, $data];
        return HaSendungen::$restAntwort ?? parent::sendServiceRequestToParent($domain, $service, $data);
    }

    protected function sendMqttMessage(string $topic, string $payload): void
    {
        HaSendungen::$liste[] = ['mqtt', $topic, $payload];
        parent::sendMqttMessage($topic, $payload);
    }

    protected function getConfiguredEntities(string $context): array
    {
        KernelZaehler::zaehle('ConfiguredEntities:' . $context);
        return parent::getConfiguredEntities($context);
    }

    protected function ReadPropertyBoolean(string $Name): bool
    {
        KernelZaehler::zaehle('ReadProp:' . $Name);
        return parent::ReadPropertyBoolean($Name);
    }

    protected function ReadPropertyString(string $Name): string
    {
        KernelZaehler::zaehle('ReadProp:' . $Name);
        return parent::ReadPropertyString($Name);
    }

    protected function ReadPropertyInteger(string $Name): int
    {
        KernelZaehler::zaehle('ReadProp:' . $Name);
        return parent::ReadPropertyInteger($Name);
    }

    protected function ReadAttributeString(string $Name): string
    {
        KernelZaehler::zaehle('ReadAttr:' . $Name);
        return parent::ReadAttributeString($Name);
    }

    protected function WriteAttributeString(string $Name, string $Value): bool
    {
        KernelZaehler::zaehle('WriteAttr:' . $Name);
        return parent::WriteAttributeString($Name, $Value);
    }

    protected function SetBuffer(string $Name, string $Data): bool
    {
        self::$pufferNamen[$this->InstanceID][$Name] = true;
        return parent::SetBuffer($Name, $Data);
    }

    protected function GetIDForIdent(string $Ident): int|false
    {
        KernelZaehler::zaehle('GetIDForIdent');
        return parent::GetIDForIdent($Ident);
    }

    protected function SetValue(string $Ident, mixed $Value): bool
    {
        KernelZaehler::zaehle('SetValue:' . $Ident);
        return parent::SetValue($Ident, $Value); // typstreng: TypeError statt Cast
    }

    protected function EnableAction(string $Ident): bool
    {
        KernelZaehler::zaehle('EnableAction');
        return parent::EnableAction($Ident);
    }

    protected function DisableAction(string $Ident): bool
    {
        KernelZaehler::zaehle('DisableAction');
        return parent::DisableAction($Ident);
    }

    protected function SendDebug(string $Message, string $Data, int $Format): bool
    {
        KernelZaehler::zaehle('SendDebug');
        return parent::SendDebug($Message, $Data, $Format);
    }

    protected function MaintainVariable(string $Ident, string $Name, int $Type, array|string $ProfileOrPresentation, int $Position, bool $Keep): bool
    {
        $vorher = $this->variablenId($Ident);
        $ergebnis = parent::MaintainVariable($Ident, $Name, $Type, $ProfileOrPresentation, $Position, $Keep);
        $nachher = $this->variablenId($Ident);
        if ($nachher !== null && $nachher !== $vorher) {
            KernelZaehler::zaehle($vorher === null ? 'VariableCreated' : 'VariableTypeChanged');
        }
        return $ergebnis;
    }

    protected function SetTimerInterval(string $Ident, int $Milliseconds): bool
    {
        if ($Ident === self::TIMER_REACHABILITY && !$this->erreichbarkeitsTimerRegistriert) {
            // Wortlaut der Kernel-Warnung (Log nuc, 30.09.2026 20:05:29, 27 Instanzen). Der Stub wirft
            // hier eine Ausnahme, der Kernel warnt - und nur eine Warnung lässt sich mit @ unterdrücken.
            trigger_error('Timer ' . $Ident . ' does not exist', E_USER_WARNING);
            return false;
        }
        return parent::SetTimerInterval($Ident, $Milliseconds);
    }
}

final class DeviceHarness extends HomeAssistantDevice
{
    use ModulRahmenTrait;

    public const string MODULE_ID = '{72D6A284-1870-4E11-92D8-0402C8233C29}'; // Home Assistant Device/module.json
    public const string MODULE_NAME = 'Home Assistant Device';
}

final class EntityHarness extends HomeAssistantEntity
{
    use ModulRahmenTrait;

    public const string MODULE_ID = '{C27D957C-3761-497B-8A30-A223405E04F2}'; // Home Assistant Entity/module.json
    public const string MODULE_NAME = 'Home Assistant Entity';
}

/**
 * Legt eine Instanz im Kernel-Stub an (Create + ApplyChanges laufen in createInstance), setzt
 * Attribute und Properties und wendet sie an - wie Anlegen und Speichern in der Konsole.
 *
 * @param class-string<DeviceHarness|EntityHarness> $klasse
 * @param array<string, mixed> $properties
 * @param array<string, string> $attribute
 */
function neueInstanz(string $klasse, array $properties, array $attribute = []): DeviceHarness|EntityHarness
{
    $id = IPS\ObjectManager::registerObject(OBJECTTYPE_INSTANCE);
    IPS\InstanceManager::createInstance($id, [
        'ModuleID'   => $klasse::MODULE_ID,
        'ModuleName' => $klasse::MODULE_NAME,
        'ModuleType' => MODULETYPE_DEVICE,
        'Class'      => $klasse,
    ]);
    $modul = IPS\InstanceManager::getInstanceInterface($id);
    foreach ($attribute as $name => $wert) {
        $modul->attributSetzen($name, $wert);
    }
    foreach ($properties as $name => $wert) {
        $modul->SetProperty($name, $wert);
    }
    $modul->ApplyChanges();
    return $modul;
}

/** Gerät im Bundle-Modus: Die Entitäten kommen aus einer Datei, ein Parent ist nicht nötig. */
function neuesGeraet(array $properties, array $attribute = ['MQTTBaseTopic' => 'statestream']): DeviceHarness
{
    return neueInstanz(DeviceHarness::class, $properties, $attribute);
}

function neueEntitaet(array $properties, array $attribute = ['MQTTBaseTopic' => 'statestream']): EntityHarness
{
    return neueInstanz(EntityHarness::class, $properties, $attribute);
}

/**
 * Neue PHP-Ausführung derselben Instanz: frisches Modul-Objekt, der Kernel-Zustand (Properties,
 * Attribute, Buffer, Timer) bleibt. Greift dafür auf zwei private Felder des Stubs zu
 * (IPSModuleStrict::$module, IPS\InstanceManager::$interfaces) - bei einem neuen Stub-Pin prüfen.
 *
 * @template T of DeviceHarness|EntityHarness
 * @param T $alt
 * @return T
 */
function neueAusfuehrung(DeviceHarness|EntityHarness $alt): DeviceHarness|EntityHarness
{
    $kern = new ReflectionProperty(IPSModuleStrict::class, 'module');
    $zustand = $kern->getValue($alt);

    $neu = new ($alt::class)($alt->id());
    $neu->erreichbarkeitsTimerRegistriert = $alt->erreichbarkeitsTimerRegistriert;
    $kern->setValue($neu, $zustand);
    $zustand->setGetTimeCallback(Closure::bind(fn(): int => $this->getTime(), $neu, IPSModuleStrict::class));

    $schnittstellen = new ReflectionProperty(IPS\InstanceManager::class, 'interfaces');
    $alle = $schnittstellen->getValue();
    $alle[$alt->id()] = $neu;
    $schnittstellen->setValue(null, $alle);

    return $neu;
}

/** MQTT-Meldung des Splitters, wie sie ReceiveData() erreicht. */
function mqttMeldung(string $topic, string $payload): string
{
    return json_encode([
        'DataID'  => HAIds::DATA_SPLITTER_TO_DEVICE,
        'Topic'   => $topic,
        'Payload' => bin2hex($payload),
    ], JSON_THROW_ON_ERROR);
}

IPS\Kernel::reset(); // einmal je Testlauf; weitere Instanzen entstehen im selben Kernel
