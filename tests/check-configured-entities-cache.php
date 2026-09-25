<?php

declare(strict_types=1);

/**
 * Red-Green-Check für den ReceiveData-Hotpath des klassischen Home-Assistant-Device-Moduls.
 *
 * Hintergrund (Supportfall, 08/2026): Bei Geräten mit vielen Entitäten wird pro MQTT-Message
 * die komplette Entitäten-Konfiguration (ResolvedConfig, 150–250 KB) mehrfach neu gelesen,
 * dekodiert und mit Kernel-Aufrufen pro Entität benannt — der Splitter staut sich dadurch.
 *
 * Der Check lädt das ECHTE Modul (Home Assistant Device/module.php) über einen
 * IPSModuleStrict-Stub und simuliert Symcon-Semantik: Jede MQTT-Message läuft in einer
 * eigenen PHP-„Ausführung" (frisches Modul-Objekt), während Properties/Attribute/Buffer/
 * Variablen in statischen Stores überleben (wie Kernel-Settings bzw. Instanz-Buffer).
 * Gemessen wird Arbeit (Attribut-Reads, Kernel-Aufrufe), nicht Zeit — deterministisch.
 *
 * Rot vor dem ConfiguredEntities-Cache (Build 147), grün danach:
 *   1. ≤1 ResolvedConfig-Attribut-Read pro Message (statt 2 je Durchlauf bei 3–4 Durchläufen)
 *   2. ≤15 GetIDForIdent/IPS_GetObject pro Message (statt ~1–2 je Entität und Durchlauf)
 *   3. Instanz-Memo: zweiter getConfiguredEntities-Aufruf derselben Ausführung ist gratis
 *   4. P5: EnableExpertDebug/EnablePerformanceLog werden nicht pro Debug-Aufruf neu gelesen
 *   5. P7: Unavailable-Entities-JSON wird nicht pro Message mit {} überschrieben,
 *      sondern beim StateCacheFlush korrekt befüllt
 *
 * Dauerhafte Regressionswächter (auch vor dem Umbau grün):
 *   6. Cache-Hit-Ergebnis identisch mit frischem Rebuild (Idents, Dedup-Namenszähler)
 *   7. Konfigurationsänderung (ApplyChanges) schlägt auf den nächsten Aufruf durch
 */

require_once __DIR__ . '/harness.php';

error_reporting(E_ALL);

// ---------------------------------------------------------------------------
// Symcon-Konstanten (Werte für den Check beliebig, nur konsistent)
// ---------------------------------------------------------------------------
foreach ([
    'VARIABLETYPE_BOOLEAN' => 0,
    'VARIABLETYPE_INTEGER' => 1,
    'VARIABLETYPE_FLOAT'   => 2,
    'VARIABLETYPE_STRING'  => 3,
    'OBJECTTYPE_CATEGORY'  => 0,
    'OBJECTTYPE_INSTANCE'  => 1,
    'OBJECTTYPE_VARIABLE'  => 2,
    'OBJECTTYPE_SCRIPT'    => 3,
    'OBJECTTYPE_EVENT'     => 4,
    'OBJECTTYPE_MEDIA'     => 5,
    'OBJECTTYPE_LINK'      => 6,
    'IS_ACTIVE'            => 102,
    'IS_INACTIVE'          => 104,
    'KR_READY'             => 10103,
    'IPS_KERNELMESSAGE'    => 10100,
    'FM_CONNECT'           => 11101,
    'FM_DISCONNECT'        => 11102,
    'IM_CHANGESTATUS'      => 10506,
    'KL_MESSAGE'           => 10201,
    'KL_SUCCESS'           => 10202,
    'KL_NOTIFY'            => 10203,
    'KL_WARNING'           => 10204,
    'KL_ERROR'             => 10205,
    'KL_DEBUG'             => 10206,
    'VARIABLE_PRESENTATION_SWITCH'             => '{5D2B9B4A-0000-0000-0000-000000000001}',
    'VARIABLE_PRESENTATION_VALUE_PRESENTATION' => '{7B15C4F7-C9F0-4E1E-8F1A-1B0F9E2F7A11}',
    'VARIABLE_PRESENTATION_DATE_TIME'          => '{5D2B9B4A-0000-0000-0000-000000000002}',
    'VARIABLE_PRESENTATION_DURATION'           => '{5D2B9B4A-0000-0000-0000-000000000003}',
    'VARIABLE_PRESENTATION_ENUMERATION'        => '{5D2B9B4A-0000-0000-0000-000000000004}',
    'VARIABLE_PRESENTATION_LEGACY'             => '{5D2B9B4A-0000-0000-0000-000000000005}',
    'VARIABLE_PRESENTATION_SLIDER'             => '{5F203CA1-B360-40FB-9407-D2C0C40A7B90}',
    'VARIABLE_PRESENTATION_COLOR'              => '{68296F5D-2AAD-495F-A2CA-693F1F65C388}',
    'VARIABLE_PRESENTATION_SHUTTER'            => '{5D2B9B4A-0000-0000-0000-000000000006}',
] as $name => $value) {
    if (!defined($name)) {
        define($name, $value);
    }
}

