# mcpulse/mcpulse

Analytics for MCP servers, in PHP.

**[getmcpulse.com](https://getmcpulse.com)** · [Docs](https://docs.getmcpulse.com) · [Dashboard](https://app.getmcpulse.com)

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
| `agree` | off | Fields that more than one tool returns. See below |
| `agreeWindowMs` | `300000` | How long a value stays comparable |
| `agreeTolerance` | `0.0001` | Relative, for floats |

An empty key turns it off, so a server started without its key configured is
silent rather than a source of 401s on every flush.

### Cross-tool agreement

Opt-in, and it catches the failure every other metric here calls healthy.

Two tools return the same underlying field. A stale cache key, or a versioned key
a cron did not follow, and they disagree for hours. Every number stays green the
whole time — the calls succeed, the results are non-empty, there are no retries
and the latency is fine. Callers get two different answers to one question and
nothing reports it.

Name the value once, and say where each tool returns it:

```php
MCPulse::configure(new Options(
    key: getenv('MCPULSE_KEY') ?: '',
    agree: [
        'global_liquidity' => [
            ['tool' => 'getGlobalLiquidity', 'path' => 'globalLiquidity.value_t'],
            ['tool' => 'getPillars', 'path' => 'pillars.global_liquidity.value'],
        ],
    ],
    agreeWindowMs: 300_000,
));
```

A map rather than a list of field names, because the same value routinely ships
under a different name and a different shape in each tool — which is most of why
two copies of it drift apart without anyone noticing.

**The comparison happens in your process, and only the verdict is sent.**

```json
{
  "v": 1, "type": "agreement", "session_id": "s_7f2a91",
  "field": "global_liquidity",
  "tool_a": "getGlobalLiquidity", "tool_b": "getPillars",
  "agreed": false, "checked_at": "2026-09-11T14:22:31Z", "window_ms": 300000
}
```

No value, no difference, no hash of a value. Hashing could not work anyway:
`25.22`, `25.220` and `"25.22"` are the same number and three different hashes,
so a server-side check would report every representation change as a divergence
forever.

**PHP has a limit here the other SDKs do not**, and it is the same one behind
"One known gap" below: under FPM the process ends with the request, so two tools
called in two requests are two processes and there is nothing to compare. The
check is worth having on a long-lived worker — Swoole, RoadRunner, a stdio
server — and does nothing useful under FPM.

Three more things worth knowing:

- **`agreeWindowMs` must be shorter than your data's refresh interval.** A window
  that outlives a refresh compares a figure against its own predecessor and calls
  a legitimate change a divergence.
- **Integers and strings are compared exactly.** The tolerance is relative and
  applies to floats only — a count that is off by one is off by one. `"12abc"` is
  not a number either, whatever a cast would say.
- **The path is searched in `structuredContent`, in the JSON of a text content
  part, and in the result itself**, so it does not matter which envelope your tool
  returns. Run once with `debug: true`: a path that never resolves says so there,
  which is how a typo'd declaration shows up as something other than a passing
  check.

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
