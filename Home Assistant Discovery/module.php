<?php

/** @noinspection PhpUnused */

/** @noinspection AutoloadingIssuesInspection */

declare(strict_types=1);

require_once __DIR__ . '/../libs/HAIds.php';
require_once __DIR__ . '/../libs/ModuleDebug.php';

class HomeAssistantDiscovery extends IPSModuleStrict
{
    use ModuleDebugTrait;

    // GUIDs ohne Typisierung für PHP 8.0
    private const string DISCOVERY_SEARCHTARGET = '_home-assistant._tcp';
    private const string BUFFER_SERVERS         = 'Servers';
    private const string BUFFER_SEARCHACTIVE    = 'SearchActive';
    private const string TIMER_LOAD             = 'DiscoveryTimer';
    // Kurze Verzögerung, damit GetConfigurationForm sofort zurückkehrt und die
    // mDNS-Suche danach im Timer läuft (vorher stand hier versehentlich 120*1200 ms = 144 s).
    private const int SEARCH_START_DELAY_MS     = 1000;

    public function Create(): void
    {
        parent::Create();
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
        $this->RegisterTimer(self::TIMER_LOAD, 0, 'IPS_RequestAction($_IPS["TARGET"], "discover", "");');
        $this->RegisterPropertyBoolean('EnableExpertDebug', false);

        $this->SetBuffer(self::BUFFER_SERVERS, json_encode([], JSON_THROW_ON_ERROR));
        $this->markSearchInactive();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->markSearchInactive();
    }

    private function markSearchInactive(): void
    {
        $this->SetBuffer(self::BUFFER_SEARCHACTIVE, json_encode(false, JSON_THROW_ON_ERROR));
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if (($Message === IPS_KERNELMESSAGE) && ($Data[0] === KR_READY)) {
            $this->ApplyChanges();
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident === 'discover') {
            $this->SetTimerInterval(self::TIMER_LOAD, 0);
            try {
                $this->performDiscovery();
            } catch (Throwable $e) {
                $this->debugExpert('ERROR', $e->getMessage());
                $this->stopSearch();
            }
        }
    }

    /**
     * Steuert den gesamten Suchprozess
     */
    private function performDiscovery(): void
    {
        $this->debugExpert('Discovery', 'Starting search process...');

        // 1. Bereits erstellte Konfiguratoren laden
        $existingConfigurators = IPS_GetInstanceListByModuleID(HAIds::MODULE_CONFIGURATOR);

        // 2. Netzwerk nach neuen Servern scannen
        $foundServers = $this->scanNetworkForServers();

        // 3. Ergebnisse abgleichen (Gefundene vs. Existierende)
        $formValues = $this->mapServersToForm($foundServers, $existingConfigurators);

        // 4. GUI Update
        $jsonValues = json_encode($formValues, JSON_THROW_ON_ERROR);
        $this->debugExpert('Discovery', "Final List: $jsonValues");

        $this->SetBuffer(self::BUFFER_SERVERS, $jsonValues);
        $this->UpdateFormField('configurator', 'values', $jsonValues);

        $this->stopSearch();
    }

    private function stopSearch(): void
    {
        $this->markSearchInactive();
        $this->UpdateFormField('searchingInfo', 'visible', false);
    }

    /**
     * Führt mDNS Browsing und Resolving durch
     */
    private function scanNetworkForServers(): array
    {
        $mdnsID = $this->getMdnsInstance();

        // Phase 1: Browsing
        ZC_QueryServiceType($mdnsID, self::DISCOVERY_SEARCHTARGET, '');
        $this->debugExpert('mDNS', 'Browsing... waiting 2s');
        sleep(2);

        $services = ZC_QueryServiceType($mdnsID, self::DISCOVERY_SEARCHTARGET, '');

        $processedServers = [];
        foreach ($services as $service) {
            // Phase 2: Resolving, falls IPv4 fehlt
            if (empty($service['IPv4'])) {
                $service = $this->resolveServiceDetails($mdnsID, $service);
            }

            // Daten normalisieren
            $serverInfo = $this->parseServiceData($service);
            if ($serverInfo !== null) {
                $processedServers[] = $serverInfo;
            }
        }

        return $processedServers;
    }

    /**
     * Versucht Details (IP, Port, TXT) nachzuladen
     */
    private function resolveServiceDetails(int $mdnsID, array $service): array
    {
        try {
            $this->debugExpert('mDNS', 'Resolving: ' . $service['Name']);
            $details = ZC_QueryService($mdnsID, $service['Name'], $service['Type'], $service['Domain']);
            // FIX: Das Array "auspacken", da ZC_QueryService eine Liste liefert
            $detailItem = $details[0] ?? $details;
            return array_merge($service, $detailItem);
        } catch (Throwable $e) {
            $this->debugExpert('mDNS Error', 'Resolve failed: ' . $e->getMessage());
        }
        return $service;
    }