const CHECK_DEVICE_INSTANCE_ID = 34001;

// ---------------------------------------------------------------------------
// Zähl-Registry: Arbeit statt Zeit messen
// ---------------------------------------------------------------------------
final class IpsStubCounters
{
    /** @var array<string, int> */
    public static array $counters = [];

    public static function bump(string $key): void
    {
        self::$counters[$key] = (self::$counters[$key] ?? 0) + 1;
    }

    /** @return array<string, int> */
    public static function snapshot(): array
    {
        return self::$counters;
    }

    /** @return array<string, int> Nur positive Deltas seit dem Snapshot */
    public static function diff(array $before): array
    {
        $diff = [];
        foreach (self::$counters as $key => $value) {
            $delta = $value - ($before[$key] ?? 0);
            if ($delta > 0) {
                $diff[$key] = $delta;
            }
        }
        return $diff;
    }
}

// ---------------------------------------------------------------------------
// Objekt-/Variablen-Store des Stub-Kernels (überlebt Modul-Objekte wie der Kernel)
// ---------------------------------------------------------------------------
final class IpsStubKernel
{
    public static int $nextObjectId = 30000;

    /** @var array<int, array<string, mixed>> objectId => Objektdaten */
    public static array $objects = [];

    /** @var array<int, array<string, mixed>> objectId => Variablendaten */
    public static array $variables = [];

    /** @var array<int, array<string, int>> instanceId => (ident => objectId) */
    public static array $identMap = [];

    public static function createVariable(int $instanceId, string $ident, string $name, int $type): int
    {
        $objectId = self::$nextObjectId++;
        self::$objects[$objectId] = [
            'ObjectID'     => $objectId,
            'ObjectIdent'  => $ident,
            'ObjectName'   => $name,
            'ObjectType'   => OBJECTTYPE_VARIABLE,
            'ParentID'     => $instanceId,
        ];
        self::$variables[$objectId] = [
            'VariableType'  => $type,
            'Value'         => null,
        ];
        self::$identMap[$instanceId][$ident] = $objectId;
        IpsStubCounters::bump('VariableCreated');
        return $objectId;
    }

    public static function deleteObject(int $objectId): void
    {
        $ident = (string)(self::$objects[$objectId]['ObjectIdent'] ?? '');
        $parent = (int)(self::$objects[$objectId]['ParentID'] ?? 0);
        unset(self::$objects[$objectId], self::$variables[$objectId]);
        if ($ident !== '' && isset(self::$identMap[$parent][$ident])) {
            unset(self::$identMap[$parent][$ident]);
        }
    }

    public static function variableIdByIdent(int $instanceId, string $ident): ?int
    {
        return self::$identMap[$instanceId][$ident] ?? null;
    }

    public static function variableValueByIdent(int $instanceId, string $ident): mixed
    {
        $id = self::variableIdByIdent($instanceId, $ident);
        return $id === null ? null : (self::$variables[$id]['Value'] ?? null);
    }

    public static function setVariableValueByIdent(int $instanceId, string $ident, mixed $value): void
    {
        $id = self::variableIdByIdent($instanceId, $ident);
        if ($id !== null) {
            self::$variables[$id]['Value'] = $value;
        }
    }
}

