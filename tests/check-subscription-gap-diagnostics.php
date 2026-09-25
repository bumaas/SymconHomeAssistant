<?php

declare(strict_types=1);

/**
 * Integrationscheck für die Abonnement-Diagnose des MQTT-Discovery-Splitters.
 *
 * Hintergrund (Supportfall bgersmann, Forum 142973, 08/2026): Discovery-Configs kamen unter
 * homeassistant/… an, die referenzierten State-Topics lagen aber unter Garage/gecos/… — und
 * der MQTT Client hatte nur homeassistant/# abonniert. Kein einziger State erreichte den
 * Splitter, die Diagnose nannte die Ursache nicht.
 *
 * Der Check lädt das ECHTE Modul (Home Assistant MQTT Discovery Splitter/module.php) über
 * einen IPSModuleStrict-Stub mit der Parent-Kette Splitter -> MQTT Client -> IO und stellt
 * den Fall synthetisch nach (zwei Configs unter homeassistant/…, Runtime-Topics unter
 * garage/gecos/…). Erwartung:
 *   1. Selbsttest warnt konkret: "… not covered by any MQTT client subscription" mit
 *      Abonnement-Vorschlag garage/#
 *   2. Formular-Diagnose: DiagSubscriptionGap-Caption nennt Anzahl + Vorschlag,
 *      DiagSubscriptionAlert wird sichtbar
 *   3. Bundle-Export enthält parent_subscriptions, subscription_check='gap',
 *      unsubscribed-Zähler und je Topic subscription_covered=false
 *   4. Gegenprobe mit zusätzlichem Abonnement garage/#: generische Warnung statt
 *      Abo-Warnung, kein Alert, unsubscribed=0, subscription_covered=true
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
    'IS_CREATING'          => 101,
    'IS_ACTIVE'            => 102,
    'IS_DELETING'          => 103,
    'IS_INACTIVE'          => 104,
    'IS_NOTCREATED'        => 105,
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
] as $name => $value) {
    if (!defined($name)) {
        define($name, $value);
    }
}

const CHECK_SPLITTER_ID = 41001;
const CHECK_MQTT_CLIENT_ID = 41002;
const CHECK_IO_ID = 41003;

// Vom Test veränderbare Abonnement-Liste des MQTT-Client-Stubs
$GLOBALS['stubSubscriptions'] = [['Topic' => 'homeassistant/#', 'QoS' => 1]];

// ---------------------------------------------------------------------------
// IPSModuleStrict-Stub: Properties/Attribute/Buffer/Timer in statischen Stores,
// UpdateFormField-Aufrufe werden für Assertions mitgeschrieben.
// ---------------------------------------------------------------------------
class IPSModuleStrict
{
    /** @var array<int, array<string, mixed>> */
    public static array $propertyValues = [];
    /** @var array<int, array<string, mixed>> */
    public static array $propertyDefaults = [];
    /** @var array<int, array<string, mixed>> */
    public static array $attributes = [];
    /** @var array<int, array<string, string>> */
    public static array $buffers = [];
    /** @var array<int, array<string, int>> */
    public static array $timers = [];
    /** @var array<int, int> */
    public static array $status = [];
    /** @var array<int, string> */
    public static array $receiveFilters = [];
    /** @var array<string, array<string, mixed>> Feldname => (Parameter => Wert) */
    public static array $formFieldUpdates = [];

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
        return (string)(self::$propertyValues[$this->InstanceID][$Name]
            ?? self::$propertyDefaults[$this->InstanceID][$Name]
            ?? '');
    }

    public function ReadPropertyBoolean(string $Name): bool
    {
        return (bool)(self::$propertyValues[$this->InstanceID][$Name]
            ?? self::$propertyDefaults[$this->InstanceID][$Name]
            ?? false);
    }

    public function ReadPropertyInteger(string $Name): int
    {
        return (int)(self::$propertyValues[$this->InstanceID][$Name]
            ?? self::$propertyDefaults[$this->InstanceID][$Name]
            ?? 0);
    }

    // Attribute bewusst ohne Rückgabetyp: nicht registriert => false wie mit @-Suppression
    protected function RegisterAttributeString(string $Name, string $Default): void
    {
        if (!array_key_exists($Name, self::$attributes[$this->InstanceID] ?? [])) {
            self::$attributes[$this->InstanceID][$Name] = $Default;
        }
    }

    protected function RegisterAttributeBoolean(string $Name, bool $Default): void
    {
        if (!array_key_exists($Name, self::$attributes[$this->InstanceID] ?? [])) {
            self::$attributes[$this->InstanceID][$Name] = $Default;
        }
    }

    public function ReadAttributeString(string $Name)
    {
        if (!array_key_exists($Name, self::$attributes[$this->InstanceID] ?? [])) {
            return false;
        }
        return self::$attributes[$this->InstanceID][$Name];
    }

    public function WriteAttributeString(string $Name, string $Value): void
    {
        self::$attributes[$this->InstanceID][$Name] = $Value;
    }

    public function ReadAttributeBoolean(string $Name)
    {
        if (!array_key_exists($Name, self::$attributes[$this->InstanceID] ?? [])) {
            return false;
        }
        return (bool)self::$attributes[$this->InstanceID][$Name];
    }

    public function WriteAttributeBoolean(string $Name, bool $Value): void
    {
        self::$attributes[$this->InstanceID][$Name] = $Value;
    }

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

    public function SendDebug(string $Message, string $Data, int $Format): void
    {
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
        self::$formFieldUpdates[$Field][$Parameter] = $Value;
    }

    public function SetSummary(string $Summary): void
    {
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

    public function SendDataToChildren(string $Data): void
    {
    }

    public function HasActiveParent(): bool
    {
        return true;
    }
}

