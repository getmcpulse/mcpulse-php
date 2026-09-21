<?php

declare(strict_types=1);

namespace MCPulse;

/**
 * Cross-tool agreement — do two tools that return the same field agree?
 *
 * ## The failure this catches, and why nothing else can
 *
 * Two tools on one server hand back the same underlying number under different names. A stale
 * cache key, or a versioned key a cron did not follow, and they disagree for hours. Every metric
 * this package already collects stays green the whole time: the calls succeed, the results are
 * non-empty, there are no retries and the latency is fine. Callers get two different answers to
 * one question and nothing anywhere reports it.
 *
 * It is invisible to external monitoring by construction. A proxy sees two well-formed responses;
 * a status page sees two 200s. Only something inside the process can know that
 * `globalLiquidity.value_t` from one tool and `pillars.global_liquidity.value` from another are
 * supposed to be the same figure. That is the one thing this SDK has that nothing outside does.
 *
 * ## Why the declaration is a map and not a list of fields
 *
 * The same value routinely ships under a different name and a different shape in each tool that
 * returns it. A flat list of field names would only work on a server that already names things
 * consistently — which is a server that does not have this bug. So the author names the value
 * once, canonically, and says where it appears in each tool.
 *
 * ## Why the comparison happens here rather than at the API
 *
 * The obvious design is to hash each value and let the server compare hashes. It cannot work.
 * `25.22`, `25.220` and `"25.22"` are the same number and three different hashes, so a
 * representation change in one tool would report as a divergence on every call, forever. Comparing
 * numerically requires the values, and the values are the one thing this package will not send.
 *
 * Both halves of that are satisfied by comparing in-process: the numbers are compared where they
 * already are, and only the verdict leaves.
 *
 * ## What leaves
 *
 * `{field, tool_a, tool_b, agreed}` and the time. No value, no difference, no hash of a value. The
 * author already knows what their numbers are; what they do not know is that two tools disagreed
 * about one.
 *
 * A port of `src/agree.ts` in the TypeScript SDK, and it must stay behaviourally identical to it —
 * the verdict is a shared wire contract, so a customer running two languages has to get the same
 * answer from both.
 */
final class Agree
{
    /** Defaults for the window and the relative tolerance. */
    public const WINDOW_MS = 300_000;
    public const TOLERANCE = 0.0001;

    /**
     * Ceilings on the declaration and on what is held.
     *
     * The same discipline as the payload buffer, for the same reason: this runs inside somebody
     * else's server, and the one failure we must never cause is their process running out of
     * memory over our analytics.
     */
    public const MAX_FIELDS = 256;
    public const MAX_SITES = 512;

    /** Content parts searched for a path, when a result carries several. */
    public const MAX_CONTENT_PARTS = 4;

