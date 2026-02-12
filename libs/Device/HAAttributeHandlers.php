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
            if (!array_key_exists($attribute, HACoverDefinitions::ATTRIBUTE_DEFINITIONS)) {
                $value = $this->parseAttributePayload($payload);
                if ($value !== null) {
                    $this->storeEntityAttribute($entityId, $attribute, $value);
                    $this->updateEntityCache($entityId, null, [$attribute => $value]);
                    $this->updateEntityPresentation($entityId, $this->entities[$entityId]['attributes'] ?? []);
                }
                return true;
            }

            if (!$this->ensureCoverAttributeVariable($entityId, $attribute)) {
                $this->debugExpert('AttributeTopic', 'Keine Variable fÃ¼r Attribut', ['EntityID' => $entityId, 'Attribute' => $attribute]);
                return false;
            }

            $value = $this->parseAttributePayload($payload);
            if ($value === null) {
                $this->debugExpert('AttributeTopic', 'Payload null', ['EntityID' => $entityId, 'Attribute' => $attribute]);
                return true;
            }

            $meta = HACoverDefinitions::ATTRIBUTE_DEFINITIONS[$attribute] ?? null;
            if ($meta === null) {
                $this->debugExpert('AttributeTopic', 'Attribut nicht definiert', ['Attribute' => $attribute]);
                return false;
            }

            $value = $this->castVariableValue($value, $meta['type']);
            $ident = $this->sanitizeIdent($entityId . '_' . $attribute);
            $this->setValueWithDebug($ident, $value);
            $this->debugExpert('AttributeTopic', 'SetValue', ['Ident' => $ident, 'Value' => $value]);
            $this->storeEntityAttribute($entityId, $attribute, $value);
            $this->updateEntityCache($entityId, null, [$attribute => $value]);
            $this->updateEntityPresentation($entityId, $this->entities[$entityId]['attributes'] ?? []);
            if ($attribute === HACoverDefinitions::ATTRIBUTE_POSITION || $attribute === HACoverDefinitions::ATTRIBUTE_POSITION_ALT) {
                $mainIdent = $this->sanitizeIdent($entityId);
                if (@$this->GetIDForIdent($mainIdent) !== false) {
                    $this->setValueWithDebug($mainIdent, (float)$value);
                }
            }
            return true;
        }

        if ($currentDomain === HAClimateDefinitions::DOMAIN) {
            if (!array_key_exists($attribute, HAClimateDefinitions::ATTRIBUTE_DEFINITIONS)) {
                $value = $this->parseAttributePayload($payload);
                if ($value !== null) {
                    $this->storeEntityAttribute($entityId, $attribute, $value);
                    $this->updateEntityCache($entityId, null, [$attribute => $value]);
                    $this->updateEntityPresentation($entityId, $this->entities[$entityId]['attributes'] ?? []);
                }
                return true;
            }

            if (!$this->ensureClimateAttributeVariable($entityId, $attribute)) {
                $this->debugExpert('AttributeTopic', 'Keine Variable für Attribut', ['EntityID' => $entityId, 'Attribute' => $attribute]);
                return false;
            }

            $value = $this->parseAttributePayload($payload);
            if ($value === null) {
                $this->debugExpert('AttributeTopic', 'Payload null', ['EntityID' => $entityId, 'Attribute' => $attribute]);
                return true;
            }

            $meta = HAClimateDefinitions::ATTRIBUTE_DEFINITIONS[$attribute] ?? null;
            if ($meta === null) {
                $this->debugExpert('AttributeTopic', 'Attribut nicht definiert', ['Attribute' => $attribute]);
                return false;
            }

            $value = $this->castVariableValue($value, $meta['type']);
            $ident = $this->sanitizeIdent($entityId . '_' . $attribute);
            $this->setValueWithDebug($ident, $value);
            $this->debugExpert('AttributeTopic', 'SetValue', ['Ident' => $ident, 'Value' => $value]);
            $this->updateEntityCache($entityId, null, [$attribute => $value]);
            return true;
        }
        if ($currentDomain === HAFanDefinitions::DOMAIN) {
            if (!array_key_exists($attribute, HAFanDefinitions::ATTRIBUTE_DEFINITIONS)) {
                $value = $this->parseAttributePayload($payload);
                if ($value !== null) {
                    $this->storeEntityAttribute($entityId, $attribute, $value);
                    $this->updateEntityCache($entityId, null, [$attribute => $value]);
                    $this->updateEntityPresentation($entityId, $this->entities[$entityId]['attributes'] ?? []);
                }
                return true;
            }

            if (!$this->ensureFanAttributeVariable($entityId, $attribute)) {
                $this->debugExpert('AttributeTopic', 'Keine Variable fÃ¼r Attribut', ['EntityID' => $entityId, 'Attribute' => $attribute]);
                return false;
            }

            $value = $this->parseAttributePayload($payload);
            if ($value === null) {
                $this->debugExpert('AttributeTopic', 'Payload null', ['EntityID' => $entityId, 'Attribute' => $attribute]);
                return true;
            }

            $meta = HAFanDefinitions::ATTRIBUTE_DEFINITIONS[$attribute] ?? null;
            if ($meta === null) {
                $this->debugExpert('AttributeTopic', 'Attribut nicht definiert', ['Attribute' => $attribute]);
                return false;
            }

            $value = $this->castVariableValue($value, $meta['type']);
            $ident = $this->sanitizeIdent($entityId . '_' . $attribute);
            $this->setValueWithDebug($ident, $value);
            $this->debugExpert('AttributeTopic', 'SetValue', ['Ident' => $ident, 'Value' => $value]);
            $this->updateEntityCache($entityId, null, [$attribute => $value]);
            return true;
        }
        if ($currentDomain === HAHumidifierDefinitions::DOMAIN) {
            if ($attribute === 'humidity') {
                $attribute = 'target_humidity';
            }
            if (!array_key_exists($attribute, HAHumidifierDefinitions::ATTRIBUTE_DEFINITIONS)) {
                $value = $this->parseAttributePayload($payload);
                if ($value !== null) {
                    $this->storeEntityAttribute($entityId, $attribute, $value);
                    $this->updateEntityCache($entityId, null, [$attribute => $value]);
                    $this->updateEntityPresentation($entityId, $this->entities[$entityId]['attributes'] ?? []);
                }
                return true;
            }

            if (!$this->ensureHumidifierAttributeVariable($entityId, $attribute)) {
                $this->debugExpert('AttributeTopic', 'Keine Variable fÃ¼r Attribut', ['EntityID' => $entityId, 'Attribute' => $attribute]);
                return false;
            }

            $value = $this->parseAttributePayload($payload);
            if ($value === null) {
                $this->debugExpert('AttributeTopic', 'Payload null', ['EntityID' => $entityId, 'Attribute' => $attribute]);
                return true;
            }

            $meta = HAHumidifierDefinitions::ATTRIBUTE_DEFINITIONS[$attribute] ?? null;
            if ($meta === null) {
                $this->debugExpert('AttributeTopic', 'Attribut nicht definiert', ['Attribute' => $attribute]);
                return false;
            }

            $value = $this->castVariableValue($value, $meta['type']);
            $ident = $this->sanitizeIdent($entityId . '_' . $attribute);
            $this->setValueWithDebug($ident, $value);
            $this->debugExpert('AttributeTopic', 'SetValue', ['Ident' => $ident, 'Value' => $value]);
            $this->updateEntityCache($entityId, null, [$attribute => $value]);
            return true;
        }
        if ($currentDomain === HAMediaPlayerDefinitions::DOMAIN) {
            if ($attribute === 'entity_picture') {
                $attribute = 'media_image_url';
            }
            if (!array_key_exists($attribute, HAMediaPlayerDefinitions::ATTRIBUTE_DEFINITIONS)) {
                $value = $this->parseAttributePayload($payload);
                if ($value !== null) {
                    $this->storeEntityAttribute($entityId, $attribute, $value);
                    $this->updateEntityCache($entityId, null, [$attribute => $value]);
                    $this->updateEntityPresentation($entityId, $this->entities[$entityId]['attributes'] ?? []);
                }
                return true;
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
                    $this->debugExpert('AttributeTopic', 'Keine Variable fÃ¼r Attribut', ['EntityID' => $entityId, 'Attribute' => $attribute]);
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

            if (!$this->ensureMediaPlayerAttributeVariable($entityId, $attribute)) {
                $this->debugExpert('AttributeTopic', 'Keine Variable für Attribut', ['EntityID' => $entityId, 'Attribute' => $attribute]);
                return false;
            }

            $value = $this->parseAttributePayload($payload);
            if ($value === null) {
                $this->debugExpert('AttributeTopic', 'Payload null', ['EntityID' => $entityId, 'Attribute' => $attribute]);
                return true;
            }

            $meta = HAMediaPlayerDefinitions::ATTRIBUTE_DEFINITIONS[$attribute] ?? null;
            if ($meta === null) {
                $this->debugExpert('AttributeTopic', 'Attribut nicht definiert', ['Attribute' => $attribute]);
                return false;
            }

            if ($attribute === 'repeat') {
                $value = $this->mapMediaPlayerRepeatToValue($value);
            } else {
                $value = $this->castVariableValue($value, $meta['type']);
            }
            $ident = $this->sanitizeIdent($entityId . '_' . $attribute);
            $this->setValueWithDebug($ident, $value);
            $this->debugExpert('AttributeTopic', 'SetValue', ['Ident' => $ident, 'Value' => $value]);
            $this->updateEntityCache($entityId, null, [$attribute => $value]);
            return true;
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

}








