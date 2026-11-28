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
require_once dirname(__DIR__) . '/Home Assistant Splitter/module.php';
require_once dirname(__DIR__) . '/Home Assistant MQTT Discovery Splitter/module.php';
require_once dirname(__DIR__) . '/Home Assistant MQTT Discovery Device/module.php';
require_once dirname(__DIR__) . '/Home Assistant MQTT Discovery Configurator/module.php';
require_once dirname(__DIR__) . '/Home Assistant Configurator/module.php';

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

    /** @var array<string, array> Antworten auf GET /api/states/<entity_id>; fehlt der Eintrag, echter Weg. */
    public static array $zustaende = [];

    /** @var list<string> abgefragte Entitäten (GET /api/states/<entity_id>) */
    public static array $zustandsAbfragen = [];

    /** @var array<string, int>|null Antwort des Splitters auf die Anzeigegenauigkeit; null = echter Weg (ohne Parent: keine Antwort). */
    public static ?array $anzeigeGenauigkeit = null;

    /**
     * Läuft, während eine Anfrage an Home Assistant unterwegs ist (REST-Service, GET /api/states oder ein
     * set-Topic, das der Splitter per REST ausführt), also mitten in der Ausführung, die sie gestellt hat. Der
     * Kernel lässt in dieser Zeit weitere Aufrufe in dieselbe Instanz (nuc 27.11.2026); ein Test legt hier
     * Meldungen einer zweiten Ausführung hinein. Parameter: Art ('rest', 'zustand' oder 'mqtt') und Entität
     * bzw. Topic.
     *
     * @var (Closure(string, string): void)|null
     */
    public static ?Closure $unterwegs = null;

    /** Antwort auf HasActiveParent(); null = echter Weg (im Bundle-Modus ohne Parent: false). */
    public static ?bool $parentAktiv = null;

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

    /** @var array<string, mixed> zuletzt per UpdateFormField gesetzte Werte, Schlüssel „Feld.Parameter" */
    public static array $formularFelder = [];

    protected function UpdateFormField(string $Field, string $Parameter, mixed $Value): bool
    {
        self::$formularFelder[$Field . '.' . $Parameter] = $Value;
        return parent::UpdateFormField($Field, $Parameter, $Value);
    }

    /** false = Bestandsinstanz im Reload-Fenster: Create() hat den ReachabilityTimer noch nicht registriert. */
    public bool $erreichbarkeitsTimerRegistriert = true;

    /**
     * false = Reload-Fenster vor bzw. mitten in Create(): Die Attribute sind noch nicht registriert.
     * Der Kernel warnt dann bei jedem Lesen und Schreiben und liefert false (nuc 01.10.2026:
     * trim(false), sprintf(false, …)). Der Rahmen warnt genauso; ResolvedConfig liefert über die Naht
     * readResolvedConfigAttribute() false, weil der Stub ReadAttributeString als `: string` deklariert.
     * Registriert das Modul in diesem Zustand selbst ein Attribut, scheitert das anschließende Create()
     * an „already registered" — gezählt als RegisterAttr:<Name>.
     */
    public bool $attributeRegistriert = true;

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

    /** Variablenwert am Modul vorbei setzen (Variablen von IPSModuleStrict sind schreibgeschützt). */
    public function wertSetzen(string $ident, mixed $wert): void
    {
        $id = (int)$this->variablenId($ident);
        IPS\ObjectManager::setReadOnly($id, false);
        SetValue($id, $wert);
        IPS\ObjectManager::setReadOnly($id, true);
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
        if (HaSendungen::$unterwegs !== null) {
            (HaSendungen::$unterwegs)('rest', (string)($data['entity_id'] ?? ''));
        }
        return HaSendungen::$restAntwort ?? parent::sendServiceRequestToParent($domain, $service, $data);
    }

    protected function HasActiveParent(): bool
    {
        return HaSendungen::$parentAktiv ?? parent::HasActiveParent();
    }

    /** @var array<string, int> Lesezugriffe je Buffer */
    public static array $pufferLesungen = [];

    protected function GetBuffer(string $Name): string
    {
        self::$pufferLesungen[$Name] = (self::$pufferLesungen[$Name] ?? 0) + 1;
        return parent::GetBuffer($Name);
    }

    protected function requestDisplayPrecisions(array $entityIds): ?array
    {
        if (HaSendungen::$anzeigeGenauigkeit === null) {
            return parent::requestDisplayPrecisions($entityIds);
        }
        return array_intersect_key(HaSendungen::$anzeigeGenauigkeit, array_flip($entityIds));
    }

    protected function requestHaState(string $entityId): ?array
    {
        HaSendungen::$zustandsAbfragen[] = $entityId;
        if (HaSendungen::$unterwegs !== null) {
            (HaSendungen::$unterwegs)('zustand', $entityId);
        }
        return HaSendungen::$zustaende[$entityId] ?? parent::requestHaState($entityId);
    }

    protected function sendMqttMessage(string $topic, string $payload): void
    {
        HaSendungen::$liste[] = ['mqtt', $topic, $payload];
        if (HaSendungen::$unterwegs !== null) {
            (HaSendungen::$unterwegs)('mqtt', $topic);
        }
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
        if (!$this->attributeRegistriert) {
            // Wortlaut der Kernel-Warnung nicht belegt; der Kernel liefert danach false.
            trigger_error('Attribute ' . $Name . ' is not registered', E_USER_WARNING);
            return '';
        }
        return parent::ReadAttributeString($Name);
    }

    protected function WriteAttributeString(string $Name, string $Value): bool
    {
        KernelZaehler::zaehle('WriteAttr:' . $Name);
        if (!$this->attributeRegistriert) {
            trigger_error('Attribute ' . $Name . ' is not registered', E_USER_WARNING);
            return false;
        }
        return parent::WriteAttributeString($Name, $Value);
    }

    protected function readResolvedConfigAttribute(): string|false
    {
        return $this->attributeRegistriert ? parent::readResolvedConfigAttribute() : false;
    }

    protected function RegisterAttributeString(string $Name, string $DefaultValue): bool
    {
        if (!$this->attributeRegistriert) {
            // Im Stub existiert das Attribut schon; am nuc wäre es jetzt doppelt registriert.
            KernelZaehler::zaehle('RegisterAttr:' . $Name);
            return true;
        }
        return parent::RegisterAttributeString($Name, $DefaultValue);
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
        $erstellt = $nachher !== null && $nachher !== $vorher;
        if ($erstellt) {
            KernelZaehler::zaehle($vorher === null ? 'VariableCreated' : 'VariableTypeChanged');
        }
        // Der Stub liefert immer true; IPSModuleStrict liefert laut Doku (SDK PHP, Modul), „ob die
        // Variable erstellt wurde". Ein Typwechsel legt sie unter neuer ID an (im Stub belegt; die
        // Doku nennt den Fall nicht ausdrücklich).
        return $ergebnis && $erstellt;
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
 * Reload-Fenster für Module ohne eigenen Rahmen (Splitter, MQTT Discovery). Im Fenster
 * (attributeRegistriert = false) warnt jeder Property-, Attribut- und Timer-Zugriff wie der Kernel
 * (nuc 01.10.2026 17:39:42: „Property MQTTBaseTopic not found" → trim(false)) und liefert den
 * Leerwert seines Typs. Das Merkmal des Moduls liefert über seine Naht false — der Stub deklariert
 * die Lesefunktionen mit festem Rückgabetyp und kann kein false liefern.
 */
trait FensterRahmenTrait
{
    public bool $attributeRegistriert = true;
    /** Aufrufe von ApplyChanges je Rahmenklasse (statische Trait-Felder gelten je verwendender Klasse). */
    public static int $uebernahmen = 0;

    public function ApplyChanges(): void
    {
        self::$uebernahmen++;
        parent::ApplyChanges();
    }

    public function id(): int
    {
        return $this->InstanceID;
    }

    public function attribut(string $name): string
    {
        return parent::ReadAttributeString($name);
    }

    public function attributSetzen(string $name, string $wert): void
    {
        parent::WriteAttributeString($name, $wert);
    }

    public function status(): int
    {
        return IPS_GetInstance($this->InstanceID)['InstanceStatus'];
    }

    /** Ruft eine private Methode des Moduls auf. */
    public function rufe(string $methode, mixed ...$argumente): mixed
    {
        return Closure::bind(fn() => $this->{$methode}(...$argumente), $this, get_parent_class($this))();
    }

    public function puffer(string $name): string
    {
        return parent::GetBuffer($name);
    }

    /** @var array<int, array<string, true>> je Instanz-ID die gesetzten Buffer */
    private static array $fensterPuffer = [];

    /** GetBuffer-Aufrufe des Moduls je Rahmenklasse (Kosten im heißen Pfad). */
    public static int $pufferLesungen = 0;

    protected function GetBuffer(string $Name): string
    {
        self::$pufferLesungen++;
        return parent::GetBuffer($Name);
    }

    protected function SetBuffer(string $Name, string $Data): bool
    {
        self::$fensterPuffer[$this->InstanceID][$Name] = true;
        return parent::SetBuffer($Name, $Data);
    }

    /** Kernel-Neustart oder Neuladen: Alle Instanz-Buffer sind weg, Attribute bleiben. */
    public function pufferVerwerfen(): void
    {
        foreach (array_keys(self::$fensterPuffer[$this->InstanceID] ?? []) as $name) {
            parent::SetBuffer($name, '');
        }
    }

    public function timer(string $ident): int
    {
        return parent::GetTimerInterval($ident);
    }

    protected function getTime(): int
    {
        return 0;
    }

    private function imFenster(string $was): bool
    {
        if ($this->attributeRegistriert) {
            return false;
        }
        trigger_error($was . ' not found', E_USER_WARNING);
        return true;
    }

    protected function ReadPropertyString(string $Name): string
    {
        return $this->imFenster('Property ' . $Name) ? '' : parent::ReadPropertyString($Name);
    }

    protected function ReadPropertyBoolean(string $Name): bool
    {
        return $this->imFenster('Property ' . $Name) ? false : parent::ReadPropertyBoolean($Name);
    }

    protected function ReadPropertyInteger(string $Name): int
    {
        return $this->imFenster('Property ' . $Name) ? 0 : parent::ReadPropertyInteger($Name);
    }

    /** @var array<string, int> Zugriffe des Moduls auf String-Attribute je Rahmenklasse, Schlüssel „r:Name“ bzw. „w:Name“ */
    public static array $attributZugriffe = [];

    protected function ReadAttributeString(string $Name): string
    {
        self::$attributZugriffe['r:' . $Name] = (self::$attributZugriffe['r:' . $Name] ?? 0) + 1;
        return $this->imFenster('Attribute ' . $Name) ? '' : parent::ReadAttributeString($Name);
    }

    protected function ReadAttributeBoolean(string $Name): bool
    {
        return $this->imFenster('Attribute ' . $Name) ? false : parent::ReadAttributeBoolean($Name);
    }

    protected function WriteAttributeString(string $Name, string $Value): bool
    {
        self::$attributZugriffe['w:' . $Name] = (self::$attributZugriffe['w:' . $Name] ?? 0) + 1;
        return $this->imFenster('Attribute ' . $Name) ? false : parent::WriteAttributeString($Name, $Value);
    }

    protected function WriteAttributeBoolean(string $Name, bool $Value): bool
    {
        return $this->imFenster('Attribute ' . $Name) ? false : parent::WriteAttributeBoolean($Name, $Value);
    }

    protected function SetTimerInterval(string $Ident, int $Milliseconds): bool
    {
        return $this->imFenster('Timer ' . $Ident) ? false : parent::SetTimerInterval($Ident, $Milliseconds);
    }
}

final class SplitterHarness extends HomeAssistantSplitter
{
    use FensterRahmenTrait;

    public const string MODULE_ID = HAIds::MODULE_SPLITTER;
    public const string MODULE_NAME = 'Home Assistant Splitter';
    public const int MODULE_TYPE = MODULETYPE_SPLITTER;

    /** Antwort der WebSocket-API auf list_for_display (Form wie HAWebSocketClient::request). */
    public static array $registryAntwort = ['ok' => false, 'error' => 'kein Testwert'];
    public static int $registryAbrufe = 0;

    protected function fetchEntityRegistryForDisplay(string $haUrl, string $token): array
    {
        self::$registryAbrufe++;
        return self::$registryAntwort;
    }

    protected function readBaseTopicProperty(): string|false
    {
        return $this->attributeRegistriert ? parent::readBaseTopicProperty() : false;
    }

    /** @var list<array{url: string, daten: mixed}> REST-Aufrufe an HA (performCurl) */
    public static array $restAufrufe = [];

    /** @var list<string> Topics, die der Splitter an den MQTT Client weiterreicht */
    public static array $mqttWeiterleitungen = [];

    /**
     * Stellt HA nach: bekommt URL und gesendete Daten, liefert ['response', 'httpCode', 'error'] oder null
     * für die Standardantwort.
     */
    public static ?Closure $haAntwort = null;

    /** HA antwortet ohne $haAntwort auf jeden Aufruf mit 200 und `[]`; für Schaltbefehle wird der Inhalt nicht ausgewertet. */
    protected function performCurl(string $url, string $token, array $options = []): array
    {
        $daten = isset($options['postFields']) ? json_decode($options['postFields'], true) : null;
        self::$restAufrufe[] = ['url' => $url, 'daten' => $daten];
        $antwort = self::$haAntwort !== null ? (self::$haAntwort)($url, $daten) : null;
        return ($antwort ?? []) + ['response' => '[]', 'httpCode' => 200, 'contentType' => 'application/json', 'error' => ''];
    }

    protected function SendDataToParent(string $Data): string
    {
        self::$mqttWeiterleitungen[] = (string)(json_decode($Data, true)['Topic'] ?? '');
        return '';
    }
}

final class DiscoverySplitterHarness extends HomeAssistantMQTTDiscoverySplitter
{
    use FensterRahmenTrait;

    public const string MODULE_ID = HAIds::MODULE_MQTT_DISCOVERY_SPLITTER;
    public const string MODULE_NAME = 'Home Assistant MQTT Discovery Splitter';
    public const int MODULE_TYPE = MODULETYPE_SPLITTER;
    /** @var array<string, mixed> zuletzt per UpdateFormField gesetzte Werte, Schlüssel „Feld.Parameter" */
    public static array $formularFelder = [];

    protected function UpdateFormField(string $Field, string $Parameter, mixed $Value): bool
    {
        self::$formularFelder[$Field . '.' . $Parameter] = $Value;
        return parent::UpdateFormField($Field, $Parameter, $Value);
    }

    /** @var list<array{0: string, 1: int}> Sperranfragen des Moduls über seine Naht enterSemaphore (Name, Wartezeit in ms) */
    public static array $sperrAnfragen = [];

    protected function enterSemaphore(string $Name, int $Milliseconds): bool
    {
        self::$sperrAnfragen[] = [$Name, $Milliseconds];
        return parent::enterSemaphore($Name, $Milliseconds);
    }

    /** @var list<array{0: string, 1: int}> Meldungen des Moduls ins Meldungsfenster (Text, Typ) */
    public static array $meldungen = [];

    protected function LogMessage(string $Message, int $Type): bool
    {
        self::$meldungen[] = [$Message, $Type];
        return parent::LogMessage($Message, $Type);
    }

    protected function readSourceModeProperty(): string|false
    {
        return $this->attributeRegistriert ? parent::readSourceModeProperty() : false;
    }
}

final class DiscoveryDeviceHarness extends HomeAssistantMQTTDiscoveryDevice
{
    use FensterRahmenTrait;

    public const string MODULE_ID = HAIds::MODULE_MQTT_DISCOVERY_DEVICE;
    public const string MODULE_NAME = 'Home Assistant MQTT Discovery Device';
    public const int MODULE_TYPE = MODULETYPE_DEVICE;

    protected function readTopicProcessingIndexAttribute(): string|false
    {
        return $this->attributeRegistriert ? parent::readTopicProcessingIndexAttribute() : false;
    }

    /** Antwort des Discovery-Splitters auf GetDiscoveryConfigs (null = Stub fragen). */
    public static ?array $splitterAntwort = null;

    /** Antwort des Discovery-Splitters auf GetTopicPayloads (zwischengespeicherte Meldungen; null = Stub fragen). */
    public static ?array $topicPayloadAntwort = null;

    /** Splitter antwortet nicht (leere Antwort, wie bei einem kurz belegten Splitter). */
    public static bool $splitterStumm = false;

    /** Parent gilt als aktiv, ohne einen Splitter mit Broker aufzubauen. */
    public static bool $parentAktiv = false;

    /** @var list<array{Topic: string, Payload: string}> MQTT-Sendungen an den Splitter (PacketType 3), Payload dekodiert */
    public static array $mqttSendungen = [];

    /** @var list<array<string, mixed>> Anfragen des Geräts an den Splitter (DiscoveryAction gesetzt), dekodiert */
    public static array $anfragen = [];

    protected function SendDataToParent(string $Data): string
    {
        $anfrage = json_decode($Data, true);
        if (isset($anfrage['DiscoveryAction'])) {
            self::$anfragen[] = $anfrage;
        }
        if (($anfrage['PacketType'] ?? null) === 3) {
            self::$mqttSendungen[] = ['Topic' => (string)$anfrage['Topic'], 'Payload' => (string)hex2bin((string)$anfrage['Payload'])];
            return '';
        }
        if (self::$topicPayloadAntwort !== null && ($anfrage['DiscoveryAction'] ?? '') === 'GetTopicPayloads') {
            return json_encode(self::$topicPayloadAntwort, JSON_THROW_ON_ERROR);
        }
        if (self::$splitterStumm && ($anfrage['DiscoveryAction'] ?? '') === 'GetDiscoveryConfigs') {
            return '';
        }
        if (self::$splitterAntwort !== null && ($anfrage['DiscoveryAction'] ?? '') === 'GetDiscoveryConfigs') {
            return json_encode(self::$splitterAntwort, JSON_THROW_ON_ERROR);
        }
        return parent::SendDataToParent($Data);
    }

    protected function HasActiveParent(): bool
    {
        return self::$parentAktiv || parent::HasActiveParent();
    }

    /** @var list<string> Idents, deren SetValue scheitert (false, wie am Kernel nach einer Warnung). */
    public static array $schreibfehler = [];

    /**
     * Wie der Kernel INF und NAN in einer Float-Variable behandelt. Der Stub wirft dort eine Ausnahme (null), die
     * Anlagen nicht (gemessen am 28.11.2026 mit SetValue auf eine Float-Variable):
     * 'rust' = true ohne Warnung, gespeichert wird 0.0 (Testbox, 9.1);
     * 'cpp'  = Warnung „NaN/INF Werte werden nicht unterstützt“ und false, der alte Wert bleibt (Neustadt, 9.0).
     */
    public static ?string $nichtEndlich = null;

    protected function SetValue(string $Ident, mixed $Value): bool
    {
        if (self::$nichtEndlich !== null && is_float($Value) && !is_finite($Value)) {
            return self::$nichtEndlich === 'rust' && parent::SetValue($Ident, 0.0);
        }
        return !in_array($Ident, self::$schreibfehler, true) && parent::SetValue($Ident, $Value);
    }
}

final class DiscoveryConfiguratorHarness extends HomeAssistantMQTTDiscoveryConfigurator
{
    use FensterRahmenTrait;

    public const string MODULE_ID = HAIds::MODULE_MQTT_DISCOVERY_CONFIGURATOR;
    public const string MODULE_NAME = 'Home Assistant MQTT Discovery Configurator';
    public const int MODULE_TYPE = MODULETYPE_CONFIGURATOR;

    /** Antwort des Discovery-Splitters auf GetDiscoveryConfigs (null = Stub fragen). */
    public static ?array $splitterAntwort = null;

    /** Parent gilt als aktiv, ohne einen Splitter mit Broker aufzubauen. */
    public static bool $parentAktiv = false;

    protected function SendDataToParent(string $Data): string
    {
        $anfrage = json_decode($Data, true);
        if (self::$splitterAntwort !== null && ($anfrage['DiscoveryAction'] ?? '') === 'GetDiscoveryConfigs') {
            return json_encode(self::$splitterAntwort, JSON_THROW_ON_ERROR);
        }
        return parent::SendDataToParent($Data);
    }

    protected function HasActiveParent(): bool
    {
        return self::$parentAktiv || parent::HasActiveParent();
    }
}

final class ConfiguratorHarness extends HomeAssistantConfigurator
{
    use FensterRahmenTrait;

    public const string MODULE_ID = HAIds::MODULE_CONFIGURATOR;
    public const string MODULE_NAME = 'Home Assistant Configurator';
    public const int MODULE_TYPE = MODULETYPE_CONFIGURATOR;

    /** Parent gilt als aktiv, ohne einen Splitter mit Broker aufzubauen. */
    public static bool $parentAktiv = false;

    protected function HasActiveParent(): bool
    {
        return self::$parentAktiv || parent::HasActiveParent();
    }
}

/**
 * Legt eine Instanz eines Moduls ohne eigenen Rahmen im Kernel-Stub an (ohne Parent).
 *
 * @template T of SplitterHarness|DiscoverySplitterHarness|DiscoveryDeviceHarness|DiscoveryConfiguratorHarness|ConfiguratorHarness
 * @param class-string<T> $klasse
 * @return T
 */
function neueFensterInstanz(string $klasse): SplitterHarness|DiscoverySplitterHarness|DiscoveryDeviceHarness|DiscoveryConfiguratorHarness|ConfiguratorHarness
{
    $id = IPS\ObjectManager::registerObject(OBJECTTYPE_INSTANCE);
    IPS\InstanceManager::createInstance($id, [
        'ModuleID'   => $klasse::MODULE_ID,
        'ModuleName' => $klasse::MODULE_NAME,
        'ModuleType' => $klasse::MODULE_TYPE,
        'Class'      => $klasse,
    ]);
    return IPS\InstanceManager::getInstanceInterface($id);
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
 * @template T of IPSModuleStrict
 * @param T $alt
 * @return T
 */
function neueAusfuehrung(IPSModuleStrict $alt): IPSModuleStrict
{
    $kern = new ReflectionProperty(IPSModuleStrict::class, 'module');
    $zustand = $kern->getValue($alt);

    $neu = new ($alt::class)($alt->id());
    if (property_exists($alt, 'erreichbarkeitsTimerRegistriert')) {
        $neu->erreichbarkeitsTimerRegistriert = $alt->erreichbarkeitsTimerRegistriert;
    }
    $neu->attributeRegistriert = $alt->attributeRegistriert;
    $kern->setValue($neu, $zustand);
    $zustand->setGetTimeCallback(Closure::bind(fn(): int => $this->getTime(), $neu, IPSModuleStrict::class));

    $schnittstellen = new ReflectionProperty(IPS\InstanceManager::class, 'interfaces');
    $alle = $schnittstellen->getValue();
    $alle[$alt->id()] = $neu;
    $schnittstellen->setValue(null, $alle);

    return $neu;
}

/**
 * Löst einen Formularknopf aus wie die Konsole: führt dessen onClick
 * `IPS_RequestAction($id, "<Ident>", <Wert>);` an der Instanz aus. Der Wert ist true oder ein Text in
 * einfachen Anführungszeichen. Anderes gilt als Testfehler (Ausnahme), damit ein umgebauter Knopf nicht
 * unbemerkt etwas anderes auslöst.
 */
function drueckeKnopf(IPSModuleStrict $modul, ?array $knopf): void
{
    $onClick = trim((string)($knopf['onClick'] ?? ''));
    if (preg_match('/^IPS_RequestAction\(\$id,\s*["\']([A-Za-z0-9_]+)["\'],\s*(.+)\);$/s', $onClick, $treffer) !== 1) {
        throw new RuntimeException('Knopf ohne IPS_RequestAction: ' . $onClick);
    }
    $roh = trim($treffer[2]);
    $wert = match (true) {
        $roh === 'true' => true,
        preg_match("/^'((?:[^'\\\\]|\\\\.)*)'$/s", $roh, $text) === 1 => strtr($text[1], ['\\\\' => '\\', "\\'" => "'"]),
        default => throw new RuntimeException('Knopfwert nicht verstanden: ' . $roh),
    };
    $modul->RequestAction($treffer[1], $wert);
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
// Ohne registrierte Bibliothek kennt IPS_ConnectInstance() die Datenflüsse der Module nicht
IPS\ModuleLoader::loadLibrary(dirname(__DIR__) . '/library.json');
