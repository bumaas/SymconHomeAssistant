<?php

declare(strict_types=1);

trait HAAttributeActionMappingTrait
{
    // Media-Player-Attribute brauchen teils zusätzliche Laufzeitbedingungen.
    private function isWritableMediaPlayerAttribute(string $attribute, array $entityAttributes = []): bool
    {
        $meta = $this->getWritableAttributeMeta(HAMediaPlayerDefinitions::ATTRIBUTE_DEFINITIONS, $attribute);
        if ($meta === null) {
            return false;
        }
        if ($entityAttributes !== [] && !$this->checkSupportedFeatures($meta, $entityAttributes)) {
            return false;
        }
        if ($entityAttributes !== [] && !$this->hasMediaPlayerSelectableValues($attribute, $entityAttributes)) {
            return false;
        }
        if ($attribute === 'media_position') {
            $duration = $entityAttributes['media_duration'] ?? null;
            if (!is_numeric($duration) || (float) $duration <= 0.0) {
                return false;
            }
        }
        return true;
    }

    // Listenbasierte Media-Player-Attribute werden nur mit belastbaren HA-Optionen schreibbar.
    private function hasMediaPlayerSelectableValues(string $attribute, array $entityAttributes): bool
    {
        return match ($attribute) {
            'source' => $this->getMediaPlayerSelectableValues($entityAttributes, 'source_list') !== [],
            'sound_mode' => $this->getMediaPlayerSelectableValues($entityAttributes, 'sound_mode_list') !== [],
            default => true,
        };
    }

    private function getMediaPlayerSelectableValues(array $entityAttributes, string $listAttribute): array
    {
        return HASelectDefinitions::normalizeOptions($entityAttributes[$listAttribute] ?? null);
    }

    private function isWritableCoverAttribute(string $attribute, array $entityAttributes = []): bool
    {
        $meta = $this->getWritableAttributeMeta(HACoverDefinitions::ATTRIBUTE_DEFINITIONS, $attribute);
        if ($meta === null || $entityAttributes === []) {
            return false;
        }
        if (!$this->checkSupportedFeatures($meta, $entityAttributes)) {
            return false;
        }
        return true;
    }

