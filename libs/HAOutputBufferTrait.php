<?php

declare(strict_types=1);

trait HAOutputBufferTrait
{
    private function applyOutputBufferForStringResponse(string $response, string $context): void
    {
        $configuredBufferMb     = max(0, $this->ReadPropertyInteger('OutputBufferSize'));
        $configuredBufferBytes  = $configuredBufferMb > 0 ? $configuredBufferMb * 1024 * 1024 : 0;
        $responseBytes          = strlen($response);
        $recommendedBufferBytes = max($configuredBufferBytes, $responseBytes + 262144);
        ini_set('ips.output_buffer', (string)$recommendedBufferBytes);

        $this->debugExpert($context, 'output_buffer', [
            'ResponseBytes'         => $responseBytes,
            'ConfiguredBufferBytes' => $configuredBufferBytes,
            'RecommendedBufferBytes' => $recommendedBufferBytes,
            'AppliedBufferBytes'    => ini_get('ips.output_buffer')
        ]);
    }
}