    /**
     * Reads the declaration, or decides there is nothing to do.
     *
     * Returns null for anything that cannot produce a comparison: no map, a field named by only
     * one tool, a malformed entry. Quietly — a bad declaration must not be able to stop a server
     * booting, and `debug` is where a typo becomes visible.
     *
     * @param  null|array<string, list<array{tool?: string, path?: string}>>  $declaration
     * @param  callable(string): void  $log
     * @return null|array{by_tool: array<string, list<array{0: string, 1: string}>>, window_ms: int, tolerance: float, site_count: int}
     */
    public static function resolve(
        ?array $declaration,
        ?float $windowMs,
        ?float $tolerance,
        callable $log,
    ): ?array {
        if ($declaration === null || $declaration === []) {
            return null;
        }

        $byTool = [];
        $bars = [];
        $defaultWindow = (int) self::positive($windowMs, self::WINDOW_MS);
        $defaultTolerance = self::positive($tolerance, self::TOLERANCE);
        $siteCount = 0;
        $fields = 0;

        foreach ($declaration as $field => $entry) {
            if ($fields >= self::MAX_FIELDS) {
                break;
            }
            ++$fields;

            $name = (string) $field;
            if ($name === '') {
                continue;
            }

            // Two shapes, and the short one is not deprecated: a bare list of sites is the whole
            // declaration when the defaults suit, and for most fields they do. The map form is for
            // when they do not — a global freshness bar cannot be right on a server returning more
            // than one kind of number. From the design partner this was built with: "a quote 30s old
            // is fine for a chat answer, fatal for a trade."
            if (is_array($entry) && isset($entry['sources'])) {
                $sites = $entry['sources'];
                $fieldWindow = $entry['window_ms'] ?? $entry['windowMs'] ?? null;
                $fieldTolerance = $entry['tolerance'] ?? null;
            } else {
                $sites = $entry;
                $fieldWindow = null;
                $fieldTolerance = null;
            }

            if (!is_array($sites)) {
                continue;
            }

            $usable = [];
            $tools = [];
            foreach ($sites as $site) {
                if (!is_array($site)) {
                    continue;
                }
                $tool = $site['tool'] ?? null;
                $path = $site['path'] ?? null;
                if (!is_string($tool) || $tool === '' || !is_string($path) || $path === '') {
                    continue;
                }
                $usable[] = [$tool, $path];
                $tools[$tool] = true;
            }

            // One tool cannot disagree with itself, so a field named once is a declaration with no
            // comparison in it. Said out loud, because the likely cause is a typo in the second
            // tool's name.
            if (count($tools) < 2) {
                $log(sprintf('agree: "%s" names fewer than two tools — nothing to compare', $name));

                continue;
            }

            foreach ($usable as [$tool, $path]) {
                if ($siteCount >= self::MAX_SITES) {
                    break;
                }
                $byTool[$tool][] = [$name, $path];
                ++$siteCount;
            }

            // Falls back one at a time, so a field can override the window and inherit the
            // tolerance.
            $bars[$name] = [
                (int) self::positive(is_numeric($fieldWindow) ? (float) $fieldWindow : null, (float) $defaultWindow),
                self::positive(is_numeric($fieldTolerance) ? (float) $fieldTolerance : null, $defaultTolerance),
            ];
        }

        if ($byTool === []) {
            return null;
        }

        $tuned = count(array_filter(
            $bars,
            static fn ($bar) => $bar[0] !== $defaultWindow || $bar[1] !== $defaultTolerance
        ));
        $log(sprintf(
            'agree: watching %d declared paths across %d tools%s',
            $siteCount,
            count($byTool),
            $tuned > 0 ? sprintf(', %d with their own window or tolerance', $tuned) : ''
        ));

        // Rebuilt from $byTool rather than collected alongside it, so the two cannot disagree:
        // what is reported as declared is exactly what is being watched.
        //
        // A verdict only exists when two tools *did* report inside the window, so a field that
        // never produced one is invisible to the API — including the worst case, where a path is
        // mistyped, never resolves, and the check reads as perfect agreement forever.
        //
        // Field names and tool names only. The paths stay in this process: they describe the shape
        // of a customer's results, and coverage does not need them.
        $byField = [];
        foreach ($byTool as $tool => $sites) {
            foreach ($sites as [$fieldName, $_path]) {
                $byField[$fieldName][$tool] = true;
            }
        }
        ksort($byField);

        $declared = [];
        foreach ($byField as $fieldName => $tools) {
            $names = array_keys($tools);
            sort($names);
            $declared[] = ['field' => $fieldName, 'tools' => $names];
        }

        return [
            'by_tool' => $byTool,
            'bars' => $bars,
            'window_ms' => $defaultWindow,
            'tolerance' => $defaultTolerance,
            'site_count' => $siteCount,
            'declared' => $declared,
        ];
    }

    private static function positive(?float $value, float $fallback): float
    {
        if ($value === null || !is_finite($value) || $value <= 0) {
            return $fallback;
        }

        return $value;
    }

