<?php

/** @noinspection AutoloadingIssuesInspection */

declare(strict_types=1);

trait ModuleDebugTrait
{
    private const array BASIC_DEBUG_CATEGORIES = [
        'ApplyChanges',
        'Config',
        'Discovery',
        'MessageSink',
        'MQTT',
        'RequestAction',
        'REST',
        'UpdateCache',
        'UpdateCacheFromHA'
    ];

    // P5: Property-Read pro Ausführung memoisieren — debugExpert läuft im
    // Message-Hotpath vielfach; Property-Änderungen greifen ohnehin erst mit
    // der nächsten Ausführung (ApplyChanges).
    private ?bool $moduleDebugExpertEnabled = null;

    private function isExpertDebugEnabled(): bool
    {
        return $this->moduleDebugExpertEnabled ??= (bool)@$this->ReadPropertyBoolean('EnableExpertDebug');
    }

    private function debugExpert(string $category, string $message, array $context = [], bool $always = false): void
    {
        if (!$always && !$this->isExpertDebugEnabled() && !in_array($category, self::BASIC_DEBUG_CATEGORIES, true)) {
            return;
        }

        // Der Kontext trägt fremden Text (Payloads). Ungültiges UTF-8 darin wird ersetzt, eine Debug-Zeile darf die
        // Verarbeitung der Meldung nicht abbrechen.
        $suffix = '';
        if (!empty($context)) {
            $suffix = ' | ' . json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        $this->SendDebug($category, $message . $suffix, 0);
    }

    private function debugRuntimeIssue(string $category, string $message, array $context = []): void
    {
        $this->debugExpert($category, $message, $context, true);
    }

    // Eine eingehende MQTT-Meldung im erweiterten Debug, für Device, Entity und MQTT Discovery Device gleich.
    private function debugIncomingMessage(string $topic, string $payload): void
    {
        if ($this->isExpertDebugEnabled()) {
            $this->debugExpert('ReceiveData', 'Eingangsdaten', ['Topic' => $topic, 'Payload' => $this->debugPrintable($payload)]);
        }
    }

    // Fremder Text für den Debug-Kontext: gültiges UTF-8 unverändert, alles andere (Bilddaten) nur mit seiner Länge.
    // debugExpert ersetzte die Bytes sonst durch Ersatzzeichen, die im Debug niemandem helfen.
    private function debugPrintable(string $text): string
    {
        return preg_match('//u', $text) === 1 ? $text : sprintf('(binär, %d Bytes)', strlen($text));
    }
}