// ---------------------------------------------------------------------------
// IPSModuleStrict-Stub: Properties/Attribute/Buffer/Timer in statischen Stores,
// damit ein frisches Modul-Objekt einer neuen PHP-Ausführung in Symcon entspricht.
// ---------------------------------------------------------------------------
class IPSModuleStrict
{
    /** @var array<int, array<string, mixed>> */
    public static array $propertyValues = [];
    /** @var array<int, array<string, mixed>> */
    public static array $propertyDefaults = [];
    /** @var array<int, array<string, string>> */
    public static array $attributes = [];
    /** @var array<int, array<string, string>> */
    public static array $buffers = [];
    /** @var array<int, array<string, int>> */
    public static array $timers = [];
    /** @var array<int, int> */
    public static array $status = [];
    /** @var array<int, string> */
    public static array $summaries = [];
    /** @var array<int, string> */
    public static array $receiveFilters = [];

    public int $InstanceID;

    public function __construct(int $InstanceID)
    {
        $this->InstanceID = $InstanceID;
    }

    public function Create(): void
    {
    }

    public function ApplyChanges(): void
    {
    }

    // --- Properties ---
    protected function RegisterPropertyString(string $Name, string $Default): void
    {
        self::$propertyDefaults[$this->InstanceID][$Name] = $Default;
    }

    protected function RegisterPropertyBoolean(string $Name, bool $Default): void
    {
        self::$propertyDefaults[$this->InstanceID][$Name] = $Default;
    }

    protected function RegisterPropertyInteger(string $Name, int $Default): void
    {
        self::$propertyDefaults[$this->InstanceID][$Name] = $Default;
    }

    public function ReadPropertyString(string $Name): string
    {
        IpsStubCounters::bump('ReadProp:' . $Name);
        $value = self::$propertyValues[$this->InstanceID][$Name]
            ?? self::$propertyDefaults[$this->InstanceID][$Name]
            ?? '';
        return (string)$value;
    }

    public function ReadPropertyBoolean(string $Name): bool
    {
        IpsStubCounters::bump('ReadProp:' . $Name);
        $value = self::$propertyValues[$this->InstanceID][$Name]
            ?? self::$propertyDefaults[$this->InstanceID][$Name]
            ?? false;
        return (bool)$value;
    }

    public function ReadPropertyInteger(string $Name): int
    {
        IpsStubCounters::bump('ReadProp:' . $Name);
        $value = self::$propertyValues[$this->InstanceID][$Name]
            ?? self::$propertyDefaults[$this->InstanceID][$Name]
            ?? 0;
        return (int)$value;
    }

    // --- Attribute (bewusst ohne Rückgabetyp: nicht registriert => false wie mit @-Suppression) ---
    protected function RegisterAttributeString(string $Name, string $Default): void
    {
        if (!array_key_exists($Name, self::$attributes[$this->InstanceID] ?? [])) {
            self::$attributes[$this->InstanceID][$Name] = $Default;
        }
    }

    public function ReadAttributeString(string $Name)
    {
        IpsStubCounters::bump('ReadAttr:' . $Name);
        if (!array_key_exists($Name, self::$attributes[$this->InstanceID] ?? [])) {
            return false;
        }
        return self::$attributes[$this->InstanceID][$Name];
    }

    public function WriteAttributeString(string $Name, string $Value): void
    {
        IpsStubCounters::bump('WriteAttr:' . $Name);
        self::$attributes[$this->InstanceID][$Name] = $Value;
    }

    // --- Buffer / Timer ---
    public function GetBuffer(string $Name): string
    {
        return self::$buffers[$this->InstanceID][$Name] ?? '';
    }

    public function SetBuffer(string $Name, string $Value): void
    {
        self::$buffers[$this->InstanceID][$Name] = $Value;
    }

    protected function RegisterTimer(string $Name, int $Interval, string $Script): void
    {
        if (!array_key_exists($Name, self::$timers[$this->InstanceID] ?? [])) {
            self::$timers[$this->InstanceID][$Name] = $Interval;
        }
    }

    public function SetTimerInterval(string $Name, int $Interval): void
    {
        self::$timers[$this->InstanceID][$Name] = $Interval;
    }

    public function GetTimerInterval(string $Name): int
    {
        return self::$timers[$this->InstanceID][$Name] ?? 0;
    }

    // --- Nachrichten ---
    protected function RegisterMessage(int $SenderID, int $Message): void
    {
    }

