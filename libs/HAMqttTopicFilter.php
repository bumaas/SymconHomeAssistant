<?php

declare(strict_types=1);

/**
 * MQTT-Topic-Filter-Logik (Wildcard-Matching nach MQTT-Spezifikation) für die
 * Abonnement-Diagnose der Splitter: Welche referenzierten Topics deckt die
 * Subscriptions-Liste des MQTT-Client-Parents ab, welche fehlen?
 */
class HAMqttTopicFilter
{
    /**
     * Segmentweises MQTT-Matching: '+' = genau ein Segment, '#' = Rest (nur als
     * letztes Segment; deckt auch das Eltern-Topic selbst, d. h. 'a/#' matcht 'a').
     */
    public static function matchesFilter(string $topic, string $filter): bool
    {
        $topic = trim($topic, '/');
        $filter = trim($filter, '/');
        if ($filter === '#') {
            return true;
        }
        if ($filter === '' || $topic === '') {
            return $filter === $topic;
        }

        $topicSegments = explode('/', $topic);
        $filterSegments = explode('/', $filter);
        $lastFilterIndex = count($filterSegments) - 1;
        $topicCount = count($topicSegments);

        foreach ($filterSegments as $i => $segment) {
            if ($segment === '#') {
                return $i === $lastFilterIndex;
            }
            if ($i >= $topicCount) {
                return false;
            }
            if ($segment !== '+' && $segment !== $topicSegments[$i]) {
                return false;
            }
        }

        return count($filterSegments) === $topicCount;
    }

    /** @param string[] $filters */
    public static function coveredByAny(string $topic, array $filters): bool
    {
        foreach ($filters as $filter) {
            if (self::matchesFilter($topic, (string)$filter)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string[] $topics
     * @param string[] $filters
     * @return string[] sortierte Liste der Topics, die kein Filter abdeckt
     */
    public static function uncoveredTopics(array $topics, array $filters): array
    {
        $uncovered = [];
        foreach ($topics as $topic) {
            $topic = trim((string)$topic, '/');
            if ($topic === '' || self::coveredByAny($topic, $filters)) {
                continue;
            }
            $uncovered[$topic] = true;
        }

        $result = array_keys($uncovered);
        sort($result, SORT_STRING);
        return $result;
    }

    /**
     * Leitet aus nicht abgedeckten Topics Abonnement-Vorschläge ab:
     * je erstes Topic-Segment ein Filter '<segment>/#'.
     *
     * @param string[] $topics
     * @return string[]
     */
    public static function suggestFilters(array $topics): array
    {
        $suggestions = [];
        foreach ($topics as $topic) {
            $topic = trim((string)$topic, '/');
            if ($topic === '') {
                continue;
            }
            $suggestions[explode('/', $topic, 2)[0] . '/#'] = true;
        }

        $result = array_keys($suggestions);
        sort($result, SORT_STRING);
        return $result;
    }

    /**
     * Deckt der Filter den gesamten Teilbaum '<prefix>/#' ab? Strenger als reines
     * Topic-Matching: 'prefix/sensor/#' oder 'prefix/+/+/config' decken nur Teile
     * des Baums und zählen daher nicht.
     */
    public static function filterCoversPrefix(string $filter, string $prefix): bool
    {
        $filter = trim($filter, '/');
        $prefix = trim($prefix, '/');
        if ($filter === '#') {
            return true;
        }
        if ($filter === '' || $prefix === '') {
            return false;
        }

        $filterSegments = explode('/', $filter);
        $prefixSegments = explode('/', $prefix);
        $lastFilterIndex = count($filterSegments) - 1;

        foreach ($prefixSegments as $i => $segment) {
            $filterSegment = $filterSegments[$i] ?? null;
            if ($filterSegment === '#') {
                return $i === $lastFilterIndex;
            }
            if ($filterSegment === null || ($filterSegment !== '+' && $filterSegment !== $segment)) {
                return false;
            }
        }

        return $lastFilterIndex === count($prefixSegments) && $filterSegments[$lastFilterIndex] === '#';
    }

    /**
     * Sammelt Abonnement-Topics aus einer Instanz-Konfiguration: alle Felder, deren
     * Schlüssel "subscri" enthält (der Symcon MQTT Client hält die Liste als
     * JSON-String [{"Topic":"...","QoS":n}]).
     *
     * @return string[]
     */
    public static function collectSubscriptionsFromConfig(array $config): array
    {
        $subscriptions = [];
        foreach ($config as $key => $value) {
            if (stripos((string)$key, 'subscri') === false) {
                continue;
            }
            foreach (self::flattenSubscriptionTopics($value) as $topic) {
                $topic = trim((string)$topic);
                if ($topic !== '') {
                    $subscriptions[$topic] = true;
                }
            }
        }

        return array_keys($subscriptions);
    }

    /**
     * Extrahiert Topic-Strings aus Listen-Properties: JSON-Strings werden dekodiert,
     * in Arrays zählen String-Werte unter Schlüsseln mit "topic"; verschachtelte
     * Arrays werden rekursiv durchsucht.
     *
     * @return string[]
     */
    public static function flattenSubscriptionTopics(mixed $value): array
    {
        $topics = [];
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return [];
            }
            if (str_starts_with($trimmed, '[') || str_starts_with($trimmed, '{')) {
                try {
                    $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
                    return self::flattenSubscriptionTopics($decoded);
                } catch (Throwable) {
                    return [$trimmed];
                }
            }
            return [$trimmed];
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (is_string($item) && stripos((string)$key, 'topic') !== false) {
                    $topics[] = $item;
                } elseif (is_array($item)) {
                    foreach (self::flattenSubscriptionTopics($item) as $topic) {
                        $topics[] = $topic;
                    }
                }
            }
        }
        return $topics;
    }

    /**
     * RegEx für SetReceiveDataFilter des klassischen Splitters: nur Datenpakete durchlassen, deren
     * Topic unter dem eingestellten Base-Topic liegt. Abonniert der MQTT Client breit (z. B. '#'),
     * reicht er sonst den gesamten Broker-Verkehr an den Splitter weiter — jede fremde Nachricht
     * kostet dann eine PHP-Ausführung, ohne je zu einem Wert zu führen.
     *
     * Der Filter läuft als RegEx über das komplette Datenpaket-JSON. Zwei Fallstricke bestimmen die
     * Form des Musters, deshalb wird nur das ERSTE Segment des Base-Topics geprüft:
     *  - Der Slash steht im JSON je nach Encoding als '/' oder '\/' — das Muster hört davor auf und
     *    matcht damit beide Schreibweisen.
     *  - Ein Slash im Muster selbst wäre riskant, weil der Kernel das Muster mit einem eigenen
     *    Delimiter anwendet.
     * Bei mehrstufigem Base-Topic ('symcon/ha') filtert das etwas grober als möglich; das ist
     * unkritisch, denn die eigentliche Zuordnung macht ohnehin der PHP-Code dahinter.
     *
     * Leeres Base-Topic => '.*' (nicht filtern); in dem Zustand meldet der Splitter ohnehin einen
     * Konfigurationsfehler.
     */
    public static function receiveDataFilterPattern(string $baseTopic): string
    {
        $baseTopic = trim(trim($baseTopic), '/');
        if ($baseTopic === '') {
            return '.*';
        }
        $firstSegment = explode('/', $baseTopic)[0];
        if ($firstSegment === '') {
            return '.*';
        }
        return '.*"Topic":"' . preg_quote($firstSegment) . '.*';
    }
}
