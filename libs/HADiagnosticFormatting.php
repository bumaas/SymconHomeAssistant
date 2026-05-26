<?php

declare(strict_types=1);

trait HADiagnosticFormattingTrait
{
    private function buildUnsupportedDiagnosticsPanel(
        string $panelCaption,
        string $summaryCaption,
        string $unsupportedNoneCaption,
        string $unsupportedCaption,
        array $unsupported,
        bool $visible,
        ?string $name = null
    ): array
    {
        $panel = [
            'type' => 'ExpansionPanel',
            'caption' => $panelCaption,
            'expanded' => false,
            'visible' => $visible,
            'items' => [[
                'type' => 'Label',
                'caption' => $summaryCaption
            ], [
                'type' => 'Label',
                'caption' => $unsupported === []
                    ? $unsupportedNoneCaption
                    : sprintf($unsupportedCaption, $this->formatUnsupportedDiagnosticSummary($unsupported))
            ]]
        ];

        if ($name !== null) {
            $panel['name'] = $name;
        }

        return $panel;
    }

    private function formatUnsupportedDiagnosticSummary(array $unsupported): string
    {
        return $this->formatCountedDiagnosticSummary(
            $unsupported,
            static fn(array $entry): string => (string)($entry['component'] ?? 'unknown')
        );
    }

    private function formatCountedDiagnosticSummary(array $entries, callable $labelBuilder, int $maxEntries = 8): string
    {
        $parts = [];
        foreach (array_slice($entries, 0, $maxEntries) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $parts[] = sprintf(
                '%s (%d)',
                $labelBuilder($entry),
                (int)($entry['count'] ?? 0)
            );
        }

        return $parts === [] ? $this->Translate('none') : implode(', ', $parts);
    }
}
