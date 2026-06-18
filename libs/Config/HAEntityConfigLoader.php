<?php

declare(strict_types=1);

trait HAEntityConfigLoaderTrait
{
    // Anzahl Entities pro Template-Request, um die HA-Output-Grenze einzuhalten.
    private const int ENTITY_CHUNK_SIZE = 50;

    private const string HA_ENTITY_ID_TEMPLATE = <<<'EOT'
[
    {% set domains = DOMAINS_PLACEHOLDER %}
    {% for state in states if state.domain in domains %}
    {{ state.entity_id | to_json }}{% if not loop.last %},{% endif %}
    {% endfor %}
]
EOT;

    private const string HA_ENTITY_ID_TEMPLATE_ALL = <<<'EOT'
[
    {% for state in states %}
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
    {# JSON-Objektschlüssel müssen Strings sein; einige HA-Attribute nutzen int-Keys. #}
    {%- set ns.items = ns.items + [((k | string) | to_json) ~ ':' ~ sanitize_json(v, depth + 1)] -%}
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
        "device": "{{ device_attr(state.entity_id, 'name_by_user') | default(device_attr(state.entity_id, 'name'), true) | default('Unknown', true) }} ({{ area_name(state.entity_id) | default('No area', true) }})",
        "device_name": "{{ device_attr(state.entity_id, 'name_by_user') | default(device_attr(state.entity_id, 'name'), true) | default('Unknown', true) }}",
        "device_manufacturer": "{{ device_attr(state.entity_id, 'manufacturer') | default('', true) }}",
        "device_model": "{{ device_attr(state.entity_id, 'model') | default('', true) }}",
        "device_id": "{{ device_id(state.entity_id) | default('none', true) }}",
        "area": "{{ area_name(state.entity_id) | default('No area', true) }}",
        "supported_features": {{ state.attributes.supported_features | default(0) | int }}
    }{% if not loop.last %},{% endif %}
    {% endfor %}
]
EOT;

    private const string HA_FULL_DATA_TEMPLATE_BY_DEVICE = <<<'EOT'
[
    {# Rekursive JSON-Sanitizer: wandelt Sets/Iterables in JSON-kompatible Listen um #}
    {% macro sanitize_json(value, depth=0) -%}
    {%- if depth > 4 -%}
    {{ 'null' }}
    {%- elif value is mapping -%}
    {%- set ns = namespace(items=[]) -%}
    {%- for k, v in value.items() -%}
    {%- set ns.items = ns.items + [((k | string) | to_json) ~ ':' ~ sanitize_json(v, depth + 1)] -%}
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

    {% set target_device_id = DEVICE_ID_PLACEHOLDER %}
    {% for state in states if device_id(state.entity_id) | default('none', true) == target_device_id %}
    {
        "entity_id": "{{ state.entity_id }}",
        "domain": "{{ state.domain }}",
        "name": "{{ state.attributes.friendly_name | default(state.name) }}",
        "attributes": {{ sanitize_json(state.attributes) }},
        "device": "{{ device_attr(state.entity_id, 'name_by_user') | default(device_attr(state.entity_id, 'name'), true) | default('Unknown', true) }} ({{ area_name(state.entity_id) | default('No area', true) }})",
        "device_name": "{{ device_attr(state.entity_id, 'name_by_user') | default(device_attr(state.entity_id, 'name'), true) | default('Unknown', true) }}",
        "device_manufacturer": "{{ device_attr(state.entity_id, 'manufacturer') | default('', true) }}",
        "device_model": "{{ device_attr(state.entity_id, 'model') | default('', true) }}",
        "device_id": "{{ device_id(state.entity_id) | default('none', true) }}",
        "area": "{{ area_name(state.entity_id) | default('No area', true) }}",
        "supported_features": {{ state.attributes.supported_features | default(0) | int }}
    }{% if not loop.last %},{% endif %}
    {% endfor %}
]
EOT;

    private function fetchEntityIdsForDomains(array $domains): ?array
    {
        if ($domains === []) {
            return [];
        }

        $domainsJson = json_encode($domains, JSON_THROW_ON_ERROR);
        $template = str_replace('DOMAINS_PLACEHOLDER', $domainsJson, self::HA_ENTITY_ID_TEMPLATE);
        $result = $this->renderHATemplate(trim($template));
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

    private function fetchEntityIdsForAllDomains(): ?array
    {
        $result = $this->renderHATemplate(trim(self::HA_ENTITY_ID_TEMPLATE_ALL));
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
        return $this->renderHATemplate(trim($template));
    }

    private function fetchEntitiesByDeviceId(string $deviceId): ?array
    {
        if (trim($deviceId) === '') {
            return [];
        }

        $deviceIdJson = json_encode($deviceId, JSON_THROW_ON_ERROR);
        $template = str_replace('DEVICE_ID_PLACEHOLDER', $deviceIdJson, self::HA_FULL_DATA_TEMPLATE_BY_DEVICE);
        return $this->renderHATemplate(trim($template));
    }

    private function fetchAllRawEntities(?array $domains = null): array
    {
        $entityIds = ($domains === null)
            ? $this->fetchEntityIdsForAllDomains()
            : $this->fetchEntityIdsForDomains($domains);

        if ($entityIds === null || $entityIds === []) {
            return [];
        }

        $entities = [];
        foreach (array_chunk($entityIds, self::ENTITY_CHUNK_SIZE) as $idChunk) {
            $rawEntities = $this->fetchEntitiesByIds($idChunk);
            if ($rawEntities === null || $rawEntities === []) {
                continue;
            }

            foreach ($rawEntities as $entity) {
                if (is_array($entity) && isset($entity['entity_id']) && is_string($entity['entity_id'])) {
                    $entities[$entity['entity_id']] = $entity;
                }
            }
        }

        return $entities;
    }

    private function resolveRawEntityByEntityId(string $entityId): ?array
    {
        if ($entityId === '') {
            return null;
        }

        $entities = $this->fetchEntitiesByIds([$entityId]);
        if (!is_array($entities) || $entities === []) {
            return null;
        }

        return array_find(
            $entities,
            static fn(mixed $entity): bool => is_array($entity) && (($entity['entity_id'] ?? '') === $entityId)
        );
    }

    private function renderHATemplate(string $template): ?array
    {
        $postData = json_encode(['template' => $template], JSON_THROW_ON_ERROR);
        return $this->sendRestRequestToParent('/api/template', $postData);
    }
}
