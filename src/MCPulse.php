<?php

declare(strict_types=1);

namespace MCPulse;

/**
 * Analytics for MCP servers.
 *
 *     MCPulse::configure(new Options('mp_live_…'));
 *
 *     $result = MCPulse::record('search', $arguments, fn () => $handler($arguments));
 *
 * Three rules this package keeps, in order of how badly it would hurt to break one:
 *
 * 1. **Never throw.** Every entry point swallows. If MCPulse fails inside a customer's tool call,
 *    their tool fails and they blame us.
 * 2. **Never block.** Record, buffer, return. Nothing waits on the network on the path a model is
 *    waiting on.
 * 3. **Never store customer data.** Sizes and hashes leave this process. Arguments and results do
 *    not, and no option turns that off.
 *
 * PHP is the one runtime where rule 2 needs a different shape. There are no daemon threads in a
 * typical PHP process and, under FPM, the process ends with the request — so payloads are buffered
 * in memory and flushed once, on shutdown, after the response has been sent. A long-lived server
 * (Swoole, RoadRunner, a stdio worker) reaches the same place through `flushAll()`.
 */
final class MCPulse
{
    private static ?Options $options = null;
    private static ?string $sessionId = null;
    private static string $clientName = 'unknown';
    private static bool $startupSent = false;
    private static bool $shutdownRegistered = false;

    /** @var list<array<string, mixed>> */
    private static array $pending = [];

    /**
     * Diverts payloads away from the buffer. Only tests set it.
     *
     * @var null|callable(array<string, mixed>): void
     */
    public static $sink = null;

    /**
     * Starts recording, or turns everything into a no-op if the options say not to.
     *
     * Idempotent: calling it twice reuses the same session rather than opening a second one. Left
     * unguarded, a server built per request would report every call under two sessions and double
     * both the customer's numbers and their bill.
     */
    public static function configure(Options $options): void
    {
        try {
            if (!$options->active()) {
                self::log('disabled — no key, or enabled: false', $options);
                self::$options = null;

                return;
            }

            if (self::$options !== null && self::$options->streamKey() === $options->streamKey()) {
                return;
            }

            self::$options = $options;
            self::$sessionId ??= Hashing::newSessionId();

            if (!self::$shutdownRegistered) {
                // Registered once for the process. Under FPM this runs after the response has been
                // sent, so the customer's user never waits on our POST.
                self::$shutdownRegistered = true;
                register_shutdown_function(self::flushAll(...));
            }

            self::log('watching', $options);
        } catch (\Throwable) {
            // Deliberately silent. Failing here must look like configure was never called.
            self::$options = null;
        }
    }

    /**
     * The session calls are being filed under, or null when recording is off.
     *
     * Exposed so a server can log which session it joined, and so the shared-session guarantee can
     * be asserted rather than assumed.
     */
    public static function sessionId(): ?string
    {
        return self::$options === null ? null : self::$sessionId;
    }

    /** Notes who is connected, so calls can be attributed to a client. */
    public static function rememberClient(?string $name): void
    {
        if ($name !== null && $name !== '') {
            self::$clientName = substr($name, 0, Options::MAX_CLIENT_NAME);
        }
    }

    /**
     * Times one tool call and buffers the result.
     *
     * The callable's return value is handed back untouched and an exception is re-thrown untouched,
     * so a recorded call behaves exactly like an unrecorded one.
     *
     * Wrapping the handler rather than watching from outside is what lets MCPulse tell a handler
     * that threw from one that returned an error result — a distinction an MCP server erases by
     * converting both into `isError` before anything outside can see it.
     *
     * @template T
     * @param callable(): T $handler
     * @return T
     */
    public static function record(
        string $toolName,
        mixed $arguments,
        callable $handler,
        ?string $clientName = null,
    ): mixed {
        if (self::$options === null) {
            return $handler();
        }

        self::rememberClient($clientName);

        $startedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $started = hrtime(true);

        $result = null;
        $threw = false;
        try {
            $result = $handler();

            return $result;
        } catch (\Throwable $error) {
            $threw = true;
            // Re-thrown untouched: swallowing it would change what the customer's server does.
            throw $error;
        } finally {
            try {
                self::emitCall($toolName, $arguments, $result, $threw, $startedAt, $started);
            } catch (\Throwable) {
                // Recording must never be the reason a tool call fails.
            }
        }
    }

