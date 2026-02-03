<?php /** @noinspection AutoloadingIssuesInspection */

declare(strict_types=1);

require_once __DIR__ . '/../libs/HAIds.php';
require_once __DIR__ . '/../libs/HADebug.php';
require_once __DIR__ . '/../libs/HALockDefinitions.php';
require_once __DIR__ . '/../libs/HALightDefinitions.php';
require_once __DIR__ . '/../libs/HASwitchDefinitions.php';
require_once __DIR__ . '/../libs/HACoverDefinitions.php';
require_once __DIR__ . '/../libs/HANumberDefinitions.php';
require_once __DIR__ . '/../libs/HAClimateDefinitions.php';
require_once __DIR__ . '/../libs/HAVacuumDefinitions.php';

class HomeAssistantSplitter extends IPSModuleStrict
{
    use HADebugTrait;
    public function Create(): void
    {
        parent::Create();

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);

        $this->RegisterPropertyString('MQTTBaseTopic', 'homeassistant');
        $this->RegisterPropertyString('HAUrl', 'http://homeassistant.local:8123');
        $this->RegisterPropertyString('HAToken', '');
        $this->RegisterPropertyBoolean('UseRestForSetTopics', true);
        $this->RegisterPropertyInteger('RestAckTimeoutSec', 5);
        $this->RegisterPropertyBoolean('EnableExpertDebug', false);
        $this->RegisterPropertyString('DebugResponseFormat', 'json_compact');

        $this->RegisterAttributeString('LastMQTTMessage', '');
        $this->RegisterAttributeString('LastRestError', '');
        $this->RegisterAttributeString('LastRestResponse', '');
        $this->RegisterAttributeString('LastRestTimeout', '');
        $this->RegisterAttributeString('PendingRestAcks', '{}');