// ---------------------------------------------------------------------------
// Globale IPS_*-Funktionen: Kette Splitter -> MQTT Client -> IO
// ---------------------------------------------------------------------------
function IPS_GetKernelRunlevel(): int
{
    return KR_READY;
}

function IPS_InstanceExists(int $InstanceID): bool
{
    return in_array($InstanceID, [CHECK_SPLITTER_ID, CHECK_MQTT_CLIENT_ID, CHECK_IO_ID], true);
}

function IPS_GetInstance(int $InstanceID): array
{
    $connection = match ($InstanceID) {
        CHECK_SPLITTER_ID => CHECK_MQTT_CLIENT_ID,
        CHECK_MQTT_CLIENT_ID => CHECK_IO_ID,
        default => 0
    };
    $moduleId = $InstanceID === CHECK_MQTT_CLIENT_ID ? HAIds::MODULE_MQTT_CLIENT : '';

    return [
        'InstanceID'     => $InstanceID,
        'ConnectionID'   => $connection,
        'InstanceStatus' => IPSModuleStrict::$status[$InstanceID] ?? IS_ACTIVE,
        'ModuleInfo'     => ['ModuleID' => $moduleId, 'ModuleName' => $moduleId === '' ? '' : 'MQTT Client'],
    ];
}

function IPS_GetName(int $ObjectID): string
{
    return 'Objekt ' . $ObjectID;
}

function IPS_GetConfiguration(int $InstanceID): string
{
    if ($InstanceID !== CHECK_MQTT_CLIENT_ID) {
        return '{}';
    }

    return json_encode([
        'ClientID' => 'symcon-check',
        'UserName' => 'check-user',
        'Password' => 'check-pass',
        'Subscriptions' => json_encode($GLOBALS['stubSubscriptions'], JSON_THROW_ON_ERROR)
    ], JSON_THROW_ON_ERROR);
}

// ---------------------------------------------------------------------------
// Echtes Modul laden
// ---------------------------------------------------------------------------
require_once dirname(__DIR__) . '/Home Assistant MQTT Discovery Splitter/module.php';

// ---------------------------------------------------------------------------
// Check-Gerüst und Helfer
// ---------------------------------------------------------------------------
function check(bool $condition, string $label, string $detail = ''): void
{
    pruefe($condition, $label, $detail);
}

function receiveMqttMessage(HomeAssistantMQTTDiscoverySplitter $splitter, string $topic, string $payload): void
{
    $splitter->ReceiveData(json_encode([
        'DataID'           => HAIds::DATA_MQTT_RX,
        'Topic'            => $topic,
        'Payload'          => bin2hex($payload),
        'Retain'           => false,
        'QualityOfService' => 0
    ], JSON_THROW_ON_ERROR));
}

function exportBundle(HomeAssistantMQTTDiscoverySplitter $splitter): array
{
    $json = $splitter->ForwardData(json_encode([
        'DataID'          => HAIds::DATA_MQTT_DISCOVERY_DEVICE_TO_SPLITTER,
        'DiscoveryAction' => 'ExportDiscoveryBundle'
    ], JSON_THROW_ON_ERROR));

    return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
}

// ---------------------------------------------------------------------------
// Szenario: Configs unter homeassistant/…, Runtime-Topics unter garage/gecos/…
// (synthetisch dem Fall bgersmann nachgebildet, keine echten Gerätedaten)
// ---------------------------------------------------------------------------
$splitter = new HomeAssistantMQTTDiscoverySplitter(CHECK_SPLITTER_ID);
$splitter->Create();
$splitter->ApplyChanges();

check((IPSModuleStrict::$status[CHECK_SPLITTER_ID] ?? 0) === IS_ACTIVE, 'Splitter wird aktiv (Parent-Kette im Stub steht)');

