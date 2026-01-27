<?php /** @noinspection AutoloadingIssuesInspection */

declare(strict_types=1);

require_once __DIR__ . '/../lib/HAIds.php';
require_once __DIR__ . '/../lib/HADebug.php';

class HomeAssistantConfigurator extends IPSModuleStrict
{
    use HADebugTrait;

    // ... Caches ...
    private array $entities = [];

    private const int ENTITY_SUMMARY_MAX_NAMES = 4;

    private const string HA_FULL_DATA_TEMPLATE = <<<'EOT'
[
    {% set domains = DOMAINS_PLACEHOLDER %}
    {% for state in states if state.domain in domains %}
    {
        "entity_id": "{{ state.entity_id }}",
        "domain": "{{ state.domain }}",
        "name": "{{ state.attributes.friendly_name | default(state.name) }}",
        "attributes": {{ state.attributes | to_json }},
        "device": "{{ device_attr(state.entity_id, 'name') | default('Unbekannt', true) }} ({{ area_name(state.entity_id) | default('Kein Bereich', true) }})",
        "device_name": "{{ device_attr(state.entity_id, 'name') | default('Unbekannt', true) }}",
        "device_id": "{{ device_id(state.entity_id) | default('none', true) }}",
        "area": "{{ area_name(state.entity_id) | default('Kein Bereich', true) }}"
    }{% if not loop.last %},{% endif %}
    {% endfor %}
]
EOT;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyBoolean('EnableExpertDebug', false);

        // Standard-Domänen im korrekten Listen-Format initialisieren (Array von Objekten)
        $defaultDomains = ['light', 'switch', 'sensor', 'binary_sensor', 'climate', 'number', 'lock', 'cover', 'event', 'select'];
        $domainList     = [];
        foreach ($defaultDomains as $d) {
            $domainList[] = ['Domain' => $d];
        }

        $this->RegisterPropertyString(
            'IncludeDomains',
            json_encode($domainList, JSON_THROW_ON_ERROR)
        );
        $this->RegisterPropertyString('DeviceMapping', '[]');
        $this->RegisterAttributeString('CachedEntities', json_encode([], JSON_THROW_ON_ERROR));
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->UpdateCacheFromHA();

        if (empty($this->entities)) {
            try {
                $this->entities = json_decode($this->ReadAttributeString('CachedEntities'), true, 512, JSON_THROW_ON_ERROR) ?? [];
            } catch (JsonException) {
                $this->entities = [];
            }
        }

        try {
            $domainsList = json_decode($this->ReadPropertyString('IncludeDomains'), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $domainsList = [];
        }
        foreach ($form['elements'] as &$element) {
            if (!isset($element['items']) || !is_array($element['items'])) {
                continue;
            }
            foreach ($element['items'] as &$item) {
                if (($item['name'] ?? '') === 'IncludeDomains') {
                    $item['values'] = $domainsList;
                    break 2;
                }
            }
            unset($item);
        }
        unset($element);

        $devices = $this->groupEntitiesToDevices($this->entities);
        $values  = $this->prepareConfiguratorValues($devices);

        $form['actions'][] = [
            'type'     => 'Configurator',
            'name'     => 'HomeAssistantDevices',
            'caption'  => 'Found Devices',
            'rowCount' => 20,
            'add'      => false,
            'delete'   => true,
            'columns'  => [
                ['caption' => 'Area', 'name' => 'Area', 'width' => '150px'],
                ['caption' => 'Device', 'name' => 'name', 'width' => '250px'],
                ['caption' => 'Entities', 'name' => 'Summary', 'width' => 'auto']
            ],
            'values'   => $values
        ];

        return json_encode($form, JSON_THROW_ON_ERROR);
    }

