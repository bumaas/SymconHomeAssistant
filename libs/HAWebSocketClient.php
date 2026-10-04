<?php

declare(strict_types=1);

/**
 * Einmalige Anfrage an die WebSocket-API von Home Assistant: verbinden, anmelden, einen Befehl senden,
 * die Antwort lesen, trennen. Keine Dauerverbindung.
 *
 * Gebraucht für Angaben, die nur die WebSocket-API liefert — derzeit die Anzeigegenauigkeit aus der
 * Entity-Registry (`config/entity_registry/list_for_display`, Feld `dp`). REST, statestream und
 * Templates kennen sie nicht: Sie gehört zu den Anzeigeeinstellungen, nicht zum Zustand.
 *
 * Rahmenformat nach RFC 6455: Der Client maskiert jeden Rahmen, der Server nie; Antworten können in
 * Fortsetzungsrahmen geteilt sein, große Nutzlasten tragen eine 16- oder 64-Bit-Länge.
 */
final class HAWebSocketClient
{
    public const int OPCODE_CONTINUATION = 0x0;
    public const int OPCODE_TEXT = 0x1;
    public const int OPCODE_CLOSE = 0x8;
    public const int OPCODE_PING = 0x9;
    public const int OPCODE_PONG = 0xA;

    // Obergrenze einer Antwort; list_for_display am nuc: 223 KB für rund 1.800 Entitäten.
    private const int MAX_MESSAGE_BYTES = 16 * 1024 * 1024;

    /** @var resource|null */
    private $stream = null;
    private string $readBuffer = '';

    private function __construct(private readonly int $timeoutSec)
    {
    }

    /**
     * Führt einen Befehl aus und liefert dessen `result`.
     *
     * @param array<string, mixed> $command ohne `id`
     * @return array{ok: true, result: mixed}|array{ok: false, error: string}
     */
    public static function request(string $haUrl, string $token, array $command, int $timeoutSec = 10): array
    {
        $client = new self($timeoutSec);
        try {
            $client->connect($haUrl);
            $client->authenticate($token);
            $client->sendJson(['id' => 1] + $command);
            while (true) {
                $message = $client->readJson();
                if (($message['id'] ?? null) !== 1 || ($message['type'] ?? '') !== 'result') {
                    continue;
                }
                if (($message['success'] ?? false) !== true) {
                    $error = $message['error']['message'] ?? $message['error']['code'] ?? 'unknown error';
                    return ['ok' => false, 'error' => 'Command failed: ' . $error];
                }
                return ['ok' => true, 'result' => $message['result'] ?? null];
            }
        } catch (RuntimeException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        } finally {
            $client->close();
        }
    }

