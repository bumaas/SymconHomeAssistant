<?php

declare(strict_types=1);

/**
 * Gemeinsame Presentation-Definitionen für Entity-/Attribut-Variablen, die früher im REST-Device
 * und im MQTT-Discovery-Device getrennt (und mit der Zeit divergierend) gepflegt wurden.
 *
 * Single Source of Truth für die driftgefährdeten Presentation-Schemas (Slider mit USAGE_TYPE /
 * GRADIENT_TYPE, Farb-Darstellung, binary_sensor-Optionen). Beide Pfade delegieren hierher; die
 * Werte stammen 1:1 aus dem REST-Pfad, damit dessen Ausgabe unverändert bleibt.
 *
 * Reine Array-Bauer ohne IPS-/Translate-Abhängigkeit. Captions (binary_sensor) werden bereits
 * übersetzt übergeben. Die verwendeten VARIABLE_PRESENTATION_*-Konstanten stellt IP-Symcon zur
 * Laufzeit bereit.
 */
trait HASharedPresentationTrait
{
    private function sharedFilterPresentation(array $presentation): array
    {
        return array_filter($presentation, static fn($value): bool => $value !== null);
    }

    private function sharedFormatPresentationSuffix(string $suffix): ?string
    {
        $suffix = trim($suffix);
        if ($suffix === '') {
            return null;
        }
        return ' ' . $suffix;
    }

    private function sharedMetaDigitsOverride(array $meta): ?int
    {
        if (!array_key_exists('digits', $meta) || !is_numeric($meta['digits'])) {
            return null;
        }
        return min(3, max(0, (int) $meta['digits']));
    }

    // Helligkeit: Slider 0..255, USAGE_TYPE 2 (Intensität) fürs Composite "Licht".
    private function buildSharedLightBrightnessPresentation(bool $isPercent, ?int $digits, ?string $suffix): array
    {
        return $this->sharedFilterPresentation([
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'MIN'          => 0,
            'MAX'          => 255,
            'STEP_SIZE'    => 1,
            'PERCENTAGE'   => $isPercent,
            'DIGITS'       => $digits ?? 0,
            'USAGE_TYPE'   => 2,
            'SUFFIX'       => $suffix
        ]);
    }

    // Farbtemperatur (mired ODER kelvin): begrenzter Slider mit USAGE_TYPE 1 + Farbtemperatur-
    // Gradient. Liefert null, wenn keine gültigen Grenzen vorliegen (Caller fällt dann zurück).
    private function buildSharedLightColorTempSliderPresentation(
        mixed $min,
        mixed $max,
        bool $isPercent,
        ?int $digits,
        ?string $suffix
    ): ?array {
        if (!is_numeric($min) || !is_numeric($max)) {
            return null;
        }

        return $this->sharedFilterPresentation([
            'PRESENTATION'  => VARIABLE_PRESENTATION_SLIDER,
            'MIN'           => (float) $min,
            'MAX'           => (float) $max,
            'STEP_SIZE'     => 1,
            'PERCENTAGE'    => $isPercent,
            'DIGITS'        => $digits ?? 0,
            'USAGE_TYPE'    => 1,
            'GRADIENT_TYPE' => 2,
            'SUFFIX'        => $suffix
        ]);
    }

    // RGB-Farbe: Farb-Darstellung mit RGB-Encoding.
    private function buildSharedLightRgbColorPresentation(): array
    {
        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_COLOR,
            'ENCODING'     => 0 // RGB
        ];
    }

    // xy-Farbe (CIE): Farb-Darstellung mit xy-Encoding (String-Variable, nativer HA-xy-Wert).
    private function buildSharedLightXyColorPresentation(): array
    {
        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_COLOR,
            'ENCODING'     => 4 // xy
        ];
    }

    // HS-Farbe: Farb-Darstellung mit HSV-Encoding (String-Variable).
    private function buildSharedLightHsColorPresentation(): array
    {
        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_COLOR,
            'ENCODING'     => 2 // HSV
        ];
    }

    // Ziel-/Ist-Temperatur (climate): begrenzter Slider mit USAGE_TYPE 0 (Temperatur) +
    // Temperatur-Gradient. Liefert null, wenn keine gültigen Grenzen vorliegen.
    private function buildSharedTemperatureSliderPresentation(
        mixed $min,
        mixed $max,
        mixed $step,
        ?int $digits,
        ?string $suffix
    ): ?array {
        if (!is_numeric($min) || !is_numeric($max)) {
            return null;
        }

        return $this->sharedFilterPresentation([
            'PRESENTATION'  => VARIABLE_PRESENTATION_SLIDER,
            'MIN'           => (float) $min,
            'MAX'           => (float) $max,
            'STEP_SIZE'     => is_numeric($step) ? (float) $step : 1.0,
            'DIGITS'        => $digits ?? 0,
            'USAGE_TYPE'    => 0, // Temperatur (1 wäre Farbtemperatur)
            'GRADIENT_TYPE' => 1, // Temperatur-Gradient
            'SUFFIX'        => $suffix
        ]);
    }

    // binary_sensor: Wertanzeige mit zwei (bereits übersetzten) Zustands-Captions + optionalem Icon.
    private function buildSharedBinarySensorPresentation(string $trueCaption, string $falseCaption, string $icon): array
    {
        $options = [
            [
                'Value'       => false,
                'Caption'     => $falseCaption,
                'IconActive'  => false,
                'IconValue'   => '',
                'ColorActive' => false,
                'ColorValue'  => -1
            ],
            [
                'Value'       => true,
                'Caption'     => $trueCaption,
                'IconActive'  => false,
                'IconValue'   => '',
                'ColorActive' => false,
                'ColorValue'  => -1
            ]
        ];

        return $this->sharedFilterPresentation([
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'OPTIONS'      => json_encode($options, JSON_THROW_ON_ERROR),
            'ICON'         => $icon !== '' ? $icon : null
        ]);
    }
}
