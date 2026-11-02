<?php

declare(strict_types=1);

/**
 * Prüft die Übersetzungs-Vollständigkeit der Module dieses Repos:
 *
 *  - Jeder caption/label/suffix-Text aus form.json braucht einen de-Schlüssel in locale.json.
 *  - Jeder Translate('...')-Text (module.php und form.json-Skripte, z. B. onClick)
 *    braucht ebenfalls einen de-Schlüssel.
 *  - Verwaiste de-Schlüssel werden nur gemeldet, nicht als Fehler gewertet
 *    (dynamische Nutzung wie zusammengesetzte Captions ist möglich).
 *  - Jeder Translate('...')-Text aus einem Trait, den die Modulklasse (auch mittelbar)
 *    einbindet, braucht einen de-Schlüssel in der locale.json DIESES Moduls. Welche Traits
 *    das sind, liefert Reflection am Kernel-Stub (tests/stubs). Bis build 213 galt ein
 *    libs-Text schon als übersetzt, wenn er in irgendeiner locale.json stand; dem Entity-Modul
 *    fehlten so neun Texte, die nur das Device übersetzte (Store-Review Punkt 2, 02.11.2026).
 *  - Translate-Texte aus libs/, die kein Modul einbindet, werden nur als Hinweis gemeldet,
 *    wenn sie in KEINER locale.json vorkommen.
 *
 * Schlusszeile „N Prüfungen, M Fehler“ (eine Prüfung je Modul und Text), Exit-Code 1 bei
 * fehlenden Übersetzungen (für die CI), sonst 0.
 * Aufruf: php tests/check_locale.php
 */

require_once __DIR__ . '/stubs/autoload.php';

$moduleDirs = [
    'Home Assistant Configurator',
    'Home Assistant Device',
    'Home Assistant Discovery',
    'Home Assistant Entity',
    'Home Assistant MQTT Discovery Configurator',
    'Home Assistant MQTT Discovery Device',
    'Home Assistant MQTT Discovery Splitter',
    'Home Assistant Splitter',
];
$root = dirname(__DIR__);
$fail = false;

$allDeKeys  = [];
$pruefungen = 0;
$fehler     = 0;

foreach ($moduleDirs as $moduleDir) {
    require_once $root . '/' . $moduleDir . '/module.php';
}

foreach ($moduleDirs as $moduleDir) {
    $dir = $root . '/' . $moduleDir;
    echo "==== $moduleDir ====\n";

    $localeFile = $dir . '/locale.json';
    if (!is_file($localeFile)) {
        echo "keine locale.json vorhanden - übersprungen\n\n";
        continue;
    }
    $locale         = json_decode(file_get_contents($localeFile), true, 512, JSON_THROW_ON_ERROR);
    $deTranslations = $locale['translations']['de'] ?? [];
    $deKeys         = array_keys($deTranslations);
    $allDeKeys      = array_merge($allDeKeys, $deKeys);

    $modulePhp = file_get_contents($dir . '/module.php');

    // 1) Alle caption/label/suffix-Texte aus form.json rekursiv einsammeln
    $formTexts = [];
    $formRaw   = '';
    $formFile  = $dir . '/form.json';
    if (is_file($formFile)) {
        $formRaw = file_get_contents($formFile);
        $form    = json_decode($formRaw, true, 512, JSON_THROW_ON_ERROR);
        collectFormTexts($form, '', $formTexts);
    }

    // 2) Translate-Aufrufe aus module.php und aus den Skripten in form.json (z. B. onClick)
    $translateTexts = collectTranslateTexts($modulePhp . "\n" . $formRaw);

    // 3) Translate-Aufrufe aus allen Traits, die die Modulklasse einbindet
    $traitTexts = collectTraitTranslateTexts($dir . '/module.php', $modulePhp);
    $translateTexts += $traitTexts;
    unset($translateTexts['']);

    // Fehlend: form.json-Text ohne de-Schlüssel
    $missingForm = [];
    foreach ($formTexts as $text => $paths) {
        if (!isset($deTranslations[$text])) {
            $missingForm[$text] = $paths[0];
        }
    }

    // Fehlend: Translate-Text ohne de-Schlüssel
    $missingPhp = [];
    foreach (array_keys($translateTexts) as $text) {
        if (!isset($deTranslations[$text])) {
            $missingPhp[] = $text;
        }
    }

    // Verwaist: de-Schlüssel weder als form.json-Text noch als Translate-Text.
    // Texte aus libs/ zählen nicht als verwaist (gemeinsame Traits).
    $orphans = [];
    foreach ($deKeys as $key) {
        if (!isset($formTexts[$key]) && !isset($translateTexts[$key])) {
            $inLiteral = str_contains($modulePhp, $key) || str_contains($formRaw, $key);
            $orphans[] = [$key, $inLiteral ? 'kommt wörtlich in module.php/form.json vor' : 'nirgends im Modulordner gefunden'];
        }
    }

    echo 'form.json Texte (unique): ' . count($formTexts) . "\n";
    echo 'locale de-Schlüssel:      ' . count($deKeys) . "\n";
    echo 'Translate-Texte:          ' . count($translateTexts) . ' (davon aus Traits ' . count($traitTexts) . ")\n";
    echo 'Sprachen in locale.json:  ' . implode(', ', array_keys($locale['translations'] ?? [])) . "\n\n";

    echo 'FEHLENDE ÜBERSETZUNGEN (form.json -> kein de-Schlüssel): ' . count($missingForm) . "\n";
    foreach ($missingForm as $text => $path) {
        echo "  - \"$text\"  ($path)\n";
    }
    echo 'FEHLENDE ÜBERSETZUNGEN (Translate -> kein de-Schlüssel): ' . count($missingPhp) . "\n";
    foreach ($missingPhp as $text) {
        echo "  - \"$text\"\n";
    }
    echo 'VERWAISTE de-SCHLÜSSEL (nur Hinweis, kein Fehler): ' . count($orphans) . "\n";
    foreach ($orphans as [$key, $note]) {
        echo "  - \"$key\"  [$note]\n";
    }
    echo "\n";

    $pruefungen += count($formTexts) + count($translateTexts);
    $fehler += count($missingForm) + count($missingPhp);
    if ($missingForm !== [] || $missingPhp !== []) {
        $fail = true;
    }
}

