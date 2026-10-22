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

    bedingungenWerdenAusgewertet($templates);
}

/**
 * Code-Review build 211: Die Bedingung wurde verworfen, jedes `{{ value_json.X if … }}` galt als
 * unterstützt. Ein aus gemeldetes Licht zeigte so die Helligkeit 120, ein ausgeschaltetes Klimagerät
 * den letzten Modus. Jetzt wertet das Modul einfache Bedingungen aus (is defined, |length, Vergleich
 * mit einer Zahl oder einem Text, verknüpft mit and); alles andere bleibt nicht unterstützt.
 */
function bedingungenWerdenAusgewertet(array $templates): void
{
    $nichtUnterstuetzt = [
        '{{ value_json.a if value_json.b is defined else value_json.c }}' => 'else mit Ausdruck',
        "{{ value_json.a if value_json.b == 'x' or value_json.c == 'y' else 0 }}" => 'or',
        "{{ value_json.a if is_state('light.x', 'on') else 0 }}" => 'Funktionsaufruf',
        '{{ value_json.a if not value_json.b else 0 }}' => 'not',
    ];
    foreach ($nichtUnterstuetzt as $template => $art) {
        $parsed = HAMqttDiscoveryTemplate::parseValueTemplate($template);
        pruefe(($parsed['supported'] ?? true) === false, "Bedingung mit $art: nicht unterstützt", json_encode($parsed));
    }

    if (!pruefe(method_exists(HAMqttDiscoveryTemplate::class, 'resolveJsonPathValue'), 'HAMqttDiscoveryTemplate::resolveJsonPathValue vorhanden')) {
        return;
    }

    $wert = static fn(string $template, array $json): mixed
        => HAMqttDiscoveryTemplate::resolveJsonPathValue(HAMqttDiscoveryTemplate::parseValueTemplate($template), $json);

    $licht = "{{ value_json.brightness if value_json.state == 'ON' else 0 }}";
    pruefe($wert($licht, ['state' => 'ON', 'brightness' => 120]) === 120, 'Licht an: Helligkeit 120');
    pruefe($wert($licht, ['state' => 'OFF', 'brightness' => 120]) === '0', 'Licht aus: Rückfallwert 0', var_export($wert($licht, ['state' => 'OFF', 'brightness' => 120]), true));

    $modus = $templates['klimaanlage-buero/mode_stat_tpl'];
    pruefe($wert($modus, ['mode' => 'cool']) === 'cool', 'mitsubishi2mqtt-Modus: Feld gesetzt');
    pruefe($wert($modus, ['mode' => '']) === 'off', 'mitsubishi2mqtt-Modus: leer → off');
    pruefe($wert($modus, ['action' => 'idle']) === 'off', 'mitsubishi2mqtt-Modus: fehlt → off');

    // Ohne else gibt HA einen leeren Text aus, den das Klimagerät verwirft: nichts schreiben.
    $raum = $templates['klimaanlage-buero/curr_temp_tpl'];
    pruefe($wert($raum, ['room_temperature' => 21.5]) === 21.5, 'Raumtemperatur 21,5 (|int > 1.00)');
    pruefe($wert($raum, ['room_temperature' => 0.5]) === null, 'Raumtemperatur 0,5 verworfen (|int > 1.00)', var_export($wert($raum, ['room_temperature' => 0.5]), true));

    $anderesFeld = "{{ value_json.a if value_json.b is defined else 'x' }}";
    pruefe($wert($anderesFeld, ['a' => 'y']) === 'x', 'Bedingung auf fehlendes anderes Feld: Rückfallwert', var_export($wert($anderesFeld, ['a' => 'y']), true));
    pruefe($wert($anderesFeld, ['a' => 'y', 'b' => 1]) === 'y', 'Bedingung auf vorhandenes anderes Feld: Wert');
}
