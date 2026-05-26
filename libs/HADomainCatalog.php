<?php

declare(strict_types=1);

final class HADomainCatalog
{
    // The matrix bundles stable domain metadata that was previously duplicated.
    private static function getMatrix(): array
    {
        return [
            HALightDefinitions::DOMAIN => [
                'configurator_default' => true,
                'main_writable' => true,
                'status_domain' => true,
                'attribute_topics' => true,
                'attribute_payload' => true,
                'variable_type' => HALightDefinitions::VARIABLE_TYPE,
                'supported_features' => HALightDefinitions::SUPPORTED_FEATURES
            ],
            HASwitchDefinitions::DOMAIN => [
                'configurator_default' => true,
                'main_writable' => true,
                'attribute_topics' => true,
                'variable_type' => HASwitchDefinitions::VARIABLE_TYPE
            ],
            'input_boolean' => [
                'alias' => HASwitchDefinitions::DOMAIN,
                'configurator_default' => true,
                'main_writable' => true,
                'variable_type' => HASwitchDefinitions::VARIABLE_TYPE
            ],
            HASensorDefinitions::DOMAIN => [
                'configurator_default' => true,
                'attribute_topics' => true,
                'device_class_name_fallback' => true
            ],
            HABinarySensorDefinitions::DOMAIN => [
                'configurator_default' => true,
                'attribute_topics' => true,
                'device_class_name_fallback' => true,
                'variable_type' => HABinarySensorDefinitions::VARIABLE_TYPE
            ],
            HAClimateDefinitions::DOMAIN => [
                'configurator_default' => true,
                'main_writable' => true,
                'attribute_topics' => true,
                'attribute_payload' => true,
                'variable_type' => HAClimateDefinitions::VARIABLE_TYPE,
                'supported_features' => HAClimateDefinitions::SUPPORTED_FEATURES
            ],
            HANumberDefinitions::DOMAIN => [
                'configurator_default' => true,
                'main_writable' => true,
                'attribute_topics' => true,
                'device_class_name_fallback' => true
            ],
            'input_number' => [
                'alias' => HANumberDefinitions::DOMAIN,
                'configurator_default' => true,
                'main_writable' => true,
                'device_class_name_fallback' => true
            ],
            HALockDefinitions::DOMAIN => [
                'configurator_default' => true,
                'main_writable' => true,
                'status_domain' => true,
                'attribute_topics' => true,
                'variable_type' => HALockDefinitions::VARIABLE_TYPE,
                'supported_features' => HALockDefinitions::SUPPORTED_FEATURES
            ],
            HACoverDefinitions::DOMAIN => [
                'configurator_default' => true,
                'main_writable' => true,
                'attribute_topics' => true,
                'attribute_payload' => true,
                'supported_features' => HACoverDefinitions::SUPPORTED_FEATURES
            ],
            HAValveDefinitions::DOMAIN => [
                'configurator_default' => true,
                'main_writable' => true,
                'attribute_topics' => true,
                'supported_features' => HAValveDefinitions::SUPPORTED_FEATURES
            ],
            HAEventDefinitions::DOMAIN => [
                'configurator_default' => true,
                'attribute_topics' => true,
                'variable_type' => HAEventDefinitions::VARIABLE_TYPE
            ],
            HASelectDefinitions::DOMAIN => [
                'configurator_default' => true,
                'main_writable' => true,
                'attribute_topics' => true,
                'variable_type' => HASelectDefinitions::VARIABLE_TYPE
            ],
            'input_select' => [
                'alias' => HASelectDefinitions::DOMAIN,
                'configurator_default' => true,
                'main_writable' => true,
                'attribute_topics' => true,
                'variable_type' => HASelectDefinitions::VARIABLE_TYPE
            ],
            HAInputTextDefinitions::DOMAIN => [
                'configurator_default' => true,
                'main_writable' => true,
                'attribute_topics' => true,
                'variable_type' => HAInputTextDefinitions::VARIABLE_TYPE
            ],
            HADateTimeDefinitions::DOMAIN => [
                'configurator_default' => true,
                'main_writable' => true,
                'attribute_topics' => true,
                'variable_type' => HADateTimeDefinitions::VARIABLE_TYPE
            ],
            HAInputDateTimeDefinitions::DOMAIN => [
                'configurator_default' => true,
                'main_writable' => true,
                'attribute_topics' => true,
                'variable_type' => HAInputDateTimeDefinitions::VARIABLE_TYPE
            ],
            HAVacuumDefinitions::DOMAIN => [
                'configurator_default' => true,
                'status_domain' => true,
                'attribute_topics' => true,
                'variable_type' => HAVacuumDefinitions::VARIABLE_TYPE,
                'supported_features' => HAVacuumDefinitions::SUPPORTED_FEATURES
            ],
            HALawnMowerDefinitions::DOMAIN => [
                'configurator_default' => true,
                'status_domain' => true,
                'attribute_topics' => true,
                'variable_type' => HALawnMowerDefinitions::VARIABLE_TYPE,
                'supported_features' => HALawnMowerDefinitions::SUPPORTED_FEATURES
            ],
            HAMediaPlayerDefinitions::DOMAIN => [
                'configurator_default' => true,
                'status_domain' => true,
                'attribute_topics' => true,
                'attribute_payload' => true,
                'variable_type' => HAMediaPlayerDefinitions::VARIABLE_TYPE,
                'supported_features' => HAMediaPlayerDefinitions::SUPPORTED_FEATURES
            ],
            HACameraDefinitions::DOMAIN => [
                'configurator_default' => true,
                'status_domain' => true,
                'attribute_topics' => true,
                'variable_type' => HACameraDefinitions::VARIABLE_TYPE,
                'supported_features' => HACameraDefinitions::SUPPORTED_FEATURES
            ],
            HAImageDefinitions::DOMAIN => [
                'configurator_default' => true,
                'status_domain' => true,
                'attribute_topics' => true,
                'variable_type' => HAImageDefinitions::VARIABLE_TYPE
            ],
            HADeviceTrackerDefinitions::DOMAIN => [
                'configurator_default' => true,
                'status_domain' => true,
                'attribute_topics' => true,
                'variable_type' => HADeviceTrackerDefinitions::VARIABLE_TYPE
            ],
            HAUpdateDefinitions::DOMAIN => [
                'configurator_default' => true,
                'status_domain' => true,
                'attribute_topics' => true,
                'variable_type' => HAUpdateDefinitions::VARIABLE_TYPE
            ],
            HAButtonDefinitions::DOMAIN => [
                'configurator_default' => true,
                'main_writable' => true,
                'variable_type' => HAButtonDefinitions::VARIABLE_TYPE
            ],
            HAInputButtonDefinitions::DOMAIN => [
                'alias' => HAButtonDefinitions::DOMAIN,
                'configurator_default' => true
            ],
            HAFanDefinitions::DOMAIN => [
                'configurator_default' => true,
                'main_writable' => true,
                'status_domain' => true,
                'attribute_topics' => true,
                'attribute_payload' => true,
                'variable_type' => HAFanDefinitions::VARIABLE_TYPE,
                'supported_features' => HAFanDefinitions::SUPPORTED_FEATURES
            ],
            HAHumidifierDefinitions::DOMAIN => [
                'configurator_default' => true,
                'main_writable' => true,
                'status_domain' => true,
                'attribute_topics' => true,
                'attribute_payload' => true,
                'variable_type' => HAHumidifierDefinitions::VARIABLE_TYPE,
                'supported_features' => HAHumidifierDefinitions::SUPPORTED_FEATURES
            ]
        ];
    }

