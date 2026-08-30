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

    // Debounce der Diagnose-Anzeige (einheitlich mit dem Discovery-Splitter): hochfrequente Auslöser
    // setzen nur ein Dirty-Flag und bewaffnen den Timer; das teure updateDiagnosticsLabels() läuft dann
    // gebündelt einmal pro Intervall statt pro Auslöser.
    private const string TIMER_DIAGNOSTICS_REFRESH   = 'DiagnosticsRefresh';
    private const string ATTRIBUTE_DIAGNOSTICS_DIRTY = 'DiagnosticsDirty';
    private const int DIAGNOSTICS_REFRESH_INTERVAL_MS = 1000;

    // Opt-in Topic-Statistik: zählt eingehende Messages je Entität und gibt sie periodisch aggregiert aus
    // (statt pro Message zu loggen). Der Zustand MUSS in Buffern liegen: ReceiveData (Zählen) und der
    // Timer (DumpTopicStatistics) laufen in getrennten PHP-Ausführungen, Member-Variablen überleben das
    // nicht. Ohne Buffer wäre der Zähler beim Dump immer leer und der Fensterstart auf 0 (=> riesiges
    // "Fenster <epoch>s | total=0").
    private const string BUFFER_TOPIC_STATS_COUNTS = 'TopicStatsCounts';
    private const string BUFFER_TOPIC_STATS_START  = 'TopicStatsWindowStart';

    // Das "Last MQTT message"-Label ist reine Diagnose. Bei ~13 Messages/Sek. würde ein
    // WriteAttributeString + UpdateFormField pro Message dauerhaft messbare Last erzeugen, ohne
    // Mehrwert (das Label hat Sekunden-Granularität). Daher höchstens alle paar Sekunden schreiben.
    // Der Drossel-Zeitstempel MUSS im Buffer liegen: ReceiveData läuft über getrennte
    // PHP-Ausführungen, Member-Variablen überleben das nicht.
    private const string BUFFER_LAST_MQTT_TOUCH    = 'LastMqttTouchEpoch';
    private const int LAST_MQTT_LABEL_THROTTLE_SEC = 5;

    // Selbsttest: ab welchem Alter (Sekunden) der letzte MQTT-Empfang als "veraltet" gilt.
    private const int SELFTEST_MQTT_RECENCY_SEC = 300;

    // Selbsttest: welche HA-Domänen seit dem letzten (Re-)Start tatsächlich Daten geliefert haben.
    // Reine Diagnose für den Fall, dass mqtt_statestream via include/exclude ganze Domänen ausblendet.
    // MUSS im Buffer liegen (ReceiveData läuft in getrennten PHP-Ausführungen); als Delimited-String,
    // damit der Hot-Path pro Message nur einen str_contains-Check macht und selten (nur bei neuer
    // Domäne) schreibt statt JSON zu (de)serialisieren.
    private const string BUFFER_SEEN_DOMAINS = 'SeenDomains';

    // Performance-Dauermessung (gated über EnablePerformanceLog): aggregiert die Samples je Scope in
    // einem Fenster (count/sum/max/slow) und schreibt periodisch eine Zusammenfassung ins Symcon-Log —
    // im Gegensatz zum SendDebug-Kanal auch ohne offenes Debugfenster über Stunden auswertbar.
    // Zustand MUSS in Buffern liegen: ReceiveData (Messen) und der Timer (Dump) laufen in getrennten
    // PHP-Ausführungen, Member-Variablen überleben das nicht.
    private const string TIMER_PERF_STATS        = 'PerfStatsTimer';
    private const string BUFFER_PERF_STATS       = 'PerfStatsBuffer';
    private const string BUFFER_PERF_STATS_START = 'PerfStatsWindowStart';

    // Ausreißer oberhalb PerformanceSlowThresholdMs landen sofort im Symcon-Log, aber gedrosselt
    // (max. eine Zeile pro Intervall), damit ein Lastgewitter das Log nicht flutet; unterdrückte
    // Ausreißer bleiben über slowCount in der Fensterstatistik sichtbar. Nur die Gesamt-Scopes werden
    // geschwellt — ownWork/sendToChildren stecken als Aufschlüsselung im Kontext des total-Samples.
    private const string BUFFER_PERF_SLOW_LOG_TS  = 'PerfSlowLogEpoch';
    private const int PERF_SLOW_LOG_THROTTLE_SEC  = 10;
    private const array PERF_SLOW_LOG_SCOPES      = ['ReceiveData.total', 'Upstream.eventDelta'];

    // Upstream-Delta: Event-State-Payloads tragen die HA-Ereigniszeit; das Delta zur Empfangszeit misst
    // die gesamte Strecke HA → Broker → MQTT-Client → Splitter inklusive der Wartezeit vor diesem
    // ReceiveData-Aufruf (die In-Handler-Messung allein kann diese Queue-Wartezeit nicht sehen).
    // Größere Deltas sind Retained-Replays nach Reconnect und keine Latenz.
    private const int PERF_UPSTREAM_MAX_DELTA_SEC = 60;

    // Reine Zuordnung HA-Domäne => Definitions-Klasse für buildRestServicePayload().
    // Die input_*-Helfer-Domänen teilen sich den Service-Aufbau mit ihrer Basis-Domäne.
    private const array DOMAIN_DEFINITION_MAP = [
        HALightDefinitions::DOMAIN         => HALightDefinitions::class,
        HAButtonDefinitions::DOMAIN        => HAButtonDefinitions::class,
        HAInputButtonDefinitions::DOMAIN   => HAButtonDefinitions::class,
        HAVacuumDefinitions::DOMAIN        => HAVacuumDefinitions::class,
        HALawnMowerDefinitions::DOMAIN     => HALawnMowerDefinitions::class,
        HALockDefinitions::DOMAIN          => HALockDefinitions::class,
        HACoverDefinitions::DOMAIN         => HACoverDefinitions::class,
        HAFanDefinitions::DOMAIN           => HAFanDefinitions::class,
        HAHumidifierDefinitions::DOMAIN    => HAHumidifierDefinitions::class,
        HAMediaPlayerDefinitions::DOMAIN   => HAMediaPlayerDefinitions::class,
        HASwitchDefinitions::DOMAIN        => HASwitchDefinitions::class,
        'input_boolean'                    => HASwitchDefinitions::class,
        HASelectDefinitions::DOMAIN        => HASelectDefinitions::class,
        'input_select'                     => HASelectDefinitions::class,
        HANumberDefinitions::DOMAIN        => HANumberDefinitions::class,
        'input_number'                     => HANumberDefinitions::class,
        HAInputTextDefinitions::DOMAIN     => HAInputTextDefinitions::class,
        HADateTimeDefinitions::DOMAIN      => HADateTimeDefinitions::class,
        HAInputDateTimeDefinitions::DOMAIN => HAInputDateTimeDefinitions::class,
        HAClimateDefinitions::DOMAIN       => HAClimateDefinitions::class,
    ];

    public function Create(): void
    {
        parent::Create();

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
        $this->RegisterMessage($this->InstanceID, FM_DISCONNECT);
        $this->registerParentStatusTracking();
        $this->SetReceiveDataFilter('^$');

        $this->RegisterPropertyString('MQTTBaseTopic', 'homeassistant');
        $this->RegisterPropertyString('HAUrl', 'http://homeassistant.local:8123');
        $this->RegisterPropertyString('HAToken', '');
        $this->RegisterPropertyInteger('RestAckTimeoutSec', 5);
        $this->RegisterPropertyBoolean('EnableExpertDebug', false);
        $this->RegisterPropertyBoolean('EnablePerformanceLog', false);
        $this->RegisterPropertyInteger('PerformanceSlowThresholdMs', 100);
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
        $this->RegisterTimer(self::TIMER_PERF_STATS, 0, 'HA_DumpPerformanceStatistics($_IPS["TARGET"]);');
        $this->RegisterTimer(self::TIMER_DIAGNOSTICS_REFRESH, 0, 'HA_RefreshDiagnostics($_IPS["TARGET"]);');
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if (($Message === IPS_KERNELMESSAGE) && (($Data[0] ?? null) === KR_READY)) {
            $this->debugExpert('MessageSink', 'Kernel bereit. Aktualisiere...', [], true);
            $this->ApplyChanges();
            return;
        }

        // Statuswechsel des Parents werden VOR dem Runtime-Gate ausgewertet: Sie treffen
        // während des Bootlaufs ein, und dann darf die Meldung nicht verlorengehen
        // (Muster des HomeConnect-Moduls). Die Entprellung fängt flatternde Parents ab.
        if ($Message === IM_CHANGESTATUS) {
            if (!$this->isNewParentStatus((int) ($Data[0] ?? 0))) {
                return;
            }
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
        // Hop-by-Hop-Propagation: Erst „nicht bereit" setzen, dann prüfen. Ohne diesen Zwischen-
        // schritt bleibt der Status auf dem Startwert IS_ACTIVE stehen, wenn die Prüfung ebenfalls
        // aktiv ergibt — es gäbe also gar keinen Wechsel und die Kind-Instanzen bekämen kein
        // IM_CHANGESTATUS.
        $this->SetStatus(201);
        $baseTopic = trim($this->ReadPropertyString('MQTTBaseTopic'));

        // Nur Topics unterhalb des Base-Topics annehmen: Ein breit abonnierender MQTT Client (z. B. '#')
        // reicht sonst den gesamten Broker-Verkehr herein, und jede fremde Nachricht kostet eine
        // PHP-Ausführung, ohne je zu einem Wert zu führen. Der Kernel sortiert das nun vorher aus.
        $receiveFilter = HAMqttTopicFilter::receiveDataFilterPattern($baseTopic);
        $this->SetReceiveDataFilter($receiveFilter);
        $this->debugExpert('ApplyChanges', 'ReceiveDataFilter gesetzt', ['Filter' => $receiveFilter]);

        $this->applyTopicStatisticsConfiguration();
        $this->applyPerformanceStatisticsConfiguration();
        $this->updateLastMqttMessageLabel();
        $this->updateDiagnosticsLabels();
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

            // Statistik VOR dem Bookkeeping-Drop zählen, damit die echte eingehende Last je Gerät sichtbar
            // wird (inkl. der Topics, die wir gleich verwerfen).
            if ($this->isTopicStatisticsEnabled()) {
                $this->recordTopicStatistic($topic, strlen((string)($data['Payload'] ?? '')));
            }

            // Universelle HA-Bookkeeping-Topics (Zeitstempel etc.) werden von keinem Device benötigt und
            // dort ohnehin verworfen. Sie hier vor dem Broadcast abzuweisen erspart die teure synchrone
            // Weiterleitung an alle Kinder (jede Weiterleitung kostet messbar, das Ergebnis ist null).
            if (HADomainCatalog::isIgnorableBookkeepingTopic($topic)) {
                return '';
            }

            $messageStartedAt = microtime(true);

            $this->recordSeenDomain($topic);

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
            $this->recordUpstreamEventDelta($topic, (string)($data['Payload'] ?? ''));
            $ownWorkMs = $this->logPerformanceSample('ReceiveData.ownWork', $stepStartedAt, ['topic' => $topic]);

            $data['DataID'] = HAIds::DATA_SPLITTER_TO_DEVICE;
            $stepStartedAt = microtime(true);
            $this->SendDataToChildren(json_encode($data, JSON_THROW_ON_ERROR));
            $sendToChildrenMs = $this->logPerformanceSample('ReceiveData.sendToChildren', $stepStartedAt, ['topic' => $topic]);

            $this->logPerformanceSample('ReceiveData.total', $messageStartedAt, [
                'topic'             => $topic,
                'ownWork_ms'        => $ownWorkMs,
                'sendToChildren_ms' => $sendToChildrenMs,
            ]);
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

        $credentials = $this->readHaCredentials();
        if ($credentials === null) {
            $this->debugExpert('REST', __FUNCTION__, ['Missing HAUrl/HAToken']);
            return false;
        }

        $url = rtrim($credentials['url'], '/') . '/api/services/' . $domain . '/' . $service;
        $postData = json_encode($data, JSON_THROW_ON_ERROR);
        $this->debugExpert('REST', __FUNCTION__, ['Url' => $url, 'Data' => $data]);
        $ok = $this->sendHaRequest($url, $credentials['token'], $postData);
        if ($ok && isset($data['entity_id']) && is_string($data['entity_id']) && $data['entity_id'] !== '') {
            $this->addPendingRestAck($data['entity_id'], $service);
        }
        return $ok;
    }

    private function isPerformanceLogEnabled(): bool
    {
        return (bool)@$this->ReadPropertyBoolean('EnablePerformanceLog');
    }

    // Liefert die gemessene Dauer (ms) zurück, damit Aufrufer Teilschritte in den Kontext des
    // Gesamt-Samples aufnehmen können (Aufschlüsselung in der Ausreißer-Logzeile).
    private function logPerformanceSample(string $scope, float $startedAt, array $context = []): float
    {
        if (!$this->isPerformanceLogEnabled()) {
            return 0.0;
        }

        $elapsedMs = round((microtime(true) - $startedAt) * 1000.0, 3);
        $context = ['elapsed_ms' => $elapsedMs] + $context;
        $this->SendDebug('Performance', $scope . ' | ' . json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0);
        $this->recordPerformanceSample($scope, $elapsedMs, $context);
        return $elapsedMs;
    }

    private function applyPerformanceStatisticsConfiguration(): void
    {
        $enabled = $this->isPerformanceLogEnabled();
        // Fenster bei jedem ApplyChanges neu starten und Aggregation leeren.
        $this->SetBuffer(self::BUFFER_PERF_STATS, '');
        $this->SetBuffer(self::BUFFER_PERF_STATS_START, $enabled ? (string)time() : '');

        if (!$enabled) {
            $this->SetTimerInterval(self::TIMER_PERF_STATS, 0);
            return;
        }

        $minutes = max(1, (int)@$this->ReadPropertyInteger('TopicStatisticsIntervalMinutes'));
        $this->SetTimerInterval(self::TIMER_PERF_STATS, $minutes * 60 * 1000);
    }

    private function recordPerformanceSample(string $scope, float $elapsedMs, array $context): void
    {
        // Fensterstart faul setzen, falls ApplyChanges ihn (noch) nicht gesetzt hat.
        if ($this->GetBuffer(self::BUFFER_PERF_STATS_START) === '') {
            $this->SetBuffer(self::BUFFER_PERF_STATS_START, (string)time());
        }

        $stats = $this->loadPerformanceStats();
        $entry = $stats[$scope] ?? ['count' => 0, 'sumMs' => 0.0, 'maxMs' => 0.0, 'maxAt' => 0, 'maxTopic' => '', 'slowCount' => 0];
        $entry['count']++;
        $entry['sumMs'] += $elapsedMs;
        if ($elapsedMs > (float)$entry['maxMs']) {
            $entry['maxMs'] = $elapsedMs;
            $entry['maxAt'] = time();
            $entry['maxTopic'] = (string)($context['topic'] ?? '');
        }

        $thresholdMs = (int)@$this->ReadPropertyInteger('PerformanceSlowThresholdMs');
        if ($thresholdMs > 0 && $elapsedMs >= $thresholdMs && in_array($scope, self::PERF_SLOW_LOG_SCOPES, true)) {
            $entry['slowCount']++;
            $this->logSlowPerformanceSample($scope, $context);
        }

        $stats[$scope] = $entry;
        $this->SetBuffer(self::BUFFER_PERF_STATS, json_encode($stats, JSON_THROW_ON_ERROR));
    }

    private function loadPerformanceStats(): array
    {
        $raw = $this->GetBuffer(self::BUFFER_PERF_STATS);
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

    // Ausreißer sofort und persistent ins Symcon-Log — gedrosselt, damit ein Lastgewitter das Log
    // nicht flutet (unterdrückte Ausreißer bleiben über slowCount in der Fensterstatistik sichtbar).
    private function logSlowPerformanceSample(string $scope, array $context): void
    {
        $now = time();
        $last = (int)$this->GetBuffer(self::BUFFER_PERF_SLOW_LOG_TS);
        if ($last > 0 && ($now - $last) < self::PERF_SLOW_LOG_THROTTLE_SEC) {
            return;
        }
        $this->SetBuffer(self::BUFFER_PERF_SLOW_LOG_TS, (string)$now);
        $this->LogMessage(
            sprintf('Performance-Ausreißer %s | %s', $scope, json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            KL_WARNING
        );
    }

    // Event-State-Payloads tragen die HA-Ereigniszeit; Details siehe PERF_UPSTREAM_MAX_DELTA_SEC.
    private function recordUpstreamEventDelta(string $topic, string $payloadHex): void
    {
        if (!$this->isPerformanceLogEnabled()) {
            return;
        }

        $t = trim($topic, '/');
        $base = trim($this->ReadPropertyString('MQTTBaseTopic'), '/');
        if ($base !== '') {
            if (!str_starts_with($t, $base . '/')) {
                return;
            }
            $t = substr($t, strlen($base) + 1);
        }
        if (!str_starts_with($t, 'event/') || !str_ends_with($t, '/state')) {
            return;
        }

        $payload = trim($this->decodePayload($payloadHex), "\" \t\r\n");
        if ($payload === '') {
            return;
        }
        if (is_numeric($payload)) {
            $eventTs = (float)$payload;
        } else {
            try {
                $eventTs = (float)(new DateTimeImmutable($payload))->format('U.u');
            } catch (Exception) {
                return;
            }
        }

        $deltaSec = microtime(true) - $eventTs;
        if ($deltaSec < 0.0 || $deltaSec > self::PERF_UPSTREAM_MAX_DELTA_SEC) {
            return;
        }
        $this->logPerformanceSample('Upstream.eventDelta', $eventTs, ['topic' => $topic]);
    }

    /** @noinspection PhpUnused */
    public function DumpPerformanceStatistics(): void
    {
        $now = time();
        $startedAt = (int)$this->GetBuffer(self::BUFFER_PERF_STATS_START);
        $elapsed = $startedAt > 0 ? max(1, $now - $startedAt) : 0;
        $stats = $this->loadPerformanceStats();
        $this->SetBuffer(self::BUFFER_PERF_STATS, '');
        $this->SetBuffer(self::BUFFER_PERF_STATS_START, (string)$now);

        if ($stats === []) {
            $this->SendDebug('Performance', sprintf('Fenster %ds | keine Samples', $elapsed), 0);
            return;
        }

        ksort($stats, SORT_STRING);
        $parts = [];
        foreach ($stats as $scope => $entry) {
            $count = (int)$entry['count'];
            if ($count <= 0) {
                continue;
            }
            $slowCount = (int)$entry['slowCount'];
            $parts[] = sprintf(
                '%s: n=%d (%.1f/min) avg=%.1fms max=%.1fms (%s%s)%s',
                $scope,
                $count,
                $count / ($elapsed / 60.0),
                (float)$entry['sumMs'] / $count,
                (float)$entry['maxMs'],
                date('H:i:s', (int)$entry['maxAt']),
                $entry['maxTopic'] !== '' ? ', ' . $entry['maxTopic'] : '',
                $slowCount > 0 ? sprintf(' | slow=%d', $slowCount) : ''
            );
        }

        // Eine kompakte Zeile pro Fenster ins persistente Symcon-Log (auswertbar ohne Debugfenster),
        // dieselbe Information zusätzlich auf den Debug-Kanal.
        $summary = sprintf('Performance-Fenster %ds | %s', $elapsed, implode(' | ', $parts));
        $this->LogMessage($summary, KL_NOTIFY);
        $this->SendDebug('Performance', $summary, 0);
    }

    private function isTopicStatisticsEnabled(): bool
    {
        return (bool)@$this->ReadPropertyBoolean('EnableTopicStatistics');
    }

    private function applyTopicStatisticsConfiguration(): void
    {
        $enabled = $this->isTopicStatisticsEnabled();
        // Fenster bei jedem ApplyChanges neu starten und Zähler leeren.
        $this->SetBuffer(self::BUFFER_TOPIC_STATS_COUNTS, '');
        $this->SetBuffer(self::BUFFER_TOPIC_STATS_START, $enabled ? (string)time() : '');

        if (!$enabled) {
            $this->SetTimerInterval(self::TIMER_TOPIC_STATS, 0);
            return;
        }

        $minutes = max(1, (int)@$this->ReadPropertyInteger('TopicStatisticsIntervalMinutes'));
        $this->SetTimerInterval(self::TIMER_TOPIC_STATS, $minutes * 60 * 1000);
    }

    private function recordTopicStatistic(string $topic, int $payloadBytes): void
    {
        if (HATopicStatistics::keyForTopic($topic) === '') {
            return;
        }
        // Fensterstart faul setzen, falls ApplyChanges ihn (noch) nicht gesetzt hat.
        if ($this->GetBuffer(self::BUFFER_TOPIC_STATS_START) === '') {
            $this->SetBuffer(self::BUFFER_TOPIC_STATS_START, (string)time());
        }
        $counts = HATopicStatistics::record($this->loadTopicStatsCounts(), $topic, $payloadBytes);
        $this->SetBuffer(self::BUFFER_TOPIC_STATS_COUNTS, json_encode($counts, JSON_THROW_ON_ERROR));
    }

    // Always-on, Hot-Path-schonend: merkt sich die distinkten HA-Domänen (erstes Segment nach dem
    // MQTTBaseTopic), die seit dem letzten (Re-)Start Daten geliefert haben. Schreibt nur, wenn eine
    // Domäne neu ist. Dient dem Selbsttest, um statestream-include/exclude-Filter sichtbar zu machen.
    private function recordSeenDomain(string $topic): void
    {
        $t = trim($topic, '/');
        if ($t === '') {
            return;
        }
        $base = trim($this->ReadPropertyString('MQTTBaseTopic'), '/');
        if ($base !== '') {
            if (!str_starts_with($t, $base . '/')) {
                return;
            }
            $t = substr($t, strlen($base) + 1);
        }
        $slash = strpos($t, '/');
        $domain = $slash === false ? $t : substr($t, 0, $slash);
        if ($domain === '') {
            return;
        }

        $buf = $this->GetBuffer(self::BUFFER_SEEN_DOMAINS);
        if (str_contains('|' . $buf . '|', '|' . $domain . '|')) {
            return;
        }
        $this->SetBuffer(self::BUFFER_SEEN_DOMAINS, $buf === '' ? $domain : $buf . '|' . $domain);
    }

    private function loadTopicStatsCounts(): array
    {
        return HATopicStatistics::decodeCounts($this->GetBuffer(self::BUFFER_TOPIC_STATS_COUNTS));
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

        $aggregate = HATopicStatistics::aggregate($counts);
        if ($aggregate['total'] === 0) {
            $this->SendDebug('TopicStats', sprintf('Fenster %ds | total=0 (keine Messages)', $elapsed), 0);
            return;
        }

        $this->SendDebug('TopicStats', HATopicStatistics::formatHeader($aggregate, $elapsed), 0);
        $rank = 0;
        foreach ($aggregate['devices'] as $deviceKey => $entry) {
            if (++$rank > HATopicStatistics::TOP_DEVICES_DEBUG) {
                $this->SendDebug('TopicStats', sprintf('  ... (%d weitere Geräte)', count($aggregate['devices']) - HATopicStatistics::TOP_DEVICES_DEBUG), 0);
                break;
            }
            $this->SendDebug('TopicStats', HATopicStatistics::formatDeviceRow((string)$deviceKey, $entry, $elapsed), 0);
        }
        // Größte Datenlieferanten je Entität: macht einzelne "fette" Topics (Attribut-JSON, Token, Listen)
        // sichtbar, die in der reinen Nachrichtenzahl untergehen.
        $this->SendDebug('TopicStats', 'Top-Entitäten nach Bytes:', 0);
        foreach ($aggregate['topEntitiesByBytes'] as $entityKey => $entry) {
            $this->SendDebug('TopicStats', HATopicStatistics::formatDeviceRow((string)$entityKey, $entry, $elapsed), 0);
        }

        // Kopfzeile + Top-Geräte + Bytes-Spitzenreiter zusätzlich als eine kompakte Zeile ins persistente
        // Symcon-Log (auswertbar ohne Debugfenster, z. B. aus einer eingesandten Logdatei) — analog Performance-Fenster.
        $this->LogMessage(HATopicStatistics::formatLogLine($aggregate, $elapsed), KL_NOTIFY);
    }

    // Schreibt das LastMQTTMessage-Attribut höchstens alle LAST_MQTT_LABEL_THROTTLE_SEC Sekunden, damit die
    // Diagnose nicht pro eingehender Message persistiert (siehe BUFFER_LAST_MQTT_TOUCH). Die Anzeige wird nur
    // bei tatsächlicher Änderung angestoßen und über scheduleDiagnosticsRefresh() gedebounced refresht.
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

    // Bündelt hochfrequente Diagnose-Aktualisierungen: nur Dirty-Flag setzen und den Timer bewaffnen.
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
        $last = $this->attributeOrFallback('LastMQTTMessage', $this->Translate('never'));
        $this->updateFormFieldSafe(
            'LastMQTTMessage',
            'caption',
            sprintf($this->Translate('Last MQTT message: %s'), $last)
        );
    }

    /**
     * Liest HAUrl/HAToken (getrimmt); null, wenn eines der beiden leer ist.
     * Die Fehlerreaktion (Debug, Diagnose, Rückgabewert) bleibt Sache des Aufrufers.
     *
     * @return array{url: string, token: string}|null
     */
    private function readHaCredentials(): ?array
    {
        $haUrl = trim($this->ReadPropertyString('HAUrl'));
        $token = trim($this->ReadPropertyString('HAToken'));
        if ($haUrl === '' || $token === '') {
            return null;
        }
        return ['url' => $haUrl, 'token' => $token];
    }

    // Einheitlicher Fehlerabschluss der REST-Diagnose: Fehlertext + Response persistieren,
    // Anzeige aktualisieren, false für die direkte Rückgabe an den Aufrufer.
    private function failRestDiagnostics(string $error, string $response = ''): false
    {
        $this->WriteAttributeString('LastRestError', $error);
        $this->WriteAttributeString('LastRestResponse', $response);
        $this->updateDiagnosticsLabels();
        return false;
    }

    // Einheitlicher Erfolgsabschluss der REST-Diagnose (Gegenstück zu failRestDiagnostics()).
    private function succeedRestDiagnostics(string $response): true
    {
        if ($this->ReadAttributeString('LastRestError') !== '') {
            $this->WriteAttributeString('LastRestError', '');
        }
        $this->WriteAttributeString('LastRestResponse', $response);
        $this->updateDiagnosticsLabels();
        return true;
    }

    /**
     * Gemeinsamer cURL-Unterbau der vier HA-HTTP-Zugriffe (Reachability-Probe, Service-Call,
     * Raw-Request, Image-Download). Baut das Handle einheitlich auf (Bearer-Auth, RETURNTRANSFER)
     * und schließt es nach der Ausführung IMMER mit curl_close(). Die komplette Fehler-/
     * Erfolgsbehandlung bleibt Sache der Aufrufer.
     *
     * Optionen (nur die Abweichungen vom Standardfall):
     * - headers:        list<string> — zusätzliche Header nach dem Authorization-Header
     *                   (Default: ['Content-Type: application/json'])
     * - connectTimeout: int — CURLOPT_CONNECTTIMEOUT (Default: nicht gesetzt)
     * - timeout:        int — CURLOPT_TIMEOUT (Default: 10)
     * - followLocation: bool — CURLOPT_FOLLOWLOCATION + CURLOPT_MAXREDIRS 3 (Default: false)
     * - postFields:     string — CURLOPT_POST + CURLOPT_POSTFIELDS (Default: kein POST)
     *
     * @param array{headers?: list<string>, connectTimeout?: int, timeout?: int, followLocation?: bool, postFields?: string} $options
     *
     * @return array{response: string|false, httpCode: int, contentType: string|null, error: string}
     */
    private function performCurl(string $url, string $token, array $options = []): array
    {
        $ch = curl_init($url);

        $headers = array_merge(
            ['Authorization: Bearer ' . $token],
            $options['headers'] ?? ['Content-Type: application/json']
        );

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        if (isset($options['connectTimeout'])) {
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $options['connectTimeout']);
        }
        curl_setopt($ch, CURLOPT_TIMEOUT, $options['timeout'] ?? 10);
        if ($options['followLocation'] ?? false) {
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        }
        if (isset($options['postFields'])) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $options['postFields']);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        curl_close($ch);

        return [
            'response' => $response,
            'httpCode' => $httpCode,
            'contentType' => $contentType,
            'error' => $error
        ];
    }

    private function isRestApiReachable(): bool
    {
        $credentials = $this->readHaCredentials();
        if ($credentials === null) {
            return $this->failRestDiagnostics('Missing HAUrl/HAToken');
        }

        $url = rtrim($credentials['url'], '/') . '/api/';
        $result = $this->performCurl($url, $credentials['token'], ['connectTimeout' => 5, 'timeout' => 5]);
        $response = $result['response'];
        $httpCode = $result['httpCode'];
        $error = $result['error'];

        if ($error) {
            return $this->failRestDiagnostics('cURL: ' . $error);
        }
        if ($response === false || $response === '') {
            return $this->failRestDiagnostics('Empty response');
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            return $this->failRestDiagnostics('HTTP ' . $httpCode, (string)$response);
        }

        return $this->succeedRestDiagnostics('');
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
        $credentials = $this->readHaCredentials();
        if ($credentials === null) {
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
        $url = rtrim($credentials['url'], '/') . '/api/services/' . $domain . '/' . $service;
        $postData = json_encode($data, JSON_THROW_ON_ERROR);

        $this->debugExpert('REST', 'Send command', ['Url' => $url, 'Data' => $data]);
        $ok = $this->sendHaRequest($url, $credentials['token'], $postData);
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
        $definitionClass = self::DOMAIN_DEFINITION_MAP[$domain] ?? null;
        if ($definitionClass === null) {
            return ['', []];
        }
        return $definitionClass::buildRestServicePayload($value);
    }

    private function sendHaRequest(string $url, string $token, string $postData): bool
    {
        $result = $this->performCurl($url, $token, ['postFields' => $postData]);
        $response = $result['response'];
        $httpCode = $result['httpCode'];
        $error = $result['error'];

        if ($error) {
            $this->debugExpert('REST', "cURL Error: $error");
            return $this->failRestDiagnostics('cURL: ' . $error, 'HTTP ' . $httpCode);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $this->debugExpert('REST', "HTTP Error: $httpCode | Response: $response");
            return $this->failRestDiagnostics('HTTP ' . $httpCode, $this->formatRestResponse($httpCode, $response));
        }

        return $this->succeedRestDiagnostics($this->formatRestResponse($httpCode, $response));
    }

    private function handleRestRequest(array $data): string
    {
        $endpoint = (string)($data['Endpoint'] ?? '');
        $method = strtoupper((string)($data['Method'] ?? 'POST'));
        $body = $data['Body'] ?? null;

        if ($endpoint === '') {
            return json_encode(['Error' => 'Missing endpoint'], JSON_THROW_ON_ERROR);
        }

        $credentials = $this->readHaCredentials();
        if ($credentials === null) {
            return json_encode(['Error' => 'Missing HAUrl/HAToken'], JSON_THROW_ON_ERROR);
        }

        $url = rtrim($credentials['url'], '/') . $endpoint;

        $this->debugExpert('REST', 'Handle request', ['Endpoint' => $endpoint, 'Method' => $method]);
        $result = $this->sendHaRequestRaw($url, $credentials['token'], is_string($body) ? $body : null, $method);
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

        $credentials = $this->readHaCredentials();
        if ($credentials === null) {
            return json_encode(['Error' => 'Missing HAUrl/HAToken'], JSON_THROW_ON_ERROR);
        }

        if (!preg_match('#^https?://#i', $url)) {
            $url = rtrim($credentials['url'], '/') . '/' . ltrim($url, '/');
        }

        $result = $this->sendHaImageRequest($url, $credentials['token']);
        return $this->encodeImageResponseForTransport($result);
    }

    private function sendHaImageRequest(string $url, string $token): array
    {
        $result = $this->performCurl($url, $token, [
            'headers'        => ['Accept: image/*', 'User-Agent: IPS-HomeAssistant'],
            'followLocation' => true
        ]);
        $response = $result['response'];
        $httpCode = $result['httpCode'];
        $contentType = $result['contentType'];
        $error = $result['error'];

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
        $options = [];
        if ($method === 'POST' && $postData !== null) {
            $options['postFields'] = $postData;
        }

        $result = $this->performCurl($url, $token, $options);
        $response = $result['response'];
        $httpCode = $result['httpCode'];
        $error = $result['error'];

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

    /**
     * Anwender-Selbsttest: fasst die vorhandenen Diagnosesignale zu einer Checkliste zusammen.
     * Wird vom Button im Formular per `echo HA_RunSelfTest($id);` aufgerufen; die Rückgabe
     * erscheint als Popup. Rein lesend (plus der ohnehin existierende REST-Probe).
     *
     * @noinspection PhpUnused
     */
    public function RunSelfTest(): string
    {
        $lines = [];
        $errors = 0;
        $warnings = 0;
        $add = static function (string $level, string $label, string $hint = '') use (&$lines, &$errors, &$warnings): void {
            $symbol = match ($level) {
                'ok' => '✓',
                'warn' => '⚠',
                'error' => '✗',
                default => '•'
            };
            if ($level === 'error') {
                $errors++;
            } elseif ($level === 'warn') {
                $warnings++;
            }
            $lines[] = $symbol . ' ' . $label;
            if ($hint !== '') {
                $lines[] = '   → ' . $hint;
            }
        };

        // hasCompatibleParentModule(MQTT Client) wird von mehreren Checks benötigt -> einmal ermitteln.
        $hasMqttClientParent = $this->hasCompatibleParentModule(HAIds::MODULE_MQTT_CLIENT);
        $baseTopic = trim($this->ReadPropertyString('MQTTBaseTopic'));

        $results = array_merge(
            $this->selfTestMqttParent(),
            $this->selfTestParentType($hasMqttClientParent),
            $this->selfTestRestApi(),
            $this->selfTestBaseTopic($baseTopic),
            $this->selfTestBrokerSocket($hasMqttClientParent),
            $this->selfTestMqttCredentials($hasMqttClientParent),
            $this->selfTestMqttRecency(),
            $this->selfTestSubscriptionCoverage($baseTopic, $hasMqttClientParent),
            $this->selfTestSeenDomains()
        );
        foreach ($results as $result) {
            $add($result[0], $result[1], $result[2] ?? '');
        }

        foreach ($this->buildSelfTestSummary($errors, $warnings) as $line) {
            $lines[] = $line;
        }

        $title = $this->Translate('Self-test: Home Assistant Splitter (classic bridge)');
        return $title . "\n\n" . implode("\n", $lines);
    }

    /**
     * 1. MQTT-Parent aktiv & kompatibel
     *
     * @return list<array{0: string, 1: string, 2?: string}>
     */
    private function selfTestMqttParent(): array
    {
        $parentState = $this->determineParentRuntimeState([HAIds::MODULE_MQTT_CLIENT, HAIds::MODULE_MQTT_SERVER]);
        $parentCtx = $this->buildCurrentParentDebugContext();
        if ($parentState === 'active') {
            return [['ok', sprintf($this->Translate('MQTT parent: active (#%d, %s)'), (int)$parentCtx['ParentID'], (string)$parentCtx['ModuleName'])]];
        }
        return [[
            'error',
            $this->Translate('MQTT parent: not connected, inactive or incompatible'),
            $this->Translate('Connect an active MQTT Client or MQTT Server as parent (status 201/202).')
        ]];
    }

    /**
     * 2. Parent-Typ
     *
     * @return list<array{0: string, 1: string, 2?: string}>
     */
    private function selfTestParentType(bool $hasMqttClientParent): array
    {
        if ($hasMqttClientParent) {
            return [['ok', $this->Translate('Parent type: MQTT Client (recommended)')]];
        }
        if ($this->hasCompatibleParentModule(HAIds::MODULE_MQTT_SERVER)) {
            return [[
                'warn',
                $this->Translate('Parent type: MQTT Server'),
                $this->Translate('An MQTT Client receives the retained replay on connect (immediate full initial state). MQTT Server works too but without that replay.')
            ]];
        }
        return [];
    }

    /**
     * 3. REST erreichbar & Token gültig (nutzt den vorhandenen aktiven Probe)
     *
     * @return list<array{0: string, 1: string, 2?: string}>
     */
    private function selfTestRestApi(): array
    {
        if ($this->isRestApiReachable()) {
            return [['ok', $this->Translate('REST API reachable and token valid')]];
        }
        return [[
            'error',
            sprintf($this->Translate('REST API not reachable: %s'), $this->ReadAttributeString('LastRestError')),
            $this->Translate('HTTP 401: check the token. cURL/connection error: check HA URL/port/network. Both HAUrl and HAToken must be set.')
        ]];
    }

    /**
     * 4. MQTTBaseTopic gesetzt
     *
     * @return list<array{0: string, 1: string, 2?: string}>
     */
    private function selfTestBaseTopic(string $baseTopic): array
    {
        if ($baseTopic !== '') {
            return [['ok', sprintf($this->Translate('MQTT base topic: %s'), $baseTopic)]];
        }
        return [[
            'error',
            $this->Translate('MQTT base topic is empty'),
            $this->Translate('Set MQTTBaseTopic to match mqtt_statestream.base_topic in Home Assistant.')
        ]];
    }

    /**
     * 5. Broker-Socket-Status (nur sinnvoll bei MQTT Client; CONNACK/Auth ist darüber nicht sichtbar)
     *
     * @return list<array{0: string, 1: string, 2?: string}>
     */
    private function selfTestBrokerSocket(bool $hasMqttClientParent): array
    {
        if (!$hasMqttClientParent) {
            return [];
        }
        $ioStatus = $this->parentIoInstanceStatus();
        if ($ioStatus === IS_ACTIVE) {
            return [['ok', $this->Translate('Broker socket connected')]];
        }
        if ($ioStatus !== null) {
            return [[
                'error',
                sprintf($this->Translate('Broker socket not connected (status %d)'), $ioStatus),
                $this->Translate('Check host/port/network and the MQTT credentials of the MQTT Client.')
            ]];
        }
        return [];
    }

    /**
     * 5a. MQTT-Zugangsdaten des Parents (fehlende Credentials sind bei Mosquitto die häufigste Ursache).
     * Kommen bereits Daten an, funktioniert der anonyme Zugang offensichtlich -> nur Info statt Warnung.
     *
     * @return list<array{0: string, 1: string, 2?: string}>
     */
    private function selfTestMqttCredentials(bool $hasMqttClientParent): array
    {
        if (!$hasMqttClientParent) {
            return [];
        }
        $credentials = $this->parentMqttCredentials();
        if ($credentials === null) {
            return [];
        }
        if ($credentials['UserName'] === '' && $credentials['Password'] === '') {
            if ($this->ReadAttributeString('LastMQTTMessage') === '') {
                return [[
                    'warn',
                    $this->Translate('MQTT client has no credentials configured'),
                    $this->Translate('The Mosquitto broker in Home Assistant rejects anonymous connections by default. Create a Home Assistant user (Settings → People → Users) and enter its name and password in the MQTT Client instance.')
                ]];
            }
            return [['•', $this->Translate('MQTT client has no credentials configured (fine, the broker accepts anonymous access)')]];
        }
        if ($credentials['UserName'] !== '') {
            return [['ok', sprintf($this->Translate('MQTT credentials set (user: %s)'), $credentials['UserName'])]];
        }
        return [];
    }

    /**
     * 6. Kommen MQTT-Daten an? (Aktualität, nicht nur Existenz)
     *
     * @return list<array{0: string, 1: string, 2?: string}>
     */
    private function selfTestMqttRecency(): array
    {
        $lastMqtt = $this->ReadAttributeString('LastMQTTMessage');
        $ageHint = $this->Translate('mqtt_statestream is event-driven, so occasional gaps are normal. A stale value right after Apply usually means a broken connection (wrong MQTT user/password, subscription or base_topic).');
        if ($lastMqtt === '') {
            return [[
                'warn',
                $this->Translate('No MQTT data received yet'),
                $this->Translate('Check that mqtt_statestream is enabled, the subscription covers the base topic, and base_topic matches MQTTBaseTopic.')
            ]];
        }
        $age = time() - (int)strtotime($lastMqtt);
        if ($age >= 0 && $age <= self::SELFTEST_MQTT_RECENCY_SEC) {
            return [['ok', sprintf($this->Translate('MQTT data received (%ds ago)'), $age)]];
        }
        return [[
            'warn',
            sprintf($this->Translate('Last MQTT data at %s (%s ago) – connection may be broken'), $lastMqtt, $this->formatAge($age)),
            $ageHint
        ]];
    }

    /**
     * 6. Subscription deckt MQTTBaseTopic ab (best effort)
     *
     * @return list<array{0: string, 1: string, 2?: string}>
     */
    private function selfTestSubscriptionCoverage(string $baseTopic, bool $hasMqttClientParent): array
    {
        if ($baseTopic === '') {
            return [];
        }
        if ($this->hasCompatibleParentModule(HAIds::MODULE_MQTT_SERVER) && !$hasMqttClientParent) {
            return [['ok', $this->Translate('Parent is MQTT Server – subscriptions are handled by the broker, not a client')]];
        }
        $covered = $this->parentSubscriptionCoversBaseTopic($baseTopic);
        if ($covered === true) {
            return [['ok', $this->Translate('Parent subscription covers the base topic')]];
        }
        if ($covered === false) {
            return [[
                'warn',
                sprintf($this->Translate('Parent subscription does not seem to cover "%s"'), $baseTopic),
                sprintf(
                    // Ortsangabe bewusst mit im Text: Anwender suchen die Einstellung regelmäßig im
                    // HA-Modul selbst (Supportfälle bgersmann 08/2026, roesl 08/2026).
                    $this->Translate('The subscription is not set here but in the MQTT Client instance this splitter is connected to — open it via "Configure interface" and enter %s/# in its "Subscriptions" list.'),
                    $baseTopic
                )
            ]];
        }
        return [['•', $this->Translate('Subscription could not be checked (parent config not readable)')]];
    }

    /**
     * 7. Welche Domänen liefern tatsächlich Daten? (deckt statestream-include/exclude auf)
     *
     * @return list<array{0: string, 1: string, 2?: string}>
     */
    private function selfTestSeenDomains(): array
    {
        $seenRaw = $this->GetBuffer(self::BUFFER_SEEN_DOMAINS);
        $seen = $seenRaw === '' ? [] : explode('|', $seenRaw);
        sort($seen, SORT_STRING);
        if ($seen === []) {
            return [['•', $this->Translate('Received domains: none yet (since last restart)')]];
        }
        if (count($seen) === 1) {
            return [[
                'warn',
                sprintf($this->Translate('Only one domain is delivering data: %s'), $seen[0]),
                $this->Translate('If entities of other domains are missing (e.g. binary_sensor, switch), mqtt_statestream include/exclude in Home Assistant is probably filtering whole domains. Extend include.domains or remove the filter, then reload HA.')
            ]];
        }
        return [['ok', sprintf($this->Translate('Received domains: %s'), implode(', ', $seen))]];
    }

    /**
     * Fazit
     *
     * @return list<string>
     */
    private function buildSelfTestSummary(int $errors, int $warnings): array
    {
        $lines = [''];
        if ($errors === 0 && $warnings === 0) {
            $lines[] = $this->Translate('Summary: everything looks good.');
        } else {
            $lines[] = sprintf($this->Translate('Summary: %d error(s), %d warning(s).'), $errors, $warnings);
        }
        $lines[] = $this->Translate('More help: README section 7 (Troubleshooting) and MQTT Explorer (https://mqtt-explorer.com/).');
        return $lines;
    }

    /**
     * Liefert den InstanceStatus des IO unter dem MQTT-Client-Parent (Splitter -> MQTT Client -> IO),
     * oder null, wenn die Kette nicht auflösbar ist. CONNACK-/Auth-Fehler sind hierüber NICHT
     * sichtbar (nur die Socket-Ebene) – ergänzend dient die Aktualität der MQTT-Daten.
     */
    private function parentIoInstanceStatus(): ?int
    {
        $mqttClientId = $this->getCurrentParentId();
        if ($mqttClientId <= 0 || !IPS_InstanceExists($mqttClientId)) {
            return null;
        }
        $ioId = (int)(IPS_GetInstance($mqttClientId)['ConnectionID'] ?? 0);
        if ($ioId <= 0 || !IPS_InstanceExists($ioId)) {
            return null;
        }
        return (int)(IPS_GetInstance($ioId)['InstanceStatus'] ?? 0);
    }

    /**
     * Liest UserName/Password des MQTT-Client-Parents (best effort).
     * Liefert null, wenn die Parent-Konfiguration nicht lesbar ist oder keine
     * Credential-Felder enthält (z. B. anderer Parent-Modultyp).
     *
     * @return array{UserName: string, Password: string}|null
     */
    private function parentMqttCredentials(): ?array
    {
        $parentId = $this->getCurrentParentId();
        if ($parentId <= 0 || !IPS_InstanceExists($parentId)) {
            return null;
        }

        try {
            $config = json_decode(IPS_GetConfiguration($parentId), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
        if (!is_array($config) || !array_key_exists('UserName', $config)) {
            return null;
        }

        return [
            'UserName' => trim((string)($config['UserName'] ?? '')),
            'Password' => (string)($config['Password'] ?? '')
        ];
    }

    private function formatAge(int $seconds): string
    {
        if ($seconds < 0) {
            return (string)$seconds . 's';
        }
        if ($seconds < 60) {
            return $seconds . 's';
        }
        if ($seconds < 3600) {
            return intdiv($seconds, 60) . 'm';
        }
        if ($seconds < 86400) {
            return intdiv($seconds, 3600) . 'h';
        }
        return intdiv($seconds, 86400) . 'd';
    }

    /**
     * Best effort: prüft, ob eine Subscription des MQTT-Client-Parents das Base-Topic abdeckt.
     * Liefert true/false bei klarer Aussage, null wenn die Parent-Konfiguration nicht lesbar ist.
     */
    private function parentSubscriptionCoversBaseTopic(string $baseTopic): ?bool
    {
        $parentId = $this->getCurrentParentId();
        if ($parentId <= 0 || !IPS_InstanceExists($parentId)) {
            return null;
        }

        try {
            $configJson = IPS_GetConfiguration($parentId);
            $config = json_decode($configJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
        if (!is_array($config)) {
            return null;
        }

        $subscriptions = HAMqttTopicFilter::collectSubscriptionsFromConfig($config);
        if ($subscriptions === []) {
            return null;
        }

        foreach ($subscriptions as $sub) {
            if (HAMqttTopicFilter::filterCoversPrefix($sub, $baseTopic)) {
                return true;
            }
        }

        return false;
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

    // Attributwert lesen; bei leerem Wert den (vom Aufrufer bereits übersetzten) Fallback liefern.
    // Der Translate()-Aufruf mit Literal bleibt bewusst beim Aufrufer (Locale-Check).
    private function attributeOrFallback(string $attribute, string $translatedFallback): string
    {
        $value = $this->ReadAttributeString($attribute);
        return $value === '' ? $translatedFallback : $value;
    }

    /**
     * Datenbeschaffung für die Diagnose-Captions (Attribute, Properties, Parent-Kontext).
     *
     * @return array{parentId: int, parentStatus: int, parentName: string, lastMqtt: string, baseTopic: string, lastRestError: string, lastRestResponse: string, lastRestTimeout: string}
     */
    private function collectDiagnosticsValues(): array
    {
        $parent = $this->buildCurrentParentDebugContext();

        return [
            'parentId'         => (int)($parent['ParentID'] ?? 0),
            'parentStatus'     => (int)($parent['ParentStatus'] ?? 0),
            'parentName'       => (string)($parent['ParentName'] ?? ''),
            'lastMqtt'         => $this->attributeOrFallback('LastMQTTMessage', $this->Translate('never')),
            'baseTopic'        => trim($this->ReadPropertyString('MQTTBaseTopic')),
            'lastRestError'    => $this->attributeOrFallback('LastRestError', $this->Translate('none')),
            'lastRestResponse' => $this->attributeOrFallback('LastRestResponse', $this->Translate('none')),
            'lastRestTimeout'  => $this->attributeOrFallback('LastRestTimeout', $this->Translate('none'))
        ];
    }

    private function buildDiagnosticsCaptions(): array
    {
        $values = $this->collectDiagnosticsValues();

        $statusName = $this->getInstanceStatusName($values['parentStatus']);
        $nameSuffix = $values['parentName'] !== '' ? ' (' . $values['parentName'] . ')' : '';
        $statusSuffix = $statusName !== '' ? '(' . $statusName . ')' : '';

        return [
            'LastMQTTMessage' => sprintf($this->Translate('Last MQTT message: %s'), $values['lastMqtt']),
            'DiagParent' => sprintf(
                $this->Translate('MQTT parent: %d%s | Status %d%s'),
                $values['parentId'],
                $nameSuffix,
                $values['parentStatus'],
                $statusSuffix
            ),
            'DiagBaseTopic' => sprintf(
                $this->Translate('MQTT base topic: %s'),
                $values['baseTopic'] !== '' ? $values['baseTopic'] : $this->Translate('empty')
            ),
            'DiagRest' => sprintf($this->Translate('Last REST error: %s'), $values['lastRestError']),
            'DiagRestResponse' => sprintf($this->Translate('Last REST response: %s'), $values['lastRestResponse']),
            'DiagRestTimeout' => sprintf($this->Translate('Last REST timeout: %s'), $values['lastRestTimeout'])
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
