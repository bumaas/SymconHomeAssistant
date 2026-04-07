<?php

declare(strict_types=1);

trait HAMediaObjectsTrait
{
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

    private function maintainCameraPreviewMedia(string $entityId, int $basePosition): void
    {
        $this->maintainEntityPreviewMedia(
            $entityId,
            self::CAMERA_PREVIEW_SUFFIX,
            $basePosition,
            $this->Translate('Preview'),
            'CameraPreview',
            'ha_camera_preview'
        );
    }

    private function maintainImagePreviewMedia(string $entityId, int $basePosition): void
    {
        $this->maintainEntityPreviewMedia(
            $entityId,
            self::IMAGE_PREVIEW_SUFFIX,
            $basePosition,
            $this->Translate('Preview'),
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
        $ident = $this->sanitizeIdent($entityId) . $suffix;
        $objectId = @$this->GetIDForIdent($ident);
        if ($objectId !== false) {
            $object = IPS_GetObject($objectId);
            if (($object['ObjectType'] ?? null) !== 5) {
                $this->debugExpert($debugCategory, 'Ident belegt, kein Medienobjekt', ['Ident' => $ident, 'ObjectType' => $object['ObjectType'] ?? null]);
                return false;
            }
            $this->syncEntityPreviewMeta($objectId, $basePosition, $name, $filePrefix);
            return true;
        }

        $mediaId = IPS_CreateMedia(MEDIATYPE_IMAGE);
        IPS_SetParent($mediaId, $this->InstanceID);
        IPS_SetIdent($mediaId, $ident);
        $this->syncEntityPreviewMeta($mediaId, $basePosition, $name, $filePrefix);
        return true;
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

    private function updateCameraPreviewMedia(string $entityId): void
    {
        $absoluteUrl = $this->buildCameraPreviewUrl($entityId);
        if ($absoluteUrl === '') {
            return;
        }

        $this->updateEntityPreviewMedia(
            $entityId,
            $absoluteUrl,
            self::CAMERA_PREVIEW_SUFFIX,
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

    private function resolveImagePreviewUrl(string $entityId, ?array $attributes = null): string
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
        string $debugCategory,
        string $filePrefix
    ): void {
        $ident = $this->sanitizeIdent($entityId) . $suffix;
        $mediaId = @$this->GetIDForIdent($ident);
        if ($mediaId === false) {
            if (!$this->ensureEntityPreviewMedia($entityId, $suffix, 0, $this->Translate('Preview'), $debugCategory, $filePrefix)) {
                return;
            }
            $mediaId = @$this->GetIDForIdent($ident);
            if ($mediaId === false) {
                return;
            }
        }

        $content = $this->fetchMediaImageContent($absoluteUrl);
        if ($content === null) {
            return;
        }

        $this->ensureEntityPreviewMediaFile($mediaId, $ident, $absoluteUrl, $filePrefix);
        IPS_SetMediaContent($mediaId, base64_encode($content));
        $this->debugExpert($debugCategory, 'Bild aktualisiert', ['Ident' => $ident, 'Bytes' => strlen($content)]);
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

    private function maintainMediaPlayerCoverMedia(string $entityId, int $basePosition): void
    {
        $this->ensureMediaPlayerCoverMedia($entityId, $basePosition);
    }

    private function ensureMediaPlayerCoverMedia(string $entityId, int $basePosition): bool
    {
        $ident = $this->sanitizeIdent($entityId) . self::MEDIA_PLAYER_COVER_SUFFIX;
        $objectId = @$this->GetIDForIdent($ident);
        if ($objectId !== false) {
            $object = IPS_GetObject($objectId);
            if (($object['ObjectType'] ?? null) !== 5) {
                $this->debugExpert('MediaCover', 'Ident belegt, kein Medienobjekt', ['Ident' => $ident, 'ObjectType' => $object['ObjectType'] ?? null]);
                return false;
            }
            $this->syncMediaPlayerCoverMeta($objectId, $basePosition);
            return true;
        }

        $mediaId = IPS_CreateMedia(MEDIATYPE_IMAGE);
        IPS_SetParent($mediaId, $this->InstanceID);
        IPS_SetIdent($mediaId, $ident);
        $this->syncMediaPlayerCoverMeta($mediaId, $basePosition);
        return true;
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

    private function updateMediaPlayerCoverMedia(string $entityId, string $url): void
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return;
        }
        $absoluteUrl = $this->makeMediaImageUrlAbsolute($trimmed);
        if ($absoluteUrl === '') {
            return;
        }

        $ident = $this->sanitizeIdent($entityId) . self::MEDIA_PLAYER_COVER_SUFFIX;
        $mediaId = @$this->GetIDForIdent($ident);
        if ($mediaId === false) {
            if (!$this->ensureMediaPlayerCoverMedia($entityId, 0)) {
                return;
            }
            $mediaId = @$this->GetIDForIdent($ident);
            if ($mediaId === false) {
                return;
            }
        }

        $content = $this->fetchMediaImageContent($absoluteUrl);
        if ($content === null) {
            return;
        }
        $this->ensureMediaPlayerCoverMediaFile($mediaId, $ident, $absoluteUrl);
        IPS_SetMediaContent($mediaId, base64_encode($content));
        $this->debugExpert('MediaCover', 'Bild aktualisiert', ['Ident' => $ident, 'Bytes' => strlen($content)]);
    }

    private function ensureMediaPlayerCoverMediaFile(int $mediaId, string $ident, string $url): void
    {
        $media = IPS_GetMedia($mediaId);
        $current = (string)($media['MediaFile'] ?? '');
        $extension = $this->detectMediaImageExtension($url);
        $safeIdent = preg_replace('/\W/', '_', $ident);
        $file = 'media/ha_media_cover_' . $safeIdent . '.' . $extension;
        if ($file !== '' && $current !== $file) {
            IPS_SetMediaFile($mediaId, $file, false);
        }
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
