<?php

declare(strict_types=1);

trait HASupportedFeaturesTrait
{
    private function mapSupportedFeaturesByDomain(string $domain, int $mask, bool $stripPrefix = false): array
    {
        $map = match ($domain) {
            HALightDefinitions::DOMAIN => HALightDefinitions::SUPPORTED_FEATURES,
            HAClimateDefinitions::DOMAIN => HAClimateDefinitions::SUPPORTED_FEATURES,
            HACoverDefinitions::DOMAIN => HACoverDefinitions::SUPPORTED_FEATURES,
            HALockDefinitions::DOMAIN => HALockDefinitions::SUPPORTED_FEATURES,
            HAVacuumDefinitions::DOMAIN => HAVacuumDefinitions::SUPPORTED_FEATURES,
            HAMediaPlayerDefinitions::DOMAIN => HAMediaPlayerDefinitions::SUPPORTED_FEATURES,
            HAFanDefinitions::DOMAIN => HAFanDefinitions::SUPPORTED_FEATURES,
            HAHumidifierDefinitions::DOMAIN => HAHumidifierDefinitions::SUPPORTED_FEATURES,
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

        if (!$stripPrefix) {
            return $list;
        }

        return array_map(
            static function (string $label): string {
                $parts = explode(':', $label, 2);
                return isset($parts[1]) ? ltrim($parts[1]) : $label;
            },
            $list
        );
    }
}