    /**
     * The comparison itself.
     *
     * Numbers first, and a numeric string counts as a number — `"25.22"` and `25.22` are the same
     * figure written twice, and reporting that as a divergence is the exact mistake that makes
     * hashing unworkable. Two integers are compared exactly; two floats within the relative
     * tolerance agree. Strings that are not numbers are compared exactly. Anything structural falls
     * back to canonical JSON, which at least does not depend on key order.
     */
    public static function agrees(mixed $a, mixed $b, float $tolerance): bool
    {
        $na = self::asNumber($a);
        $nb = self::asNumber($b);

        if ($na !== null && $nb !== null) {
            if ($na === $nb) {
                return true;
            }
            // A count that is off by one is off by one. Rounding it into agreement would hide the
            // only kind of disagreement a count can have.
            if ($na === floor($na) && $nb === floor($nb)) {
                return false;
            }
            $scale = max(abs($na), abs($nb));

            return $scale === 0.0 ? true : (abs($na - $nb) / $scale) <= $tolerance;
        }

        if (is_string($a) || is_string($b)) {
            return is_string($a) && is_string($b) && $a === $b;
        }
        if (is_bool($a) || is_bool($b) || $a === null || $b === null) {
            return $a === $b;
        }

        try {
            return Canonical::canonicalize($a) === Canonical::canonicalize($b);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function asNumber(mixed $value): ?float
    {
        // PHP will happily read `true` as 1. A flag from one tool must not agree with a count of
        // one from another, so booleans are refused before anything else.
        if (is_bool($value)) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            $number = (float) $value;

            return is_finite($number) ? $number : null;
        }
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        // `is_numeric` refuses "" and, unlike a cast, also refuses "12abc" — a cast would read that
        // as 12 and silently agree with a real 12 from the other tool.
        if ($trimmed === '' || !is_numeric($trimmed)) {
            return null;
        }
        $number = (float) $trimmed;

        return is_finite($number) ? $number : null;
    }

    /**
     * `pillars.global_liquidity.value`, `rows[0].v` and `rows.0.v` all the same.
     *
     * @return list<string>
     */
    public static function segments(string $path): array
    {
        $flattened = preg_replace('/\[(\d+)\]/', '.$1', $path) ?? $path;

        return array_values(array_filter(explode('.', $flattened), static fn ($part) => $part !== ''));
    }

    /**
     * The value at `$path`, with `$found` telling absent from null.
     *
     * A declared field really can hold null, and "absent" is a different answer from "null" — one
     * is a typo in the declaration and the other is data.
     */
    public static function valueAt(mixed $root, string $path, ?bool &$found = null): mixed
    {
        $node = $root;

        foreach (self::segments($path) as $key) {
            if (is_array($node) && array_key_exists($key, $node)) {
                $node = $node[$key];

                continue;
            }
            if (is_object($node) && property_exists($node, $key)) {
                $node = $node->{$key};

                continue;
            }
            $found = false;

            return null;
        }

        $found = true;

        return $node;
    }

    /**
     * Where a declared path might be, in the order worth trying.
     *
     * An MCP result is an envelope, and which part of it holds the data is a choice the author
     * already made once when they wrote the tool. Making them make it again in the declaration —
     * remembering whether to write `structuredContent.` in front of every path — is a footgun with
     * no upside, so all three shapes are searched: the structured output, the JSON inside a text
     * part, and the result itself.
     *
     * @return list<mixed>
     */
    public static function roots(mixed $result): array
    {
        $out = [];

        $structured = Emptiness::member($result, 'structuredContent');
        if ($structured !== null) {
            $out[] = $structured;
        }

        $content = Emptiness::member($result, 'content');
        if (is_array($content)) {
            $parts = 0;
            foreach ($content as $part) {
                if ($parts >= self::MAX_CONTENT_PARTS) {
                    break;
                }
                ++$parts;

                if (Emptiness::member($part, 'type') !== 'text') {
                    continue;
                }
                $text = Emptiness::member($part, 'text');
                if (!is_string($text)) {
                    continue;
                }
                $decoded = json_decode($text, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $out[] = $decoded;
                }
                // Prose, not JSON. Nothing to walk.
            }
        }

        $out[] = $result;

        return $out;
    }
}

/**
 * Holds the most recent value of each declared field, and emits a verdict when a second tool
 * reports the same one inside the window.
 *
 * One per process rather than per call, for the reason the session id is: a request-per-process
 * runtime would otherwise never see two tools and could never compare anything. Under FPM that is
 * a real limit rather than a bug — see the README — and a long-lived worker gets the full check.
 */
final class AgreementTracker
{
    /** @var array<string, array{tool: string, value: mixed, at: float, matched: bool}> */
    private array $held = [];

    /** @var array<string, true> */
    private array $seen = [];

    /** @var array<string, int> */
    private array $unresolved = [];

    /** @var array<string, int> */
    private array $alone = [];

    /**
     * @param  array{by_tool: array<string, list<array{0: string, 1: string}>>, window_ms: int, tolerance: float, site_count: int}  $config
     * @param  callable(string): void  $log
     */
    public function __construct(
        private readonly array $config,
        private readonly string $sessionId,
        private readonly mixed $log,
    ) {
    }

