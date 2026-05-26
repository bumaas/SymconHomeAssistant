<?php

declare(strict_types=1);

trait HADiagnosticAggregationTrait
{
    private function recordUnsupportedDiagnostic(array &$diagnostics, string $component, string $example): void
    {
        $component = $component !== '' ? $component : 'unknown';
        if (!isset($diagnostics['unsupported'][$component])) {
            $diagnostics['unsupported'][$component] = [
                'component' => $component,
                'count' => 0,
                'examples' => []
            ];
        }

        $diagnostics['unsupported'][$component]['count']++;
        $this->appendDiagnosticExample($diagnostics['unsupported'][$component]['examples'], $example);
    }

    private function recordSkippedDiagnostic(array &$diagnostics, string $reason, string $component, string $example): void
    {
        $component = $component !== '' ? $component : 'unknown';
        $key = $reason . '|' . $component;
        if (!isset($diagnostics['skipped'][$key])) {
            $diagnostics['skipped'][$key] = [
                'reason' => $reason,
                'component' => $component,
                'count' => 0,
                'examples' => []
            ];
        }

        $diagnostics['skipped'][$key]['count']++;
        $this->appendDiagnosticExample($diagnostics['skipped'][$key]['examples'], $example);
    }

    private function appendDiagnosticExample(array &$examples, string $example): void
    {
        if ($example === '' || in_array($example, $examples, true)) {
            return;
        }

        $examples[] = $example;
        if (count($examples) > 3) {
            $examples = array_slice($examples, 0, 3);
        }
    }

    private function finalizeUnsupportedDiagnostics(array $unsupported): array
    {
        $unsupported = array_values($unsupported);
        usort($unsupported, static function (array $left, array $right): int {
            $countCompare = (int)($right['count'] ?? 0) <=> (int)($left['count'] ?? 0);
            if ($countCompare !== 0) {
                return $countCompare;
            }

            return strcmp((string)($left['component'] ?? ''), (string)($right['component'] ?? ''));
        });

        return $unsupported;
    }

    private function finalizeSkippedDiagnostics(array $skipped): array
    {
        $skipped = array_values($skipped);
        usort($skipped, static function (array $left, array $right): int {
            $countCompare = (int)($right['count'] ?? 0) <=> (int)($left['count'] ?? 0);
            if ($countCompare !== 0) {
                return $countCompare;
            }

            $reasonCompare = strcmp((string)($left['reason'] ?? ''), (string)($right['reason'] ?? ''));
            if ($reasonCompare !== 0) {
                return $reasonCompare;
            }

            return strcmp((string)($left['component'] ?? ''), (string)($right['component'] ?? ''));
        });

        return $skipped;
    }
}
