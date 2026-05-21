<?php

declare(strict_types=1);

final class HAInputTextDefinitions
{
    public const string DOMAIN = 'input_text';
    public const int VARIABLE_TYPE = VARIABLETYPE_STRING;

    public static function buildRestServicePayload(mixed $value): array
    {
        return HARestPayloadBuilder::buildSimpleValuePayload($value, 'set_value', 'value');
    }
}
