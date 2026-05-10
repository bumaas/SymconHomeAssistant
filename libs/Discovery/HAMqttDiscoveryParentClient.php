<?php

declare(strict_types=1);

trait HAMqttDiscoveryParentClientTrait
{
    use HAParentConnectionTrait;

    private function hasCompatibleDiscoverySplitterParent(): bool
    {
        return $this->hasCompatibleParentModule(HAIds::MODULE_MQTT_DISCOVERY_SPLITTER);
    }

    private function hasActiveDiscoverySplitterParent(): bool
    {
        return $this->hasCompatibleActiveParentModule(HAIds::MODULE_MQTT_DISCOVERY_SPLITTER);
    }

    private function getCurrentParentDebugContext(): array
    {
        return $this->buildCurrentParentDebugContext();
    }

    private function sendDiscoveryRequestToParent(string $action, array $payload = []): ?array
    {
        if (!$this->hasCompatibleDiscoverySplitterParent()) {
            $this->debugExpert('Discovery', 'No compatible parent', $this->getCurrentParentDebugContext(), true);
            return null;
        }

        if (!$this->hasActiveDiscoverySplitterParent()) {
            $this->debugExpert('Discovery', 'No active parent', $this->getCurrentParentDebugContext(), true);
            return null;
        }

        $request = array_merge(
            [
                'DataID' => HAIds::DATA_MQTT_DISCOVERY_DEVICE_TO_SPLITTER,
                'DiscoveryAction' => $action
            ],
            $payload
        );

        $responseJson = @$this->SendDataToParent(json_encode($request, JSON_THROW_ON_ERROR));
        if (!is_string($responseJson) || trim($responseJson) === '') {
            $this->debugExpert('Discovery', 'Empty response from parent');
            return null;
        }

        try {
            $response = json_decode($responseJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->debugExpert('Discovery', 'Invalid response: ' . $e->getMessage());
            return null;
        }

        if (!is_array($response)) {
            $this->debugExpert('Discovery', 'Invalid response payload');
            return null;
        }

        if (isset($response['Error'])) {
            $this->debugExpert('Discovery', 'Parent error: ' . json_encode($response, JSON_THROW_ON_ERROR));
            return null;
        }

        return $response;
    }
}
