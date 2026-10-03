<?php

declare(strict_types=1);

trait HADiagnosticsTrait
{
    protected function updateLastMqttLabel(string $field = 'DiagLastMQTT'): void
    {
        $lastMqtt = $this->ReadAttributeString('LastMQTTMessage');
        if ($lastMqtt === '') {
            $lastMqtt = $this->Translate('never');
        }
        $this->updateFormFieldSafe($field, 'caption', sprintf($this->Translate('Last MQTT message: %s'), $lastMqtt));
    }

    protected function updateLastRestFetchLabel(string $field = 'DiagLastREST'): void
    {
        $lastRest = $this->ReadAttributeString('LastRESTFetch');
        if ($lastRest === '') {
            $lastRest = $this->Translate('never');
        }
        $this->updateFormFieldSafe($field, 'caption', sprintf($this->Translate('Last REST fetch: %s'), $lastRest));
    }
}