    protected function UnregisterMessage(int $SenderID, int $Message): void
    {
    }

    protected function GetMessageList(): array
    {
        return [];
    }

    // --- Variablen ---
    public function MaintainVariable(string $Ident, string $Name, int $Type, mixed $Presentation, int $Position, bool $Keep): void
    {
        $existingId = IpsStubKernel::variableIdByIdent($this->InstanceID, $Ident);
        if (!$Keep) {
            if ($existingId !== null) {
                IpsStubKernel::deleteObject($existingId);
            }
            return;
        }

        if ($existingId !== null) {
            $existingType = (int)(IpsStubKernel::$variables[$existingId]['VariableType'] ?? -1);
            if ($existingType !== $Type) {
                IpsStubCounters::bump('VariableTypeChanged');
                IpsStubKernel::deleteObject($existingId);
                IpsStubKernel::createVariable($this->InstanceID, $Ident, $Name, $Type);
            }
            // Wie Symcon: bestehende Variablen werden nicht umbenannt.
            return;
        }

        IpsStubKernel::createVariable($this->InstanceID, $Ident, $Name, $Type);
    }

    public function GetIDForIdent(string $Ident)
    {
        IpsStubCounters::bump('GetIDForIdent');
        $id = IpsStubKernel::variableIdByIdent($this->InstanceID, $Ident);
        return $id ?? false;
    }

    public function SetValue(string $Ident, mixed $Value): void
    {
        IpsStubCounters::bump('SetValue:' . $Ident);
        IpsStubKernel::setVariableValueByIdent($this->InstanceID, $Ident, $Value);
    }

    public function GetValue(string $Ident): mixed
    {
        return IpsStubKernel::variableValueByIdent($this->InstanceID, $Ident);
    }

    // --- Sonstiges ---
    public function SendDebug(string $Message, string $Data, int $Format): void
    {
        IpsStubCounters::bump('SendDebug');
    }

    public function LogMessage(string $Message, int $Type): void
    {
    }

    public function Translate(string $Text): string
    {
        return $Text;
    }

    public function UpdateFormField(string $Field, string $Parameter, mixed $Value): void
    {
    }

    public function SetSummary(string $Summary): void
    {
        self::$summaries[$this->InstanceID] = $Summary;
    }

    public function SetStatus(int $Status): void
    {
        self::$status[$this->InstanceID] = $Status;
    }

    public function SetReceiveDataFilter(string $Filter): void
    {
        self::$receiveFilters[$this->InstanceID] = $Filter;
    }

    public function SendDataToParent(string $Data): string
    {
        return '';
    }

    public function HasActiveParent(): bool
    {
        return false;
    }
}

// ---------------------------------------------------------------------------
// Globale IPS_*-Funktionen
// ---------------------------------------------------------------------------
function IPS_GetKernelRunlevel(): int
{
    return KR_READY;
}

function IPS_InstanceExists(int $InstanceID): bool
{
    return $InstanceID === CHECK_DEVICE_INSTANCE_ID;
}

function IPS_GetInstance(int $InstanceID): array
{
    return [
        'InstanceID'     => $InstanceID,
        'ConnectionID'   => 0,
        'InstanceStatus' => IPSModuleStrict::$status[$InstanceID] ?? IS_ACTIVE,
        'ModuleInfo'     => ['ModuleID' => '', 'ModuleName' => ''],
    ];
}

function IPS_GetObject(int $ObjectID): array
{
    IpsStubCounters::bump('IPS_GetObject');
    return IpsStubKernel::$objects[$ObjectID] ?? [
        'ObjectID'    => $ObjectID,
        'ObjectIdent' => '',
        'ObjectName'  => 'Objekt ' . $ObjectID,
        'ObjectType'  => OBJECTTYPE_INSTANCE,
        'ParentID'    => 0,
    ];
}

function IPS_GetName(int $ObjectID): string
{
    return (string)(IpsStubKernel::$objects[$ObjectID]['ObjectName'] ?? ('Objekt ' . $ObjectID));
}

function IPS_SetName(int $ObjectID, string $Name): bool
{
    if (isset(IpsStubKernel::$objects[$ObjectID])) {
        IpsStubKernel::$objects[$ObjectID]['ObjectName'] = $Name;
    }
    return true;
}

