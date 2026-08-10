<?php /** @noinspection AutoloadingIssuesInspection */

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

        $suffix = '';
        if (!empty($context)) {
            $suffix = ' | ' . json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $this->SendDebug($category, $message . $suffix, 0);
    }

    private function debugRuntimeIssue(string $category, string $message, array $context = []): void
    {
        $this->debugExpert($category, $message, $context, true);
    }
}
