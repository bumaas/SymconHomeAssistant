<?php

declare(strict_types=1);

/**
 * Gemeinsamer Kern der Opt-in Topic-Statistik beider Splitter (Home Assistant Splitter und
 * MQTT Discovery Splitter): zählt eingehende MQTT-Messages und Payload-Bytes je Entität und
 * verdichtet sie beim periodischen Dump zu Geräte-Zeilen.
 *
 * Warum Bytes zusätzlich zur Nachrichtenzahl: Die Zahl allein täuscht, wenn wenige Topics große
 * Payloads tragen (Attribut-JSON, Kamera-Token, Verlaufslisten). Ein Fall aus dem Feld: 20 % weniger
 * Nachrichten, aber Last von 100 % auf 5 % — der Unterschied lag in der Nachrichtengröße. Die größte
 * Einzel-Payload je Gerät ("max") macht solche Ausreißer sofort sichtbar.
 *
 * Zählerformat im Buffer (JSON): { "<entityKey>": {"n": <Messages>, "b": <Bytes>, "m": <max Bytes>} }.
 * Ältere Buffer-Stände mit reinen Integer-Zählern werden beim Laden hochgestuft.
 */
final class HATopicStatistics
{
    public const int TOP_DEVICES_DEBUG = 20;
    public const int TOP_DEVICES_LOG   = 10;
    public const int TOP_ENTITIES_BYTES = 5;
    public const int TOP_ENTITIES_BYTES_LOG = 3;

    /**
     * Schlüssel = Topic ohne letztes Segment (Attribut-/State-Suffix) => eine Entität, alle ihre
     * Sub-Topics zählen zusammen. Geräte-Gruppierung erfolgt beim Dump über den gemeinsamen Präfix.
     */
    public static function keyForTopic(string $topic): string
    {
        $topic = trim($topic, '/');
        if ($topic === '') {
            return '';
        }
        $pos = strrpos($topic, '/');
        return $pos === false ? $topic : substr($topic, 0, $pos);
    }

