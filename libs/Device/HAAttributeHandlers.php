<?php /** @noinspection AutoloadingIssuesInspection */

declare(strict_types=1);

trait HAAttributeHandlersTrait
{
    private function tryHandleAttributeFromTopic(string $topic, string $payload): bool
    {
        // Attribute topics come as .../<domain>/<entity>/<attribute>
        $parts = explode('/', trim($topic, '/'));
        if (count($parts) < 3) {
            $this->debugExpert('AttributeTopic', 'Topic zu kurz', ['Topic' => $topic]);
            return false;
        }

        $attribute = $parts[count($parts) - 1];
        $entity    = $parts[count($parts) - 2];
        $domain    = $parts[count($parts) - 3];
        $entityId  = $domain . '.' . $entity;

        $currentDomain = $this->entities[$entityId]['domain'] ?? $domain;
        if ($currentDomain !== HALightDefinitions::DOMAIN
            && $currentDomain !== HASelectDefinitions::DOMAIN
            && $currentDomain !== HAEventDefinitions::DOMAIN
            && $currentDomain !== HACoverDefinitions::DOMAIN
            && $currentDomain !== HAClimateDefinitions::DOMAIN
            && $currentDomain !== HAMediaPlayerDefinitions::DOMAIN
            && $currentDomain !== HAFanDefinitions::DOMAIN
            && $currentDomain !== HAHumidifierDefinitions::DOMAIN) {
            $this->debugExpert('AttributeTopic', 'Domain nicht unterstützt', ['EntityID' => $entityId, 'Domain' => $domain]);
            return false;
        }
        if (!isset($this->entities[$entityId])) {
            // Ensure the entity exists even if it wasn't part of the initial config list.
            $this->entities[$entityId] = [
                'entity_id' => $entityId,
                'domain'    => $domain,
                'name'      => $entity
            ];
            $this->debugExpert('AttributeTopic', 'Entity aus Topic angelegt', ['EntityID' => $entityId]);
        }

        if ($currentDomain === HAEventDefinitions::DOMAIN) {
            if ($attribute === HAEventDefinitions::ATTRIBUTE_EVENT_TYPES) {
                $value = $this->parseAttributePayload($payload);
                if ($value !== null) {
                    $this->storeEntityAttribute($entityId, $attribute, $value);
                    $this->updateEntityCache($entityId, null, [$attribute => $value]);
                    $this->updateEntityPresentation($entityId, $this->entities[$entityId]['attributes'] ?? []);
                }
                return true;
            }
            if ($attribute !== HAEventDefinitions::ATTRIBUTE_EVENT_TYPE) {
                return true;
            }
            $value = $this->parseAttributePayload($payload);
            if ($value !== null) {
                $this->storeEntityAttribute($entityId, $attribute, $value);
                $this->updateEntityCache($entityId, (string)$value, [$attribute => $value]);
                $ident = $this->sanitizeIdent($entityId);
                $varId = @$this->GetIDForIdent($ident);
                if ($varId === false) {
                    $this->debugExpert('AttributeTopic', 'Variable nicht gefunden', ['Ident' => $ident]);
                    return false;
                }
                $this->setValueWithDebug($ident, (string)$value);
                $this->debugExpert('AttributeTopic', 'SetValue', ['Ident' => $ident, 'Value' => $value]);
            }
            return true;
        }

        if ($currentDomain === HACoverDefinitions::DOMAIN) {
            return $this->handleAttributeTopicWithDefinitions(
                $entityId,
                $attribute,
                $payload,
                HACoverDefinitions::ATTRIBUTE_DEFINITIONS,
                fn(string $id, string $attr): bool => $this->ensureCoverAttributeVariable($id, $attr),
                [
                    'store_unknown' => true,
                    'update_presentation_unknown' => true,
                    'store_defined' => true,
                    'update_presentation_defined' => true,
                    'post_set' => function (string $id, string $attr, mixed $value): void {
                        if ($attr === HACoverDefinitions::ATTRIBUTE_POSITION || $attr === HACoverDefinitions::ATTRIBUTE_POSITION_ALT) {
                            $mainIdent = $this->sanitizeIdent($id);
                            if (@$this->GetIDForIdent($mainIdent) !== false) {
                                $this->setValueWithDebug($mainIdent, (float)$value);
                            }
                        }
                    }
                ]
            );
        }
        if ($currentDomain === HAClimateDefinitions::DOMAIN) {
            return $this->handleAttributeTopicWithDefinitions(
                $entityId,
                $attribute,
                $payload,
                HAClimateDefinitions::ATTRIBUTE_DEFINITIONS,
                fn(string $id, string $attr): bool => $this->ensureClimateAttributeVariable($id, $attr),
                [
                    'store_unknown' => true,
                    'update_presentation_unknown' => true
                ]
            );
        }
        if ($currentDomain === HAFanDefinitions::DOMAIN) {
            return $this->handleAttributeTopicWithDefinitions(
                $entityId,
                $attribute,
                $payload,
                HAFanDefinitions::ATTRIBUTE_DEFINITIONS,
                fn(string $id, string $attr): bool => $this->ensureFanAttributeVariable($id, $attr),
                [
                    'store_unknown' => true,
                    'update_presentation_unknown' => true
                ]
            );
        }
        if ($currentDomain === HAHumidifierDefinitions::DOMAIN) {
            $result = $this->handleAttributeTopicWithDefinitions(
                $entityId,
                $attribute,
                $payload,
                HAHumidifierDefinitions::ATTRIBUTE_DEFINITIONS,
                fn(string $id, string $attr): bool => $this->ensureHumidifierAttributeVariable($id, $attr),
                [
                    'attribute_alias' => ['humidity' => HAHumidifierDefinitions::ATTRIBUTE_TARGET_HUMIDITY],
                    'store_unknown' => true,
                    'store_defined' => true,
                    'update_presentation_unknown' => true,
                    'update_presentation_defined' => true
                ]
            );
            return $result;
        }
        if ($currentDomain === HAMediaPlayerDefinitions::DOMAIN) {
            if ($attribute === 'entity_picture') {
                $attribute = 'media_image_url';
            }

            if ($attribute === 'media_image_url') {
                $value = $this->parseAttributePayload($payload);
                if ($value === null) {
                    $this->debugExpert('AttributeTopic', 'Payload null', ['EntityID' => $entityId, 'Attribute' => $attribute]);
                    return true;
                }
                $original = (string)$value;
                $absolute = $this->makeMediaImageUrlAbsolute($original);
                if (!$this->ensureMediaPlayerAttributeVariable($entityId, $attribute)) {
                    $this->debugExpert('AttributeTopic', 'Keine Variable für Attribut', ['EntityID' => $entityId, 'Attribute' => $attribute]);
                    return false;
                }
                $meta = HAMediaPlayerDefinitions::ATTRIBUTE_DEFINITIONS[$attribute] ?? null;
                if ($meta === null) {
                    $this->debugExpert('AttributeTopic', 'Attribut nicht definiert', ['Attribute' => $attribute]);
                    return false;
                }
                $casted = $this->castVariableValue($original, $meta['type']);
                $ident = $this->sanitizeIdent($entityId . '_' . $attribute);
                $this->setValueWithDebug($ident, $casted);
                $this->debugExpert('AttributeTopic', 'SetValue', ['Ident' => $ident, 'Value' => $casted]);
                $this->storeEntityAttribute($entityId, $attribute, $casted);
                $this->updateEntityCache($entityId, null, [$attribute => $casted]);
                if ($absolute !== '') {
                    $this->updateMediaPlayerCoverMedia($entityId, $absolute);
                }
                return true;
            }

            if ($attribute === 'repeat') {
                if (!$this->ensureMediaPlayerAttributeVariable($entityId, $attribute)) {
                    $this->debugExpert('AttributeTopic', 'Keine Variable für Attribut', ['EntityID' => $entityId, 'Attribute' => $attribute]);
                    return false;
                }
                $value = $this->parseAttributePayload($payload);
                if ($value === null) {
                    $this->debugExpert('AttributeTopic', 'Payload null', ['EntityID' => $entityId, 'Attribute' => $attribute]);
                    return true;
                }
                $value = $this->mapMediaPlayerRepeatToValue($value);
                $ident = $this->sanitizeIdent($entityId . '_' . $attribute);
                $this->setValueWithDebug($ident, $value);
                $this->debugExpert('AttributeTopic', 'SetValue', ['Ident' => $ident, 'Value' => $value]);
                $this->updateEntityCache($entityId, null, [$attribute => $value]);
                return true;
            }

            return $this->handleAttributeTopicWithDefinitions(
                $entityId,
                $attribute,
                $payload,
                HAMediaPlayerDefinitions::ATTRIBUTE_DEFINITIONS,
                fn(string $id, string $attr): bool => $this->ensureMediaPlayerAttributeVariable($id, $attr),
                [
                    'store_unknown' => true,
                    'update_presentation_unknown' => true
                ]
            );
        }
        if ($currentDomain === HASelectDefinitions::DOMAIN) {
            $value = $this->parseAttributePayload($payload);
            if ($value !== null) {
                $this->storeEntityAttribute($entityId, $attribute, $value);
                $this->updateEntityCache($entityId, null, [$attribute => $value]);
                $this->updateEntityPresentation($entityId, $this->entities[$entityId]['attributes'] ?? []);
            }
            return true;
        }

        if (!array_key_exists($attribute, HALightDefinitions::ATTRIBUTE_DEFINITIONS)) {
            $value = $this->parseAttributePayload($payload);
            if ($value !== null) {
                $this->storeEntityAttribute($entityId, $attribute, $value);
                $this->updateEntityCache($entityId, null, [$attribute => $value]);
                $this->updateEntityPresentation($entityId, $this->entities[$entityId]['attributes'] ?? []);
            }
            return true;
        }

        if (!$this->ensureLightAttributeVariable($entityId, $attribute)) {
            $this->debugExpert('AttributeTopic', 'Keine Variable für Attribut', ['EntityID' => $entityId, 'Attribute' => $attribute]);
            return false;
        }

        $value = $this->parseAttributePayload($payload);
        if ($value === null) {
            $this->debugExpert('AttributeTopic', 'Payload null', ['EntityID' => $entityId, 'Attribute' => $attribute]);
            return true;
        }

        $meta = HALightDefinitions::ATTRIBUTE_DEFINITIONS[$attribute] ?? null;
        if ($meta === null) {
            $this->debugExpert('AttributeTopic', 'Attribut nicht definiert', ['Attribute' => $attribute]);
            return false;
        }

        if (is_array($value)) {
            $value = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $value = $this->castVariableValue($value, $meta['type']);
        $ident = $this->sanitizeIdent($entityId . '_' . $attribute);
        $this->setValueWithDebug($ident, $value);
        $this->debugExpert('AttributeTopic', 'SetValue', ['Ident' => $ident, 'Value' => $value]);
        $this->updateEntityCache($entityId, null, [$attribute => $value]);

        return true;
    }

    private function handleAttributeTopicWithDefinitions(
        string $entityId,
        string $attribute,
        string $payload,
        array $definitions,
        callable $ensureVariable,
        array $options = []
    ): bool {
        $aliases = $options['attribute_alias'] ?? [];
        if (is_array($aliases) && array_key_exists($attribute, $aliases)) {
            $attribute = (string)$aliases[$attribute];
        }

        $storeUnknown = (bool)($options['store_unknown'] ?? true);
        $updatePresentationUnknown = (bool)($options['update_presentation_unknown'] ?? true);
        $storeDefined = (bool)($options['store_defined'] ?? false);
        $updatePresentationDefined = (bool)($options['update_presentation_defined'] ?? false);
        $postSet = $options['post_set'] ?? null;

        if (!array_key_exists($attribute, $definitions)) {
            if (!$storeUnknown) {
                return true;
            }
            $value = $this->parseAttributePayload($payload);
            if ($value !== null) {
                $this->storeEntityAttribute($entityId, $attribute, $value);
                $this->updateEntityCache($entityId, null, [$attribute => $value]);
                if ($updatePresentationUnknown) {
                    $this->updateEntityPresentation($entityId, $this->entities[$entityId]['attributes'] ?? []);
                }
            }
            return true;
        }

        if (!$ensureVariable($entityId, $attribute)) {
            $this->debugExpert('AttributeTopic', 'Keine Variable für Attribut', ['EntityID' => $entityId, 'Attribute' => $attribute]);
            return false;
        }

        $value = $this->parseAttributePayload($payload);
        if ($value === null) {
            $this->debugExpert('AttributeTopic', 'Payload null', ['EntityID' => $entityId, 'Attribute' => $attribute]);
            return true;
        }

        $meta = $definitions[$attribute] ?? null;
        if ($meta === null) {
            $this->debugExpert('AttributeTopic', 'Attribut nicht definiert', ['Attribute' => $attribute]);
            return false;
        }

        $value = $this->castVariableValue($value, $meta['type']);
        $ident = $this->sanitizeIdent($entityId . '_' . $attribute);
        $this->setValueWithDebug($ident, $value);
        $this->debugExpert('AttributeTopic', 'SetValue', ['Ident' => $ident, 'Value' => $value]);
        if ($storeDefined) {
            $this->storeEntityAttribute($entityId, $attribute, $value);
        }
        $this->updateEntityCache($entityId, null, [$attribute => $value]);
        if ($updatePresentationDefined) {
            $this->updateEntityPresentation($entityId, $this->entities[$entityId]['attributes'] ?? []);
        }
        if (is_callable($postSet)) {
            $postSet($entityId, $attribute, $value);
        }
        return true;
    }
}