    /** Leitet die WebSocket-Adresse aus der HA-Adresse des Splitters ab (http → ws, https → wss). */
    public static function buildWebSocketUrl(string $haUrl): ?string
    {
        $parts = parse_url(trim($haUrl));
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = (string)($parts['host'] ?? '');
        if ($host === '' || !in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        $wsScheme = $scheme === 'https' ? 'wss' : 'ws';
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
        $path = rtrim((string)($parts['path'] ?? ''), '/');
        return $wsScheme . '://' . $host . $port . $path . '/api/websocket';
    }

    /** Erwarteter Sec-WebSocket-Accept zum gesendeten Schlüssel (RFC 6455, 4.2.2). */
    public static function computeAcceptKey(string $key): string
    {
        return base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
    }

    /** Rahmen vom Client an den Server, maskiert (RFC 6455, 5.3). */
    public static function encodeClientFrame(string $payload, int $opcode = self::OPCODE_TEXT, ?string $mask = null): string
    {
        $mask ??= random_bytes(4);
        $length = strlen($payload);
        $header = chr(0x80 | $opcode);
        if ($length < 126) {
            $header .= chr(0x80 | $length);
        } elseif ($length < 65536) {
            $header .= chr(0x80 | 126) . pack('n', $length);
        } else {
            $header .= chr(0x80 | 127) . pack('J', $length);
        }
        $masked = '';
        for ($i = 0; $i < $length; $i++) {
            $masked .= $payload[$i] ^ $mask[$i % 4];
        }
        return $header . $mask . $masked;
    }

    /**
     * Zerlegt den ersten vollständigen Rahmen am Anfang von $buffer.
     *
     * @return array{fin: bool, opcode: int, payload: string, size: int}|null null = Rahmen noch unvollständig
     */
    public static function decodeFrame(string $buffer): ?array
    {
        if (strlen($buffer) < 2) {
            return null;
        }
        $first = ord($buffer[0]);
        $second = ord($buffer[1]);
        $length = $second & 0x7F;
        $offset = 2;
        if ($length === 126) {
            if (strlen($buffer) < 4) {
                return null;
            }
            $length = unpack('n', substr($buffer, 2, 2))[1];
            $offset = 4;
        } elseif ($length === 127) {
            if (strlen($buffer) < 10) {
                return null;
            }
            $length = unpack('J', substr($buffer, 2, 8))[1];
            $offset = 10;
        }
        $mask = '';
        if (($second & 0x80) !== 0) {
            if (strlen($buffer) < $offset + 4) {
                return null;
            }
            $mask = substr($buffer, $offset, 4);
            $offset += 4;
        }
        if ($length > self::MAX_MESSAGE_BYTES) {
            throw new RuntimeException('WebSocket frame too large: ' . $length . ' bytes');
        }
        if (strlen($buffer) < $offset + $length) {
            return null;
        }
        $payload = substr($buffer, $offset, $length);
        if ($mask !== '') {
            for ($i = 0; $i < $length; $i++) {
                $payload[$i] = $payload[$i] ^ $mask[$i % 4];
            }
        }
        return [
            'fin'     => ($first & 0x80) !== 0,
            'opcode'  => $first & 0x0F,
            'payload' => $payload,
            'size'    => $offset + $length,
        ];
    }

    private function connect(string $haUrl): void
    {
        $url = self::buildWebSocketUrl($haUrl);
        if ($url === null) {
            throw new RuntimeException('Invalid Home Assistant URL: ' . $haUrl);
        }
        $parts = parse_url($url);
        $secure = $parts['scheme'] === 'wss';
        $host = (string)$parts['host'];
        $port = (int)($parts['port'] ?? ($secure ? 443 : 80));
        $path = (string)($parts['path'] ?? '/api/websocket');

        $context = stream_context_create(['ssl' => ['SNI_enabled' => true, 'peer_name' => $host]]);
        $stream = @stream_socket_client(
            ($secure ? 'ssl://' : 'tcp://') . $host . ':' . $port,
            $errorCode,
            $errorMessage,
            $this->timeoutSec,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if ($stream === false) {
            throw new RuntimeException('WebSocket connect failed: ' . trim($errorMessage) . ' (' . $errorCode . ')');
        }
        stream_set_timeout($stream, $this->timeoutSec);
        $this->stream = $stream;

        $key = base64_encode(random_bytes(16));
        $hostHeader = $host . (isset($parts['port']) ? ':' . $port : '');
        $this->write(
            'GET ' . $path . " HTTP/1.1\r\n"
            . 'Host: ' . $hostHeader . "\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . 'Sec-WebSocket-Key: ' . $key . "\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "User-Agent: IPS-HomeAssistant\r\n\r\n"
        );

        while (!str_contains($this->readBuffer, "\r\n\r\n")) {
            $this->fill();
        }
        [$head, $rest] = explode("\r\n\r\n", $this->readBuffer, 2);
        $this->readBuffer = $rest;
        $statusLine = strtok($head, "\r\n");
        if (!preg_match('#^HTTP/1\.[01] 101\b#', (string)$statusLine)) {
            throw new RuntimeException('WebSocket handshake failed: ' . $statusLine);
        }
        if (!preg_match('#^Sec-WebSocket-Accept:\s*(\S+)#mi', $head, $match) || $match[1] !== self::computeAcceptKey($key)) {
            throw new RuntimeException('WebSocket handshake failed: invalid Sec-WebSocket-Accept');
        }
    }

    private function authenticate(string $token): void
    {
        $message = $this->readJson();
        if (($message['type'] ?? '') !== 'auth_required') {
            throw new RuntimeException('Unexpected first message: ' . ($message['type'] ?? '?'));
        }
        $this->sendJson(['type' => 'auth', 'access_token' => $token]);
        $message = $this->readJson();
        if (($message['type'] ?? '') !== 'auth_ok') {
            throw new RuntimeException('Authentication failed: ' . ($message['message'] ?? $message['type'] ?? '?'));
        }
    }

    private function sendJson(array $message): void
    {
        $this->write(self::encodeClientFrame(json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)));
    }

    /** Liest die nächste vollständige Textnachricht; Ping wird beantwortet, Close beendet. */
    private function readJson(): array
    {
        $message = '';
        while (true) {
            $frame = self::decodeFrame($this->readBuffer);
            if ($frame === null) {
                $this->fill();
                continue;
            }
            $this->readBuffer = substr($this->readBuffer, $frame['size']);
            switch ($frame['opcode']) {
                case self::OPCODE_PING:
                    $this->write(self::encodeClientFrame($frame['payload'], self::OPCODE_PONG));
                    continue 2;
                case self::OPCODE_PONG:
                    continue 2;
                case self::OPCODE_CLOSE:
                    throw new RuntimeException('WebSocket closed by Home Assistant');
            }
            $message .= $frame['payload'];
            if (strlen($message) > self::MAX_MESSAGE_BYTES) {
                throw new RuntimeException('WebSocket message too large');
            }
            if (!$frame['fin']) {
                continue;
            }
            try {
                $decoded = json_decode($message, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new RuntimeException('Invalid WebSocket message: ' . $e->getMessage());
            }
            if (!is_array($decoded)) {
                throw new RuntimeException('Invalid WebSocket message');
            }
            return $decoded;
        }
    }

    private function fill(): void
    {
        $chunk = fread($this->stream, 65536);
        if ($chunk === false || $chunk === '') {
            $meta = stream_get_meta_data($this->stream);
            throw new RuntimeException(($meta['timed_out'] ?? false) ? 'WebSocket timeout' : 'WebSocket connection closed');
        }
        $this->readBuffer .= $chunk;
    }

    private function write(string $data): void
    {
        $remaining = $data;
        while ($remaining !== '') {
            $written = fwrite($this->stream, $remaining);
            if ($written === false || $written === 0) {
                throw new RuntimeException('WebSocket write failed');
            }
            $remaining = substr($remaining, $written);
        }
    }

    private function close(): void
    {
        if (is_resource($this->stream)) {
            @fwrite($this->stream, self::encodeClientFrame('', self::OPCODE_CLOSE));
            fclose($this->stream);
        }
        $this->stream = null;
    }
}
