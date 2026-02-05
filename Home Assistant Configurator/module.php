<?php /** @noinspection AutoloadingIssuesInspection */

declare(strict_types=1);

require_once __DIR__ . '/../libs/HAIds.php';
require_once __DIR__ . '/../libs/HADebug.php';
require_once __DIR__ . '/../libs/HALightDefinitions.php';
require_once __DIR__ . '/../libs/HASwitchDefinitions.php';
require_once __DIR__ . '/../libs/HASensorDefinitions.php';
require_once __DIR__ . '/../libs/HABinarySensorDefinitions.php';
require_once __DIR__ . '/../libs/HAClimateDefinitions.php';
require_once __DIR__ . '/../libs/HANumberDefinitions.php';
require_once __DIR__ . '/../libs/HALockDefinitions.php';
require_once __DIR__ . '/../libs/HACoverDefinitions.php';
require_once __DIR__ . '/../libs/HAEventDefinitions.php';
require_once __DIR__ . '/../libs/HASelectDefinitions.php';
require_once __DIR__ . '/../libs/HAVacuumDefinitions.php';

class HomeAssistantConfigurator extends IPSModuleStrict
{
    use HADebugTrait;

    // ... Caches ...
    private array $entities = [];

    // Max. Anzahl an Entity-Namen in der Zusammenfassung (danach nur Anzahl).
    private const int ENTITY_SUMMARY_MAX_NAMES = 10;
    // Anzahl Entities pro Template-Request, um die HA-Output-Grenze einzuhalten.
    private const int ENTITY_CHUNK_SIZE = 50;

