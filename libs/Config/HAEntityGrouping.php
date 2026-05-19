<?php

declare(strict_types=1);

trait HAEntityGroupingTrait
{
    // Max. Anzahl an Entity-Namen in der Zusammenfassung (danach nur Anzahl).
    private const int ENTITY_SUMMARY_MAX_NAMES = 10;

    private function groupEntitiesToDevices(array $entities): array
    {
        $devices = [];
        foreach ($entities as $entity) {
            if (!is_array($entity)) {
                continue;
            }

            $isRealDevice = (($entity['device_id'] ?? 'none') !== 'none');
            $uniqueDeviceKey = $isRealDevice
                ? 'HA_DEV_' . $entity['device_id']
                : 'HA_ENT_' . str_replace('.', '_', (string)($entity['entity_id'] ?? ''));

            if (!isset($devices[$uniqueDeviceKey])) {
                $devices[$uniqueDeviceKey] = [
                    'ident' => $uniqueDeviceKey,
                    'name' => $isRealDevice ? ($entity['device'] ?? $entity['name']) : $entity['name'],
                    'manufacturer' => $isRealDevice ? ($entity['device_manufacturer'] ?? '') : '',
                    'model' => $isRealDevice ? ($entity['device_model'] ?? '') : '',
                    'device_id' => $isRealDevice ? $entity['device_id'] : $entity['entity_id'],
                    'area' => $isRealDevice ? ($entity['area'] ?? 'Kein Bereich') : 'Sonstiges',
                    'entities' => [],
                ];
            }

            $devices[$uniqueDeviceKey]['entities'][] = [
                'domain' => $entity['domain'],
                'name' => $entity['name'],
                'entity_id' => $entity['entity_id']
            ];
        }

        $this->sortGroupedDevices($devices);
        return $devices;
    }

    private function getCleanedEntities(array $device): array
    {
        $cleaned = [];
        $devicePrefix = $device['name'] . ' ';
        $nameCounts = [];

        foreach ($device['entities'] as $entity) {
            $name = $entity['name'];
            if (str_starts_with($name, $devicePrefix)) {
                $name = substr($name, strlen($devicePrefix));
            }
            $nameCounts[$name] = ($nameCounts[$name] ?? 0) + 1;
            $cleaned[] = array_merge($entity, ['name' => $name]);
        }

        foreach ($cleaned as &$entity) {
            if (($nameCounts[$entity['name']] ?? 0) <= 1) {
                continue;
            }

            $entityIdName = (string)$entity['entity_id'];
            $dotPos = strpos($entityIdName, '.');
            if ($dotPos !== false) {
                $entityIdName = substr($entityIdName, $dotPos + 1);
            }
            $entityIdName = str_replace('_', ' ', $entityIdName);
            $devicePrefixLower = strtolower($device['name']) . ' ';
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
        unset($entity);

        return $cleaned;
    }

    private function generateEntitySummary(array $entities): string
    {
        if (count($entities) <= self::ENTITY_SUMMARY_MAX_NAMES) {
            return implode(', ', array_column($entities, 'name'));
        }

        return count($entities) . ' Entitäten';
    }

    private function sortGroupedDevices(array &$devices): void
    {
        uasort($devices, static fn(array $left, array $right): int => strcasecmp($left['area'] . '_' . $left['name'], $right['area'] . '_' . $right['name']));
    }
}
