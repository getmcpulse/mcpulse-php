# mcpulse/mcpulse

Analytics for MCP servers, in PHP.

```php
use MCPulse\MCPulse;
use MCPulse\Options;

MCPulse::configure(new Options('mp_live_…'));

// Around your tool handler:
$result = MCPulse::record('search', $arguments, fn () => $handler($arguments), $clientName);
```

Wrapping the handler rather than watching from outside is what lets MCPulse tell
a handler that threw from one that returned an error result — a distinction an
MCP server erases by converting both into `isError` before anything outside sees
it.

## Install

```bash
composer require mcpulse/mcpulse
```

No dependencies beyond `ext-json`. This package installs into other people's
servers, and a Guzzle version pin or a cURL extension requirement is a support
burden with no upside for a single POST.

## Options

| Argument | Default | Meaning |
|---|---|---|
| `key` | — | Ingest key, `mp_live_…`, minted per MCP in the dashboard |
| `endpoint` | `https://api.getmcpulse.com` | Point at a local API while developing |
| `enabled` | `true` | `false` makes everything a no-op — useful in tests and CI |
| `debug` | `false` | Log what is sent, and why a send failed, to **stderr** |

An empty key turns it off, so a server started without its key configured is
silent rather than a source of 401s on every flush.

## How "never block" works in PHP

There are no daemon threads in a typical PHP process, and under FPM the process
ends with the request. So payloads are buffered in memory and flushed once, from
a `register_shutdown_function` — which runs *after* the response has been sent,
so the user never waits on our POST.

A long-lived server — Swoole, RoadRunner, a stdio worker — should call
`MCPulse::flushAll()` on its own schedule, since it never reaches a request end.

## One known gap

`bad_args` is not reported. A server that validates arguments before calling the
handler rejects them outside the callable, so the call never reaches `record`.
Reporting it anyway would mean reading the difference back out of an error
message, and error strings are not an interface anyone promised to keep. `ok`,
`tool_error` and `crashed` are all exact.

## What leaves your process

Sizes and hashes. Arguments and results do not, and no option turns that on.

## Cross-language consistency

`argsHash` is the first 12 hex characters of the SHA-256 of the
[RFC 8785](https://www.rfc-editor.org/rfc/rfc8785) canonical form of the
arguments. `tests/canonical.json` is the shared conformance suite every MCPulse
SDK runs.

PHP needed the most undone of any language here. `json_encode` escapes `/` and
every non-ASCII character by default; float printing is governed by the
`serialize_precision` ini setting, so the digits are found by a round-trip
search that does not depend on a customer's php.ini; and RFC 8785 sorts keys by
UTF-16 code unit while `strcmp` compares bytes.

**Use `argsHashRaw` on the wire bytes where you can.** PHP has a trap no other
language has: `json_decode($raw, true)` turns `{"0":"a"}` into `["a"]`, which is
then indistinguishable from the JSON array `["a"]` and hashes identically.
`argsHashRaw` decodes to objects, which keeps the two apart and keeps PHP
agreeing with every other SDK.

## Running the tests

```bash
php tests/run.php
```

No Composer install needed.

## Licence

MIT
