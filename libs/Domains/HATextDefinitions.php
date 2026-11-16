<?php

declare(strict_types=1);

/**
 * MQTT-Discovery-Komponente `text` (HA Core mqtt/text.py): Zustand als Text, Schreiben sendet den Text an
 * command_topic. Länge (min/max) und pattern prüft das Modul nicht, wie beim input_text der Bridge.
 */
final class HATextDefinitions
{
    public const string DOMAIN = 'text';
    public const int VARIABLE_TYPE = VARIABLETYPE_STRING;
}
