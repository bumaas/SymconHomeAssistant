<?php

declare(strict_types=1);

require_once __DIR__ . '/harness.php';
require_once dirname(__DIR__) . '/libs/HACommonIncludes.php';

main();
ergebnis();

function main(): void
{
    $cases = [
        [
            'template' => '{{ value_json["state"] }}',
            'expected' => ['state']
        ],
        [
            'template' => "{{ value_json['motor_reversal'] }}",
            'expected' => ['motor_reversal']
        ],
        [
            'template' => '{{ value_json["update"]["latest_version"] }}',
            'expected' => ['update', 'latest_version']
        ],
        [
            'template' => '{{ value_json[3] }}',
            'expected' => ['3']
        ]
    ];

    foreach ($cases as $case) {
        $parsed = HAMqttDiscoveryTemplate::parseValueTemplate($case['template']);
        if (!pruefe(is_array($parsed), "Template wird geparst: {$case['template']}")) {
            continue;
        }

        pruefe(
            ($parsed['kind'] ?? null) === 'json_path',
            "Template-Typ json_path für {$case['template']}",
            'erhalten: ' . json_encode($parsed['kind'] ?? null)
        );

        pruefe(
            ($parsed['path'] ?? null) === $case['expected'],
            'Pfad ' . json_encode($case['expected']) . " für {$case['template']}",
            'erhalten: ' . json_encode($parsed['path'] ?? null)
        );
    }
}