    /**
     * Records what one tool returned, and compares it against what another returned earlier.
     *
     * Synchronous, and cheap enough to be: a handful of lookups per declared path. It runs after
     * the handler has already answered, so nothing a model waits on waits on this.
     *
     * @param  null|callable(array<string, mixed>): void  $emit
     */
    public function observe(string $tool, mixed $result, ?float $nowMs = null, ?callable $emit = null): void
    {
        $sites = $this->config['by_tool'][$tool] ?? null;
        if ($sites === null || $sites === []) {
            return;
        }

        $now = $nowMs ?? (microtime(true) * 1000);
        $candidates = Agree::roots($result);
        $log = $this->log;

        foreach ($sites as [$field, $path]) {
            $key = $field . '|' . $tool;

            $value = null;
            $found = false;
            foreach ($candidates as $root) {
                $hit = Agree::valueAt($root, $path, $resolved);
                if ($resolved === true) {
                    $value = $hit;
                    $found = true;

                    break;
                }
            }

            if (!$found) {
                // Counted rather than thrown, and named the first time. A declaration whose path
                // never resolves reads as perfect agreement otherwise — the failure mode where a
                // typo looks like a passing check.
                $this->unresolved[$key] = ($this->unresolved[$key] ?? 0) + 1;
                if ($this->unresolved[$key] === 1 && !isset($this->seen[$key])) {
                    $log(sprintf(
                        'agree: "%s" did not resolve in %s — declared but never observed',
                        $path,
                        $tool,
                    ));
                }

                continue;
            }

            $this->seen[$key] = true;
            unset($this->unresolved[$key]);

            $previous = $this->held[$field] ?? null;
            // This field's own window and tolerance, resolved at startup. A quote 30s old is fine
            // for a chat answer and fatal for a trade, and both can come off the same server
            // through the same SDK.
            [$windowMs, $tolerance] = $this->config['bars'][$field]
                ?? [$this->config['window_ms'], $this->config['tolerance']];

            $fresh = $previous !== null && ($now - $previous['at']) <= $windowMs;

            if ($previous !== null && !$fresh && !$previous['matched']) {
                // The window closed with one tool having answered and the other not. A low-traffic
                // tool can stay silently wrong for a long time this way, so it is counted rather
                // than passed over as a clean run.
                $this->alone[$field] = ($this->alone[$field] ?? 0) + 1;
                $log(sprintf(
                    'agree: "%s" observed from %s only in the last window',
                    $field,
                    $previous['tool'],
                ));
            }

            $compared = $fresh && $previous['tool'] !== $tool;
            if ($compared) {
                $agreed = Agree::agrees($previous['value'], $value, $tolerance);
                $this->held[$field]['matched'] = true;

                if ($emit !== null) {
                    $emit([
                        'v' => 1,
                        'type' => 'agreement',
                        'session_id' => $this->sessionId,
                        'field' => $field,
                        'tool_a' => $previous['tool'],
                        'tool_b' => $tool,
                        'agreed' => $agreed,
                        'checked_at' => self::iso($now),
                        // The window this verdict was actually reached under, not the global
                        // default — otherwise a per-field override is invisible downstream and a
                        // 30-second verdict reads as a five-minute one.
                        'window_ms' => $windowMs,
                    ]);
                }

                $log(sprintf(
                    'agree: %s %s (%s vs %s)',
                    $field,
                    $agreed ? 'agreed' : 'DIVERGED',
                    $previous['tool'],
                    $tool,
                ));
            }

            // Only a comparison against a *different* tool counts as matched; a tool refreshing its
            // own value has not been checked against anything.
            $this->remember($field, ['tool' => $tool, 'value' => $value, 'at' => $now, 'matched' => $compared]);
        }
    }

    /**
     * Counters, for tests and for anything reporting on a run.
     *
     * @return array{unresolved: array<string, int>, alone: array<string, int>, held: int}
     */

    /**
     * What was declared, for the startup payload.
     *
     * Taken from the resolved config rather than from the options, so a field that named only one
     * tool — and therefore watches nothing — is not reported as covered.
     *
     * @return list<array{field: string, tools: list<string>}>
     */
    public function declared(): array
    {
        return $this->config['declared'] ?? [];
    }

    public function stats(): array
    {
        return ['unresolved' => $this->unresolved, 'alone' => $this->alone, 'held' => count($this->held)];
    }

    /** @param  array{tool: string, value: mixed, at: float, matched: bool}  $entry */
    private function remember(string $field, array $entry): void
    {
        if (!isset($this->held[$field]) && count($this->held) >= Agree::MAX_FIELDS) {
            // Oldest first, the same rule the payload buffer sheds by.
            array_shift($this->held);
        }
        $this->held[$field] = $entry;
    }

    private static function iso(float $nowMs): string
    {
        $seconds = (int) floor($nowMs / 1000);
        $millis = (int) round($nowMs - ($seconds * 1000));

        return gmdate('Y-m-d\TH:i:s', $seconds) . sprintf('.%03dZ', $millis);
    }
}
