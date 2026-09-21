<?php

declare(strict_types=1);

namespace MCPulse;

/** Everything {@see MCPulse} accepts, and what it means when you leave it out. */
final class Options
{
    /** Where payloads go when no endpoint is given. */
    public const DEFAULT_ENDPOINT = 'https://api.getmcpulse.com';

    /** Flush when either is reached, whichever comes first. */
    public const FLUSH_AT_ITEMS = 30;
    public const FLUSH_EVERY_SECONDS = 5.0;

    /**
     * Hard ceiling on the buffer. Reached only when the network is gone; past it the oldest
     * payloads are dropped, because a customer's server running out of memory over our analytics
     * is the one failure we must never cause.
     */
    public const MAX_BUFFERED = 1000;

    public const SEND_TIMEOUT_SECONDS = 10.0;

    /** Caps, so one malformed name cannot bloat a batch. */
    public const MAX_TOOL_NAME = 200;
    public const MAX_CLIENT_NAME = 128;
    public const MAX_TOOLS = 500;

    public readonly string $key;
    public readonly string $endpoint;

    /**
     * `$agree` is opt-in and off by default: the fields more than one tool returns, and where each
     * returns them. Two tools that disagree about the same figure leave every other metric here
     * green, so it is the one failure nothing else can see. See {@see Agree}.
     *
     * @param  null|array<string, list<array{tool?: string, path?: string}>>  $agree
     */
    public function __construct(
        string $key,
        ?string $endpoint = null,
        public readonly bool $enabled = true,
        public readonly bool $debug = false,
        public readonly ?array $agree = null,
        public readonly ?float $agreeWindowMs = null,
        public readonly ?float $agreeTolerance = null,
        /**
         * Opt-in: tools a client will refuse to offer until some precondition holds.
         *
         * A tool declared here is excluded from every discoverability metric. Without it, a model
         * correctly declining to call a dangerous tool reads as poor discoverability, and the
         * advice that follows makes the server less safe. See {@see Policy}.
         *
         * @var array<string, mixed>|null
         */
        public readonly ?array $policy = null,
        /**
         * How this server is being served: `stdio`, `http` or `sse`.
         *
         * The PHP MCP SDKs do not expose the transport from a server object, so it is declared
         * rather than guessed: a row that says `stdio` when it was SSE is worse than no row,
         * because the wire-mapping table would be applied to it confidently and wrongly. Left
         * unset, the row says `unknown` and is excluded from that table.
         */
        public readonly ?string $transport = null,
    ) {
        $this->key = trim($key);
        $target = ($endpoint === null || trim($endpoint) === '') ? self::DEFAULT_ENDPOINT : $endpoint;
        $this->endpoint = rtrim($target, '/');
    }

    /**
     * Whether anything should be recorded at all.
     *
     * An empty key turns the SDK off: a server started without its key configured should be
     * silent, not a source of 401s on every flush.
     */
    public function active(): bool
    {
        return $this->enabled && $this->key !== '';
    }

    public function streamKey(): string
    {
        return $this->endpoint . '|' . $this->key;
    }

    /** Writes a line to stderr when `debug` is on, and nothing otherwise. */
    public function log(string $message): void
    {
        if ($this->debug) {
            // stderr, never stdout: stdout is the transport for a stdio MCP server, and a stray
            // line there corrupts the protocol.
            file_put_contents('php://stderr', "[mcpulse] {$message}\n");
        }
    }
}

/** How a tool call ended. Exactly one of these, always. */
enum Outcome: string
{
    /** Ran and returned a result. */
    case Ok = 'ok';

    /** Arguments failed validation; the handler never ran. */
    case BadArgs = 'bad_args';

    /** Ran and returned `isError: true`. */
    case ToolError = 'tool_error';

    /** Threw. */
    case Crashed = 'crashed';
}