receiveMqttMessage($splitter, 'homeassistant/switch/gecos1_out3/config', json_encode([
    'name'               => 'Ausgang 03',
    'state_topic'        => 'garage/gecos/outputs/0/24/3',
    'command_topic'      => 'garage/gecos/command/output/0/24/3',
    'payload_on'         => 'ON',
    'payload_off'        => 'OFF',
    'availability_topic' => 'garage/gecos/status',
    'unique_id'          => 'gecos1_out3',
    'device'             => ['identifiers' => ['gecos1'], 'name' => 'GeCoS Check']
], JSON_THROW_ON_ERROR));
receiveMqttMessage($splitter, 'homeassistant/binary_sensor/gecos1_in1/config', json_encode([
    'name'        => 'Eingang 01',
    'state_topic' => 'garage/gecos/inputs/0/20/1',
    'payload_on'  => 'ON',
    'payload_off' => 'OFF',
    'unique_id'   => 'gecos1_in1',
    'device'      => ['identifiers' => ['gecos1'], 'name' => 'GeCoS Check']
], JSON_THROW_ON_ERROR));

$bundle = exportBundle($splitter);
$referencedCount = count($bundle['referenced_topics'] ?? []);
check($referencedCount >= 4, 'Referenzierte Topics erkannt (State/Command/Availability)', "referenced=$referencedCount");

// 1. Selbsttest warnt konkret mit Abonnement-Vorschlag
$selfTest = $splitter->RunSelfTest();
check(str_contains($selfTest, 'not covered by any MQTT client subscription'), 'Selbsttest: Abo-Lücken-Warnung vorhanden', $selfTest);
check(str_contains($selfTest, 'garage/#'), 'Selbsttest: Vorschlag garage/# genannt', $selfTest);
check(str_contains($selfTest, 'homeassistant/#'), 'Selbsttest: aktuelle Abonnements genannt', $selfTest);

// 2. Formular-Diagnose: Caption + Alert
IPSModuleStrict::$formFieldUpdates = [];
$splitter->RefreshDiscoveryDiagnostics();
$gapCaption = (string)(IPSModuleStrict::$formFieldUpdates['DiagSubscriptionGap']['caption'] ?? '');
check(str_contains($gapCaption, (string)$referencedCount) && str_contains($gapCaption, 'garage/#'), 'Diagnose-Label nennt Anzahl und Vorschlag', $gapCaption);
check((IPSModuleStrict::$formFieldUpdates['DiagSubscriptionAlert']['visible'] ?? null) === true, 'DiagSubscriptionAlert wird sichtbar');

// 3. Bundle-Export: Abonnement-Infos enthalten
check(($bundle['splitter']['parent_subscriptions'] ?? null) === ['homeassistant/#'], 'Bundle: parent_subscriptions enthalten');
check(($bundle['splitter']['subscription_check'] ?? '') === 'gap', 'Bundle: subscription_check = gap');
check((int)($bundle['diagnostics']['referenced_topic_payloads']['unsubscribed'] ?? -1) === $referencedCount, 'Bundle: unsubscribed-Zähler passt');
$allUncovered = $referencedCount > 0;
foreach (($bundle['referenced_topics'] ?? []) as $entry) {
    $allUncovered = $allUncovered && (($entry['subscription_covered'] ?? null) === false);
}
check($allUncovered, 'Bundle: alle referenzierten Topics als nicht abonniert markiert');

// ---------------------------------------------------------------------------
// 4. Gegenprobe: garage/# zusätzlich abonniert -> Lücke verschwindet
// ---------------------------------------------------------------------------
$GLOBALS['stubSubscriptions'] = [['Topic' => 'homeassistant/#', 'QoS' => 1], ['Topic' => 'garage/#', 'QoS' => 0]];
$splitter2 = new HomeAssistantMQTTDiscoverySplitter(CHECK_SPLITTER_ID);

$selfTest2 = $splitter2->RunSelfTest();
check(!str_contains($selfTest2, 'not covered by any MQTT client subscription'), 'Gegenprobe: keine Abo-Lücken-Warnung mehr', $selfTest2);
check(str_contains($selfTest2, 'have no payload yet'), 'Gegenprobe: generische Payload-Warnung bleibt (Topics weiter ohne Payload)', $selfTest2);

IPSModuleStrict::$formFieldUpdates = [];
$splitter2->ApplyChanges();
$gapCaption2 = (string)(IPSModuleStrict::$formFieldUpdates['DiagSubscriptionGap']['caption'] ?? '');
check(str_contains($gapCaption2, 'none'), 'Gegenprobe: Diagnose-Label meldet keine Lücke', $gapCaption2);
check((IPSModuleStrict::$formFieldUpdates['DiagSubscriptionAlert']['visible'] ?? null) === false, 'Gegenprobe: DiagSubscriptionAlert bleibt ausgeblendet');

$bundle2 = exportBundle($splitter2);
check(($bundle2['splitter']['subscription_check'] ?? '') === 'ok', 'Gegenprobe: subscription_check = ok');
check((int)($bundle2['diagnostics']['referenced_topic_payloads']['unsubscribed'] ?? -1) === 0, 'Gegenprobe: unsubscribed = 0');
$allCovered = $referencedCount > 0;
foreach (($bundle2['referenced_topics'] ?? []) as $entry) {
    $allCovered = $allCovered && (($entry['subscription_covered'] ?? null) === true);
}
check($allCovered, 'Gegenprobe: alle referenzierten Topics als abonniert markiert');

ergebnis();