function IPS_SetIdent(int $ObjectID, string $Ident): bool
{
    return true;
}

function IPS_SetParent(int $ObjectID, int $ParentID): bool
{
    return true;
}

function IPS_SetPosition(int $ObjectID, int $Position): bool
{
    return true;
}

function IPS_GetProperty(int $InstanceID, string $Property): mixed
{
    return '';
}

function IPS_SetProperty(int $InstanceID, string $Property, mixed $Value): bool
{
    return true;
}

function IPS_ApplyChanges(int $InstanceID): bool
{
    return true;
}

function IPS_RequestAction(int $InstanceID, string $Ident, mixed $Value): bool
{
    return true;
}

function IPS_GetChildrenIDs(int $ObjectID): array
{
    return array_values(IpsStubKernel::$identMap[$ObjectID] ?? []);
}

function IPS_GetVariable(int $VariableID): array
{
    return [
        'VariableID'   => $VariableID,
        'VariableType' => (int)(IpsStubKernel::$variables[$VariableID]['VariableType'] ?? VARIABLETYPE_STRING),
    ];
}

function IPS_CreateMedia(int $MediaType): int
{
    return IpsStubKernel::$nextObjectId++;
}

function IPS_GetMedia(int $MediaID): array
{
    return ['MediaID' => $MediaID, 'MediaFile' => ''];
}

function IPS_SetMediaFile(int $MediaID, string $File, bool $Cached): bool
{
    return true;
}

function IPS_SetMediaContent(int $MediaID, string $Content): bool
{
    return true;
}

function IPS_DeleteMedia(int $MediaID, bool $DeleteFile): bool
{
    IpsStubKernel::deleteObject($MediaID);
    return true;
}

// ---------------------------------------------------------------------------
// Echtes Modul laden
// ---------------------------------------------------------------------------
require_once dirname(__DIR__) . '/Home Assistant Device/module.php';

// ---------------------------------------------------------------------------
// Check-Gerüst
// ---------------------------------------------------------------------------
function check(bool $condition, string $label, string $detail = ''): void
{
    pruefe($condition, $label, $detail);
}

function newExecution(): HomeAssistantDevice
{
    // Frisches Objekt = neue PHP-Ausführung in Symcon; die statischen Stores bleiben.
    return new HomeAssistantDevice(CHECK_DEVICE_INSTANCE_ID);
}

function callPrivate(object $object, string $method, mixed ...$args): mixed
{
    $closure = function (string $m, array $a) {
        return $this->{$m}(...$a);
    };
    return $closure->bindTo($object, $object)('' . $method, $args);
}

function readPrivateProperty(object $object, string $property): mixed
{
    $closure = function (string $p) {
        return $this->{$p};
    };
    return $closure->bindTo($object, $object)($property);
}

/**
 * Simuliert eine einzelne MQTT-Message in einer eigenen Ausführung
 * und liefert die dabei angefallene Arbeit als Zähler-Delta.
 *
 * @return array<string, int>
 */
function runMessage(string $topic, string $payload): array
{
    $before = IpsStubCounters::snapshot();
    $device = newExecution();
    $device->ReceiveData(json_encode([
        'DataID'  => '{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}',
        'Topic'   => $topic,
        'Payload' => bin2hex($payload),
    ], JSON_THROW_ON_ERROR));
    return IpsStubCounters::diff($before);
}

function kernelCallCount(array $diff): int
{
    return ($diff['GetIDForIdent'] ?? 0) + ($diff['IPS_GetObject'] ?? 0);
}

// ---------------------------------------------------------------------------
// Fixture: Gerät mit vielen Sensor-Entitäten (synthetisch, keine privaten Daten)
// ---------------------------------------------------------------------------
const CHECK_ENTITY_COUNT = 180;
const CHECK_DEVICE_NAME = 'Testgerät WP';