        $this->RegisterTimer('RestAckTimer', 0, 'HA_CheckRestAcks($_IPS["TARGET"]);');
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === FM_CONNECT || $Message === FM_DISCONNECT) {
            $this->debugExpert('MessageSink', 'Verbindungsstatus geaendert. Aktualisiere...', [], true);
            $this->ApplyChanges();
        }
    }

    public function GetCompatibleParents(): string
    {
        $parents = [
            'type'    => 'connect',
            'modules' => [
                [
                    'moduleID'      => HAIds::MODULE_MQTT_CLIENT
                ],
                [
                    'moduleID'      => HAIds::MODULE_MQTT_SERVER
                ]
            ]
        ];

        return json_encode($parents, JSON_THROW_ON_ERROR);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->SetReceiveDataFilter('.*');

        $this->updateLastMqttMessageLabel();
        $this->updateDiagnosticsLabels();
        $baseTopic = trim($this->ReadPropertyString('MQTTBaseTopic'));
        if ($baseTopic === '') {
            $this->SetStatus(202);
            $this->debugExpert('Config', 'MQTTBaseTopic ist leer. MQTT Statestream Updates kommen dann nicht an.');
            return;
        }

        if (!$this->isMqttParentActive()) {
            $this->SetStatus(201);
            $this->debugExpert('Config', 'MQTT Parent ist nicht aktiv.');
            return;
        }

        if ($this->ReadPropertyBoolean('UseRestForSetTopics') && !$this->isRestApiReachable()) {
            $this->SetStatus(203);
            $this->debugExpert('Config', 'REST API nicht erreichbar.', [
                'Reason' => $this->ReadAttributeString('LastRestError')
            ]);
            return;
        }

        $this->SetStatus(IS_ACTIVE);
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true, 512, JSON_THROW_ON_ERROR);
        return json_encode($form, JSON_THROW_ON_ERROR);
    }

    public function ForwardData(string $JSONString): string
    {
        try {
            $data = json_decode($JSONString, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->debugExpert('ForwardData', 'Invalid JSON', ['Error' => $e->getMessage()]);
            return '';
        }

        $dataId = $data['DataID'] ?? '';
        if ($dataId === HAIds::DATA_DEVICE_TO_SPLITTER) {
            if (array_key_exists('Endpoint', $data)) {
                return $this->handleRestRequest($data);
            }
            $data['DataID'] = HAIds::DATA_MQTT_TX;
            $JSONString = json_encode($data, JSON_THROW_ON_ERROR);
            $dataId = HAIds::DATA_MQTT_TX;
        }

        if ($dataId !== HAIds::DATA_MQTT_TX) {
            return $this->SendDataToParent($JSONString);
        }

        $packetType = (int)($data['PacketType'] ?? 0);
        if ($packetType === 3 && $this->ReadPropertyBoolean('UseRestForSetTopics')) {
            $topic = (string)($data['Topic'] ?? '');
            if ($this->isSetTopic($topic, $domain, $entity)) {
                $payload = $this->decodePayload((string)($data['Payload'] ?? ''));
                if ($this->sendRestCommand($domain, $entity, $payload)) {
                    $this->debugExpert('MQTT', 'Set topic handled via REST', ['Topic' => $topic]);
                    return '';
                }
            }
        }

        return $this->SendDataToParent($JSONString);
    }

    public function ReceiveData(string $JSONString): string
    {
        try {
            $data = json_decode($JSONString, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->debugExpert('ReceiveData', 'Invalid JSON', ['Error' => $e->getMessage()]);
            return '';
        }
        $dataId = $data['DataID'] ?? '';
        if ($this->ReadPropertyBoolean('EnableExpertDebug')) {
            $topic = (string)($data['Topic'] ?? '');
            $this->debugExpert('MQTT', 'ReceiveData | DataID=' . $dataId . ' | Topic=' . $topic);
        }
        if ($dataId === HAIds::DATA_MQTT_RX || $dataId === HAIds::DATA_MQTT_TX) {
            $this->WriteAttributeString('LastMQTTMessage', date('Y-m-d H:i:s'));
            $this->updateLastMqttMessageLabel();
            $topic = (string)($data['Topic'] ?? '');
            $entityId = $this->extractEntityIdFromTopic($topic);
            if ($entityId !== '') {
                $this->clearPendingRestAck($entityId);
            }
            $data['DataID'] = HAIds::DATA_SPLITTER_TO_DEVICE;
            $this->SendDataToChildren(json_encode($data, JSON_THROW_ON_ERROR));
            return '';
        }

        $this->SendDataToChildren($JSONString);
        return '';
    }

    private function updateLastMqttMessageLabel(): void
    {
        $last = $this->ReadAttributeString('LastMQTTMessage');
        if ($last === '') {
            $last = 'nie';
        }
        $this->updateFormFieldSafe('LastMQTTMessage', 'caption', 'Letzte MQTT-Message: ' . $last);
    }

    private function isMqttParentActive(): bool
    {
        $instance = IPS_GetInstance($this->InstanceID);
        $parentId = (int)($instance['ConnectionID'] ?? 0);
        if ($parentId <= 0 || !IPS_InstanceExists($parentId)) {
            $this->debugExpert('Config', 'MQTT Parent fehlt', ['ParentID' => $parentId], true);
            return false;
        }

        $parent = IPS_GetInstance($parentId);
        $status = (int)($parent['InstanceStatus'] ?? 0);
        if ($status !== IS_ACTIVE) {
            $this->debugExpert('Config', 'MQTT Parent Status', [
                'ParentID' => $parentId,
                'ParentName' => IPS_GetName($parentId),
                'Status' => $status,
                'ModuleID' => (string)($parent['ModuleInfo']['ModuleID'] ?? ''),
                'ModuleName' => (string)($parent['ModuleInfo']['ModuleName'] ?? '')
            ], true);
        }
        return $status === IS_ACTIVE;
    }

    private function isRestApiReachable(): bool
    {
        $haUrl = trim($this->ReadPropertyString('HAUrl'));
        $token = trim($this->ReadPropertyString('HAToken'));
        if ($haUrl === '' || $token === '') {
            $this->WriteAttributeString('LastRestError', 'Missing HAUrl/HAToken');
            $this->WriteAttributeString('LastRestResponse', '');
            $this->updateDiagnosticsLabels();
            return false;
        }

        $url = rtrim($haUrl, '/') . '/api/';
        $ch = curl_init($url);

        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        ];

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if ($error) {
            $this->WriteAttributeString('LastRestError', 'cURL: ' . $error);
            $this->WriteAttributeString('LastRestResponse', '');
            $this->updateDiagnosticsLabels();
            return false;
        }
        if ($response === false || $response === '') {
            $this->WriteAttributeString('LastRestError', 'Empty response');
            $this->WriteAttributeString('LastRestResponse', '');
            $this->updateDiagnosticsLabels();
            return false;
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            $this->WriteAttributeString('LastRestError', 'HTTP ' . $httpCode);
            $this->WriteAttributeString('LastRestResponse', (string)$response);
            $this->updateDiagnosticsLabels();
            return false;
        }

        if ($this->ReadAttributeString('LastRestError') !== '') {
            $this->WriteAttributeString('LastRestError', '');
        }
        $this->WriteAttributeString('LastRestResponse', '');
        $this->updateDiagnosticsLabels();
        return true;
    }

    private function isSetTopic(string $topic, ?string &$domain, ?string &$entity): bool
    {
        $domain = null;
        $entity = null;

        $parts = explode('/', trim($topic, '/'));
        if (count($parts) < 4) {
            return false;
        }

        if (end($parts) !== 'set') {
            return false;
        }

        $entity = $parts[count($parts) - 2];
        $domain = $parts[count($parts) - 3];
        return $domain !== '' && $entity !== '';
    }

    private function decodePayload(string $payloadHex): string
    {
        $decoded = hex2bin($payloadHex);
        return $decoded === false ? '' : $decoded;
    }

    private function sendRestCommand(string $domain, string $entity, string $payload): bool
    {
        $haUrl = trim($this->ReadPropertyString('HAUrl'));
        $token = trim($this->ReadPropertyString('HAToken'));
        if ($haUrl === '' || $token === '') {
            $this->debugExpert('REST', 'Missing HAUrl/HAToken, forwarding to MQTT.');
            return false;
        }

        $value = $this->normalizePayload($payload);
        [$service, $data] = $this->buildRestServicePayload($domain, $value);
        if ($service === '') {
            $this->debugExpert('REST', 'Unsupported domain for REST command: ' . $domain);
            return false;
        }

        $data['entity_id'] = $domain . '.' . $entity;
        $url = rtrim($haUrl, '/') . '/api/services/' . $domain . '/' . $service;
        $postData = json_encode($data, JSON_THROW_ON_ERROR);

        $this->debugExpert('REST', 'Send command', ['Url' => $url, 'Data' => $data]);
        $ok = $this->sendHaRequest($url, $token, $postData);
        if ($ok) {
            $this->addPendingRestAck($domain . '.' . $entity, $service);
        }
        return $ok;
    }

    private function normalizePayload(string $payload): mixed
    {
        $trimmed = trim($payload);
        $upper = strtoupper($trimmed);
        if ($upper === 'ON') {
            return true;
        }
        if ($upper === 'OFF') {
            return false;
        }

        if ($trimmed !== '' && is_numeric($trimmed)) {
            return (float)$trimmed;
        }

        try {
            return json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // Non-JSON payloads are handled below.
        }

        return $payload;
    }

    private function buildRestServicePayload(string $domain, mixed $value): array
    {
        if (is_array($value) && $domain === HALightDefinitions::DOMAIN) {
            $data = $value;
            $service = 'turn_on';
            if (array_key_exists('state', $data)) {
                $state = $data['state'];
                unset($data['state']);
                if ($state === false || $state === 0 || strtoupper((string)$state) === 'OFF') {
                    $service = 'turn_off';
                }
            }
            return [$service, $data];
        }

        if ($domain === HAVacuumDefinitions::DOMAIN) {
            if (is_array($value)) {
                if (isset($value['fan_speed'])) {
                    return ['set_fan_speed', ['fan_speed' => (string)$value['fan_speed']]];
                }
                if (isset($value['command'])) {
                    $data = ['command' => (string)$value['command']];
                    if (isset($value['params'])) {
                        $data['params'] = $value['params'];
                    }
                    return ['send_command', $data];
                }
            }

            if (is_bool($value)) {
                return [$value ? 'start' : 'stop', []];
            }

            $command = strtolower(trim((string)$value));
            if ($command === 'clean' || $command === 'start' || $command === 'on') {
                return ['start', []];
            }
            if ($command === 'stop' || $command === 'off') {
                return ['stop', []];
            }
            if ($command === 'pause') {
                return ['pause', []];
            }
            if ($command === 'return' || $command === 'return_to_base' || $command === 'dock' || $command === 'home') {
                return ['return_to_base', []];
            }
            if ($command === 'clean_spot' || $command === 'spot') {
                return ['clean_spot', []];
            }
            if ($command === 'locate') {
                return ['locate', []];
            }
            if ($command !== '') {
                return ['set_fan_speed', ['fan_speed' => $command]];
            }
        }

        if ($domain === HALockDefinitions::DOMAIN) {
            $command = HALockDefinitions::normalizeCommand($value);
            if ($command === '') {
                return ['', []];
            }
            return [$command, []];
        }

        return match ($domain) {
            HALightDefinitions::DOMAIN, HASwitchDefinitions::DOMAIN => [$value ? 'turn_on' : 'turn_off', []],
            HACoverDefinitions::DOMAIN => [$value ? 'open_cover' : 'close_cover', []],
            HANumberDefinitions::DOMAIN => ['set_value', ['value' => (float)$value]],
            HAClimateDefinitions::DOMAIN => ['set_temperature', ['temperature' => (float)$value]],
            default => ['', []],
        };
    }

    private function sendHaRequest(string $url, string $token, string $postData): bool
    {
        $ch = curl_init($url);

        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        ];

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if ($error) {
            $this->debugExpert('REST', "cURL Error: $error");
            $this->WriteAttributeString('LastRestError', 'cURL: ' . $error);
            $this->WriteAttributeString('LastRestResponse', 'HTTP ' . $httpCode);
            $this->updateDiagnosticsLabels();
            return false;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $this->debugExpert('REST', "HTTP Error: $httpCode | Response: $response");
            $this->WriteAttributeString('LastRestError', 'HTTP ' . $httpCode);
            $this->WriteAttributeString('LastRestResponse', $this->formatRestResponse($httpCode, $response));
            $this->updateDiagnosticsLabels();
            return false;
        }

        if ($this->ReadAttributeString('LastRestError') !== '') {
            $this->WriteAttributeString('LastRestError', '');
        }
        $this->WriteAttributeString('LastRestResponse', $this->formatRestResponse($httpCode, $response));
        $this->updateDiagnosticsLabels();
        return true;
    }

    private function handleRestRequest(array $data): string
    {
        $endpoint = (string)($data['Endpoint'] ?? '');
        $method = strtoupper((string)($data['Method'] ?? 'POST'));
        $body = $data['Body'] ?? null;

        if ($endpoint === '') {
            return json_encode(['Error' => 'Missing endpoint'], JSON_THROW_ON_ERROR);
        }

        $haUrl = trim($this->ReadPropertyString('HAUrl'));
        $token = trim($this->ReadPropertyString('HAToken'));
        if ($haUrl === '' || $token === '') {
            return json_encode(['Error' => 'Missing HAUrl/HAToken'], JSON_THROW_ON_ERROR);
        }

        $url = rtrim($haUrl, '/') . $endpoint;

        $this->debugExpert('REST', 'Handle request', ['Endpoint' => $endpoint, 'Method' => $method]);
        $result = $this->sendHaRequestRaw($url, $token, is_string($body) ? $body : null, $method);
        $this->storeRestDiagnostics($result);
        if ($this->ReadPropertyBoolean('EnableExpertDebug')) {
            $httpCode = $result['HttpCode'] ?? 'n/a';
            $response = $this->formatDebugResponse((string)($result['Response'] ?? ''));
            $this->debugExpert('REST', 'Response | HttpCode=' . $httpCode . ' | ' . $response);
        }
        return json_encode($result, JSON_THROW_ON_ERROR);
    }

    private function sendHaRequestRaw(string $url, string $token, ?string $postData, string $method): array
    {
        $ch = curl_init($url);

        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        ];

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        if ($method === 'POST' && $postData !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if ($error) {
            $this->debugExpert('REST', 'cURL error', ['Error' => $error, 'HttpCode' => $httpCode]);
            return ['Error' => $error, 'HttpCode' => $httpCode, 'Response' => $response];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $this->debugExpert('REST', 'HTTP error', ['HttpCode' => $httpCode, 'Response' => $response]);
            return ['Error' => 'HTTP Error', 'HttpCode' => $httpCode, 'Response' => $response];
        }

        return ['HttpCode' => $httpCode, 'Response' => $response];
    }

    private function storeRestDiagnostics(array $result): void
    {
        $error = $result['Error'] ?? '';
        $httpCode = $result['HttpCode'] ?? '';
        $response = (string)($result['Response'] ?? '');
        $message = '';
        if ($error !== '') {
            $message = 'cURL: ' . $error;
        } elseif (is_numeric($httpCode) && ((int)$httpCode < 200 || (int)$httpCode >= 300)) {
            $message = 'HTTP ' . $httpCode;
            if ($response !== '') {
                $message .= ' | ' . $this->truncateResponse($response, 200, true);
            }
        }
        $this->WriteAttributeString('LastRestError', $message);
        if ($httpCode !== '') {
            $this->WriteAttributeString('LastRestResponse', $this->formatRestResponse((int)$httpCode, $response));
        } elseif ($error !== '') {
            $this->WriteAttributeString('LastRestResponse', 'cURL error');
        }
        $this->updateDiagnosticsLabels();
    }

    private function formatDebugResponse(string $response): string
    {
        $format = $this->ReadPropertyString('DebugResponseFormat');
        if ($format === '') {
            $format = 'json_compact';
        }

        $text = $response;
        $compactWhitespace = true;

        if ($format === 'json_compact' || $format === 'json_pretty') {
            try {
                $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $decoded = null;
            }
            if (is_array($decoded)) {
                $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
                if ($format === 'json_pretty') {
                    $text = json_encode($decoded, JSON_THROW_ON_ERROR | $flags | JSON_PRETTY_PRINT);
                    $compactWhitespace = false;
                } else {
                    $text = json_encode($decoded, JSON_THROW_ON_ERROR | $flags);
                }
            }
        } elseif ($format === 'raw') {
            $compactWhitespace = false;
        }

        return $this->truncateResponse($text, 400, $compactWhitespace);
    }

    private function truncateResponse(string $response, int $maxLength = 400, bool $compactWhitespace = true): string
    {
        if ($compactWhitespace) {
            $response = preg_replace('/\s+/', ' ', $response) ?? $response;
        }
        $response = trim($response);

        if (strlen($response) <= $maxLength) {
            return $response;
        }

        return substr($response, 0, $maxLength) . '...';
    }

    private function formatRestResponse(int $httpCode, string $response): string
    {
        $text = $response;
        if ($text !== '') {
            $text = $this->truncateResponse($text, 200, true);
        }
        return 'HTTP ' . $httpCode . ($text !== '' ? ' | ' . $text : '');
    }

    private function updateDiagnosticsLabels(): void
    {
        $instance = IPS_GetInstance($this->InstanceID);
        $parentId = (int)($instance['ConnectionID'] ?? 0);
        $parentStatus = 0;
        $parentName = '';
        if ($parentId > 0 && IPS_InstanceExists($parentId)) {
            $parent = IPS_GetInstance($parentId);
            $parentStatus = (int)($parent['InstanceStatus'] ?? 0);
            $parentName = IPS_GetName($parentId);
        }

        $baseTopic = trim($this->ReadPropertyString('MQTTBaseTopic'));
        $statusName = $this->getInstanceStatusName($parentStatus);
        $nameSuffix = $parentName !== '' ? ' (' . $parentName . ')' : '';
        $statusSuffix = $statusName !== '' ? '(' . $statusName .')' : '';
        $this->updateFormFieldSafe(
            'DiagParent',
            'caption',
            'MQTT Parent: ' . $parentId . $nameSuffix . ' | Status ' . $parentStatus . $statusSuffix
        );
        $this->updateFormFieldSafe('DiagBaseTopic', 'caption', 'MQTT Base Topic: ' . ($baseTopic !== '' ? $baseTopic : 'leer'));

        $lastRestError = $this->ReadAttributeString('LastRestError');
        if ($lastRestError === '') {
            $lastRestError = 'keiner';
        }
        $this->updateFormFieldSafe('DiagRest', 'caption', 'Letzter REST-Fehler: ' . $lastRestError);

        $lastRestResponse = $this->ReadAttributeString('LastRestResponse');
        if ($lastRestResponse === '') {
            $lastRestResponse = 'keine';
        }
        $this->updateFormFieldSafe('DiagRestResponse', 'caption', 'Letzte REST-Antwort: ' . $lastRestResponse);

        $lastRestTimeout = $this->ReadAttributeString('LastRestTimeout');
        if ($lastRestTimeout === '') {
            $lastRestTimeout = 'keiner';
        }
        $this->updateFormFieldSafe('DiagRestTimeout', 'caption', 'Letzter REST-Timeout: ' . $lastRestTimeout);
    }

    private function getInstanceStatusName(int $status): string
    {
        return match ($status) {
            IS_CREATING => 'IS_CREATING',
            IS_ACTIVE => 'IS_ACTIVE',
            IS_DELETING => 'IS_DELETING',
            IS_INACTIVE => 'IS_INACTIVE',
            IS_NOTCREATED => 'IS_NOTCREATED',
            default => ''
        };
    }

    /** @noinspection PhpUnused */
    public function CheckRestAcks(): void
    {
        $timeoutSec = $this->ReadPropertyInteger('RestAckTimeoutSec');
        if ($timeoutSec <= 0) {
            $this->SetTimerInterval('RestAckTimer', 0);
            return;
        }

        $pending = $this->readPendingRestAcks();
        if ($pending === []) {
            $this->SetTimerInterval('RestAckTimer', 0);
            return;
        }

        $now = time();
        $changed = false;
        foreach ($pending as $entityId => $info) {
            $ts = (int)($info['ts'] ?? 0);
            $service = (string)($info['service'] ?? '');
            if ($ts > 0 && ($now - $ts) >= $timeoutSec) {
                $message = $entityId;
                if ($service !== '') {
                    $message .= ' | ' . $service;
                }
                $message .= ' | ' . $timeoutSec . 's';
                $this->WriteAttributeString('LastRestTimeout', $message);
                unset($pending[$entityId]);
                $changed = true;
            }
        }

        if ($changed) {
            $this->writePendingRestAcks($pending);
            $this->updateDiagnosticsLabels();
        }

        if ($pending === []) {
            $this->SetTimerInterval('RestAckTimer', 0);
        }
    }

    private function addPendingRestAck(string $entityId, string $service): void
    {
        if ($entityId === '') {
            return;
        }
        $pending = $this->readPendingRestAcks();
        $pending[$entityId] = [
            'ts' => time(),
            'service' => $service
        ];
        $this->writePendingRestAcks($pending);
        $this->SetTimerInterval('RestAckTimer', 1000);
    }

    private function clearPendingRestAck(string $entityId): void
    {
        if ($entityId === '') {
            return;
        }
        $pending = $this->readPendingRestAcks();
        if (!array_key_exists($entityId, $pending)) {
            return;
        }
        unset($pending[$entityId]);
        $this->writePendingRestAcks($pending);
        if ($pending === []) {
            $this->SetTimerInterval('RestAckTimer', 0);
        }
    }

    private function readPendingRestAcks(): array
    {
        $json = $this->ReadAttributeString('PendingRestAcks');
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return is_array($data) ? $data : [];
    }

    private function writePendingRestAcks(array $pending): void
    {
        $this->WriteAttributeString('PendingRestAcks', json_encode($pending, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function extractEntityIdFromTopic(string $topic): string
    {
        $baseTopic = trim($this->ReadPropertyString('MQTTBaseTopic'));
        if ($baseTopic === '' || $topic === '') {
            return '';
        }

        $parts = explode('/', trim($topic, '/'));
        $baseParts = explode('/', trim($baseTopic, '/'));
        if (count($parts) < count($baseParts) + 2) {
            return '';
        }

        for ($i = 0, $iMax = count($baseParts); $i < $iMax; $i++) {
            if ($parts[$i] !== $baseParts[$i]) {
                return '';
            }
        }

        $domain = $parts[count($baseParts)] ?? '';
        $entity = $parts[count($baseParts) + 1] ?? '';
        if ($domain === '' || $entity === '') {
            return '';
        }
        return $domain . '.' . $entity;
    }

    private function updateFormFieldSafe(string $name, string $property, mixed $value): void
    {
        @ $this->UpdateFormField($name, $property, $value);
    }
}