    private const string HA_FULL_DATA_TEMPLATE = <<<'EOT'
[
    {# Rekursive JSON-Sanitizer: wandelt Sets/Iterables in JSON-kompatible Listen um #}
    {% macro sanitize_json(value, depth=0) -%}
    {%- if depth > 4 -%}
    {{ 'null' }}
    {%- elif value is mapping -%}
    {%- set ns = namespace(items=[]) -%}
    {%- for k, v in value.items() -%}
    {%- set ns.items = ns.items + [(k | to_json) ~ ':' ~ sanitize_json(v, depth + 1)] -%}
    {%- endfor -%}
    {{ '{' ~ (ns.items | join(',')) ~ '}' }}
    {%- elif value is iterable and value is not string -%}
    {%- set ns = namespace(items=[]) -%}
    {%- for v in value -%}
    {%- set ns.items = ns.items + [sanitize_json(v, depth + 1)] -%}
    {%- endfor -%}
    {{ '[' ~ (ns.items | join(',')) ~ ']' }}
    {%- else -%}
    {%- if value is string or value is number or value is boolean -%}
    {{ value | to_json }}
    {%- else -%}
    {{ value | string | to_json }}
    {%- endif -%}
    {%- endif -%}
    {%- endmacro %}

    {% set domains = DOMAINS_PLACEHOLDER %}
    {% for state in states if state.domain in domains %}
    {
        "entity_id": "{{ state.entity_id }}",
        "domain": "{{ state.domain }}",
        "name": "{{ state.attributes.friendly_name | default(state.name) }}",
        "attributes": {{ sanitize_json(state.attributes) }},
        "device": "{{ device_attr(state.entity_id, 'name') | default('Unbekannt', true) }} ({{ area_name(state.entity_id) | default('Kein Bereich', true) }})",
        "device_name": "{{ device_attr(state.entity_id, 'name') | default('Unbekannt', true) }}",
        "device_id": "{{ device_id(state.entity_id) | default('none', true) }}",
        "area": "{{ area_name(state.entity_id) | default('Kein Bereich', true) }}",
        "supported_features": {{ state.attributes.supported_features | default(0) | int }}
    }{% if not loop.last %},{% endif %}
    {% endfor %}
]
EOT;

    private const string HA_ENTITY_ID_TEMPLATE = <<<'EOT'
[
    {% set domains = DOMAINS_PLACEHOLDER %}
    {% for state in states if state.domain in domains %}
    {{ state.entity_id | to_json }}{% if not loop.last %},{% endif %}
    {% endfor %}
]
EOT;

    private const string HA_FULL_DATA_TEMPLATE_BY_ENTITY = <<<'EOT'
[
    {# Rekursive JSON-Sanitizer: wandelt Sets/Iterables in JSON-kompatible Listen um #}
    {% macro sanitize_json(value, depth=0) -%}
    {%- if depth > 4 -%}
    {{ 'null' }}
    {%- elif value is mapping -%}
    {%- set ns = namespace(items=[]) -%}
    {%- for k, v in value.items() -%}
    {%- set ns.items = ns.items + [(k | to_json) ~ ':' ~ sanitize_json(v, depth + 1)] -%}
    {%- endfor -%}
    {{ '{' ~ (ns.items | join(',')) ~ '}' }}
    {%- elif value is iterable and value is not string -%}
    {%- set ns = namespace(items=[]) -%}
    {%- for v in value -%}
    {%- set ns.items = ns.items + [sanitize_json(v, depth + 1)] -%}
    {%- endfor -%}
    {{ '[' ~ (ns.items | join(',')) ~ ']' }}
    {%- else -%}
    {%- if value is string or value is number or value is boolean -%}
    {{ value | to_json }}
    {%- else -%}
    {{ value | string | to_json }}
    {%- endif -%}
    {%- endif -%}
    {%- endmacro %}

    {% set entities = ENTITIES_PLACEHOLDER %}
    {% for state in states if state.entity_id in entities %}
    {
        "entity_id": "{{ state.entity_id }}",
        "domain": "{{ state.domain }}",
        "name": "{{ state.attributes.friendly_name | default(state.name) }}",
        "attributes": {{ sanitize_json(state.attributes) }},
        "device": "{{ device_attr(state.entity_id, 'name') | default('Unbekannt', true) }} ({{ area_name(state.entity_id) | default('Kein Bereich', true) }})",
        "device_name": "{{ device_attr(state.entity_id, 'name') | default('Unbekannt', true) }}",
        "device_id": "{{ device_id(state.entity_id) | default('none', true) }}",
        "area": "{{ area_name(state.entity_id) | default('Kein Bereich', true) }}",
        "supported_features": {{ state.attributes.supported_features | default(0) | int }}
    }{% if not loop.last %},{% endif %}
    {% endfor %}
]
EOT;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyBoolean('EnableExpertDebug', false);

        // Standard-Domänen im korrekten Listen-Format initialisieren (Array von Objekten)
        $defaultDomains = [
            HALightDefinitions::DOMAIN,
            HASwitchDefinitions::DOMAIN,
            HASensorDefinitions::DOMAIN,
            HABinarySensorDefinitions::DOMAIN,
            HAClimateDefinitions::DOMAIN,
            HANumberDefinitions::DOMAIN,
            HALockDefinitions::DOMAIN,
            HACoverDefinitions::DOMAIN,
            HAEventDefinitions::DOMAIN,
            HASelectDefinitions::DOMAIN,
            HAVacuumDefinitions::DOMAIN
        ];
        $domainList     = [];
        foreach ($defaultDomains as $d) {
            $domainList[] = ['Domain' => $d];
        }

        $this->RegisterPropertyString(
            'IncludeDomains',
            json_encode($domainList, JSON_THROW_ON_ERROR)
        );
        $this->RegisterPropertyInteger('OutputBufferSize', 10);
        $this->RegisterPropertyString('DeviceMapping', '[]');
        $this->RegisterAttributeString('CachedEntities', json_encode([], JSON_THROW_ON_ERROR));
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->UpdateCacheFromHA();

        $bufferSizeMb = max(0, $this->ReadPropertyInteger('OutputBufferSize'));
        if ($bufferSizeMb > 0) {
            $bufferSizeBytes = $bufferSizeMb * 1024 * 1024;
            ini_set('ips.output_buffer', (string) $bufferSizeBytes);
        }

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
        $values = $this->prepareConfiguratorValues($devices);

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
                'device_id' => $isRealDevice ? $entity['device_id'] : $entity['entity_id'],
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

                if (isset($finalEntity['attributes']['device_class'])
                    && (!isset($finalEntity['device_class'])
                        || trim((string)$finalEntity['device_class']) === '')
                    && is_array($finalEntity['attributes'])
                    && is_string($finalEntity['attributes']['device_class'])) {
                    $finalEntity['device_class'] = trim($finalEntity['attributes']['device_class']);
                }

                $this->enrichSupportedFeaturesList($finalEntity);

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

    private function enrichSupportedFeaturesList(array &$entity): void
    {
        if (!isset($entity['attributes']) || !is_array($entity['attributes'])) {
            return;
        }
        if (isset($entity['attributes']['supported_features_list'])) {
            return;
        }
        if (!isset($entity['attributes']['supported_features']) || !is_numeric($entity['attributes']['supported_features'])) {
            return;
        }

        $domain = (string)($entity['domain'] ?? '');
        if ($domain === '' && isset($entity['entity_id']) && str_contains($entity['entity_id'], '.')) {
            [$domain] = explode('.', (string)$entity['entity_id'], 2);
        }

        $list = $this->mapSupportedFeaturesByDomain($domain, (int)$entity['attributes']['supported_features']);
        if ($list !== []) {
            $entity['attributes']['supported_features_list'] = $list;
        }
    }

    private function mapSupportedFeaturesByDomain(string $domain, int $mask): array
    {
        $map = match ($domain) {
            HALightDefinitions::DOMAIN => HALightDefinitions::SUPPORTED_FEATURES,
            HAClimateDefinitions::DOMAIN => HAClimateDefinitions::SUPPORTED_FEATURES,
            HACoverDefinitions::DOMAIN => HACoverDefinitions::SUPPORTED_FEATURES,
            HALockDefinitions::DOMAIN => HALockDefinitions::SUPPORTED_FEATURES,
            HAVacuumDefinitions::DOMAIN => HAVacuumDefinitions::SUPPORTED_FEATURES,
            default => []
        };

        if ($map === []) {
            return [];
        }

        $list = [];
        foreach ($map as $bit => $label) {
            if (($mask & (int)$bit) === (int)$bit) {
                $list[] = $label;
            }
        }
        return $list;
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
     * Bei bis zu <ENTITY_SUMMARY_MAX_NAMES> Entitäten werden deren Namen aufgelistet, bei mehr Entitäten
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
     * Neu: Führt nur noch EINEN Request aus, der gefilterte Entitäten inklusive
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
        if ($parentID <= 0) {
            return;
        }

        $newEntities = [];
        $domainsSimple = array_column($domainsList, 'Domain');
        $domainChunks = array_chunk($domainsSimple, 1);
        foreach ($domainChunks as $chunk) {
            $this->debugExpert('UpdateCache', 'Request Domain', ['Domains' => $chunk]);
            $entityIds = $this->fetchEntityIdsForDomains($chunk);
            if ($entityIds === null) {
                $this->debugExpert('UpdateCache', 'API-Fehler (IDs)', ['Domains' => $chunk]);
                continue;
            }
            if ($entityIds === []) {
                $this->debugExpert('UpdateCache', 'Keine Entities', ['Domains' => $chunk]);
                continue;
            }

            $idChunks = array_chunk($entityIds, self::ENTITY_CHUNK_SIZE);
            foreach ($idChunks as $idChunk) {
                $this->debugExpert('UpdateCache', 'Request Entities', ['Count' => count($idChunk)]);
                $rawEntities = $this->fetchEntitiesByIds($idChunk);
                if ($rawEntities === null) {
                    $this->debugExpert('UpdateCache', 'API-Fehler (Entities)', ['Count' => count($idChunk)]);
                    continue;
                }
                if ($rawEntities === []) {
                    continue;
                }
                $this->debugExpert('UpdateCache', 'Entities geladen', ['Count' => count($rawEntities)]);

                foreach ($rawEntities as $entity) {
                    if (!isset($entity['attributes']) || !is_array($entity['attributes'])) {
                        $entity['attributes'] = [];
                    }
                    if (!isset($entity['attributes']['supported_features']) || !is_numeric($entity['attributes']['supported_features'])) {
                        if (isset($entity['supported_features']) && is_numeric($entity['supported_features'])) {
                            $entity['attributes']['supported_features'] = (int)$entity['supported_features'];
                        }
                    }
                    unset($entity['supported_features']);
                    // Fallback für den Anzeigenamen des Geräts, falls 'Unbekannt'
                    if ($entity['device_name'] === 'Unbekannt' || $entity['device_id'] === 'none') {
                        $entity['device'] = ucfirst($entity['domain']) . ' (Ohne Gerät)';
                    } else {
                        // Sauberer Gerätename ohne Bereich in Klammern für die Anzeige
                        $entity['device'] = $entity['device_name'];
                    }

                    $newEntities[$entity['entity_id']] = $entity;
                }
            }
        }

        if ($newEntities === []) {
            $this->debugExpert('UpdateCache', 'No entities found or API error');
            return;
        }

        // 4. Persistieren
        $this->entities = $newEntities;
        $this->WriteAttributeString('CachedEntities', json_encode($this->entities, JSON_THROW_ON_ERROR));

        // 5. Protokollieren
        $this->LogUpdatedEntities();
    }

    private function fetchEntityIdsForDomains(array $domains): ?array
    {
        if ($domains === []) {
            return [];
        }
        $domainsJson = json_encode($domains, JSON_THROW_ON_ERROR);
        $template = str_replace('DOMAINS_PLACEHOLDER', $domainsJson, self::HA_ENTITY_ID_TEMPLATE);
        $result = $this->RenderHATemplate(trim($template));
        if ($result === null) {
            return null;
        }
        $ids = [];
        foreach ($result as $item) {
            if (is_string($item) && $item !== '') {
                $ids[] = $item;
            }
        }
        return $ids;
    }

    private function fetchEntitiesByIds(array $entityIds): ?array
    {
        if ($entityIds === []) {
            return [];
        }
        $entitiesJson = json_encode($entityIds, JSON_THROW_ON_ERROR);
        $template = str_replace('ENTITIES_PLACEHOLDER', $entitiesJson, self::HA_FULL_DATA_TEMPLATE_BY_ENTITY);
        return $this->RenderHATemplate(trim($template));
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
            $this->debugExpert(
                __FUNCTION__,
                sprintf(
                    "EntityID: %s | device_id: %s | device_name: %s",
                    $entity['entity_id'],
                    $entity['device_id'],
                    $entity['device_name']
                )
            );
        }
    }

    private function RenderHATemplate(string $template): ?array
    {
        $postData = json_encode(['template' => $template], JSON_THROW_ON_ERROR);
        return $this->sendRestRequestToParent('/api/template', $postData);
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

