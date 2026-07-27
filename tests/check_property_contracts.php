<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$corePath = $root . '/libs/Device/HADeviceCore.php';
$modulePaths = [
    $root . '/Home Assistant Device/module.php',
    $root . '/Home Assistant Entity/module.php',
];

$coreSource = file_get_contents($corePath);
if ($coreSource === false) {
    throw new RuntimeException('Cannot read ' . $corePath);
}

preg_match_all(
    '/ReadProperty(Boolean|Float|Integer|String)\(\s*HADeviceConstants::(PROP_[A-Z0-9_]+)\s*\)/',
    $coreSource,
    $matches,
    PREG_SET_ORDER
);

if ($matches === []) {
    throw new RuntimeException('No shared HADeviceCore property reads found.');
}

foreach ($modulePaths as $modulePath) {
    $moduleSource = file_get_contents($modulePath);
    if ($moduleSource === false) {
        throw new RuntimeException('Cannot read ' . $modulePath);
    }

    foreach ($matches as $match) {
        $type = $match[1];
        $constant = $match[2];
        $registrationPattern = sprintf(
            '/RegisterProperty%s\(\s*self::%s\s*,/',
            preg_quote($type, '/'),
            preg_quote($constant, '/')
        );
        if (preg_match($registrationPattern, $moduleSource) !== 1) {
            throw new RuntimeException(sprintf(
                '%s reads %s in HADeviceCore but does not register it as %s.',
                basename(dirname($modulePath)),
                $constant,
                $type
            ));
        }
    }
}

echo "Shared HADeviceCore property contracts are registered by all consumers.\n";
