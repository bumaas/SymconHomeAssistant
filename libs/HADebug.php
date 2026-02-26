<?php /** @noinspection AutoloadingIssuesInspection */

declare(strict_types=1);

trait HADebugTrait
{
    private const array BASIC_DEBUG_CATEGORIES = [
        'ApplyChanges',
        'Config',
        'Discovery',
        'MessageSink',
        'RequestAction',
        'REST',
        'UpdateCache',
        'UpdateCacheFromHA'
    ];

    private function debugExpert(string $category, string $message, array $context = [], bool $always = false): void
    {
        if (!$always && !$this->ReadPropertyBoolean('EnableExpertDebug')) {
            if (!in_array($category, self::BASIC_DEBUG_CATEGORIES, true)) {
                return;
            }
        }

        $suffix = '';
        if (!empty($context)) {
            $suffix = ' | ' . json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $this->SendDebug($category, $message . $suffix, 0);
    }
}
