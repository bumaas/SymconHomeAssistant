<?php

declare(strict_types=1);

/**
 * Prüft die entkoppelte Bild-Aktualisierung (HAMediaObjectsTrait) und die
 * Kaskaden-Eindämmung im Unknown-Attribut-Zweig (HAAttributeHandlersTrait):
 * - Aufträge mit gleichem Ident werden gebündelt (ein Download pro Verarbeitung)
 * - Mindestabstand verhindert erneute Downloads (auch nach Fehlversuchen)
 * - identische Attribut-Wiederholungen (z. B. source_list) lösen keinen Refresh aus
 * - Wertänderung eines bekannten Attributs nimmt den gezielten Pfad (ohne Extra-Maintenance)
 * - Kamera ignoriert entity_picture/access_token; access_token ist Bookkeeping (Splitter-Drop)
 */

foreach ([
    'VARIABLETYPE_BOOLEAN' => 0,
    'VARIABLETYPE_INTEGER' => 1,
    'VARIABLETYPE_FLOAT' => 2,
    'VARIABLETYPE_STRING' => 3,
    'VARIABLE_PRESENTATION_SWITCH' => 'Switch',
    'VARIABLE_PRESENTATION_VALUE_PRESENTATION' => 'ValuePresentation',
    'VARIABLE_PRESENTATION_DATE_TIME' => 'DateTime',
    'VARIABLE_PRESENTATION_DURATION' => 'Duration',
    'VARIABLE_PRESENTATION_ENUMERATION' => 'Enumeration',
    'VARIABLE_PRESENTATION_LEGACY' => 'Legacy',
    'VARIABLE_PRESENTATION_SLIDER' => 'Slider',
    'VARIABLE_PRESENTATION_COLOR' => 'Color',
    'VARIABLE_PRESENTATION_SHUTTER' => 'Shutter'
] as $name => $value) {
    if (!defined($name)) {
        define($name, $value);
    }
}

if (!function_exists('IPS_SetMediaContent')) {
    function IPS_SetMediaContent(int $mediaId, string $content): void
    {
        MediaRefreshHarness::$mediaContentWrites[] = $mediaId;
    }
}

require_once __DIR__ . '/harness.php';
require_once dirname(__DIR__) . '/libs/HACommonIncludes.php';
require_once dirname(__DIR__) . '/libs/Device/HAAttributeHandlers.php';
require_once dirname(__DIR__) . '/libs/Device/HAMediaObjects.php';

function check(bool $condition, string $label): void
{
    pruefe($condition, $label);
}

final class MediaRefreshHarness implements HADeviceConstants
{
    use HAMediaObjectsTrait;

    /** @var int[] */
    public static array $mediaContentWrites = [];

    /** @var array<string, string> */
    private array $buffers = [];
    /** @var array<string, int> */
    public array $timerIntervals = [];
    public int $fetchCalls = 0;
    public bool $fetchSucceeds = true;

    public function schedule(string $ident, string $url): void
    {
        $this->scheduleMediaRefresh($ident, $url, 'ha_camera_preview', 'CameraPreview');
    }

    public function process(): void
    {
        $this->processPendingMediaRefreshJobs();
    }

    public function pendingJobCount(): int
    {
        return count($this->loadMediaRefreshBuffer(self::BUFFER_PENDING_MEDIA_JOBS));
    }

    protected function GetBuffer(string $name): string
    {
        return $this->buffers[$name] ?? '';
    }

    protected function SetBuffer(string $name, string $value): void
    {
        $this->buffers[$name] = $value;
    }

    protected function SetTimerInterval(string $name, int $interval): void
    {
        $this->timerIntervals[$name] = $interval;
    }

    protected function GetTimerInterval(string $name): int
    {
        return $this->timerIntervals[$name] ?? 0;
    }

    protected function GetIDForIdent(string $ident): int
    {
        return 4711;
    }

    private function fetchMediaImageContent(string $url): ?string
    {
        $this->fetchCalls++;
        return $this->fetchSucceeds ? 'BILDDATEN' : null;
    }

    private function ensureEntityPreviewMediaFile(int $mediaId, string $ident, string $url, string $filePrefix): void
    {
    }

    protected function debugExpert(string $context, string $message, array $data = [], bool $log = false): void
    {
    }
}

final class UnknownAttributeHarness implements HADeviceConstants
{
    use HAAttributeHandlersTrait;

    /** @var array<string, array<string, mixed>> */
    public array $entities = [];

    public int $fullRefreshes = 0;
    public int $targetedRefreshes = 0;
    /** @var array{0:string,1:string,2:string}|null */
    public ?array $lastTrigger = null;

    public function handleMediaPlayer(string $entityId, string $attribute, string $payload): bool
    {
        $this->entities[$entityId] ??= [
            'entity_id' => $entityId,
            'domain' => HAMediaPlayerDefinitions::DOMAIN,
            'attributes' => []
        ];

        return $this->handleMediaPlayerAttributeTopic($entityId, $attribute, $payload);
    }

    public function handleCamera(string $entityId, string $attribute, string $payload): bool
    {
        $this->entities[$entityId] ??= [
            'entity_id' => $entityId,
            'domain' => HACameraDefinitions::DOMAIN,
            'attributes' => []
        ];

        return $this->handleCameraAttributeTopic($entityId, $attribute, $payload);
    }

