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

    /**
     * Anzeigegenauigkeit (Nachkommastellen) der Entitäten, wie Home Assistant sie anzeigt — der Splitter
     * holt sie per WebSocket aus der Entity-Registry. null = keine Antwort (Parent fehlt, Fehlschlag).
     *
     * @param list<string> $entityIds
     * @return array<string, int>|null
     */
    protected function requestDisplayPrecisions(array $entityIds): ?array
    {
        if ($entityIds === [] || $this->determineParentRuntimeState([HAIds::MODULE_SPLITTER]) !== 'active') {
            return null;
        }
        $responseJson = $this->SendDataToParent(json_encode([
            'DataID'           => HAIds::DATA_DEVICE_TO_SPLITTER,
            'DisplayPrecision' => array_values($entityIds),
        ], JSON_THROW_ON_ERROR));
        $response = is_string($responseJson) && $responseJson !== '' ? json_decode($responseJson, true) : null;
        if (!is_array($response) || ($response['Ok'] ?? false) !== true || !is_array($response['Map'] ?? null)) {
            $this->debugExpert('REST', 'Anzeigegenauigkeit nicht verfügbar', ['Response' => $responseJson]);
            return null;
        }
        return array_filter($response['Map'], 'is_int');
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
        // SendDataToParent liefert false, wenn der Kernel die Weiterleitung abbricht
        // (z. B. Insight-Schleifenschutz beim Start) — dann kein String.
        if (!is_string($responseJson)) {
            $this->debugExpert('REST', 'Send to parent failed (kernel aborted request)');
            return null;
        }
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