    /**
     * Extrahiert relevante Daten aus dem mDNS-Ergebnis
     */
    private function parseServiceData(array $service): ?array
    {
        $ip = $this->determineIp($service);
        if ($ip === '') {
            return null;
        }

        $txt = $this->mergeTxtRecords($service);

        $name    = $txt['location_name'] ?? 'Home Assistant';
        $version = $txt['version'] ?? 'N/A';
        $port    = $service['Port'] ?? 8123;
        $url     = $txt['base_url'] ?? sprintf('http://%s:%s', $ip, $port);

        return [
            'name'    => $name,
            'host'    => $ip,
            'version' => $version,
            'url'     => $url
        ];
    }

    /**
     * Ermittelt die IP-Adresse (IPv4 Feld oder Hostname-Auflösung)
     */
    private function determineIp(array $service): string
    {
        if (!empty($service['IPv4'])) {
            return $service['IPv4'][0];
        }

        if (!empty($service['Host'])) {
            $host = rtrim($service['Host'], '.');
            return gethostbyname($host);
        }

        return '';
    }

    /**
     * Führt TXTStrings und TXTRecords zusammen
     */
    private function mergeTxtRecords(array $service): array
    {
        $raw = [];
        foreach (['TXTStrings', 'TXTRecords'] as $key) {
            if (isset($service[$key]) && is_array($service[$key])) {
                $raw = array_merge($raw, $service[$key]);
            }
        }

        $result = [];
        foreach ($raw as $line) {
            if (is_string($line) && str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $result[$key] = $value;
            }
        }
        return $result;
    }

    /**
     * Verbindet gefundene Server mit existierenden Symcon-Instanzen
     */
    private function mapServersToForm(array $foundServers, array $existingInstanceIDs): array
    {
        $formValues = [];

        // Map: IP/Host => InstanzID (inklusive unvollständiger Konfiguratoren)
        $hostToId = [];
        foreach ($existingInstanceIDs as $id) {
            $host = $this->getConfiguratorHost($id);
            if ($host !== '') {
                $hostToId[$host] = $id;
            } else {
                $hostToId['__missing__' . $id] = $id;
            }
        }

        // 1. Gefundene Server hinzufügen
        foreach ($foundServers as $server) {
            $host       = $server['host'];
            $instanceID = $hostToId[$host] ?? 0;

            if (isset($hostToId[$host])) {
                unset($hostToId[$host]);
            }

            $formValues[] = $this->buildServerRow(
                $server['name'],
                $host,
                $server['version'],
                $server['url'],
                $instanceID,
                $this->buildCreateBlueprint($server, $host)
            );
        }

        // 2. Offline Instanzen hinzufügen
        foreach ($hostToId as $host => $id) {
            $displayHost = str_starts_with($host, '__missing__') ? $this->Translate('unknown') : $host;
            $formValues[] = $this->buildServerRow(
                IPS_GetName($id),
                $displayHost,
                $this->Translate('unknown'),
                $this->Translate('offline'),
                $id,
                []
            );
        }

        return $formValues;
    }

    /**
     * Einheitliche Zeile der Discovery-Tabelle (gefundene wie offline Instanzen).
     */
    private function buildServerRow(string $name, string $host, string $version, string $url, int $instanceID, array $create): array
    {
        return [
            'name'       => $name,
            'host'       => $host,
            'version'    => $version,
            'url'        => $url,
            'instanceID' => $instanceID,
            'create'     => $create
        ];
    }

    /**
     * Instanz-Blueprint (Configurator → Splitter → MQTT Client → Client Socket)
     * für einen gefundenen Home-Assistant-Server.
     */
    private function buildCreateBlueprint(array $server, string $host): array
    {
        return [
            [
                'moduleID'      => HAIds::MODULE_CONFIGURATOR,
                'configuration' => new stdClass(),
                'name'          => 'Home Assistant Konfigurator'
            ],
            [
                'moduleID'      => HAIds::MODULE_SPLITTER,
                'configuration' => [
                    'HAUrl' => $server['url']
                ],
                'name'          => 'Home Assistant Splitter'
            ],
            [
                'moduleID'      => HAIds::MODULE_MQTT_CLIENT,
                'configuration' => [
                    'ClientID'          => $this->buildMqttClientId(),
                    'KeepAliveInterval' => 60,
                    'Subscriptions'     => json_encode([['Topic' => 'homeassistant/#', 'QoS' => 1]], JSON_THROW_ON_ERROR),
                ],
                'name'          => 'MQTT Client Home Assistant'
            ],
            [
                'moduleID'      => HAIds::MODULE_CLIENTSOCKET,
                'configuration' => [
                    'Host' => $host,
                    'Open' => true,
                    'Port' => 1883
                ],
                'name'          => 'Client Socket Home Assistant'
            ]
        ];
    }