    /**
     * Dekodiert den Zähler-Buffer; leere/ungültige Inhalte ergeben ein leeres Array. Alte Buffer mit
     * Integer-Zählern (vor Bytes-Erfassung) werden auf das Objektformat gehoben, damit ein Modul-Reload
     * mitten im Fenster keine Exception im Hot-Path auslöst.
     *
     * @return array<string, array{n:int,b:int,m:int}>
     */
    public static function decodeCounts(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }
        if (!is_array($decoded)) {
            return [];
        }
        $counts = [];
        foreach ($decoded as $key => $entry) {
            $counts[(string)$key] = self::normalizeEntry($entry);
        }
        return $counts;
    }

    /**
     * @param array<string, array{n:int,b:int,m:int}> $counts
     * @return array<string, array{n:int,b:int,m:int}>
     */
    public static function record(array $counts, string $topic, int $payloadBytes): array
    {
        $key = self::keyForTopic($topic);
        if ($key === '') {
            return $counts;
        }
        $entry = self::normalizeEntry($counts[$key] ?? null);
        $entry['n']++;
        $entry['b'] += max(0, $payloadBytes);
        $entry['m'] = max($entry['m'], $payloadBytes);
        $counts[$key] = $entry;
        return $counts;
    }

    /**
     * Verdichtet die Entitäts-Zähler eines Fensters zu Geräte-Zeilen (absteigend nach Nachrichtenzahl)
     * und den Top-Entitäten nach Bytes.
     *
     * Pro Gerät gruppieren: Objekt-ID (letztes Segment der Entity-Keys) über gemeinsamen Präfix
     * clustern, damit z. B. alle marstek_*-Entitäten domainübergreifend in einer Zeile zusammenlaufen.
     * (Clustern der vollen Keys würde am gemeinsamen "<base>/<domain>/" alles zusammenwerfen.)
     *
     * @param array<string, array{n:int,b:int,m:int}> $counts
     * @return array{
     *   total:int, bytes:int, max:int, entities:int,
     *   devices: array<string, array{n:int,b:int,m:int}>,
     *   topEntitiesByBytes: array<string, array{n:int,b:int,m:int}>
     * }
     */
    public static function aggregate(array $counts): array
    {
        $total = 0;
        $bytes = 0;
        $max = 0;
        $byObjectId = [];
        foreach ($counts as $entityKey => $entry) {
            $entry = self::normalizeEntry($entry);
            $total += $entry['n'];
            $bytes += $entry['b'];
            $max = max($max, $entry['m']);
            $pos = strrpos((string)$entityKey, '/');
            $objectId = $pos === false ? (string)$entityKey : substr((string)$entityKey, $pos + 1);
            $byObjectId[$objectId] = self::merge($byObjectId[$objectId] ?? null, $entry);
        }

        $objectIds = array_keys($byObjectId);
        sort($objectIds, SORT_STRING);
        $devices = [];
        foreach (HADomainCatalog::clusterByCommonPrefix($objectIds, 3) as $cluster) {
            $members = $cluster['members'];
            $deviceKey = count($members) >= 2 ? $cluster['prefix'] . '*' : ($members[0] ?? '');
            $sum = null;
            foreach ($members as $m) {
                $sum = self::merge($sum, $byObjectId[$m] ?? null);
            }
            $devices[$deviceKey] = self::merge($devices[$deviceKey] ?? null, $sum);
        }
        uasort($devices, static fn(array $a, array $b): int => $b['n'] <=> $a['n']);

        $byBytes = [];
        foreach ($counts as $entityKey => $entry) {
            $byBytes[(string)$entityKey] = self::normalizeEntry($entry);
        }
        uasort($byBytes, static fn(array $a, array $b): int => $b['b'] <=> $a['b']);

        return [
            'total'              => $total,
            'bytes'              => $bytes,
            'max'                => $max,
            'entities'           => count($counts),
            'devices'            => $devices,
            'topEntitiesByBytes' => array_slice($byBytes, 0, self::TOP_ENTITIES_BYTES, true),
        ];
    }

    /** Kopfzeile eines Fensters, z. B. "Fenster 300s | total=10253 (2050.6/min) | 1.2 MB (4.1 KB/s, max 4.8 KB) | Entitäten=25 | Geräte=4". */
    public static function formatHeader(array $aggregate, int $elapsedSeconds): string
    {
        $elapsedSeconds = max(1, $elapsedSeconds);
        return sprintf(
            'Fenster %ds | total=%d (%s/min) | %s (%s/s, max %s) | Entitäten=%d | Geräte=%d',
            $elapsedSeconds,
            $aggregate['total'],
            self::perMinute($aggregate['total'], $elapsedSeconds),
            self::formatBytes($aggregate['bytes']),
            self::formatBytes((int)round($aggregate['bytes'] / $elapsedSeconds)),
            self::formatBytes($aggregate['max']),
            $aggregate['entities'],
            count($aggregate['devices'])
        );
    }

    /** Kompakte Geräte-Angabe für die Log-Zeile, z. B. "jackery_* 9814 (1962.8/min, 1.1 MB, max 4.8 KB)". */
    public static function formatDevice(string $deviceKey, array $entry, int $elapsedSeconds): string
    {
        return sprintf(
            '%s %d (%s/min, %s, max %s)',
            $deviceKey,
            $entry['n'],
            self::perMinute($entry['n'], max(1, $elapsedSeconds)),
            self::formatBytes($entry['b']),
            self::formatBytes($entry['m'])
        );
    }

    /** Tabellenzeile für das Debug-Fenster (feste Spaltenbreiten). */
    public static function formatDeviceRow(string $deviceKey, array $entry, int $elapsedSeconds): string
    {
        return sprintf(
            '  %-50s %6d (%s/min) %10s  max %s',
            $deviceKey,
            $entry['n'],
            self::perMinute($entry['n'], max(1, $elapsedSeconds)),
            self::formatBytes($entry['b']),
            self::formatBytes($entry['m'])
        );
    }

    /** Eine Zeile fürs persistente Symcon-Log: Kopfzeile + Top-Geräte. */
    public static function formatLogLine(array $aggregate, int $elapsedSeconds): string
    {
        $parts = [];
        foreach (array_slice($aggregate['devices'], 0, self::TOP_DEVICES_LOG, true) as $deviceKey => $entry) {
            $parts[] = self::formatDevice((string)$deviceKey, $entry, $elapsedSeconds);
        }
        $remaining = count($aggregate['devices']) - self::TOP_DEVICES_LOG;
        if ($remaining > 0) {
            $parts[] = sprintf('… (%d weitere)', $remaining);
        }
        // Bytes-Spitzenreiter je Entität (Objekt-ID reicht zur Identifikation, die Domäne steht im Debug-Fenster).
        $byteParts = [];
        foreach (array_slice($aggregate['topEntitiesByBytes'], 0, self::TOP_ENTITIES_BYTES_LOG, true) as $entityKey => $entry) {
            $pos = strrpos((string)$entityKey, '/');
            $objectId = $pos === false ? (string)$entityKey : substr((string)$entityKey, $pos + 1);
            $byteParts[] = sprintf('%s %s (max %s)', $objectId, self::formatBytes($entry['b']), self::formatBytes($entry['m']));
        }
        return sprintf(
            'Topic-Statistik %s | Top: %s | Bytes-Top: %s',
            self::formatHeader($aggregate, $elapsedSeconds),
            implode(', ', $parts),
            implode(', ', $byteParts)
        );
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1, '.', '') . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1, '.', '') . ' KB';
        }
        return $bytes . ' B';
    }

    private static function perMinute(int $n, int $elapsedSeconds): string
    {
        return number_format($n / ($elapsedSeconds / 60.0), 1, '.', '');
    }

    /** @return array{n:int,b:int,m:int} */
    private static function normalizeEntry(mixed $entry): array
    {
        if (is_int($entry)) {
            return ['n' => $entry, 'b' => 0, 'm' => 0];
        }
        if (!is_array($entry)) {
            return ['n' => 0, 'b' => 0, 'm' => 0];
        }
        return [
            'n' => (int)($entry['n'] ?? 0),
            'b' => (int)($entry['b'] ?? 0),
            'm' => (int)($entry['m'] ?? 0),
        ];
    }

    /** @return array{n:int,b:int,m:int} */
    private static function merge(?array $a, ?array $b): array
    {
        $a = self::normalizeEntry($a);
        $b = self::normalizeEntry($b);
        return ['n' => $a['n'] + $b['n'], 'b' => $a['b'] + $b['b'], 'm' => max($a['m'], $b['m'])];
    }
}
