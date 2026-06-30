<?php /** @noinspection PhpUnused */

/** @noinspection AutoloadingIssuesInspection */

declare(strict_types=1);

require_once __DIR__ . '/../libs/HACommonIncludes.php';

class HomeAssistantSplitter extends IPSModuleStrict
{
    use HAParentConnectionTrait;
    use ModuleDebugTrait;
    use HASupportedFeaturesTrait;
    use HADiagnosticsTrait;

    private const string TIMER_RESTACK = 'RestAckTimer';
    private const string TIMER_TOPIC_STATS = 'TopicStatsTimer';

    // Debounce der Diagnose-Anzeige (einheitlich mit dem Discovery-Splitter): hochfrequente Ausloeser
    // setzen nur ein Dirty-Flag und bewaffnen den Timer; das teure updateDiagnosticsLabels() laeuft dann
    // gebuendelt einmal pro Intervall statt pro Ausloeser.
    private const string TIMER_DIAGNOSTICS_REFRESH   = 'DiagnosticsRefresh';
    private const string ATTRIBUTE_DIAGNOSTICS_DIRTY = 'DiagnosticsDirty';
    private const int DIAGNOSTICS_REFRESH_INTERVAL_MS = 1000;

    // Opt-in Topic-Statistik: zaehlt eingehende Messages je Entitaet und gibt sie periodisch aggregiert aus
    // (statt pro Message zu loggen). Der Zustand MUSS in Buffern liegen: ReceiveData (Zaehlen) und der
    // Timer (DumpTopicStatistics) laufen in getrennten PHP-Ausfuehrungen, Member-Variablen ueberleben das
    // nicht. Ohne Buffer waere der Zaehler beim Dump immer leer und der Fensterstart auf 0 (=> riesiges
    // "Fenster <epoch>s | total=0").
    private const string BUFFER_TOPIC_STATS_COUNTS = 'TopicStatsCounts';
    private const string BUFFER_TOPIC_STATS_START  = 'TopicStatsWindowStart';

