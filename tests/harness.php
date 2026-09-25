<?php

declare(strict_types=1);

/**
 * Gemeinsamer Unterbau aller Laufzeit-Checks (tests/check-*.php).
 *
 * - Jede Warning/Notice wird zur Ausnahme; eine Warnung läuft also nicht unbemerkt durch.
 * - pruefe() zählt jede Prüfung, ergebnis() schreibt die Schlusszeile
 *   "N Prüfungen, M Fehler" und beendet mit Exit 0 (grün) bzw. 1 (rot) - dieses Format
 *   liest tools/rotgruen.php.
 * - Eine nicht gefangene Ausnahme zählt als Fehler und endet ebenfalls mit der Schlusszeile.
 *
 * Die Tests verwenden noch eigene Attrappen statt des offiziellen Kernel-Stubs; die Umstellung
 * erfolgt beim nächsten Anfassen des jeweiligen Tests.
 */

set_error_handler(static function (int $nr, string $text, string $datei, int $zeile): bool {
    if (!(error_reporting() & $nr)) {
        return false; // mit @ unterdrückt - kein Testfehler
    }
    if ($nr & (E_USER_ERROR | E_USER_WARNING | E_WARNING | E_NOTICE | E_USER_NOTICE | E_DEPRECATED | E_USER_DEPRECATED)) {
        throw new ErrorException($text, 0, $nr, $datei, $zeile);
    }
    return false;
});

set_exception_handler(static function (Throwable $e): void {
    pruefe(false, sprintf('unerwartete %s: %s (%s:%d)', $e::class, $e->getMessage(), basename($e->getFile()), $e->getLine()));
    ergebnis();
});

$pruefungen = 0;
$fehler = [];

/**
 * Zählt eine Prüfung, gibt sie aus und liefert das Ergebnis zurück (für Abbruchzweige).
 * $still = true für Massenprüfungen (je Fixture-Eintrag): gezählt wird immer, ausgegeben nur ein Fehler.
 */
function pruefe(bool $ok, string $text, string $detail = '', bool $still = false): bool
{
    global $pruefungen, $fehler;
    $pruefungen++;
    if (!$ok) {
        $fehler[] = $text;
    }
    if (!$ok || !$still) {
        echo ($ok ? '  ok   ' : '  FEHL ') . $text . ($ok || $detail === '' ? '' : ' - ' . $detail) . "\n";
    }
    return $ok;
}

/** Schlusszeile und Exit-Code - Format ist Pflicht, rotgruen.php parst genau das. */
function ergebnis(): never
{
    global $pruefungen, $fehler;
    echo "\n$pruefungen Prüfungen, " . count($fehler) . " Fehler\n";
    exit($fehler === [] ? 0 : 1);
}
