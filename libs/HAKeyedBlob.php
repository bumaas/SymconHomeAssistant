<?php

declare(strict_types=1);

/**
 * Zeilenweises Format für einen Cache im Instanz-Buffer, aus dem sich eine einzelne Zeile lesen lässt, ohne den
 * ganzen Bestand zu dekodieren:
 *   Zeile 1:      Kopf als JSON-Objekt (Signatur und was der Aufrufer sonst braucht)
 *   je Eintrag:   "<Schlüssel als JSON-String>" TAB <Wert als JSON>
 * JSON kodiert Zeilenumbrüche und Tabs immer als Escape, beide Trenner sind also eindeutig.
 *
 * Anlass: Unter der Rust-Edition kann jede MQTT-Meldung in einer eigenen PHP-Ausführung laufen, ein Memo im
 * Objekt hält dann nicht. Das klassische Gerät dekodierte deshalb je Meldung seine ganze Konfiguration (bei rund
 * 300 Entitäten auf einem Raspberry Pi 42 ms), das Discovery-Gerät baute seine Entitäten je Meldung neu auf
 * (Anwender mit Z-Wave JS UI, 26.11.2026: 8 bis 14 ms). Beide lesen jetzt nur die Zeilen, die die Meldung braucht.
 *
 * Die Klasse kennt weder Signatur noch Inhalt. Ob ein Blob noch gilt, entscheidet der Aufrufer am Kopf.
 */
final class HAKeyedBlob
{
    private const int JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * @param array<string, mixed> $header
     * @param iterable<array{0: string, 1: mixed}> $rows Schlüssel und Wert; ein Schlüssel darf mehrfach vorkommen
     * @param int $extraFlags zusätzliche json_encode-Flags für die Werte (z. B. JSON_PRESERVE_ZERO_FRACTION)
     * @throws JsonException
     */
    public static function encode(array $header, iterable $rows, int $extraFlags = 0): string
    {
        $lines = [json_encode($header, self::JSON_FLAGS)];
        foreach ($rows as [$key, $value]) {
            $lines[] = self::encodeKey((string)$key) . "\t" . json_encode($value, self::JSON_FLAGS | $extraFlags);
        }
        return implode("\n", $lines);
    }

    /** Kopfzeile als Array; null, wenn der Blob leer ist oder keinen lesbaren Kopf hat. */
    public static function header(string $blob): ?array
    {
        if ($blob === '') {
            return null;
        }
        $end = strpos($blob, "\n");
        $header = json_decode($end === false ? $blob : substr($blob, 0, $end), true);
        return is_array($header) ? $header : null;
    }

    /**
     * Eine Zeile über ihren Schlüssel. Kommt er mehrfach vor, gewinnt das letzte Vorkommen (wie in einem Index,
     * der die Zeilen der Reihe nach einträgt).
     *
     * @return array|false|null die Zeile; null, wenn es den Schlüssel nicht gibt; false, wenn die Zeile kein
     *                          Array ergibt (der Blob gilt dann als beschädigt)
     * @throws JsonException wenn der Schlüssel kein gültiges UTF-8 ist
     */
    public static function row(string $blob, string $key): array|false|null
    {
        $start = strrpos($blob, "\n" . self::encodeKey($key) . "\t");
        if ($start === false) {
            return null;
        }
        $start = strpos($blob, "\t", $start) + 1;
        $end = strpos($blob, "\n", $start);
        $decoded = json_decode($end === false ? substr($blob, $start) : substr($blob, $start, $end - $start), true);
        return is_array($decoded) ? $decoded : false;
    }

    /**
     * Alle Werte in der Reihenfolge des Blobs, ohne den Kopf.
     *
     * @return list<mixed>|null null, wenn eine Zeile nicht lesbar ist
     */
    public static function rows(string $blob): ?array
    {
        $values = [];
        $lines = explode("\n", $blob);
        array_shift($lines);
        foreach ($lines as $line) {
            $tab = strpos($line, "\t");
            if ($tab === false) {
                return null;
            }
            $json = substr($line, $tab + 1);
            $value = json_decode($json, true);
            if ($value === null && $json !== 'null') {
                return null;
            }
            $values[] = $value;
        }
        return $values;
    }

    /** @throws JsonException */
    private static function encodeKey(string $key): string
    {
        return json_encode($key, self::JSON_FLAGS);
    }
}