    // Das "Last MQTT message"-Label ist reine Diagnose. Bei ~13 Messages/Sek. wuerde ein
    // WriteAttributeString + UpdateFormField pro Message dauerhaft messbare Last erzeugen, ohne
    // Mehrwert (das Label hat Sekunden-Granularitaet). Daher hoechstens alle paar Sekunden schreiben.
    // Der Drossel-Zeitstempel MUSS im Buffer liegen: ReceiveData laeuft ueber getrennte
    // PHP-Ausfuehrungen, Member-Variablen ueberleben das nicht.
    private const string BUFFER_LAST_MQTT_TOUCH    = 'LastMqttTouchEpoch';
    private const int LAST_MQTT_LABEL_THROTTLE_SEC = 5;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);
        $this->SetReceiveDataFilter('^$');

        $this->RegisterPropertyString('MQTTBaseTopic', 'homeassistant');
        $this->RegisterPropertyString('HAUrl', 'http://homeassistant.local:8123');
        $this->RegisterPropertyString('HAToken', '');
        $this->RegisterPropertyInteger('RestAckTimeoutSec', 5);
        $this->RegisterPropertyBoolean('EnableExpertDebug', false);
        $this->RegisterPropertyBoolean('EnablePerformanceLog', false);
        $this->RegisterPropertyBoolean('EnableTopicStatistics', false);
        $this->RegisterPropertyInteger('TopicStatisticsIntervalMinutes', 15);
        $this->RegisterPropertyString('DebugResponseFormat', 'json_compact');
        $this->RegisterPropertyInteger('OutputBufferSize', 10);

        $this->RegisterAttributeString('LastMQTTMessage', '');
        $this->RegisterAttributeString('LastRestError', '');
        $this->RegisterAttributeString('LastRestResponse', '');
        $this->RegisterAttributeString('LastRestTimeout', '');
        $this->RegisterAttributeString('PendingRestAcks', '{}');
        $this->RegisterAttributeBoolean(self::ATTRIBUTE_DIAGNOSTICS_DIRTY, false);

        $this->RegisterTimer(self::TIMER_RESTACK, 0, 'HA_CheckRestAcks($_IPS["TARGET"]);');
        $this->RegisterTimer(self::TIMER_TOPIC_STATS, 0, 'HA_DumpTopicStatistics($_IPS["TARGET"]);');
        $this->RegisterTimer(self::TIMER_DIAGNOSTICS_REFRESH, 0, 'HA_RefreshDiagnostics($_IPS["TARGET"]);');
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if (($Message === IPS_KERNELMESSAGE) && (($Data[0] ?? null) === KR_READY)) {
            $this->debugExpert('MessageSink', 'Kernel bereit. Aktualisiere...', [], true);
            $this->ApplyChanges();
            return;
        }

        if (!$this->isModuleRuntimeReady()) {
            return;
        }

        if ($Message === FM_CONNECT || $Message === FM_DISCONNECT || $Message === IM_CHANGESTATUS) {
            $this->debugExpert('MessageSink', 'Verbindungsstatus geändert. Aktualisiere...', [], true);
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

    public function GetConfigurationForm(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->applyCurrentDiagnosticsToForm($form);
        return json_encode($form, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->SetTimerInterval(self::TIMER_DIAGNOSTICS_REFRESH, 0);
        $this->syncParentStatusMessageRegistration();
        if (!$this->isKernelReady()) {
            $this->debugExpert('ApplyChanges', 'Kernel noch nicht bereit. Initialisierung wird bis KR_READY verschoben.', [], true);
            return;
        }
        $this->SetReceiveDataFilter('.*');

        $this->applyTopicStatisticsConfiguration();
        $this->updateLastMqttMessageLabel();
        $this->updateDiagnosticsLabels();
        $baseTopic = trim($this->ReadPropertyString('MQTTBaseTopic'));
        if ($baseTopic === '') {
            $this->SetStatus(202);
            $this->debugExpert('Config', 'MQTTBaseTopic ist leer. MQTT Statestream Updates kommen dann nicht an.');
            return;
        }

        if (!$this->hasCompatibleParentModules([HAIds::MODULE_MQTT_CLIENT, HAIds::MODULE_MQTT_SERVER])) {
            $this->SetStatus(201);
            $this->debugExpert('Config', 'MQTT Parent ist nicht kompatibel.', $this->buildCurrentParentDebugContext(), true);
            return;
        }

        if (!$this->hasCompatibleActiveParentModules([HAIds::MODULE_MQTT_CLIENT, HAIds::MODULE_MQTT_SERVER])) {
            $this->SetStatus(201);
            $this->debugExpert('Config', 'MQTT Parent ist nicht aktiv.', $this->buildCurrentParentDebugContext(), true);
            return;
        }

        if (!$this->isRestApiReachable()) {
            $this->SetStatus(203);
            $this->debugExpert('Config', 'REST API nicht erreichbar.', [
                'Reason' => $this->ReadAttributeString('LastRestError')
            ]);
            return;
        }

        $this->SetStatus(IS_ACTIVE);
    }

    public function ForwardData(string $JSONString): string
    {
        if (!$this->isModuleRuntimeReady()) {
            return '';
        }
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
            if (array_key_exists('ImageUrl', $data)) {
                return $this->handleImageRequest($data);
            }
            $data['DataID'] = HAIds::DATA_MQTT_TX;
            $JSONString = json_encode($data, JSON_THROW_ON_ERROR);
            $dataId = HAIds::DATA_MQTT_TX;
        }

        if ($dataId !== HAIds::DATA_MQTT_TX) {
            return $this->SendDataToParent($JSONString);
        }

        // `*/set`-Topics werden im klassischen Bridge-Pfad immer über die HA-REST-API
        // ausgeführt, da `mqtt_statestream` rein ausgehend ist und der Broker keine
        // Command-Topics konsumiert. sendRestCommand() fällt bei fehlender REST-Konfiguration
        // oder nicht unterstützter Domain selbst auf die MQTT-Weiterleitung zurück.
        $packetType = (int)($data['PacketType'] ?? 0);
        if ($packetType === 3) {
            $topic = (string)($data['Topic'] ?? '');
            if ($this->isSetTopic($topic, $domain, $entity)) {
                $payload = $this->decodePayload((string)($data['Payload'] ?? ''));
                if ($this->sendRestCommand($domain, $entity, $payload)) {
                    $this->debugExpert('MQTT', 'Set topic handled via REST', ['Topic' => $topic]);
                    return '';
                }
            }
        }

        $topic = (string)($data['Topic'] ?? '');
        $this->debugExpert('MQTT', 'Weiterleitung an MQTT-Broker', ['Topic' => $topic]);
        return $this->SendDataToParent($JSONString);
    }

    public function ReceiveData(string $JSONString): string
    {
        if (!$this->isModuleRuntimeReady()) {
            return '';
        }
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
            $topic = (string)($data['Topic'] ?? '');

            // Statistik VOR dem Bookkeeping-Drop zaehlen, damit die echte eingehende Last je Geraet sichtbar
            // wird (inkl. der Topics, die wir gleich verwerfen).
            if ($this->isTopicStatisticsEnabled()) {
                $this->recordTopicStatistic($topic);
            }

            // Universelle HA-Bookkeeping-Topics (Zeitstempel etc.) werden von keinem Device benoetigt und
            // dort ohnehin verworfen. Sie hier vor dem Broadcast abzuweisen erspart die teure synchrone
            // Weiterleitung an alle Kinder (jede Weiterleitung kostet messbar, das Ergebnis ist null).
            if (HADomainCatalog::isIgnorableBookkeepingTopic($topic)) {
                return '';
            }

            $messageStartedAt = microtime(true);

            $stepStartedAt = microtime(true);
            $this->touchLastMqttMessage();
            if (str_ends_with($topic, '/state')) {
                $state = $this->decodePayload((string)($data['Payload'] ?? ''));
                $this->debugExpert('MQTT', 'State-Topic empfangen', ['Topic' => $topic, 'State' => $state]);
            }
            $entityId = $this->extractEntityIdFromTopic($topic);
            if ($entityId !== '') {
                $this->clearPendingRestAck($entityId);
            }
            $this->logPerformanceSample('ReceiveData.ownWork', $stepStartedAt, ['topic' => $topic]);

            $data['DataID'] = HAIds::DATA_SPLITTER_TO_DEVICE;
            $stepStartedAt = microtime(true);
            $this->SendDataToChildren(json_encode($data, JSON_THROW_ON_ERROR));
            $this->logPerformanceSample('ReceiveData.sendToChildren', $stepStartedAt, ['topic' => $topic]);

            $this->logPerformanceSample('ReceiveData.total', $messageStartedAt, ['topic' => $topic]);
            return '';
        }

        $this->SendDataToChildren($JSONString);
        return '';
    }

    /** @noinspection PhpUnused */
    public function CallService(string $domain, string $service, array $data): bool
    {
        $domain = trim($domain);
        $service = trim($service);
        if ($domain === '' || $service === '') {
            $this->debugExpert('REST', __FUNCTION__, ['domain/service missing']);
            return false;
        }

        $haUrl = trim($this->ReadPropertyString('HAUrl'));
        $token = trim($this->ReadPropertyString('HAToken'));
        if ($haUrl === '' || $token === '') {
            $this->debugExpert('REST', __FUNCTION__, ['Missing HAUrl/HAToken']);
            return false;
        }

        $url = rtrim($haUrl, '/') . '/api/services/' . $domain . '/' . $service;
        $postData = json_encode($data, JSON_THROW_ON_ERROR);
        $this->debugExpert('REST', __FUNCTION__, ['Url' => $url, 'Data' => $data]);
        $ok = $this->sendHaRequest($url, $token, $postData);
        if ($ok && isset($data['entity_id']) && is_string($data['entity_id']) && $data['entity_id'] !== '') {
            $this->addPendingRestAck($data['entity_id'], $service);
        }
        return $ok;
    }

    private function isPerformanceLogEnabled(): bool
    {
        return (bool)@$this->ReadPropertyBoolean('EnablePerformanceLog');
    }

    private function logPerformanceSample(string $scope, float $startedAt, array $context = []): void
    {
        if (!$this->isPerformanceLogEnabled()) {
            return;
        }

        $context = ['elapsed_ms' => round((microtime(true) - $startedAt) * 1000.0, 3)] + $context;
        $this->SendDebug('Performance', $scope . ' | ' . json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0);
    }

    private function isTopicStatisticsEnabled(): bool
    {
        return (bool)@$this->ReadPropertyBoolean('EnableTopicStatistics');
    }

    private function applyTopicStatisticsConfiguration(): void
    {
        $enabled = $this->isTopicStatisticsEnabled();
        // Fenster bei jedem ApplyChanges neu starten und Zaehler leeren.
        $this->SetBuffer(self::BUFFER_TOPIC_STATS_COUNTS, '');
        $this->SetBuffer(self::BUFFER_TOPIC_STATS_START, $enabled ? (string)time() : '');

        if (!$enabled) {
            $this->SetTimerInterval(self::TIMER_TOPIC_STATS, 0);
            return;
        }

        $minutes = max(1, (int)@$this->ReadPropertyInteger('TopicStatisticsIntervalMinutes'));
        $this->SetTimerInterval(self::TIMER_TOPIC_STATS, $minutes * 60 * 1000);
    }

    private function recordTopicStatistic(string $topic): void
    {
        $key = $this->statisticsKeyForTopic($topic);
        if ($key === '') {
            return;
        }
        // Fensterstart faul setzen, falls ApplyChanges ihn (noch) nicht gesetzt hat.
        if ($this->GetBuffer(self::BUFFER_TOPIC_STATS_START) === '') {
            $this->SetBuffer(self::BUFFER_TOPIC_STATS_START, (string)time());
        }
        $counts = $this->loadTopicStatsCounts();
        $counts[$key] = ($counts[$key] ?? 0) + 1;
        $this->SetBuffer(self::BUFFER_TOPIC_STATS_COUNTS, json_encode($counts, JSON_THROW_ON_ERROR));
    }

    private function loadTopicStatsCounts(): array
    {
        $raw = $this->GetBuffer(self::BUFFER_TOPIC_STATS_COUNTS);
        if ($raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }
        return is_array($decoded) ? $decoded : [];
    }

    // Schluessel = Topic ohne letztes Segment (Attribut-/State-Suffix) => eine Entitaet, alle ihre
    // Sub-Topics zaehlen zusammen. Geraete-Gruppierung erfolgt beim Dump ueber den gemeinsamen Praefix.
    private function statisticsKeyForTopic(string $topic): string
    {
        $topic = trim($topic, '/');
        if ($topic === '') {
            return '';
        }
        $pos = strrpos($topic, '/');
        return $pos === false ? $topic : substr($topic, 0, $pos);
    }

    /** @noinspection PhpUnused */
    public function DumpTopicStatistics(): void
    {
        $now = time();
        $startedAt = (int)$this->GetBuffer(self::BUFFER_TOPIC_STATS_START);
        $elapsed = $startedAt > 0 ? max(1, $now - $startedAt) : 0;
        $counts = $this->loadTopicStatsCounts();
        $this->SetBuffer(self::BUFFER_TOPIC_STATS_COUNTS, '');
        $this->SetBuffer(self::BUFFER_TOPIC_STATS_START, (string)$now);

        $total = array_sum($counts);
        if ($total === 0) {
            $this->SendDebug('TopicStats', sprintf('Fenster %ds | total=0 (keine Messages)', $elapsed), 0);
            return;
        }

        // Pro Geraet gruppieren: Objekt-ID (letztes Segment der Entity-Keys) ueber gemeinsamen Praefix
        // clustern, damit z. B. alle marstek_*-Entitaeten domainuebergreifend in einer Zeile zusammenlaufen.
        // (Clustern der vollen Keys wuerde am gemeinsamen "<base>/<domain>/" alles zusammenwerfen.)
        $byObjectId = [];
        foreach ($counts as $entityKey => $n) {
            $pos = strrpos((string)$entityKey, '/');
            $objectId = $pos === false ? (string)$entityKey : substr((string)$entityKey, $pos + 1);
            $byObjectId[$objectId] = ($byObjectId[$objectId] ?? 0) + $n;
        }

        $objectIds = array_keys($byObjectId);
        sort($objectIds, SORT_STRING);
        $devices = [];
        foreach (HADomainCatalog::clusterByCommonPrefix($objectIds, 3) as $cluster) {
            $members = $cluster['members'];
            $deviceKey = count($members) >= 2 ? $cluster['prefix'] . '*' : ($members[0] ?? '');
            $sum = 0;
            foreach ($members as $m) {
                $sum += $byObjectId[$m] ?? 0;
            }
            $devices[$deviceKey] = ($devices[$deviceKey] ?? 0) + $sum;
        }
        arsort($devices);

        $perMin = static fn(int $n): string => number_format($n / ($elapsed / 60.0), 1, '.', '');
        $header = sprintf(
            'Fenster %ds | total=%d (%s/min) | Entitaeten=%d | Geraete=%d',
            $elapsed,
            $total,
            $perMin($total),
            count($counts),
            count($devices)
        );
        $this->SendDebug('TopicStats', $header, 0);

        $rank = 0;
        foreach ($devices as $deviceKey => $sum) {
            if (++$rank > 20) {
                $this->SendDebug('TopicStats', sprintf('  ... (%d weitere Geraete)', count($devices) - 20), 0);
                break;
            }
            $this->SendDebug('TopicStats', sprintf('  %-50s %6d (%s/min)', $deviceKey, $sum, $perMin($sum)), 0);
        }
    }

    // Schreibt das LastMQTTMessage-Attribut hoechstens alle LAST_MQTT_LABEL_THROTTLE_SEC Sekunden, damit die
    // Diagnose nicht pro eingehender Message persistiert (siehe BUFFER_LAST_MQTT_TOUCH). Die Anzeige wird nur
    // bei tatsaechlicher Aenderung angestossen und ueber scheduleDiagnosticsRefresh() gedebounced refresht.
    private function touchLastMqttMessage(): void
    {
        $now = time();
        $last = (int)$this->GetBuffer(self::BUFFER_LAST_MQTT_TOUCH);
        if ($last > 0 && ($now - $last) < self::LAST_MQTT_LABEL_THROTTLE_SEC) {
            return;
        }
        $this->SetBuffer(self::BUFFER_LAST_MQTT_TOUCH, (string)$now);
        $this->WriteAttributeString('LastMQTTMessage', date('Y-m-d H:i:s', $now));
        $this->scheduleDiagnosticsRefresh();
    }

    // Buendelt hochfrequente Diagnose-Aktualisierungen: nur Dirty-Flag setzen und den Timer bewaffnen.
    // Einheitlich mit dem Discovery-Splitter (RefreshDiagnostics konsumiert das Flag).
    private function scheduleDiagnosticsRefresh(): void
    {
        $this->WriteAttributeBoolean(self::ATTRIBUTE_DIAGNOSTICS_DIRTY, true);
        if ($this->GetTimerInterval(self::TIMER_DIAGNOSTICS_REFRESH) <= 0) {
            $this->SetTimerInterval(self::TIMER_DIAGNOSTICS_REFRESH, self::DIAGNOSTICS_REFRESH_INTERVAL_MS);
        }
    }

    /** @noinspection PhpUnused */
    public function RefreshDiagnostics(): void
    {
        $this->SetTimerInterval(self::TIMER_DIAGNOSTICS_REFRESH, 0);
        if (!$this->ReadAttributeBoolean(self::ATTRIBUTE_DIAGNOSTICS_DIRTY)) {
            return;
        }
        $this->WriteAttributeBoolean(self::ATTRIBUTE_DIAGNOSTICS_DIRTY, false);
        $this->updateDiagnosticsLabels();
    }

    private function updateLastMqttMessageLabel(): void
    {
        $last = $this->ReadAttributeString('LastMQTTMessage');
        if ($last === '') {
            $last = $this->Translate('never');
        }
        $this->updateFormFieldSafe(
            'LastMQTTMessage',
            'caption',
            sprintf($this->Translate('Last MQTT message: %s'), $last)
        );
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
        return match ($domain) {
            HALightDefinitions::DOMAIN => HALightDefinitions::buildRestServicePayload($value),
            HAButtonDefinitions::DOMAIN, HAInputButtonDefinitions::DOMAIN => HAButtonDefinitions::buildRestServicePayload($value),
            HAVacuumDefinitions::DOMAIN => HAVacuumDefinitions::buildRestServicePayload($value),
            HALawnMowerDefinitions::DOMAIN => HALawnMowerDefinitions::buildRestServicePayload($value),
            HALockDefinitions::DOMAIN => HALockDefinitions::buildRestServicePayload($value),
            HACoverDefinitions::DOMAIN => HACoverDefinitions::buildRestServicePayload($value),
            HAFanDefinitions::DOMAIN => HAFanDefinitions::buildRestServicePayload($value),
            HAHumidifierDefinitions::DOMAIN => HAHumidifierDefinitions::buildRestServicePayload($value),
            HAMediaPlayerDefinitions::DOMAIN => HAMediaPlayerDefinitions::buildRestServicePayload($value),
            HASwitchDefinitions::DOMAIN, 'input_boolean' => HASwitchDefinitions::buildRestServicePayload($value),
            HASelectDefinitions::DOMAIN, 'input_select' => HASelectDefinitions::buildRestServicePayload($value),
            HANumberDefinitions::DOMAIN, 'input_number' => HANumberDefinitions::buildRestServicePayload($value),
            HAInputTextDefinitions::DOMAIN => HAInputTextDefinitions::buildRestServicePayload($value),
            HADateTimeDefinitions::DOMAIN => HADateTimeDefinitions::buildRestServicePayload($value),
            HAInputDateTimeDefinitions::DOMAIN => HAInputDateTimeDefinitions::buildRestServicePayload($value),
            HAClimateDefinitions::DOMAIN => HAClimateDefinitions::buildRestServicePayload($value),
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
        if (!isset($result['Error']) && isset($result['Response'])) {
            $result['Response'] = $this->mapSupportedFeaturesResponse((string)$result['Response']);
        }
        $this->storeRestDiagnostics($result);
        if ($this->ReadPropertyBoolean('EnableExpertDebug')) {
            $httpCode = $result['HttpCode'] ?? 'n/a';
            $response = $this->formatDebugResponse((string)($result['Response'] ?? ''));
            $this->debugExpert('REST', 'Response | HttpCode=' . $httpCode . ' | ' . $response);
        }
        return json_encode($result, JSON_THROW_ON_ERROR);
    }

    private function handleImageRequest(array $data): string
    {
        $url = trim((string)($data['ImageUrl'] ?? ''));
        if ($url === '') {
            return json_encode(['Error' => 'Missing ImageUrl'], JSON_THROW_ON_ERROR);
        }

        $haUrl = trim($this->ReadPropertyString('HAUrl'));
        $token = trim($this->ReadPropertyString('HAToken'));
        if ($haUrl === '' || $token === '') {
            return json_encode(['Error' => 'Missing HAUrl/HAToken'], JSON_THROW_ON_ERROR);
        }

        if (!preg_match('#^https?://#i', $url)) {
            $url = rtrim($haUrl, '/') . '/' . ltrim($url, '/');
        }

        $result = $this->sendHaImageRequest($url, $token);
        return $this->encodeImageResponseForTransport($result);
    }

    private function sendHaImageRequest(string $url, string $token): array
    {
        $ch = curl_init($url);

        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: image/*',
            'User-Agent: IPS-HomeAssistant'
        ];

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);

        if ($error) {
            $this->debugExpert('REST', 'Image cURL error', ['Error' => $error, 'HttpCode' => $httpCode]);
            return ['Error' => $error, 'HttpCode' => $httpCode];
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $this->debugExpert('REST', 'Image HTTP error', ['HttpCode' => $httpCode]);
            return ['Error' => 'HTTP Error', 'HttpCode' => $httpCode];
        }

        $response = (string)$response;
        $bufferSizeMb = max(0, $this->ReadPropertyInteger('OutputBufferSize'));
        $maxBytes = $bufferSizeMb > 0 ? $bufferSizeMb * 1024 * 1024 : 720 * 1024;
        if (strlen($response) > $maxBytes) {
            $this->debugExpert('REST', 'Image too large', ['Bytes' => strlen($response), 'Limit' => $maxBytes]);
            return ['Error' => 'Image too large', 'HttpCode' => $httpCode];
        }

        if (!$this->isValidImageResponse($response, $contentType)) {
            $preview = $this->formatDebugResponse($response);
            $this->debugExpert('REST', 'Image response is not a valid image', [
                'HttpCode' => $httpCode,
                'ContentType' => $contentType,
                'ResponsePreview' => $preview
            ]);
            return [
                'Error' => 'Invalid image response',
                'HttpCode' => $httpCode,
                'ContentType' => $contentType,
                'ResponsePreview' => $preview
            ];
        }

        $base64 = base64_encode($response);
        return [
            'HttpCode' => $httpCode,
            'ContentType' => $contentType,
            'Base64' => $base64
        ];
    }

    private function isValidImageResponse(string $response, mixed $contentType): bool
    {
        if ($response === '') {
            return false;
        }

        $imageInfo = @getimagesizefromstring($response);
        if ($imageInfo === false) {
            return false;
        }

        if (!is_string($contentType) || $contentType === '') {
            return true;
        }

        return preg_match('#^image/#i', $contentType) === 1;
    }

    private function encodeImageResponseForTransport(array $result): string
    {
        $responseJson = json_encode($result, JSON_THROW_ON_ERROR);
        $jsonBytes = strlen($responseJson);

        $configuredBufferMb = max(0, $this->ReadPropertyInteger('OutputBufferSize'));
        $configuredBufferBytes = $configuredBufferMb > 0 ? $configuredBufferMb * 1024 * 1024 : 720 * 1024;
        $recommendedBufferBytes = max($configuredBufferBytes, $jsonBytes + 256 * 1024);
        ini_set('ips.output_buffer', (string)$recommendedBufferBytes);

        if ($jsonBytes > $recommendedBufferBytes) {
            return json_encode([
                'Error' => 'Image response too large for transport',
                'HttpCode' => $result['HttpCode'] ?? null,
                'ContentType' => $result['ContentType'] ?? null,
                'JsonBytes' => $jsonBytes,
                'BufferBytes' => $recommendedBufferBytes
            ], JSON_THROW_ON_ERROR);
        }

        return $responseJson;
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

    private function mapSupportedFeaturesResponse(string $response): string
    {
        $trimmed = trim($response);
        if ($trimmed === '' || ($trimmed[0] !== '{' && $trimmed[0] !== '[')) {
            return $response;
        }

        try {
            $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $response;
        }

        $changed = $this->applySupportedFeaturesMapping($decoded);
        if (!$changed) {
            return $response;
        }

        return json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function applySupportedFeaturesMapping(mixed &$data): bool
    {
        if (!is_array($data)) {
            return false;
        }

        $changed = false;

        $isList = array_is_list($data);
        if ($isList) {
            foreach ($data as &$item) {
                if ($this->applySupportedFeaturesMapping($item)) {
                    $changed = true;
                }
            }
            unset($item);
            return $changed;
        }

        if (isset($data['attributes']) && is_array($data['attributes'])) {
            $entityId = (string)($data['entity_id'] ?? '');
            $domain = (string)($data['domain'] ?? '');
            if ($domain === '' && $entityId !== '' && str_contains($entityId, '.')) {
                [$domain] = explode('.', $entityId, 2);
            }

            if (isset($data['attributes']['supported_features']) && is_numeric($data['attributes']['supported_features'])) {
                $mask = (int)$data['attributes']['supported_features'];
                $list = $this->mapSupportedFeaturesByDomain($domain, $mask);
                if ($list !== []) {
                    $data['attributes']['supported_features_list'] = $list;
                    $changed = true;
                }
            }
        }

        foreach ($data as &$value) {
            if ($this->applySupportedFeaturesMapping($value)) {
                $changed = true;
            }
        }
        unset($value);

        return $changed;
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
                $message .= ' | ' . $this->truncateResponse($response);
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

        return $this->truncateResponse($text, 1000, $compactWhitespace);
    }

    private function truncateResponse(string $response, int $maxLength = 1000, bool $compactWhitespace = true): string
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
            $text = $this->truncateResponse($text);
        }
        return 'HTTP ' . $httpCode . ($text !== '' ? ' | ' . $text : '');
    }

    private function updateDiagnosticsLabels(): void
    {
        foreach ($this->buildDiagnosticsCaptions() as $field => $caption) {
            $this->updateFormFieldSafe($field, 'caption', $caption);
        }
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
            $this->SetTimerInterval(self::TIMER_RESTACK, 0);
            return;
        }

        $pending = $this->readPendingRestAcks();
        if ($pending === []) {
            $this->SetTimerInterval(self::TIMER_RESTACK, 0);
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
            $this->SetTimerInterval(self::TIMER_RESTACK, 0);
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
        $this->SetTimerInterval(self::TIMER_RESTACK, 1000);
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
            $this->SetTimerInterval(self::TIMER_RESTACK, 0);
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

    private function buildDiagnosticsCaptions(): array
    {
        $parent = $this->buildCurrentParentDebugContext();
        $parentId = (int)($parent['ParentID'] ?? 0);
        $parentStatus = (int)($parent['ParentStatus'] ?? 0);
        $parentName = (string)($parent['ParentName'] ?? '');

        $lastMqtt = $this->ReadAttributeString('LastMQTTMessage');
        if ($lastMqtt === '') {
            $lastMqtt = $this->Translate('never');
        }

        $baseTopic = trim($this->ReadPropertyString('MQTTBaseTopic'));
        $statusName = $this->getInstanceStatusName($parentStatus);
        $nameSuffix = $parentName !== '' ? ' (' . $parentName . ')' : '';
        $statusSuffix = $statusName !== '' ? '(' . $statusName . ')' : '';

        $lastRestError = $this->ReadAttributeString('LastRestError');
        if ($lastRestError === '') {
            $lastRestError = $this->Translate('none');
        }

        $lastRestResponse = $this->ReadAttributeString('LastRestResponse');
        if ($lastRestResponse === '') {
            $lastRestResponse = $this->Translate('none');
        }

        $lastRestTimeout = $this->ReadAttributeString('LastRestTimeout');
        if ($lastRestTimeout === '') {
            $lastRestTimeout = $this->Translate('none');
        }

        return [
            'LastMQTTMessage' => sprintf($this->Translate('Last MQTT message: %s'), $lastMqtt),
            'DiagParent' => sprintf(
                $this->Translate('MQTT parent: %d%s | Status %d%s'),
                $parentId,
                $nameSuffix,
                $parentStatus,
                $statusSuffix
            ),
            'DiagBaseTopic' => sprintf(
                $this->Translate('MQTT base topic: %s'),
                $baseTopic !== '' ? $baseTopic : $this->Translate('empty')
            ),
            'DiagRest' => sprintf($this->Translate('Last REST error: %s'), $lastRestError),
            'DiagRestResponse' => sprintf($this->Translate('Last REST response: %s'), $lastRestResponse),
            'DiagRestTimeout' => sprintf($this->Translate('Last REST timeout: %s'), $lastRestTimeout)
        ];
    }

    private function applyCurrentDiagnosticsToForm(array &$form): void
    {
        $captions = $this->buildDiagnosticsCaptions();
        foreach ($form['actions'] as &$action) {
            if (!isset($action['items']) || !is_array($action['items'])) {
                continue;
            }

            $this->applyDiagnosticsCaptionsToItems($action['items'], $captions);
        }
        unset($action);
    }

    private function applyDiagnosticsCaptionsToItems(array &$items, array $captions): void
    {
        foreach ($items as &$item) {
            $name = (string)($item['name'] ?? '');
            if ($name === '' || !array_key_exists($name, $captions)) {
                continue;
            }
            $item['caption'] = $captions[$name];
        }
        unset($item);
    }
}