    // Ordnet eine schreibbare Attributvariable wieder ihrer Entität und Domain zu.
    private function resolveAttributeByIdent(string $ident): ?array
    {
        foreach ($this->getAttributeIdentResolvers() as $resolver) {
            $resolved = $this->resolveDomainAttributeByIdent(
                $ident,
                $resolver['domain'],
                $resolver['definitions'],
                $resolver['validator']
            );
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    private function getAttributeIdentResolvers(): array
    {
        return [
            [
                'domain' => HALightDefinitions::DOMAIN,
                'definitions' => HALightDefinitions::ATTRIBUTE_DEFINITIONS,
                'validator' => 'isWritableLightAttribute'
            ],
            [
                'domain' => HACoverDefinitions::DOMAIN,
                'definitions' => HACoverDefinitions::ATTRIBUTE_DEFINITIONS,
                'validator' => 'isWritableCoverAttribute'
            ],
            [
                'domain' => HAFanDefinitions::DOMAIN,
                'definitions' => HAFanDefinitions::ATTRIBUTE_DEFINITIONS,
                'validator' => 'isWritableFanAttribute'
            ],
            [
                'domain' => HAClimateDefinitions::DOMAIN,
                'definitions' => HAClimateDefinitions::ATTRIBUTE_DEFINITIONS,
                'validator' => 'isWritableClimateAttribute'
            ],
            [
                'domain' => HAHumidifierDefinitions::DOMAIN,
                'definitions' => HAHumidifierDefinitions::ATTRIBUTE_DEFINITIONS,
                'validator' => 'isWritableHumidifierAttribute'
            ],
            [
                'domain' => HAMediaPlayerDefinitions::DOMAIN,
                'definitions' => HAMediaPlayerDefinitions::ATTRIBUTE_DEFINITIONS,
                'validator' => 'isWritableMediaPlayerAttribute'
            ]
        ];
    }

    // Bei gleichen Attributnamen über mehrere Domains hinweg wird nur bei echtem Treffer zurückgegeben.
    private function resolveDomainAttributeByIdent(string $ident, string $domain, array $definitions, ?string $validatorMethod): ?array
    {
        foreach ($definitions as $attribute => $meta) {
            if (!($meta['writable'] ?? false)) {
                continue;
            }
            $suffix = '_' . $attribute;
            if (!str_ends_with($ident, $suffix)) {
                continue;
            }

            $baseIdent = substr($ident, 0, -strlen($suffix));
            if ($baseIdent === '') {
                continue;
            }

            $context = $this->resolveEntityContextByBaseIdent($baseIdent);
            if ($context === null) {
                continue;
            }

            $resolvedDomain = $context['entity']['domain'] ?? $this->getEntityDomain($context['entity_id']);
            if ($resolvedDomain !== $domain) {
                continue;
            }
            if ($validatorMethod !== null && !$this->{$validatorMethod}($attribute, $context['attributes'])) {
                continue;
            }

            return [
                'entity_id' => $context['entity_id'],
                'attribute' => $attribute,
                'domain'    => $resolvedDomain
            ];
        }

        return null;
    }

    // Holt Entity und Attribute sowohl aus dem Laufzeitcache als auch aus der Konfiguration.
    private function resolveEntityContextByBaseIdent(string $baseIdent): ?array
    {
        $entityId = $this->getSharedEntityIdByPrefix($baseIdent);
        $entity = null;
        $attributes = [];

        if ($entityId !== null && isset($this->entities[$entityId])) {
            $entity = $this->entities[$entityId];
            $attributes = is_array($entity['attributes'] ?? null) ? $entity['attributes'] : [];
        } else {
            $fromConfig = $this->findEntityByBaseIdentInConfig($baseIdent);
            if ($fromConfig !== null) {
                $entityId = $fromConfig['entity_id'];
                $entity = $fromConfig;
                $attributes = is_array($fromConfig['attributes'] ?? null) ? $fromConfig['attributes'] : [];
            }
        }

        if ($entityId === null || $entity === null) {
            return null;
        }

        return [
            'entity_id'   => $entityId,
            'entity'      => $entity,
            'attributes'  => $attributes
        ];
    }

    private function getWritableAttributeMeta(array $definitions, string $attribute): ?array
    {
        $meta = $definitions[$attribute] ?? null;
        if (!is_array($meta) || !($meta['writable'] ?? false)) {
            return null;
        }

        return $meta;
    }

    private function encodeAttributePayload(string $key, mixed $value): string
    {
        return json_encode([$key => $value], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // Light-Payloads werden pro Attribut in das erwartete HA-Format gebracht.
    private function buildLightAttributePayload(string $attribute, mixed $value): string
    {
        if ($this->getWritableAttributeMeta(HALightDefinitions::ATTRIBUTE_DEFINITIONS, $attribute) === null) {
            return '';
        }
        $payloadValue = $this->parseLightAttributeValue($attribute, $value);
        return $this->encodeAttributePayload($attribute, $payloadValue);
    }

    // Cover nutzt teils andere Payload-Schlüssel als den Attributnamen.
    private function buildCoverAttributePayload(string $attribute, mixed $value): string
    {
        $meta = $this->getWritableAttributeMeta(HACoverDefinitions::ATTRIBUTE_DEFINITIONS, $attribute);
        if ($meta === null) {
            return '';
        }
        $payloadKey = $meta['payload_key'] ?? '';
        if (!is_string($payloadKey) || $payloadKey === '') {
            return '';
        }
        $payloadValue = $this->parseCoverAttributeValue($value);
        return $this->encodeAttributePayload($payloadKey, $payloadValue);
    }

    // Climate unterscheidet zwischen numerischen Zielwerten und textuellen Modi.
    private function buildClimateAttributePayload(string $attribute, mixed $value): string
    {
        if ($this->getWritableAttributeMeta(HAClimateDefinitions::ATTRIBUTE_DEFINITIONS, $attribute) === null) {
            return '';
        }

        $payloadValue = match ($attribute) {
            HAClimateDefinitions::ATTRIBUTE_TARGET_TEMPERATURE_LOW,
            HAClimateDefinitions::ATTRIBUTE_TARGET_TEMPERATURE_HIGH,
            HAClimateDefinitions::ATTRIBUTE_TARGET_HUMIDITY => (float) $value,
            default => trim((string) $value)
        };
        if ($payloadValue === '') {
            return '';
        }
        return $this->encodeAttributePayload($attribute, $payloadValue);
    }

    // Media-Player-Attribute werden vor dem Senden auf HA-konforme Werte normalisiert.
    private function buildMediaPlayerAttributePayload(string $attribute, mixed $value): string
    {
        if ($this->getWritableAttributeMeta(HAMediaPlayerDefinitions::ATTRIBUTE_DEFINITIONS, $attribute) === null) {
            return '';
        }
        $payloadValue = $this->parseMediaPlayerAttributeValue($attribute, $value);
        return $this->encodeAttributePayload($attribute, $payloadValue);
    }

    // Cover-Level bleiben immer im gültigen Bereich von 0 bis 100.
    private function parseCoverAttributeValue(mixed $value): float
    {
        return $this->clampFloat((float) $value, 0.0, 100.0);
    }

    // Einige Media-Player-Attribute erwarten boolesche oder gemappte Werte statt Rohtext.
    private function parseMediaPlayerAttributeValue(string $attribute, mixed $value): mixed
    {
        return match ($attribute) {
            'volume_level' => $this->clampFloat((float) $value, 0.0, 1.0),
            'is_volume_muted', 'shuffle' => (bool) $value,
            'media_position' => max(0, (int) $value),
            'repeat' => $this->mapMediaPlayerRepeatToPayload($value),
            default => (string) $value,
        };
    }

    // Repeat kann aus Profilwerten oder aus bereits normalisierten HA-Strings kommen.
    private function mapMediaPlayerRepeatToValue(mixed $value): int
    {
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            return (int) $value;
        }
        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return 0;
        }
        return HAMediaPlayerDefinitions::REPEAT_VALUE_MAP[$normalized] ?? 0;
    }

    // Für Service-Aufrufe wird wieder auf den HA-Stringwert zurückgemappt.
    private function mapMediaPlayerRepeatToPayload(mixed $value): string
    {
        $intValue = $this->mapMediaPlayerRepeatToValue($value);
        return HAMediaPlayerDefinitions::REPEAT_PAYLOAD_MAP[$intValue] ?? 'off';
    }

    // Gemeinsamer Clamp-Helfer für Payload-Parser.
    private function clampFloat(float $value, float $min, float $max): float
    {
        if ($value < $min) {
            return $min;
        }
        if ($value > $max) {
            return $max;
        }
        return $value;
    }

    // Light unterstützt je nach Attribut Listen, Ganzzahlen oder Fließkommazahlen.
    private function parseLightAttributeValue(string $attribute, mixed $value): string|array|int|float
    {
        return match ($attribute) {
            'brightness', 'color_temp', 'color_temp_kelvin' => (int) $value,
            'transition' => (float) $value,
            'hs_color', 'xy_color' => $this->parseNumberList($value, 2, true),
            'rgb_color' => $this->parseRgbColorPayloadValue($value),
            'rgbw_color' => $this->parseNumberList($value, 4, false),
            'rgbww_color' => $this->parseNumberList($value, 5, false),
            default => (string) $value,
        };
    }

    // RGB wird intern als kompaktes JSON gespeichert, damit Symcon den Wert stabil hält.
    private function formatRgbColorStorageValue(mixed $value): string
    {
        $rgb = $this->parseRgbColorComponents($value);
        if ($rgb === null) {
            return (string) $value;
        }

        return json_encode([
            'r' => $rgb[0],
            'g' => $rgb[1],
            'b' => $rgb[2]
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    // Beim Senden an HA werden RGB-Werte wieder als numerische Liste geschrieben.
    private function parseRgbColorPayloadValue(mixed $value): array
    {
        $rgb = $this->parseRgbColorComponents($value);
        if ($rgb === null) {
            return $this->parseNumberList($value, 3, false);
        }

        return [
            (int) round($rgb[0]),
            (int) round($rgb[1]),
            (int) round($rgb[2])
        ];
    }

    // Akzeptiert JSON, Listen und einfache Trennzeichenformate für RGB-Eingaben.
    private function parseRgbColorComponents(mixed $value): ?array
    {
        $items = $value;
        if (is_string($items)) {
            $text = trim($items);
            if ($text === '') {
                return null;
            }
            if ($text[0] === '[' || $text[0] === '{') {
                try {
                    $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($decoded)) {
                        $items = $decoded;
                    }
                } catch (JsonException) {
                    $items = $text;
                }
            }
            if (!is_array($items)) {
                $parts = preg_split('/[;,]/', $items) ?: [];
                $items = array_map('trim', $parts);
            }
        }

        if (!is_array($items)) {
            return null;
        }

        if (array_key_exists('r', $items) && array_key_exists('g', $items) && array_key_exists('b', $items)) {
            $raw = [$items['r'], $items['g'], $items['b']];
        } else {
            $raw = array_values($items);
            if (count($raw) < 3) {
                return null;
            }
            $raw = array_slice($raw, 0, 3);
        }

        if (array_any($raw, static fn($component): bool => !is_numeric($component))) {
            return null;
        }

        $r = $this->clampFloat((float) $raw[0], 0.0, 255.0);
        $g = $this->clampFloat((float) $raw[1], 0.0, 255.0);
        $b = $this->clampFloat((float) $raw[2], 0.0, 255.0);
        return [$r, $g, $b];
    }

    // Parst Listen aus Arrays, JSON oder einfachen CSV-ähnlichen Strings.
    private function parseNumberList(mixed $value, int $expectedCount, bool $useFloat): array
    {
        $items = $value;
        if (!is_array($items)) {
            $text = trim((string) $value);
            if ($text === '') {
                return [];
            }
            if ($text[0] === '[' || $text[0] === '{') {
                try {
                    $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($decoded)) {
                        $items = $decoded;
                    }
                } catch (JsonException) {
                    $items = null;
                }
            }
            if (!is_array($items)) {
                $split = preg_split('/[;,]/', $text) ?: [];
                $items = array_map('trim', $split);
            }
        }

        $numbers = [];
        foreach ($items as $item) {
            $numbers[] = $useFloat ? (float) $item : (int) $item;
        }

        if ($expectedCount > 0 && count($numbers) > $expectedCount) {
            $numbers = array_slice($numbers, 0, $expectedCount);
        }
        return $numbers;
    }

    // Fallback für Attribute, die erst beim Schreiben wieder einer Konfigurations-Entität zugeordnet werden müssen.
    private function findEntityByBaseIdentInConfig(string $baseIdent): ?array
    {
        return $this->findSharedConfiguredEntityByPrefix($baseIdent);
    }
}
