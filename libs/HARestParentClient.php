<?php

declare(strict_types=1);

trait HARestParentClientTrait
{
    use HAParentConnectionTrait;

    private function hasCompatibleSplitterParent(): bool
    {
        return $this->hasCompatibleParentModule(HAIds::MODULE_SPLITTER);
    }

    private function getCurrentParentDebugContext(): array
    {
        return $this->buildCurrentParentDebugContext();
    }

    private function sendRestRequestToParent(string $endpoint, ?string $postData): ?array
    {
        $parentState = $this->determineParentRuntimeState([HAIds::MODULE_SPLITTER]);
        if ($parentState !== 'active') {
            $message = match ($parentState) {
                'missing' => 'No parent connected',
                'inactive' => 'No active parent',
                default => 'No compatible parent'
            };
            $this->debugExpert('REST', $message, $this->getCurrentParentDebugContext(), true);
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
            $this->debugExpert('REST', 'Non-JSON response (exception): ' . $e->getMessage(), ['Body' => $body]);
            return null;
        }
        if (!is_array($decoded)) {
            $this->debugExpert('REST', 'Non-JSON response (no array): ' . $body);
            return null;
        }
        return $decoded;
    }

    protected function sendServiceRequestToParent(string $domain, string $service, array $data): bool
    {
        $endpoint = '/api/services/' . rawurlencode($domain) . '/' . rawurlencode($service);
        $payload = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $this->sendRestRequestToParent($endpoint, $payload) !== null;
    }
}
