<?php

declare(strict_types=1);

trait HADiagnosticsTrait
{
    protected function updateLastMqttLabel(string $field = 'DiagLastMQTT'): void
    {
        $lastMqtt = $this->ReadAttributeString('LastMQTTMessage');
        if ($lastMqtt === '') {
            $lastMqtt = 'nie';
        }
        $this->updateFormFieldSafe($field, 'caption', 'Letzte MQTT-Message: ' . $lastMqtt);
    }

    protected function updateLastRestFetchLabel(string $field = 'DiagLastREST'): void
    {
        $lastRest = $this->ReadAttributeString('LastRESTFetch');
        if ($lastRest === '') {
            $lastRest = 'nie';
        }
        $this->updateFormFieldSafe($field, 'caption', 'Letzter REST-Abruf: ' . $lastRest);
    }

    protected function updateRestErrorLabel(string $field = 'DiagRest'): void
    {
        $lastRestError = $this->ReadAttributeString('LastRestError');
        if ($lastRestError === '') {
            $lastRestError = 'keiner';
        }
        $this->updateFormFieldSafe($field, 'caption', 'Letzter REST-Fehler: ' . $lastRestError);
    }

    protected function updateRestResponseLabel(string $field = 'DiagRestResponse'): void
    {
        $lastRestResponse = $this->ReadAttributeString('LastRestResponse');
        if ($lastRestResponse === '') {
            $lastRestResponse = 'keine';
        }
        $this->updateFormFieldSafe($field, 'caption', 'Letzte REST-Antwort: ' . $lastRestResponse);
    }

    protected function updateRestTimeoutLabel(string $field = 'DiagRestTimeout'): void
    {
        $lastRestTimeout = $this->ReadAttributeString('LastRestTimeout');
        if ($lastRestTimeout === '') {
            $lastRestTimeout = 'keiner';
        }
        $this->updateFormFieldSafe($field, 'caption', 'Letzter REST-Timeout: ' . $lastRestTimeout);
    }
}
