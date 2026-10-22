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

    bedingteTemplates();
}

/**
 * Bedingte Templates, wie mitsubishi2mqtt sie ankündigt (Fixture discovery_mitsubishi2mqtt_20261022.json):
 * Feld mit Rückfallwert, Feld ohne else, {% if %}-Block mit Begrenzung. Funktionsaufrufe um das Feld
 * (as_datetime) versteht der Parser nicht, sie bleiben „nicht unterstützt“.
 */
function bedingteTemplates(): void
{
    $bundle = json_decode((string)file_get_contents(__DIR__ . '/fixtures/discovery_mitsubishi2mqtt_20261022.json'), true, 512, JSON_THROW_ON_ERROR);
    $templates = [];
    foreach ($bundle['discovery_configs'] as $record) {
        foreach (json_decode($record['payload'], true, 512, JSON_THROW_ON_ERROR) as $schluessel => $wert) {
            if (is_string($wert) && str_contains($wert, '{')) {
                $templates[basename(dirname($record['topic'])) . '/' . $schluessel] = $wert;
            }
        }
    }

    $faelle = [
        'klimaanlage-buero/mode_stat_tpl'       => [['mode'], 'off'],
        'klimaanlage-buero/fan_mode_stat_tpl'   => [['fan'], 'auto'],
        'klimaanlage-buero/swing_mode_stat_tpl' => [['vane'], 'AUTO'],
        'klimaanlage-buero/swing_h_mode_stat_tpl' => [['wideVane'], 'SWING'],
        'klimaanlage-buero/action_template'     => [['action'], 'idle'],
        'klimaanlage-buero/curr_temp_tpl'       => [['room_temperature'], null],
        'klimaanlage-buero/temp_stat_tpl'       => [['temperature'], null],
    ];
    foreach ($faelle as $schluessel => $soll) {
        $template = $templates[$schluessel] ?? null;
        if (!pruefe($template !== null, "Fixture enthält $schluessel")) {
            continue;
        }
        $parsed = HAMqttDiscoveryTemplate::parseValueTemplate($template);
        pruefe(($parsed['kind'] ?? null) === 'json_path' && ($parsed['supported'] ?? false) === true,
            "$schluessel: unterstützter json_path", json_encode($parsed));
        pruefe(($parsed['path'] ?? null) === $soll[0], "$schluessel: Pfad " . json_encode($soll[0]), json_encode($parsed['path'] ?? null));
        pruefe(($parsed['default'] ?? null) === $soll[1], "$schluessel: Rückfallwert " . json_encode($soll[1]), json_encode($parsed['default'] ?? null));
    }

    $upTime = $templates['up_time/val_tpl'] ?? null;
    pruefe($upTime === '{{ as_datetime(value_json.up_time) }}', 'Fixture enthält Up-Time-Template', (string)$upTime);
    $parsed = HAMqttDiscoveryTemplate::parseValueTemplate((string)$upTime);
    pruefe(($parsed['supported'] ?? true) === false, 'as_datetime(…) bleibt nicht unterstützt', json_encode($parsed));

    // Feld außerhalb der Ausgabe darf nicht als Pfad gelten: zwei verschiedene Felder → nicht unterstützt.
    $parsed = HAMqttDiscoveryTemplate::parseValueTemplate("{{ value_json.a if value_json.b is defined else 'x' }}");
    pruefe(($parsed['path'] ?? null) === ['a'] && ($parsed['default'] ?? null) === 'x', 'Bedingung auf anderes Feld: Pfad der Ausgabe', json_encode($parsed));
    $parsed = HAMqttDiscoveryTemplate::parseValueTemplate('{% if value_json.a > 1 %}{{ value_json.a }}{% else %}{{ value_json.b }}{% endif %}');
    pruefe(($parsed['supported'] ?? true) === false, 'Block mit zwei Ausgabefeldern: nicht unterstützt', json_encode($parsed));
}