function buildFixtureRows(): array
{
    $rows = [];
    for ($i = 1; $i <= CHECK_ENTITY_COUNT; $i++) {
        $rows[] = [
            'entity_id'           => "sensor.testgeraet_wp_messwert_$i",
            'name'                => CHECK_DEVICE_NAME . " Messwert $i",
            'domain'              => 'sensor',
            'area'                => 'Keller',
            'device_id'           => 'devid_test_wp',
            'device_name'         => CHECK_DEVICE_NAME,
            'device_model'        => 'EHS-Modell',
            'device_manufacturer' => 'Samsung',
            'create_var'          => true,
            'attributes'          => [
                'unit_of_measurement' => '°C',
                'friendly_name'       => CHECK_DEVICE_NAME . " Messwert $i",
            ],
        ];
    }

    // Zwei Entitäten mit identischem Anzeigenamen: prüft die Dedup-Suffix-Logik
    // (sharedEntityBaseNameCounts) — genau die Stelle, die ein Cache mitsichern muss.
    foreach (['sensor.testgeraet_wp_doppelname', 'sensor.testgeraet_wp_doppelname_2'] as $entityId) {
        $rows[] = [
            'entity_id'           => $entityId,
            'name'                => CHECK_DEVICE_NAME . ' Doppelname',
            'domain'              => 'sensor',
            'area'                => 'Keller',
            'device_id'           => 'devid_test_wp',
            'device_name'         => CHECK_DEVICE_NAME,
            'device_model'        => 'EHS-Modell',
            'device_manufacturer' => 'Samsung',
            'create_var'          => true,
            'attributes'          => ['friendly_name' => CHECK_DEVICE_NAME . ' Doppelname'],
        ];
    }

    // Kandidat für den Unavailable-JSON-Check (P7).
    $rows[] = [
        'entity_id'           => 'sensor.testgeraet_wp_stoerung',
        'name'                => CHECK_DEVICE_NAME . ' Störung',
        'domain'              => 'sensor',
        'area'                => 'Keller',
        'device_id'           => 'devid_test_wp',
        'device_name'         => CHECK_DEVICE_NAME,
        'device_model'        => 'EHS-Modell',
        'device_manufacturer' => 'Samsung',
        'create_var'          => true,
        'attributes'          => ['friendly_name' => CHECK_DEVICE_NAME . ' Störung'],
    ];

    return $rows;
}

