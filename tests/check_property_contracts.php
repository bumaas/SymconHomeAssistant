<?php

declare(strict_types=1);

require_once __DIR__ . '/harness.php';

$root = dirname(__DIR__);
$corePath = $root . '/libs/Device/HADeviceCore.php';
$modulePaths = [
    $root . '/Home Assistant Device/module.php',
    $root . '/Home Assistant Entity/module.php',
];

$coreSource = file_get_contents($corePath);
if (!pruefe($coreSource !== false, 'HADeviceCore.php ist lesbar', $corePath)) {
    ergebnis();
}

preg_match_all(
    '/ReadProperty(Boolean|Float|Integer|String)\(\s*HADeviceConstants::(PROP_[A-Z0-9_]+)\s*\)/',
    $coreSource,
    $matches,
    PREG_SET_ORDER
);

if (!pruefe($matches !== [], 'HADeviceCore liest gemeinsame Properties (' . count($matches) . ' Lesestellen)')) {
    ergebnis();
}

foreach ($modulePaths as $modulePath) {
    $moduleSource = file_get_contents($modulePath);
    if (!pruefe($moduleSource !== false, basename(dirname($modulePath)) . '/module.php ist lesbar', $modulePath)) {
        continue;
    }

    foreach ($matches as $match) {
        $type = $match[1];
        $constant = $match[2];
        $registrationPattern = sprintf(
            '/RegisterProperty%s\(\s*self::%s\s*,/',
            preg_quote($type, '/'),
            preg_quote($constant, '/')
        );
        pruefe(
            preg_match($registrationPattern, $moduleSource) === 1,
            sprintf('%s registriert %s als %s', basename(dirname($modulePath)), $constant, $type),
            'wird in HADeviceCore gelesen, aber nicht registriert'
        );
    }
}

ergebnis();