    public static function normalizeDomainAlias(string $domain): string
    {
        $domain = trim($domain);
        $matrix = self::getMatrix();
        if (!isset($matrix[$domain])) {
            return $domain;
        }

        $alias = $matrix[$domain]['alias'] ?? null;
        if (is_string($alias) && $alias !== '' && isset($matrix[$alias])) {
            return $alias;
        }

        return $domain;
    }

    public static function getConfiguratorDefaultDomains(): array
    {
        return array_keys(
            array_filter(
                self::getMatrix(),
                static fn(array $meta): bool => (bool)($meta['configurator_default'] ?? false)
            )
        );
    }

    public static function isMainWritable(string $domain): bool
    {
        return (bool)(self::getDefinition($domain)['main_writable'] ?? false);
    }

    public static function isDomainSupported(string $domain): bool
    {
        $matrix = self::getMatrix();
        $domain = trim($domain);
        return isset($matrix[$domain]);
    }

    public static function getDomainSelectOptions(): array
    {
        $matrix = self::getMatrix();
        $options = [];
        foreach ($matrix as $domain => $meta) {
            $caption = ucfirst(str_replace('_', ' ', (string)$domain)) . ' (' . $domain . ')';
            $options[] = ['caption' => $caption, 'value' => $domain];
        }
        usort($options, static fn ($a, $b) => strcmp($a['caption'], $b['caption']));
        return $options;
    }

    public static function isStatusDomain(string $domain): bool
    {
        return (bool)(self::getDefinition($domain)['status_domain'] ?? false);
    }

    public static function supportsAttributeTopics(string $domain): bool
    {
        return (bool)(self::getDefinition($domain)['attribute_topics'] ?? false);
    }

    public static function supportsAttributePayload(string $domain): bool
    {
        return (bool)(self::getDefinition($domain)['attribute_payload'] ?? false);
    }

    public static function getSupportedFeaturesMap(string $domain): array
    {
        $map = self::getDefinition($domain)['supported_features'] ?? [];
        return is_array($map) ? $map : [];
    }

    public static function getMainVariableType(string $domain): ?int
    {
        $type = self::getDefinition($domain)['variable_type'] ?? null;
        return is_int($type) ? $type : null;
    }

    public static function supportsDeviceClassNameFallback(string $domain): bool
    {
        return (bool)(self::getDefinition($domain)['device_class_name_fallback'] ?? false);
    }

    private static function getDefinition(string $domain): array
    {
        $matrix = self::getMatrix();
        $domain = trim($domain);
        if (!isset($matrix[$domain])) {
            return [];
        }

        $definition = $matrix[$domain];
        $alias = $definition['alias'] ?? null;
        if (!is_string($alias) || $alias === '' || !isset($matrix[$alias])) {
            return $definition;
        }

        return array_replace($matrix[$alias], $definition);
    }
}
