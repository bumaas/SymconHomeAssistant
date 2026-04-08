<?php

declare(strict_types=1);

trait HARestParentClientTrait
{
    private function hasCompatibleSplitterParent(): bool
    {
        $instance = IPS_GetInstance($this->InstanceID);
        $parentId = (int)($instance['ConnectionID'] ?? 0);
        if ($parentId <= 0 || !IPS_InstanceExists($parentId)) {
            return false;
        }
        $parent = IPS_GetInstance($parentId);
        $moduleId = (string)($parent['ModuleInfo']['ModuleID'] ?? '');
        return $moduleId === HAIds::MODULE_SPLITTER;
    }

    private function sendRestRequestToParent(string $endpoint, ?string $postData): ?array
    {
        if (!$this->hasCompatibleSplitterParent()) {
            $this->debugExpert('REST', 'No compatible parent');
            return null;
        }

        if (!$this->HasActiveParent()) {
            $this->debugExpert('REST', 'No active parent');
            return null;
        }

        $payload = json_encode([
            'DataID'   => HAIds::DATA_DEVICE_TO_SPLITTER,
            'Endpoint' => $endpoint,
            'Method'   => $postData !== null ? 'POST' : 'GET',
            'Body'     => $postData
        ], JSON_THROW_ON_ERROR);

        $responseJson = $this->SendDataToParent($payload);
        if ($responseJson === '') {
            $this->debugExpert('REST', 'Empty response from parent');
            return null;
        }

        try {
            $response = json_decode($responseJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->debugExpert('REST', 'Invalid response: ' . $e->getMessage());
            return null;
        }
        if (!is_array($response)) {
            $this->debugExpert('REST', 'Invalid response: ' . $responseJson);
            return null;
        }
        if (isset($response['Error'])) {
            $this->debugExpert('REST', 'Parent error: ' . json_encode($response, JSON_THROW_ON_ERROR));
            return null;
        }

        $body = (string)($response['Response'] ?? '');
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->debugExpert('REST', 'Non-JSON response: ' . $e->getMessage());
            return null;
        }
        if (!is_array($decoded)) {
            $this->debugExpert('REST', 'Non-JSON response: ' . $body);
            return null;
        }
        return $decoded;
    }

    private function sendServiceRequestToParent(string $domain, string $service, array $data): bool
    {
        $endpoint = '/api/services/' . rawurlencode($domain) . '/' . rawurlencode($service);
        $payload = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $this->sendRestRequestToParent($endpoint, $payload) !== null;
    }
}