    protected function debugExpert(string $context, string $message, array $data = [], bool $log = false): void
    {
    }

    protected function debugRuntimeIssue(string $context, string $message, array $data = [], bool $log = true): void
    {
    }

    protected function storeEntityAttribute(string $entityId, string $attribute, mixed $value): void
    {
        $this->entities[$entityId]['attributes'][$attribute] = $value;
    }

    protected function updateEntityCache(string $entityId, mixed $rawState = null, array $attributes = []): void
    {
        foreach ($attributes as $attribute => $value) {
            $this->entities[$entityId]['attributes'][$attribute] = $value;
        }
    }

    protected function updateEntityPresentation(string $entityId, array $attributes = [], bool $withExtraMaintenance = true): void
    {
        if ($withExtraMaintenance) {
            $this->fullRefreshes++;
        } else {
            $this->targetedRefreshes++;
        }
    }

    protected function refreshDomainAttributePresentationsForTrigger(string $domain, string $entityId, string $changedAttribute): void
    {
        $this->lastTrigger = [$domain, $entityId, $changedAttribute];
    }

    protected function getEntityDomain(string $entityId): string
    {
        return (string)($this->entities[$entityId]['domain'] ?? '');
    }

    protected function getCachedEntityAttributes(string $entityId): array
    {
        $attributes = $this->entities[$entityId]['attributes'] ?? [];
        return is_array($attributes) ? $attributes : [];
    }

    protected function castVariableValue(mixed $value, int $type): string|int|bool|float
    {
        return (string)$value;
    }
}

// ---- Teil 1: Job-Queue (Bündeln, Mindestabstand, Fehlerfall) ----

$media = new MediaRefreshHarness();
$media->schedule('cam_preview', 'http://ha/api/camera_proxy/camera.test');
check($media->timerIntervals[MediaRefreshHarness::TIMER_MEDIA_REFRESH] === MediaRefreshHarness::MEDIA_REFRESH_DELAY_MS, 'Einreihen bewaffnet den One-Shot-Timer');
$media->schedule('cam_preview', 'http://ha/api/camera_proxy/camera.test');
check($media->pendingJobCount() === 1, 'Gleicher Ident wird gebündelt (1 Auftrag)');

$media->process();
check($media->fetchCalls === 1, 'Verarbeitung lädt genau einmal');
check(count(MediaRefreshHarness::$mediaContentWrites) === 1, 'Bildinhalt wird genau einmal geschrieben');
check($media->pendingJobCount() === 0, 'Auftrags-Buffer ist nach Verarbeitung leer');
check($media->timerIntervals[MediaRefreshHarness::TIMER_MEDIA_REFRESH] === 0, 'Timer ist nach Verarbeitung aus');

$media->schedule('cam_preview', 'http://ha/api/camera_proxy/camera.test');
$media->process();
check($media->fetchCalls === 1, 'Mindestabstand verhindert sofortigen zweiten Download');

$media->schedule('other_preview', 'http://ha/other.jpg');
$media->fetchSucceeds = false;
$media->process();
check($media->fetchCalls === 2, 'Anderer Ident wird geladen (Fehlversuch)');
$media->schedule('other_preview', 'http://ha/other.jpg');
$media->fetchSucceeds = true;
$media->process();
check($media->fetchCalls === 2, 'Auch nach Fehlversuch gilt der Mindestabstand (kein Hämmern)');

// ---- Teil 2: Unknown-Attribut-Zweig (Dedupe + gezielter Refresh) ----

$attrs = new UnknownAttributeHarness();
$listA = json_encode(['Radio', 'TV'], JSON_THROW_ON_ERROR);
$listB = json_encode(['Radio', 'TV', 'Spotify'], JSON_THROW_ON_ERROR);

$attrs->handleMediaPlayer('media_player.bad', 'source_list', $listA);
check($attrs->fullRefreshes === 1 && $attrs->targetedRefreshes === 0, 'Neues Attribut nimmt die volle Kaskade');

$attrs->handleMediaPlayer('media_player.bad', 'source_list', $listA);
check($attrs->fullRefreshes === 1 && $attrs->targetedRefreshes === 0, 'Identische Wiederholung löst keinen Refresh aus');

$attrs->handleMediaPlayer('media_player.bad', 'source_list', $listB);
check($attrs->fullRefreshes === 1 && $attrs->targetedRefreshes === 1, 'Wertänderung nimmt den gezielten Pfad');
check($attrs->lastTrigger === [HAMediaPlayerDefinitions::DOMAIN, 'media_player.bad', 'source_list'], 'Gezielter Pfad meldet Domain/Entity/Attribut korrekt');

// ---- Teil 3: Kamera-Attribute und Bookkeeping-Katalog ----

$attrs->handleCamera('camera.test', 'entity_picture', '/api/camera_proxy/camera.test?token=abc');
$attrs->handleCamera('camera.test', 'access_token', 'abc123');
check(($attrs->entities['camera.test']['attributes'] ?? []) === [], 'Kamera ignoriert entity_picture und access_token');

check(HADomainCatalog::isIgnorableBookkeepingTopic('homeassistant/camera/test/access_token'), 'access_token wird im Splitter als Bookkeeping verworfen');
check(!HADomainCatalog::isIgnorableBookkeepingTopic('homeassistant/media_player/bad/entity_picture'), 'entity_picture bleibt für andere Domains erhalten');

ergebnis();