    private function groupEntitiesToDevices(array $entities): array
    {
        $devices = [];
        foreach ($entities as $entity) {
            $isRealDevice    = $entity['device_id'] !== 'none';
            $uniqueDeviceKey = $isRealDevice ? 'HA_DEV_' . $entity['device_id'] : 'HA_ENT_' . str_replace('.', '_', $entity['entity_id']);

            if (!isset($devices[$uniqueDeviceKey])) {
                $devices[$uniqueDeviceKey] = [
                    'ident'     => $uniqueDeviceKey,
                    'name'      => $isRealDevice ? $entity['device'] : $entity['name'],
                    'device_id' => $isRealDevice ? $entity['device_id'] : '-',
                    'area'      => $isRealDevice ? $entity['area'] : 'Sonstiges',
                    'entities'  => [],
                ];
            }
            $devices[$uniqueDeviceKey]['entities'][] = [
                'domain'    => $entity['domain'],
                'name'      => $entity['name'],
                'entity_id' => $entity['entity_id']
            ];
        }

        uasort($devices, static fn($a, $b) => strcasecmp($a['area'] . '_' . $a['name'], $b['area'] . '_' . $b['name']));
        return $devices;
    }

    private function prepareConfiguratorValues(array $devices): array
    {
        // --- Matching Logic: Finde existierende Instanzen ---
        $existingInstances = IPS_GetInstanceListByModuleID(HAIds::MODULE_DEVICE);
        $mappedInstances   = [];

        foreach ($existingInstances as $id) {
            $devID = (string)@IPS_GetProperty($id, 'DeviceID');
            if ($devID !== '') {
                $mappedInstances[$devID] = $id;
            }
        }

        $values = [];
        foreach ($devices as $dev) {
            $this->debugExpert(__FUNCTION__, json_encode($dev, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));

            $instanceID = 0;

            // 1. Matching via DeviceID
            if (isset($dev['device_id'], $mappedInstances[$dev['device_id']]) && $dev['device_id'] !== 'none') {
                $instanceID = $mappedInstances[$dev['device_id']];
            }

            $cleanedEntities = $this->getCleanedEntities($dev);
            $cleanedNameById = array_column($cleanedEntities, 'name', 'entity_id');

            // Hier holen wir die Entitäten inklusive aller Attribute für die Konfiguration
            $entitiesForConfig = [];
            foreach ($dev['entities'] as $entity) {
                // Daten mergen (Cache + Aktuell)
                $finalEntity = $entity;
                if (isset($cleanedNameById[$entity['entity_id']])) {
                    $finalEntity['name'] = $cleanedNameById[$entity['entity_id']];
                }
                if (isset($this->entities[$entity['entity_id']])) {
                    $cached = $this->entities[$entity['entity_id']];
                    // Name und Attribute vom Cache übernehmen/aktualisieren
                    $finalEntity = array_merge($finalEntity, [
                        'attributes' => $cached['attributes'] ?? []
                    ]);
                }

                // Attribute zu String
                if (isset($finalEntity['attributes']) && is_array($finalEntity['attributes'])) {
                    $finalEntity['attributes'] = json_encode(
                        $finalEntity['attributes'],
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
                    );
                } else {
                    $finalEntity['attributes'] = '{}';
                }

                // Aufräumen: Diese Daten sind jetzt redundant, da im Device global gespeichert
                unset($finalEntity['device_id'], $finalEntity['area'], $finalEntity['device']);
                // Alter Gerätename

                $entitiesForConfig[] = $finalEntity;
            }

            $values[] = [
                'instanceID' => $instanceID,
                'name'       => $dev['name'],
                'Area'       => $dev['area'],
                'DeviceID'   => $dev['device_id'],
                'Summary'    => $this->generateEntitySummary($cleanedEntities),
                'group'      => $dev['area'],
                'create'     => [
                    'moduleID'      => HAIds::MODULE_DEVICE,
                    'configuration' => [
                        // Zentrale Properties setzen
                        'DeviceID'     => $dev['device_id'],
                        'DeviceArea'   => $dev['area'],
                        'DeviceName'   => $dev['name'],
                        // Bereinigte Liste übergeben
                        'DeviceConfig' => json_encode($entitiesForConfig, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
                    ],
                    'name'          => $dev['name']
                ]
            ];
        }
        return $values;
    }

    /**
     * Bereinigt die Entitäts-Namen eines Geräts, indem ein führender Bereichs-Präfix entfernt wird.
     *
     * @param array $dev Das gruppierte Gerät inklusive seiner Entitäten.
     *
     * @return array Liste der bereinigten Entitäten.
     */
    private function getCleanedEntities(array $dev): array
    {
        $cleaned      = [];
        $devicePrefix = $dev['name'] . ' ';
        $nameCounts   = [];

        foreach ($dev['entities'] as $entity) {
            $name = $entity['name'];
            if (str_starts_with($name, $devicePrefix)) {
                $name = substr($name, strlen($devicePrefix));
            }
            $nameCounts[$name] = ($nameCounts[$name] ?? 0) + 1;
            $cleaned[]         = array_merge($entity, ['name' => $name]);
        }

        foreach ($cleaned as &$entity) {
            if (($nameCounts[$entity['name']] ?? 0) > 1) {
                $entityIdName = $entity['entity_id'];
                $dotPos       = strpos($entityIdName, '.');
                if ($dotPos !== false) {
                    $entityIdName = substr($entityIdName, $dotPos + 1);
                }
                $entityIdName      = str_replace('_', ' ', $entityIdName);
                $devicePrefixLower = strtolower($dev['name']) . ' ';
                $entityIdNameLower = strtolower($entityIdName);
                if (str_starts_with($entityIdNameLower, $devicePrefixLower)) {
                    $entityIdName = substr($entityIdName, strlen($devicePrefixLower));
                }
                $entityIdName = trim($entityIdName);
                if ($entityIdName !== '') {
                    $entityIdName = ucwords(strtolower($entityIdName));
                }
                $entity['name'] = $entityIdName;
            }
        }
        unset($entity);

        return $cleaned;
    }

    /**
     * Erstellt eine kompakte Zusammenfassung der Entitäten für die Anzeige im Konfigurator.
     *
     * Bei bis zu drei Entitäten werden deren Namen aufgelistet, ab vier Entitäten
     * wird lediglich die Gesamtzahl ausgegeben.
     *
     * @param array $entities Liste der (bereinigten) Entitäten.
     *
     * @return string Zusammenfassender Text für die Spalte 'Entitäten'.
     */
    private function generateEntitySummary(array $entities): string
    {
        if (count($entities) <= self::ENTITY_SUMMARY_MAX_NAMES) {
            return implode(', ', array_column($entities, 'name'));
        }
        return count($entities) . ' Entitäten';
    }

    /**
     * Aktualisiert den internen Cache der Home Assistant Entitäten.
     *
     * Neu: Führt nur noch EINEN Request aus, der gefilterte Entitäten inkl.
     * aller Geräte-Metadaten zurückliefert.
     */
    private function UpdateCacheFromHA(): void
    {
        $instance = IPS_GetInstance($this->InstanceID);
        $parentID = $instance['ConnectionID'];

        // Domänen aus der Listen-Struktur extrahieren.
        // Format ist: [{"Domain": "light"}, {"Domain": "switch"}]
        try {
            $domainsList = json_decode($this->ReadPropertyString('IncludeDomains'), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $domainsList = [];
        }
        $domainsSimple = array_column($domainsList, 'Domain');
        $domainsJson   = json_encode($domainsSimple, JSON_THROW_ON_ERROR);

        if ($parentID <= 0) {
            return;
        }

        // 1. Template vorbereiten (Domains injizieren)
        // wir nutzen str_replace statt sprintf, um Konflikte mit Jinja2 Syntax ({% ... %}) zu vermeiden
        $template = str_replace('DOMAINS_PLACEHOLDER', $domainsJson, self::HA_FULL_DATA_TEMPLATE);

        // 2. Alles in einem Request abholen
        $rawEntities = $this->RenderHATemplate(trim($template));

        if (empty($rawEntities)) {
            $this->debugExpert('UpdateCache', 'No entities found or API error');
            return;
        }

        // 3. Daten indizieren (Array Key = entity_id)
        $newEntities = [];
        foreach ($rawEntities as $entity) {
            // Fallback für den Anzeigenamen des Geräts, falls 'Unbekannt'
            if ($entity['device_name'] === 'Unbekannt' || $entity['device_id'] === 'none') {
                $entity['device'] = ucfirst($entity['domain']) . ' (Ohne Gerät)';
            } else {
                // Sauberer Gerätename ohne Bereich in Klammern für die Anzeige
                $entity['device'] = $entity['device_name'];
            }

            $newEntities[$entity['entity_id']] = $entity;
        }

        // 4. Persistieren
        $this->entities = $newEntities;
        $this->WriteAttributeString('CachedEntities', json_encode($this->entities, JSON_THROW_ON_ERROR));

        // 5. Protokollieren
        $this->LogUpdatedEntities();
    }

    /**
     * Transformiert die Rohdaten einer Home Assistant Entität in ein internes Format.
     *
     * Dabei werden Geräteinformationen (Name, ID, Bereich) zugeordnet und ein
     * Anzeigename für das Gerät generiert, falls die Entität keinem spezifischen
     * Gerät zugeordnet ist.
     *
     * @param array      $state      Die Zustandsdaten der Entität aus der HA-API.
     * @param array|null $deviceInfo Optionale Geräte-Metadaten (Name, ID, Bereich).
     *
     * @return array Die gemappten Entitätsdaten für den Cache und den Konfigurator.
     */

    /**
     * Sortiert die aktuell geladenen Entitäten nach Bereich, Gerät und Namen
     * und gibt diese zur Kontrolle im Debug-Log aus.
     *
     * @return void
     * @throws \JsonException
     * @throws \JsonException
     */
    private function LogUpdatedEntities(): void
    {
        $debugList = $this->entities;
        uasort($debugList, static function ($a, $b) {
            $areaA = ($a['area'] === 'Kein Bereich' || empty($a['area'])) ? 'zzz' : $a['area'];
            $areaB = ($b['area'] === 'Kein Bereich' || empty($b['area'])) ? 'zzz' : $b['area'];
            $res   = strcasecmp($areaA, $areaB);
            if (strcasecmp($a['device'], $b['device'])) {
                return ($res !== 0) ? $res : (strcasecmp($a['device'], $b['device']));
            }

            return ($res !== 0) ? $res : (strcasecmp($a['name'], $b['name']));
        });

        foreach ($debugList as $entity) {
            // Attribute für die Debug-Ausgabe als String formatieren
            $attributes = json_encode($entity['attributes'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->debugExpert(
                __FUNCTION__,
                sprintf("Found: %s | %s | %s | Attr: %s", $entity['area'], $entity['device'], $entity['name'], $attributes)
            );
        }
    }

    private function RenderHATemplate(string $template): array
    {
        $postData = json_encode(['template' => $template], JSON_THROW_ON_ERROR);
        return $this->sendRestRequestToParent('/api/template', $postData) ?? [];
    }

    private function sendRestRequestToParent(string $endpoint, ?string $postData): ?array
    {
        $payload = json_encode([
                                   'DataID'   => HAIds::DATA_DEVICE_TO_SPLITTER,
                                   'Endpoint' => $endpoint,
                                   'Method'   => $postData !== null ? 'POST' : 'GET',
                                   'Body'     => $postData
                               ],
                               JSON_THROW_ON_ERROR);

        $responseJson = $this->SendDataToParent($payload);
        if ($responseJson === '') {
            $this->debugExpert('REST', 'Empty response from parent');
            return null;
        }

        try {
            $response = json_decode($responseJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->debugExpert('REST', 'Invalid response: ' . $e->getMessage());
            return null;
        }
        if (!is_array($response)) {
            $this->debugExpert('REST', 'Invalid response: ' . $responseJson);
            return null;
        }
        if (isset($response['Error'])) {
            $this->debugExpert('REST', 'Parent error: ' . json_encode($response, JSON_THROW_ON_ERROR));
            return null;
        }

        $body = (string)($response['Response'] ?? '');
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->debugExpert('REST', 'Non-JSON response: ' . $e->getMessage());
            return null;
        }
        if (!is_array($decoded)) {
            $this->debugExpert('REST', 'Non-JSON response: ' . $body);
            return null;
        }
        return $decoded;
    }

}