    /**
     * Reports the server's tool list, once per session.
     *
     * `schema_bytes` is the cost of a tool's presence in the context window, so pass the JSON that
     * actually goes over the wire — what `tools/list` returns — not the PHP object the tool was
     * declared from.
     *
     * @param iterable<mixed> $tools
     */
    public static function recordStartup(iterable $tools, ?string $clientName = null): void
    {
        try {
            if (self::$options === null || self::$startupSent) {
                return;
            }
            self::$startupSent = true;
            self::rememberClient($clientName);

            $described = [];
            foreach ($tools as $tool) {
                if (count($described) >= Options::MAX_TOOLS) {
                    break;
                }
                $name = Emptiness::member($tool, 'name');
                if (!is_string($name) || $name === '') {
                    continue;
                }
                $described[] = [
                    'name' => substr($name, 0, Options::MAX_TOOL_NAME),
                    'schema_bytes' => self::measure($tool),
                ];
            }

            self::emit([
                'v' => 1,
                'type' => 'startup',
                'session_id' => self::$sessionId,
                'client_name' => self::$clientName,
                'tools' => $described,
            ]);
        } catch (\Throwable) {
            // A startup payload is worth nothing next to the server that would have failed for it.
        }
    }

    /**
     * Sends everything buffered.
     *
     * A shutdown function already does this. Call it by hand from a long-lived server — Swoole,
     * RoadRunner, a stdio worker — that does not end with a request.
     */
    public static function flushAll(): void
    {
        try {
            $options = self::$options;
            if ($options === null || self::$pending === []) {
                return;
            }

            $batch = self::$pending;
            self::$pending = [];

            $sent = Transport::postBatch($batch, $options);
            self::log(($sent ? 'sent ' : 'dropped ') . count($batch) . ' payloads', $options);
        } catch (\Throwable) {
            // Nothing left to report to.
        }
    }

    private static function emitCall(
        string $toolName,
        mixed $arguments,
        mixed $result,
        bool $threw,
        \DateTimeImmutable $startedAt,
        int|float $startedNanos,
    ): void {
        $outcome = self::decideOutcome($result, $threw);
        $name = $toolName === '' ? 'unknown' : $toolName;

        self::emit([
            'v' => 1,
            'type' => 'call',
            'session_id' => self::$sessionId,
            'client_name' => self::$clientName,
            'tool_name' => substr($name, 0, Options::MAX_TOOL_NAME),
            'started_at' => $startedAt->format('Y-m-d\TH:i:s.v\Z'),
            'duration_ms' => (int) max(0, (hrtime(true) - $startedNanos) / 1_000_000),
            'outcome' => $outcome->value,
            'response_bytes' => self::measure($result),
            // An error is not also an absence — it has its own outcome already.
            'is_empty' => $outcome === Outcome::Ok && Emptiness::isEmptyResult($result),
            'args_hash' => Hashing::argsHash($arguments),
        ]);
    }

    /**
     * What the outcome was, given that the handler is what we wrapped.
     *
     * Wrapping the callable means a throw arrives here as a throw rather than as the `isError`
     * result the server would have converted it into. What cannot be seen from here is `bad_args`:
     * a server that validates arguments before calling the handler rejects them outside this
     * callable. Reporting it anyway would mean reading the difference back out of an error message,
     * and error strings are not an interface anyone promised to keep.
     */
    private static function decideOutcome(mixed $result, bool $threw): Outcome
    {
        if ($threw) {
            return Outcome::Crashed;
        }

        return Emptiness::member($result, 'isError') === true ? Outcome::ToolError : Outcome::Ok;
    }

    /** @param array<string, mixed> $payload */
    private static function emit(array $payload): void
    {
        $sink = self::$sink;
        if ($sink !== null) {
            $sink($payload);

            return;
        }

        if (count(self::$pending) >= Options::MAX_BUFFERED) {
            // Oldest first: recent calls describe what the server is doing now, and that is the
            // more useful half of a buffer that could not be sent.
            array_shift(self::$pending);
        }

        self::$pending[] = $payload;

        // No timer exists in a PHP request, so the item count is the only trigger before shutdown.
        if (count(self::$pending) >= Options::FLUSH_AT_ITEMS) {
            self::flushAll();
        }
    }

    /** What something costs the context window. Unserialisable means unmeasurable. */
    private static function measure(mixed $value): int
    {
        if ($value === null) {
            return 0;
        }

        try {
            $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return Sizes::utf16Length($json);
        } catch (\Throwable) {
            return 0;
        }
    }

    private static function log(string $message, Options $options): void
    {
        if (!$options->debug) {
            return;
        }

        // stderr, never stdout: stdout is the transport for a stdio MCP server, and one stray line
        // there corrupts the JSON-RPC stream and takes the customer's server down with it.
        @file_put_contents('php://stderr', "[mcpulse] {$message}\n");
    }

    /** Only tests reach for this. */
    public static function reset(): void
    {
        self::$options = null;
        self::$sessionId = null;
        self::$clientName = 'unknown';
        self::$startupSent = false;
        self::$pending = [];
    }
}
