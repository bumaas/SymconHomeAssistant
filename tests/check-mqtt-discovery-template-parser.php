<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/libs/HACommonIncludes.php';

exit(main());

function main(): int
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
        if (!is_array($parsed)) {
            fwrite(STDERR, "Template wurde nicht geparst: {$case['template']}\n");
            return 1;
        }

        if (($parsed['kind'] ?? null) !== 'json_path') {
            fwrite(STDERR, "Unerwarteter Template-Typ für {$case['template']}\n");
            return 1;
        }

        if (($parsed['path'] ?? null) !== $case['expected']) {
            fwrite(STDERR, "Unerwarteter Pfad für {$case['template']}: " . json_encode($parsed['path']) . "\n");
            return 1;
        }
    }

    fwrite(STDOUT, "OK\n");
    return 0;
}