// 3) Translate-Texte aus libs/, die in keiner einzigen locale.json vorkommen (nur Hinweis)
$libsTexts = collectTranslateTexts(collectLibsSources($root . '/libs'));
unset($libsTexts['']);
$missingLibs = array_diff(array_keys($libsTexts), $allDeKeys);
echo "==== libs/ (gemeinsame Traits) ====\n";
echo 'Translate-Texte in libs/: ' . count($libsTexts) . "\n";
echo 'IN KEINER locale.json ÜBERSETZT (nur Hinweis, kein Fehler): ' . count($missingLibs) . "\n";
foreach ($missingLibs as $text) {
    echo "  - \"$text\"\n";
}
echo "\n";

if ($fail) {
    echo "FEHLER: Es fehlen Übersetzungen (siehe oben).\n";
    echo "$pruefungen Prüfungen, $fehler Fehler\n";
    exit(1);
}

echo "OK: Alle Texte sind übersetzt.\n";
echo "$pruefungen Prüfungen, $fehler Fehler\n";

function collectFormTexts(array $node, string $path, array &$formTexts): void
{
    foreach ($node as $k => $v) {
        $p = $path . (is_int($k) ? '[' . $k . ']' : '.' . $k);
        if (in_array($k, ['caption', 'label', 'suffix'], true) && is_string($v) && $v !== '') {
            $formTexts[$v][] = $p;
        }
        if (is_array($v)) {
            collectFormTexts($v, $p, $formTexts);
        }
    }
}

/**
 * Translate-Texte aus allen Traits der Modulklasse (rekursiv, auch Traits in Traits).
 * Gelesen wird nur der Quelltext des Traits selbst (Start- bis Endzeile), nicht die ganze Datei.
 *
 * @return array<string, true>
 */
function collectTraitTranslateTexts(string $moduleFile, string $modulePhp): array
{
    if (!preg_match('/^\s*(?:final\s+)?class\s+(\w+)\s+extends/m', $modulePhp, $m)) {
        return [];
    }
    $class = new ReflectionClass($m[1]);
    if (realpath((string) $class->getFileName()) !== realpath($moduleFile)) {
        throw new RuntimeException('Klasse ' . $m[1] . ' stammt nicht aus ' . $moduleFile);
    }
    $code  = '';
    $seen  = [];
    $stack = array_values($class->getTraits());
    while ($stack !== []) {
        $trait = array_pop($stack);
        if (isset($seen[$trait->getName()])) {
            continue;
        }
        $seen[$trait->getName()] = true;
        $lines = file((string) $trait->getFileName());
        $code .= implode('', array_slice($lines, $trait->getStartLine() - 1, $trait->getEndLine() - $trait->getStartLine() + 1)) . "\n";
        foreach ($trait->getTraits() as $inner) {
            $stack[] = $inner;
        }
    }
    return collectTranslateTexts($code);
}

function collectLibsSources(string $libsDir): string
{
    $code = '';
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($libsDir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() === 'php') {
            $code .= file_get_contents($file->getPathname()) . "\n";
        }
    }
    return $code;
}

/**
 * Sammelt die Argumente aller Translate('...')-/Translate("...")-Aufrufe im übergebenen Quelltext.
 *
 * @return array<string, true> Texte als Schlüssel (dedupliziert)
 */
function collectTranslateTexts(string $code): array
{
    $texts = [];
    foreach (
        [
            '/->Translate\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'/s',
            '/->Translate\(\s*"((?:[^"\\\\]|\\\\.)*)"/s'
        ] as $pattern
    ) {
        if (preg_match_all($pattern, $code, $matches)) {
            foreach ($matches[1] as $text) {
                $texts[stripcslashes($text)] = true;
            }
        }
    }

    return $texts;
}