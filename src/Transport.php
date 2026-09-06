<?php

declare(strict_types=1);

namespace MCPulse;

/**
 * Posting one batch.
 *
 * A stream context rather than cURL or Guzzle: this package installs into other people's servers,
 * and neither an extension requirement nor a Guzzle version pin is worth it for a single POST.
 */
final class Transport
{
    /**
     * Sends one batch and reports whether it landed. Never throws — a caller must not have to
     * catch.
     *
     * A failed batch is dropped, deliberately. Retrying means either a queue that grows while the
     * network is down, or duplicate rows when a 202 is lost on the way back. Neither is worth it
     * for analytics: a gap in a chart is a far smaller problem than memory growth inside someone
     * else's server.
     *
     * @param list<array<string, mixed>> $payloads
     */
    public static function postBatch(array $payloads, Options $options): bool
    {
        if ($payloads === []) {
            return true;
        }

        try {
            $body = json_encode(['batch' => $payloads], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException) {
            // A payload we built ourselves failed to serialise. Nothing to send.
            return false;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'content-type: application/json',
                    'authorization: Bearer ' . $options->key,
                    'user-agent: mcpulse-php',
                ]),
                'content' => $body,
                'timeout' => Options::SEND_TIMEOUT_SECONDS,
                // Without this a 4xx makes the stream emit a warning and return false, and the
                // status line below never gets read.
                'ignore_errors' => true,
            ],
        ]);

        $handle = @fopen($options->endpoint . '/v1/ingest', 'rb', false, $context);
        if ($handle === false) {
            // DNS, TLS, a refused connection. All the same to us.
            return false;
        }

        $status = self::statusFrom($http_response_header ?? []);
        @fclose($handle);

        return $status >= 200 && $status < 300;
    }

    /** @param list<string> $headers */
    private static function statusFrom(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return 0;
    }
}
