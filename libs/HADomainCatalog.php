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
                'variable_type' => HAUpdateDefinitions::VARIABLE_TYPE,
                'supported_features' => HAUpdateDefinitions::SUPPORTED_FEATURES
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

    public static function getPositionBlockSize(string $domain): int
    {
        return match ($domain) {
            HAMediaPlayerDefinitions::DOMAIN => 360,   // 30 Attribute * 10 + Puffer
            HALightDefinitions::DOMAIN        => 160,   // 10 Attribute + Fallbacks bis +119
            HAFanDefinitions::DOMAIN          => 100,   // 6 Attribute * 10 + Puffer
            HAHumidifierDefinitions::DOMAIN   => 80,    // 5 Attribute * 10 + Puffer
            HAClimateDefinitions::DOMAIN      => 60,    // max +21 + Puffer
            default                           => 30,
        };
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

    /**
     * Universelle HA-Attribut-Topics, die HA (z. B. via mqtt_statestream) als eigene Topics publiziert,
     * die das Device aber nicht verarbeitet: reine Bookkeeping-Zeitstempel (last_updated/last_changed/
     * last_reported), Quellenhinweis (attribution) und der HA-Icon-Hinweis (icon; die Darstellung kommt aus
     * den IPS-Profilen/Domain-Definitionen, nicht aus dem HA-Icon). Sie werden nie zu IPS-Variablen, wuerden
     * aber pro Message eine teure Presentation-Synchronisation ausloesen. Single Source of Truth fuer beide
     * Splitter (die sie gar nicht erst an Kinder weiterreichen) und das Device (das sie verwirft).
     *
     * @var string[]
     */
    public const array IGNORABLE_BOOKKEEPING_ATTRIBUTES = ['last_updated', 'last_changed', 'last_reported', 'attribution', 'icon'];

    public static function isIgnorableBookkeepingAttribute(string $attribute): bool
    {
        return in_array($attribute, self::IGNORABLE_BOOKKEEPING_ATTRIBUTES, true);
    }

    public static function isIgnorableBookkeepingTopic(string $topic): bool
    {
        $pos = strrpos($topic, '/');
        $suffix = $pos === false ? $topic : substr($topic, $pos + 1);
        return self::isIgnorableBookkeepingAttribute($suffix);
    }

    /**
     * Laengster gemeinsamer String-Praefix einer Liste. Basis fuer das Zusammenfassen verwandter
     * Empfangsfilter-Topics in den Geraetemodulen.
     *
     * @param string[] $strings
     */
    public static function longestCommonStringPrefix(array $strings): string
    {
        if ($strings === []) {
            return '';
        }

        $prefix = (string)$strings[0];
        foreach ($strings as $candidate) {
            while ($prefix !== '' && !str_starts_with((string)$candidate, $prefix)) {
                $prefix = substr($prefix, 0, -1);
            }
            if ($prefix === '') {
                break;
            }
        }

        return $prefix;
    }

    /**
     * Fasst bereits sortierte Namen in Cluster mit gemeinsamem Praefix (>= $minPrefix Zeichen) zusammen.
     * So lassen sich K Einzeltopics im Empfangsfilter auf wenige kompakte Praefix-Muster reduzieren
     * (geteilter Kern fuer beide Geraetepfade; die jeweilige Topic-Kodierung wenden die Module selbst an).
     *
     * @param string[] $names  alphabetisch sortiert
     * @return array<int, array{members: string[], prefix: string}>
     */
    public static function clusterByCommonPrefix(array $names, int $minPrefix): array
    {
        $clusters = [];
        $current = [];
        $prefix = '';

        foreach ($names as $name) {
            if ($current === []) {
                $current = [$name];
                $prefix = $name;
                continue;
            }

            $candidate = self::longestCommonStringPrefix([$prefix, $name]);
            if (strlen($candidate) >= $minPrefix) {
                $current[] = $name;
                $prefix = $candidate;
                continue;
            }

            $clusters[] = ['members' => $current, 'prefix' => $prefix];
            $current = [$name];
            $prefix = $name;
        }

        if ($current !== []) {
            $clusters[] = ['members' => $current, 'prefix' => $prefix];
        }

        return $clusters;
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