$bundleFile = tempnam(sys_get_temp_dir(), 'ha_cache_check_');
if (!pruefe($bundleFile !== false, 'Temp-Datei für das Bundle angelegt')) {
    ergebnis();
}
file_put_contents($bundleFile, json_encode(buildFixtureRows(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

// ---------------------------------------------------------------------------
// Setup: Instanz im Bundle-Modus initialisieren (kein Parent nötig)
// ---------------------------------------------------------------------------
IPSModuleStrict::$propertyValues[CHECK_DEVICE_INSTANCE_ID] = [
    'SourceMode'                 => 'bundle',
    'BundlePath'                 => $bundleFile,
    'DeviceName'                 => CHECK_DEVICE_NAME,
    'DeviceID'                   => 'devid_test_wp',
    'ShowUnavailableEntitiesJson'=> true,
    'EnableExpertDebug'          => false,
    'EnablePerformanceLog'       => false,
];
IPSModuleStrict::$attributes[CHECK_DEVICE_INSTANCE_ID]['MQTTBaseTopic'] = 'statestream';

$setup = newExecution();
$setup->Create();
$setup->ApplyChanges();

check((IPSModuleStrict::$status[CHECK_DEVICE_INSTANCE_ID] ?? 0) === IS_ACTIVE, 'Setup: Instanz ist aktiv (Bundle-Modus)');
$variableCount = count(IpsStubKernel::$identMap[CHECK_DEVICE_INSTANCE_ID] ?? []);
check($variableCount > CHECK_ENTITY_COUNT, 'Setup: Variablen wurden angelegt', "nur $variableCount Variablen");
check(IpsStubKernel::variableIdByIdent(CHECK_DEVICE_INSTANCE_ID, 'unavailable_entities_json') !== null, 'Setup: Unavailable-JSON-Variable existiert');

// Ident-Snapshot für den Stabilitätsvergleich am Ende
$identSnapshot = array_keys(IpsStubKernel::$identMap[CHECK_DEVICE_INSTANCE_ID] ?? []);
sort($identSnapshot);

// Sentinel: Der Message-Hotpath darf diese Variable nicht anfassen.
IpsStubKernel::setVariableValueByIdent(CHECK_DEVICE_INSTANCE_ID, 'unavailable_entities_json', '__SENTINEL__');

// Warm-up-Message (füllt nach dem Umbau den ausführungsübergreifenden Cache)
runMessage('statestream/sensor/testgeraet_wp_messwert_1/state', '20.0');

// ---------------------------------------------------------------------------
// 1+2+4+5a: Arbeit pro heißer Message
// ---------------------------------------------------------------------------
$hotMessages = [
    ['statestream/sensor/testgeraet_wp_messwert_2/state', '21.5', 'Messwert 2'],
    ['statestream/sensor/testgeraet_wp_messwert_3/state', '22.5', 'Messwert 3'],
    ['statestream/sensor/fremdes_geraet_xyz/state',       '1',    'Fremd-Topic (verworfen)'],
];

foreach ($hotMessages as [$topic, $payload, $label]) {
    $diff = runMessage($topic, $payload);
    $resolvedReads = $diff['ReadAttr:ResolvedConfig'] ?? 0;
    $kernelCalls = kernelCallCount($diff);
    check($resolvedReads <= 1, "Hotpath [$label]: höchstens 1 ResolvedConfig-Read pro Message", "waren $resolvedReads");
    check($kernelCalls <= 15, "Hotpath [$label]: höchstens 15 Kernel-Objektaufrufe pro Message", "waren $kernelCalls");
    check(($diff['ReadProp:EnableExpertDebug'] ?? 0) <= 2, "P5 [$label]: EnableExpertDebug höchstens 2x gelesen", 'waren ' . ($diff['ReadProp:EnableExpertDebug'] ?? 0));
    check(($diff['ReadProp:EnablePerformanceLog'] ?? 0) <= 2, "P5 [$label]: EnablePerformanceLog höchstens 2x gelesen", 'waren ' . ($diff['ReadProp:EnablePerformanceLog'] ?? 0));
    check(($diff['SetValue:unavailable_entities_json'] ?? 0) === 0, "P7 [$label]: Unavailable-JSON wird im Hotpath nicht geschrieben", ($diff['SetValue:unavailable_entities_json'] ?? 0) . ' Writes');
}

// Sanity: Die Messages wurden fachlich verarbeitet (kein Early-Out gemessen).
$value2 = IpsStubKernel::variableValueByIdent(CHECK_DEVICE_INSTANCE_ID, 'sensor_messwert_2');
check(is_float($value2) && abs($value2 - 21.5) < 0.001, 'Sanity: Messwert 2 wurde auf 21.5 gesetzt', 'Wert: ' . var_export($value2, true));

check(
    IpsStubKernel::variableValueByIdent(CHECK_DEVICE_INSTANCE_ID, 'unavailable_entities_json') === '__SENTINEL__',
    'P7: Unavailable-JSON-Variable nach Messages unangetastet (kein {}-Überschreiben)',
    'Wert: ' . var_export(IpsStubKernel::variableValueByIdent(CHECK_DEVICE_INSTANCE_ID, 'unavailable_entities_json'), true)
);

// ---------------------------------------------------------------------------
// 3: Instanz-Memo innerhalb einer Ausführung
// ---------------------------------------------------------------------------
$memoDevice = newExecution();
callPrivate($memoDevice, 'getConfiguredEntities', 'check-memo-1');
$before = IpsStubCounters::snapshot();
$memoResult = callPrivate($memoDevice, 'getConfiguredEntities', 'check-memo-2');
$memoDiff = IpsStubCounters::diff($before);
check(is_array($memoResult) && count($memoResult) >= CHECK_ENTITY_COUNT, 'Memo: getConfiguredEntities liefert alle Entitäten');
check(($memoDiff['ReadAttr:ResolvedConfig'] ?? 0) === 0, 'Memo: zweiter Aufruf derselben Ausführung liest das Attribut nicht erneut', 'waren ' . ($memoDiff['ReadAttr:ResolvedConfig'] ?? 0));
check(kernelCallCount($memoDiff) === 0, 'Memo: zweiter Aufruf kommt ohne Kernel-Objektaufrufe aus', 'waren ' . kernelCallCount($memoDiff));

// ---------------------------------------------------------------------------
// 6: Äquivalenz Cache-Hit vs. frischer Rebuild (inkl. Dedup-Namenszähler)
// ---------------------------------------------------------------------------
$hitDevice = newExecution();
$hitRows = callPrivate($hitDevice, 'getConfiguredEntities', 'check-equivalence-hit');
$hitCounts = readPrivateProperty($hitDevice, 'sharedEntityBaseNameCounts');

// Kernel-Neustart simulieren: alle Instanz-Buffer verwerfen -> erzwungener Rebuild.
IPSModuleStrict::$buffers[CHECK_DEVICE_INSTANCE_ID] = [];
$rebuildDevice = newExecution();
$rebuildRows = callPrivate($rebuildDevice, 'getConfiguredEntities', 'check-equivalence-rebuild');
$rebuildCounts = readPrivateProperty($rebuildDevice, 'sharedEntityBaseNameCounts');

check(
    json_encode($hitRows, JSON_THROW_ON_ERROR) === json_encode($rebuildRows, JSON_THROW_ON_ERROR),
    'Äquivalenz: Cache-Hit-Ergebnis identisch mit frischem Rebuild (Idents, Reihenfolge, Felder)'
);
check($hitCounts === $rebuildCounts, 'Äquivalenz: Dedup-Namenszähler identisch (Cache-Hit vs. Rebuild)');
check(($rebuildCounts['Doppelname'] ?? 0) === 2, 'Äquivalenz: Dedup-Fall im Fixture wirksam (Doppelname 2x)', 'Zähler: ' . var_export($rebuildCounts['Doppelname'] ?? null, true));

// ---------------------------------------------------------------------------
// 7: Invalidierung — Konfigurationsänderung schlägt durch
// ---------------------------------------------------------------------------
$rows = buildFixtureRows();
$rows[] = [
    'entity_id'           => 'sensor.testgeraet_wp_neu',
    'name'                => CHECK_DEVICE_NAME . ' Neu',
    'domain'              => 'sensor',
    'area'                => 'Keller',
    'device_id'           => 'devid_test_wp',
    'device_name'         => CHECK_DEVICE_NAME,
    'device_model'        => 'EHS-Modell',
    'device_manufacturer' => 'Samsung',
    'create_var'          => true,
    'attributes'          => ['friendly_name' => CHECK_DEVICE_NAME . ' Neu'],
];
file_put_contents($bundleFile, json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$applyDevice = newExecution();
$applyDevice->ApplyChanges();

$afterDevice = newExecution();
$afterRows = callPrivate($afterDevice, 'getConfiguredEntities', 'check-invalidation');
$hasNew = is_array($afterRows) && array_any($afterRows, static fn(array $row): bool => ($row['entity_id'] ?? '') === 'sensor.testgeraet_wp_neu');
check($hasNew, 'Invalidierung: neue Entität nach ApplyChanges im nächsten Aufruf sichtbar');

// Ident-Stabilität: Bestehende Variablen wurden weder neu angelegt noch ersetzt.
$identsNow = array_keys(IpsStubKernel::$identMap[CHECK_DEVICE_INSTANCE_ID] ?? []);
$missingIdents = array_diff($identSnapshot, $identsNow);
check($missingIdents === [], 'Ident-Stabilität: keine der ursprünglichen Variablen verschwunden', implode(', ', array_slice($missingIdents, 0, 5)));
check((IpsStubCounters::$counters['VariableTypeChanged'] ?? 0) === 0, 'Ident-Stabilität: keine Variable wegen Typwechsel neu angelegt');

// ---------------------------------------------------------------------------
// 5b: P7 — Unavailable-JSON wird beim StateCacheFlush korrekt befüllt
// ---------------------------------------------------------------------------
runMessage('statestream/sensor/testgeraet_wp_stoerung/state', 'unavailable');
runMessage('statestream/sensor/testgeraet_wp_messwert_1/state', '23.0');

$flushDevice = newExecution();
$flushDevice->RequestAction(HADeviceConstants::ACTION_STATE_CACHE_FLUSH, '');

$jsonValue = IpsStubKernel::variableValueByIdent(CHECK_DEVICE_INSTANCE_ID, 'unavailable_entities_json');
$decoded = is_string($jsonValue) ? json_decode($jsonValue, true) : null;
check(
    is_array($decoded) && isset($decoded['sensor.testgeraet_wp_stoerung']),
    'P7: Unavailable-JSON enthält nach dem Flush die nicht verfügbare Entität',
    'Wert: ' . var_export($jsonValue, true)
);

@unlink($bundleFile);

ergebnis();
