<?php

declare(strict_types=1);

trait HAMediaObjectsTrait
{
    protected function ensureManagedMediaObject(
        string $ident,
        int $mediaType,
        string $debugCategory,
        callable $syncMeta
    ): bool {
        $objectId = @$this->GetIDForIdent($ident);
        if ($objectId !== false) {
            $object = IPS_GetObject($objectId);
            if (($object['ObjectType'] ?? null) !== OBJECTTYPE_MEDIA) {
                $this->debugExpert($debugCategory, 'Ident belegt, kein Medienobjekt', ['Ident' => $ident, 'ObjectType' => $object['ObjectType'] ?? null]);
                return false;
            }
            $syncMeta($objectId);
            return true;
        }

        $mediaId = IPS_CreateMedia($mediaType);
        IPS_SetParent($mediaId, $this->InstanceID);
        IPS_SetIdent($mediaId, $ident);
        $syncMeta($mediaId);
        return true;
    }

    protected function resolveManagedMediaId(string $ident, callable $ensureMedia): int|false
    {
        $mediaId = @$this->GetIDForIdent($ident);
        if ($mediaId !== false) {
            return $mediaId;
        }

        if (!$ensureMedia()) {
            return false;
        }

        return @$this->GetIDForIdent($ident);
    }

    private function makeMediaImageUrlAbsolute(string $url): string
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return $url;
        }
        if (preg_match('#^https?://#i', $trimmed) === 1) {
            return $trimmed;
        }

        $baseUrl = $this->getHaBaseUrl();
        if ($baseUrl === '') {
            return $trimmed;
        }
        if (str_starts_with($trimmed, '/')) {
            return $baseUrl . $trimmed;
        }
        return $baseUrl . '/' . $trimmed;
    }

    private function getHaBaseUrl(): string
    {
        $instance = IPS_GetInstance($this->InstanceID);
        $parentId = (int)($instance['ConnectionID'] ?? 0);
        if ($parentId <= 0) {
            return '';
        }
        $haUrl = trim((string)@IPS_GetProperty($parentId, 'HAUrl'));
        return rtrim($haUrl, '/');
    }

    protected function maintainCameraPreviewMedia(string $entityId, int $basePosition): void
    {
        $this->maintainEntityPreviewMedia(
            $entityId,
            self::CAMERA_PREVIEW_SUFFIX,
            $basePosition,
            $this->getEntityPreviewMediaName($entityId),
            'CameraPreview',
            'ha_camera_preview'
        );
    }

    protected function maintainImagePreviewMedia(string $entityId, int $basePosition): void
    {
        $this->maintainEntityPreviewMedia(
            $entityId,
            self::IMAGE_PREVIEW_SUFFIX,
            $basePosition,
            $this->getImagePreviewMediaName($entityId),
            'ImagePreview',
            'ha_image_preview'
        );
    }

    private function maintainEntityPreviewMedia(
        string $entityId,
        string $suffix,
        int $basePosition,
        string $name,
        string $debugCategory,
        string $filePrefix
    ): void {
        $this->ensureEntityPreviewMedia($entityId, $suffix, $basePosition, $name, $debugCategory, $filePrefix);
    }

    private function ensureEntityPreviewMedia(
        string $entityId,
        string $suffix,
        int $basePosition,
        string $name,
        string $debugCategory,
        string $filePrefix
    ): bool {
        $ident = $this->buildSharedSuffixIdent($entityId, $suffix);
        return $this->ensureManagedMediaObject(
            $ident,
            MEDIATYPE_IMAGE,
            $debugCategory,
            function (int $mediaId) use ($basePosition, $name, $filePrefix): void {
                $this->syncEntityPreviewMeta($mediaId, $basePosition, $name, $filePrefix);
            }
        );
    }

    private function syncEntityPreviewMeta(int $mediaId, int $basePosition, string $name, string $filePrefix): void
    {
        IPS_SetName($mediaId, $name);
        IPS_SetPosition($mediaId, $basePosition + 20);
        IPS_SetParent($mediaId, $this->InstanceID);
        $ident = IPS_GetObject($mediaId)['ObjectIdent'] ?? '';
        if (is_string($ident) && $ident !== '') {
            $this->ensureEntityPreviewMediaFileDefault($mediaId, $ident, $filePrefix);
        }
    }

    protected function updateCameraPreviewMedia(string $entityId): void
    {
        $absoluteUrl = $this->buildCameraPreviewUrl($entityId);
        if ($absoluteUrl === '') {
            return;
        }

        $this->updateEntityPreviewMedia(
            $entityId,
            $absoluteUrl,
            self::CAMERA_PREVIEW_SUFFIX,
            $this->getEntityPreviewMediaName($entityId),
            'CameraPreview',
            'ha_camera_preview'
        );
    }

    private function buildCameraPreviewUrl(string $entityId): string
    {
        $baseUrl = $this->getHaBaseUrl();
        if ($baseUrl === '') {
            return '';
        }

        return $baseUrl . '/api/camera_proxy/' . $entityId;
    }

    protected function resolveImagePreviewUrl(string $entityId, ?array $attributes = null): string
    {
        $entity = $this->entities[$entityId] ?? null;
        if (!is_array($attributes)) {
            $entityAttributes = $entity['attributes'] ?? null;
            $attributes = is_array($entityAttributes) ? $entityAttributes : [];
        }

        $attributes = $this->normalizeImageAttributes($attributes, __FUNCTION__);
        $candidate = $attributes['entity_picture'] ?? '';
        if (!is_string($candidate) || trim($candidate) === '') {
            return '';
        }

        return $this->makeMediaImageUrlAbsolute($candidate);
    }

    private function updateEntityPreviewMedia(
        string $entityId,
        string $absoluteUrl,
        string $suffix,
        string $name,
        string $debugCategory,
        string $filePrefix
    ): void {
        $ident = $this->buildSharedSuffixIdent($entityId, $suffix);
        $mediaId = $this->resolveManagedMediaId(
            $ident,
            fn(): bool => $this->ensureEntityPreviewMedia($entityId, $suffix, 0, $name, $debugCategory, $filePrefix)
        );
        if ($mediaId === false) {
            return;
        }

        // Download nicht hier (MQTT-Hotpath), sondern entkoppelt über den MediaRefresh-Timer.
        $this->scheduleMediaRefresh($ident, $absoluteUrl, $filePrefix, $debugCategory);
    }

    // ----- Entkoppelte Bild-Aktualisierung (Konstanten und Begründung: HADeviceConstants) -----

    protected function registerMediaRefreshTimer(): void
    {
        $this->RegisterTimer(
            self::TIMER_MEDIA_REFRESH,
            0,
            'IPS_RequestAction($_IPS["TARGET"], "' . self::ACTION_MEDIA_REFRESH . '", "");'
        );
    }

    // ApplyChanges-Reset: über einen Konfigurationswechsel hinweg keine veralteten Aufträge ausführen.
    protected function resetPendingMediaRefresh(): void
    {
        $this->SetTimerInterval(self::TIMER_MEDIA_REFRESH, 0);
        $this->SetBuffer(self::BUFFER_PENDING_MEDIA_JOBS, '');
    }

    private function scheduleMediaRefresh(string $ident, string $url, string $filePrefix, string $debugCategory): void
    {
        $jobs = $this->loadMediaRefreshBuffer(self::BUFFER_PENDING_MEDIA_JOBS);
        $jobs[$ident] = ['url' => $url, 'filePrefix' => $filePrefix, 'category' => $debugCategory];
        $this->SetBuffer(self::BUFFER_PENDING_MEDIA_JOBS, json_encode($jobs, JSON_THROW_ON_ERROR));
        if ($this->GetTimerInterval(self::TIMER_MEDIA_REFRESH) <= 0) {
            $this->SetTimerInterval(self::TIMER_MEDIA_REFRESH, self::MEDIA_REFRESH_DELAY_MS);
        }
        $this->debugExpert($debugCategory, 'Bild-Aktualisierung eingereiht', ['Ident' => $ident, 'Url' => $url]);
    }

    protected function handleMediaRefreshAction(string $ident): bool
    {
        if ($ident !== self::ACTION_MEDIA_REFRESH) {
            return false;
        }
        $this->processPendingMediaRefreshJobs();
        return true;
    }

    private function processPendingMediaRefreshJobs(): void
    {
        $this->SetTimerInterval(self::TIMER_MEDIA_REFRESH, 0);
        $jobs = $this->loadMediaRefreshBuffer(self::BUFFER_PENDING_MEDIA_JOBS);
        $this->SetBuffer(self::BUFFER_PENDING_MEDIA_JOBS, '');
        if ($jobs === []) {
            return;
        }

        $now = time();
        // Alte Einträge verwerfen, damit der Mindestabstands-Buffer nicht unbegrenzt wächst.
        $lastFetch = array_filter(
            $this->loadMediaRefreshBuffer(self::BUFFER_MEDIA_LAST_FETCH),
            static fn($ts): bool => is_int($ts) && ($now - $ts) < 3600
        );

        foreach ($jobs as $ident => $job) {
            if (!is_array($job)) {
                continue;
            }
            $ident = (string)$ident;
            $url = (string)($job['url'] ?? '');
            $category = (string)($job['category'] ?? 'MediaRefresh');
            if ($url === '') {
                continue;
            }
            $last = (int)($lastFetch[$ident] ?? 0);
            if ($last > 0 && ($now - $last) < self::MEDIA_REFRESH_MIN_INTERVAL_SEC) {
                $this->debugExpert($category, 'Bild-Aktualisierung übersprungen (Mindestabstand)', ['Ident' => $ident]);
                continue;
            }
            $mediaId = @$this->GetIDForIdent($ident);
            if ($mediaId === false) {
                continue;
            }

            // Auch fehlgeschlagene Abrufe zählen für den Mindestabstand (kein Hämmern bei Fehlern).
            $lastFetch[$ident] = $now;
            $content = $this->fetchMediaImageContent($url);
            if ($content === null) {
                continue;
            }
            $this->ensureEntityPreviewMediaFile($mediaId, $ident, $url, (string)($job['filePrefix'] ?? 'ha_media'));
            IPS_SetMediaContent($mediaId, base64_encode($content));
            $this->debugExpert($category, 'Bild aktualisiert', ['Ident' => $ident, 'Bytes' => strlen($content)]);
        }

        $this->SetBuffer(self::BUFFER_MEDIA_LAST_FETCH, json_encode($lastFetch, JSON_THROW_ON_ERROR));
    }

    private function loadMediaRefreshBuffer(string $bufferName): array
    {
        $raw = $this->GetBuffer($bufferName);
        if ($raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }
        return is_array($decoded) ? $decoded : [];
    }

    protected function getCameraStreamMediaName(string $entityId): string
    {
        return $this->getEntityFriendlyName($entityId) ?? $this->Translate('Stream');
    }

    private function getEntityPreviewMediaName(string $entityId): string
    {
        $baseName = $this->getEntityFriendlyName($entityId);
        if ($baseName === null) {
            return $this->Translate('Preview');
        }
        return $baseName . ' (' . $this->Translate('Preview') . ')';
    }

    private function getImagePreviewMediaName(string $entityId): string
    {
        if (!$this->isEntityIdBoundToDevice($entityId)) {
            return $this->Translate('Image');
        }
        return $this->getEntityFriendlyName($entityId) ?? $this->Translate('Image');
    }

    private function getEntityFriendlyName(string $entityId): ?string
    {
        $friendlyName = $this->getEntityFriendlyAttributeName($entityId);
        if ($friendlyName !== null) {
            return $friendlyName;
        }

        $name = $this->entities[$entityId]['name'] ?? null;
        if (!is_string($name)) {
            return null;
        }
        $name = $this->stripCurrentInstanceNamePrefix(trim($name));
        return $name !== '' ? $name : null;
    }

    private function getEntityFriendlyAttributeName(string $entityId): ?string
    {
        $attributes = $this->entities[$entityId]['attributes'] ?? null;
        if (!is_array($attributes)) {
            return null;
        }

        $friendlyName = $this->stripCurrentInstanceNamePrefix(trim((string)($attributes['friendly_name'] ?? '')));
        return $friendlyName !== '' ? $friendlyName : null;
    }

    private function isEntityIdBoundToDevice(string $entityId): bool
    {
        return $this->isCurrentInstanceDeviceBoundToEntity($entityId);
    }

    private function ensureEntityPreviewMediaFile(int $mediaId, string $ident, string $url, string $filePrefix): void
    {
        $media = IPS_GetMedia($mediaId);
        $current = (string)($media['MediaFile'] ?? '');
        $extension = $this->detectMediaImageExtension($url);
        $safeIdent = preg_replace('/\W/', '_', $ident);
        $file = 'media/' . $filePrefix . '_' . $safeIdent . '.' . $extension;
        if ($file !== '' && $current !== $file) {
            IPS_SetMediaFile($mediaId, $file, false);
        }
    }

    private function ensureEntityPreviewMediaFileDefault(int $mediaId, string $ident, string $filePrefix): void
    {
        $media = IPS_GetMedia($mediaId);
        $current = (string)($media['MediaFile'] ?? '');
        if ($current !== '' && $current !== '#') {
            return;
        }
        $safeIdent = preg_replace('/\W/', '_', $ident);
        $file = 'media/' . $filePrefix . '_' . $safeIdent . '.png';
        IPS_SetMediaFile($mediaId, $file, false);
        $size = (int)($media['MediaSize'] ?? 0);
        if ($size === 0) {
            $placeholder = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMBA0b9XQAAAABJRU5ErkJggg==';
            IPS_SetMediaContent($mediaId, $placeholder);
        }
    }

    protected function maintainMediaPlayerCoverMedia(string $entityId, int $basePosition): void
    {
        $this->ensureMediaPlayerCoverMedia($entityId, $basePosition);
    }

    private function ensureMediaPlayerCoverMedia(string $entityId, int $basePosition): bool
    {
        $ident = $this->buildSharedSuffixIdent($entityId, self::MEDIA_PLAYER_COVER_SUFFIX);
        return $this->ensureManagedMediaObject(
            $ident,
            MEDIATYPE_IMAGE,
            'MediaCover',
            function (int $mediaId) use ($basePosition): void {
                $this->syncMediaPlayerCoverMeta($mediaId, $basePosition);
            }
        );
    }

    private function syncMediaPlayerCoverMeta(int $mediaId, int $basePosition): void
    {
        IPS_SetName($mediaId, 'Cover');
        $position = $this->getMediaPlayerCoverPosition($basePosition);
        IPS_SetPosition($mediaId, $position);
        IPS_SetParent($mediaId, $this->InstanceID);
        $ident = IPS_GetObject($mediaId)['ObjectIdent'] ?? '';
        if (is_string($ident) && $ident !== '') {
            $this->ensureMediaPlayerCoverMediaFileDefault($mediaId, $ident);
        }
    }

    protected function updateMediaPlayerCoverMedia(string $entityId, string $url): void
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return;
        }
        $absoluteUrl = $this->makeMediaImageUrlAbsolute($trimmed);
        if ($absoluteUrl === '') {
            return;
        }

        $ident = $this->buildSharedSuffixIdent($entityId, self::MEDIA_PLAYER_COVER_SUFFIX);
        $mediaId = $this->resolveManagedMediaId(
            $ident,
            fn(): bool => $this->ensureMediaPlayerCoverMedia($entityId, 0)
        );
        if ($mediaId === false) {
            return;
        }

        // Download nicht hier (MQTT-Hotpath), sondern entkoppelt über den MediaRefresh-Timer.
        // Der Dateiname entsteht dort über ensureEntityPreviewMediaFile mit demselben Muster
        // ('media/ha_media_cover_<ident>.<ext>') wie zuvor.
        $this->scheduleMediaRefresh($ident, $absoluteUrl, 'ha_media_cover', 'MediaCover');
    }

    private function ensureMediaPlayerCoverMediaFileDefault(int $mediaId, string $ident): void
    {
        $media = IPS_GetMedia($mediaId);
        $current = (string)($media['MediaFile'] ?? '');
        if ($current !== '' && $current !== '#') {
            return;
        }
        $safeIdent = preg_replace('/\W/', '_', $ident);
        $file = 'media/ha_media_cover_' . $safeIdent . '.png';
        IPS_SetMediaFile($mediaId, $file, false);
        $size = (int)($media['MediaSize'] ?? 0);
        if ($size === 0) {
            $placeholder = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMBA0b9XQAAAABJRU5ErkJggg==';
            IPS_SetMediaContent($mediaId, $placeholder);
        }
    }

    private function detectMediaImageExtension(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $extension = '';
        if (is_string($path)) {
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        }
        return match ($extension) {
            'jpg', 'png', 'gif', 'webp', 'bmp' => $extension,
            default => 'jpg'
        };
    }

    private function fetchMediaImageContent(string $url): ?string
    {
        $response = $this->sendImageRequestToParent($url);
        if ($response === null) {
            return null;
        }
        $base64 = $response['Base64'] ?? '';
        if (!is_string($base64) || $base64 === '') {
            $this->debugExpert(__FUNCTION__, 'Bilddownload fehlgeschlagen', ['Url' => $url, 'Response' => $response]);
            return null;
        }
        $decoded = base64_decode($base64, true);
        if ($decoded === false) {
            $this->debugExpert(__FUNCTION__, 'Base64 decode fehlgeschlagen', ['Url' => $url]);
            return null;
        }
        return $decoded;
    }

    private function sendImageRequestToParent(string $url): ?array
    {
        if (!$this->hasActiveParent()) {
            $this->debugExpert(__FUNCTION__, 'Kein aktiver Parent', ['Url' => $url]);
            return null;
        }
        $bufferSizeMb = max(0, $this->ReadPropertyInteger(self::PROP_OUTPUT_BUFFER_SIZE));
        if ($bufferSizeMb > 0) {
            $bufferSizeBytes = $bufferSizeMb * 1024 * 1024;
            ini_set('ips.output_buffer', (string)$bufferSizeBytes);
            $this->debugExpert(__FUNCTION__, 'output_buffer', ['Value' => ini_get('ips.output_buffer')]);
        }
        $payload = json_encode([
            'DataID' => HAIds::DATA_DEVICE_TO_SPLITTER,
            'ImageUrl' => $url
        ], JSON_THROW_ON_ERROR);

        $responseJson = @$this->SendDataToParent($payload);
        if (!is_string($responseJson) || $responseJson === '') {
            $this->debugExpert(__FUNCTION__, 'Image request failed (empty response)', ['Url' => $url]);
            return null;
        }
        $decoded = $this->decodeJsonArray($responseJson, __FUNCTION__);
        if ($decoded === null) {
            return null;
        }
        if (isset($decoded['Error'])) {
            $this->debugExpert(__FUNCTION__, 'Image request error', ['Url' => $url, 'Error' => $decoded['Error']]);
            return null;
        }
        return $decoded;
    }
}
