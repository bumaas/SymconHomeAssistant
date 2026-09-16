<?php

declare(strict_types=1);

// Prüft die Aufbereitung von REST-Antworten für Diagnose-Attribute (libs/HADiagnosticText.php).
//
// Hintergrund (Forum 144258 #390, Boui, 16.09.2026): Unter der Rust-Edition meldete der Splitter
// alle ein bis zwei Minuten „Cannot auto-convert value for parameter Value (Value is not encoded
// as valid UTF-8!)“ beim Schreiben von LastRestResponse. Die Antwort wurde nach Bytes auf 1000
// gekürzt und trennte dabei Mehrbyte-Zeichen (Umlaute, °, …) — der Kernel lehnt das halbe
// Zeichen ab. Erwartung: Jede Ausgabe ist gültiges UTF-8, auch bei kaputten Eingangsdaten.

require_once dirname(__DIR__) . '/libs/HADiagnosticText.php';

$fail = 0;
$check = static function (bool $ok, string $label) use (&$fail): void {
    echo ($ok ? 'OK   ' : 'FAIL ') . $label . "\n";
    $fail += $ok ? 0 : 1;
};
$valid = static fn(string $s): bool => preg_match('//u', $s) === 1;

// 1. Kurze Antwort bleibt unverändert (Whitespace wird zusammengefasst).
$check(HADiagnosticText::truncate("  {\"a\":  \"Küche\"}\n") === '{"a": "Küche"}', 'kurz: unverändert bis auf Whitespace');

// 2. Umlaut genau an der Schnittgrenze: 999 ASCII-Bytes + „ä“ (2 Bytes) + Rest.
$text = str_repeat('x', 999) . 'ä' . str_repeat('y', 50);
$cut = HADiagnosticText::truncate($text);
$check($valid($cut), 'Umlaut an Byte 1000: Ergebnis ist gültiges UTF-8');
$check(str_ends_with($cut, '...'), 'Umlaut an Byte 1000: Kürzung wird mit ... markiert');
$check(strlen($cut) <= 1003, 'Umlaut an Byte 1000: höchstens 1000 Bytes + ...');

// 3. Drei-Byte-Zeichen (…, €) und Vier-Byte-Zeichen (Emoji) an der Grenze.
foreach (['…' => 998, '€' => 999, '🔋' => 997, '°' => 999] as $char => $prefix) {
    $cut = HADiagnosticText::truncate(str_repeat('x', $prefix) . $char . str_repeat($char, 20));
    $check($valid($cut), sprintf('%s an der Grenze (Präfix %d): gültiges UTF-8', $char, $prefix));
}

// 4. Realistische HA-Antwort (/api/states) mit deutschen Namen, Kürzung mitten im Text.
$states = [];
for ($i = 0; $i < 40; $i++) {
    $states[] = ['entity_id' => "sensor.temp_$i", 'state' => '21.5', 'attributes' => ['friendly_name' => "Wärmepumpe Außen $i", 'unit_of_measurement' => '°C']];
}
$json = json_encode($states, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$broken = 0;
for ($max = 900; $max <= 1100; $max++) {
    if (!$valid(HADiagnosticText::truncate($json, $max))) {
        $broken++;
    }
}
$check($broken === 0, "HA-Antwort mit Umlauten, Schnitt bei 900…1100 Bytes: $broken ungültige Ergebnisse");

// 5. Ungültige Bytes von außen (z. B. Latin-1 aus einem Proxy) werden bereinigt.
$latin1 = "Fehler: K\xFCche nicht erreichbar";
$check($valid(HADiagnosticText::sanitize($latin1)), 'sanitize: Latin-1-Byte wird zu gültigem UTF-8');
$check($valid(HADiagnosticText::truncate($latin1)), 'truncate: Latin-1-Byte wird zu gültigem UTF-8');
$check(HADiagnosticText::sanitize('Küche °C …') === 'Küche °C …', 'sanitize: gültiges UTF-8 bleibt unverändert');

// 6. Whitespace-Zusammenfassung zerstört keine Mehrbyte-Zeichen, deren Folgebyte 0x85/0xA0 ist.
$check(HADiagnosticText::truncate("à  Å\t\tö") === 'à Å ö', 'Whitespace: à (C3 A0) und Å (C3 85) bleiben intakt');

echo "\n" . ($fail === 0 ? 'ALL OK' : "FAILURES: $fail") . "\n";
exit($fail === 0 ? 0 : 1);
