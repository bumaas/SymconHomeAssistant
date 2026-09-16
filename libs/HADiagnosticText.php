<?php

declare(strict_types=1);

/**
 * Aufbereitung von Fremdtexten (REST-Antworten von HA) für Diagnose-Attribute und Debug-Ausgaben.
 *
 * Der Kernel nimmt Strings nur als gültiges UTF-8 an (Rust-Edition: Warnung „Value is not encoded
 * as valid UTF-8!“). Deshalb werden ungültige Bytes ersetzt und Kürzungen nie mitten in einem
 * Mehrbyte-Zeichen vorgenommen.
 */
final class HADiagnosticText
{
    public static function sanitize(string $text): string
    {
        if (preg_match('//u', $text) === 1) {
            return $text;
        }
        return mb_scrub($text, 'UTF-8');
    }

    public static function truncate(string $text, int $maxLength = 1000, bool $compactWhitespace = true): string
    {
        $text = self::sanitize($text);
        if ($compactWhitespace) {
            $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        }
        $text = trim($text);

        if (strlen($text) <= $maxLength) {
            return $text;
        }

        // mb_strcut kürzt auf höchstens $maxLength Bytes, aber nur an Zeichengrenzen.
        return mb_strcut($text, 0, $maxLength, 'UTF-8') . '...';
    }
}
