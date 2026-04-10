<?php

declare(strict_types=1);

trait HADomainValueMappingTrait
{
    private function convertValueByDomain(string $domain, string $valueData, array $attributes = []): string|bool|float|int|null
    {
        $domain = $this->normalizeDomainAlias($domain);
        $normalizedState = $this->normalizeEntityStateToken($valueData);
        if ($normalizedState === 'unavailable' || $normalizedState === 'unknown') {
            return null;
        }
        if ($domain === HASensorDefinitions::DOMAIN) {
            $deviceClass = $attributes['device_class'] ?? '';
            if (is_string($deviceClass)) {
                $deviceClass = trim($deviceClass);
            } else {
                $deviceClass = '';
            }
            if ($deviceClass === HASensorDefinitions::DEVICE_CLASS_TIMESTAMP) {
                $parsed = $this->parseTimestampValue($valueData);
                if ($parsed === null) {
                    $normalizedValue = strtolower(trim($valueData));
                    if (!in_array($normalizedValue, ['unknown', 'unavailable'], true)) {
                        $this->debugExpert('Timestamp', 'Zeitstempel konnte nicht geparst werden', ['Value' => $valueData], true);
                    }
                    return null;
                }
                return $parsed;
            }
            if ($deviceClass === HASensorDefinitions::DEVICE_CLASS_DURATION) {
                return (int)$valueData;
            }
            if (is_numeric($valueData)) {
                return (float)$valueData;
            }
            return $valueData;
        }

        return match ($domain) {
            HAButtonDefinitions::DOMAIN => -1,
            HAEventDefinitions::DOMAIN,
            HAImageDefinitions::DOMAIN => $this->parseTimestampValue($valueData),
            HALightDefinitions::DOMAIN,
            HASwitchDefinitions::DOMAIN,
            HABinarySensorDefinitions::DOMAIN,
            HAFanDefinitions::DOMAIN,
            HAHumidifierDefinitions::DOMAIN => strtoupper(trim($valueData)) === 'ON',
            HAClimateDefinitions::DOMAIN => (float)$valueData,
            HANumberDefinitions::DOMAIN => $this->inferNumberVariableType($attributes) === VARIABLETYPE_INTEGER
                ? (int)$valueData
                : (float)$valueData,
            default => $valueData,
        };
    }

    private function parseTimestampValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int)$value;
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }
            if (is_numeric($trimmed)) {
                return (int)$trimmed;
            }
            try {
                $dt = new DateTimeImmutable($trimmed);
            } catch (Exception) {
                return null;
            }
            return $dt->getTimestamp();
        }
        return null;
    }

    private function getVariableType(string $domain, array $attributes = []): int
    {
        $domain = $this->normalizeDomainAlias($domain);

        $staticTypes = $this->getStaticDomainVariableTypes();
        if (array_key_exists($domain, $staticTypes)) {
            return $staticTypes[$domain];
        }

        return match ($domain) {
            HANumberDefinitions::DOMAIN => $this->inferNumberVariableType($attributes),
            HASensorDefinitions::DOMAIN => $this->inferSensorVariableType($attributes),
            default => VARIABLETYPE_STRING,
        };
    }

    private function getStaticDomainVariableTypes(): array
    {
        return [
            HALightDefinitions::DOMAIN => HALightDefinitions::VARIABLE_TYPE,
            HABinarySensorDefinitions::DOMAIN => HABinarySensorDefinitions::VARIABLE_TYPE,
            HASwitchDefinitions::DOMAIN => HASwitchDefinitions::VARIABLE_TYPE,
            HAClimateDefinitions::DOMAIN => HAClimateDefinitions::VARIABLE_TYPE,
            HALockDefinitions::DOMAIN => HALockDefinitions::VARIABLE_TYPE,
            HASelectDefinitions::DOMAIN => HASelectDefinitions::VARIABLE_TYPE,
            HAVacuumDefinitions::DOMAIN => HAVacuumDefinitions::VARIABLE_TYPE,
            HALawnMowerDefinitions::DOMAIN => HALawnMowerDefinitions::VARIABLE_TYPE,
            HACoverDefinitions::DOMAIN => HACoverDefinitions::VARIABLE_TYPE,
            HAEventDefinitions::DOMAIN => HAEventDefinitions::VARIABLE_TYPE,
            HAMediaPlayerDefinitions::DOMAIN => HAMediaPlayerDefinitions::VARIABLE_TYPE,
            HACameraDefinitions::DOMAIN => HACameraDefinitions::VARIABLE_TYPE,
            HAImageDefinitions::DOMAIN => HAImageDefinitions::VARIABLE_TYPE,
            HAButtonDefinitions::DOMAIN => HAButtonDefinitions::VARIABLE_TYPE,
            HAFanDefinitions::DOMAIN => HAFanDefinitions::VARIABLE_TYPE,
            HAHumidifierDefinitions::DOMAIN => HAHumidifierDefinitions::VARIABLE_TYPE
        ];
    }

    private function inferSensorVariableType(array $attributes): int
    {
        $deviceClass = $attributes['device_class'] ?? '';
        if (is_string($deviceClass)) {
            $deviceClass = trim($deviceClass);
        } else {
            $deviceClass = '';
        }

        return match (true) {
            $deviceClass === HASensorDefinitions::DEVICE_CLASS_TIMESTAMP,
            $deviceClass === HASensorDefinitions::DEVICE_CLASS_DURATION => VARIABLETYPE_INTEGER,
            array_key_exists('unit_of_measurement', $attributes),
            array_key_exists('state_class', $attributes) => VARIABLETYPE_FLOAT,
            default => VARIABLETYPE_STRING,
        };
    }

    private function inferNumberVariableType(array $attributes): int
    {
        $step = $this->extractNumericAttribute($attributes, ['step', 'native_step']);
        if ($step === null || $step <= 0.0 || !$this->isWholeNumber($step)) {
            return VARIABLETYPE_FLOAT;
        }

        $min = $this->extractNumericAttribute($attributes, ['min', 'native_min_value']);
        if ($min !== null && !$this->isWholeNumber($min)) {
            return VARIABLETYPE_FLOAT;
        }

        $max = $this->extractNumericAttribute($attributes, ['max', 'native_max_value']);
        if ($max !== null && !$this->isWholeNumber($max)) {
            return VARIABLETYPE_FLOAT;
        }

        $value = $this->extractNumericAttribute($attributes, ['value', 'state']);
        if ($value !== null && !$this->isWholeNumber($value)) {
            return VARIABLETYPE_FLOAT;
        }

        return VARIABLETYPE_INTEGER;
    }

    private function extractNumericAttribute(array $attributes, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $attributes)) {
                continue;
            }
            $value = $attributes[$key];
            if (is_string($value)) {
                $value = str_replace(',', '.', trim($value));
            }
            if (is_numeric($value)) {
                return (float)$value;
            }
        }

        return null;
    }

    private function isWholeNumber(float $value): bool
    {
        return abs($value - round($value)) < 0.0000001;
    }

    private function normalizeDomainAlias(string $domain): string
    {
        if ($domain === HAInputButtonDefinitions::DOMAIN) {
            return HAButtonDefinitions::DOMAIN;
        }
        return $domain;
    }
}