    private function getConfiguratorHost(int $id): string
    {
        // Legacy: Configurator had HAUrl stored directly.
        $host = $this->hostFromInstanceUrl($id);
        if ($host !== '') {
            return $host;
        }

        // Current: Configurator gets HAUrl from the parent splitter.
        $instance = IPS_GetInstance($id);
        $parentId = (int)($instance['ConnectionID'] ?? 0);
        if ($parentId <= 0) {
            return '';
        }

        return $this->hostFromInstanceUrl($parentId);
    }

    /**
     * Host-Anteil der HAUrl-Eigenschaft einer Instanz (leer, wenn nicht ermittelbar).
     */
    private function hostFromInstanceUrl(int $instanceId): string
    {
        $url = (string)@IPS_GetProperty($instanceId, 'HAUrl');
        if ($url === '') {
            return '';
        }

        $host = parse_url($url, PHP_URL_HOST);
        return is_string($host) ? $host : '';
    }

    private function buildMqttClientId(): string
    {
        $host = gethostname();
        if (!is_string($host) || $host === '') {
            $host = php_uname('n');
        }

        $clean = preg_replace('/[^a-zA-Z0-9-]/', '-', $host) ?? '';
        $clean = trim($clean, '-');
        if ($clean === '') {
            $clean = 'symcon';
        }
        return 'symcon-ha-' . $clean;
    }

    /**
     * Helper: mDNS Instanz ID holen oder erstellen
     */
    private function getMdnsInstance(): int
    {
        $ids = IPS_GetInstanceListByModuleID(HAIds::MODULE_MDNS);
        if (count($ids) > 0) {
            return $ids[0];
        }

        // Fehlerbehandlung: Prüfen, ob die Erstellung erfolgreich war
        $id = IPS_CreateInstance(HAIds::MODULE_MDNS);
        if ($id === 0) {
            throw new RuntimeException(
                $this->Translate('Could not create DNS-SD Control instance. Please check the license limit or whether the module is available.')
            );
        }

        IPS_SetName($id, 'DNS-SD Control');
        IPS_ApplyChanges($id);
        return $id;
    }

    public function GetConfigurationForm(): string
    {
        $searchActive = json_decode($this->GetBuffer(self::BUFFER_SEARCHACTIVE), false, 512, JSON_THROW_ON_ERROR);

        if (!$searchActive) {
            $this->SetBuffer(self::BUFFER_SEARCHACTIVE, json_encode(true, JSON_THROW_ON_ERROR));
            $this->SetTimerInterval(self::TIMER_LOAD, self::SEARCH_START_DELAY_MS);
        }

        $elements   = $this->formElements();
        $actions    = $this->formActions();
        $status     = [];

        return json_encode(compact('elements', 'actions', 'status'), JSON_THROW_ON_ERROR);
    }

    private function formElements(): array
    {
        return [
            [
                'type'    => 'ExpansionPanel',
                'caption' => $this->Translate('Expert'),
                'items'   => [
                    [
                        'type'    => 'CheckBox',
                        'name'    => 'EnableExpertDebug',
                        'caption' => $this->Translate('Enable extended debug output')
                    ]
                ]
            ]
        ];
    }

    private function formActions(): array
    {
        $devices = json_decode($this->GetBuffer(self::BUFFER_SERVERS), false, 512, JSON_THROW_ON_ERROR);

        return [
            [
                'name'          => 'searchingInfo',
                'type'          => 'ProgressBar',
                'caption'       => $this->Translate('Searching for Home Assistant instances via mDNS (wait 2s)...'),
                'indeterminate' => true,
                'visible'       => count($devices) === 0
            ],
            [
                'name'     => 'configurator',
                'type'     => 'Configurator',
                'rowCount' => 20,
                'add'      => false,
                'delete'   => true,
                'sort'     => [
                    'column'    => 'host',
                    'direction' => 'ascending'
                ],
                'columns'  => $this->configuratorColumns(),
                'values'   => $devices
            ]
        ];
    }

    private function configuratorColumns(): array
    {
        return [
            [
                'caption' => $this->Translate('Name'),
                'name'    => 'name',
                'width'   => '200px'
            ],
            [
                'caption' => $this->Translate('IP Address'),
                'name'    => 'host',
                'width'   => '150px'
            ],
            [
                'caption' => $this->Translate('Version'),
                'name'    => 'version',
                'width'   => '100px'
            ],
            [
                'caption' => $this->Translate('Base URL'),
                'name'    => 'url',
                'width'   => 'auto'
            ]
        ];
    }

}
