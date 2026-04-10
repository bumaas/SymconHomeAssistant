<?php

declare(strict_types=1);

trait HASupportedFeaturesTrait
{
    private function mapSupportedFeaturesByDomain(string $domain, int $mask, bool $stripPrefix = false): array
    {
        $map = HADomainCatalog::getSupportedFeaturesMap($domain);

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
