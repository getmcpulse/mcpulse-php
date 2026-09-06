<?php

declare(strict_types=1);

namespace MCPulse;

/** Fingerprinting a call's arguments. */
final class Hashing
{
    /** What an argument set hashes to when it cannot be serialised at all. */
    public const UNHASHABLE = '000000000000';

    /**
     * A short, one-way fingerprint of a call's arguments.
     *
     * This is the only thing MCPulse ever learns about what was passed to a tool, and it is
     * deliberately not enough to learn anything: 12 hex characters of a SHA-256 over the RFC 8785
     * canonical form, with no way back. All the product asks of it is "were these two calls made
     * with the same arguments or different ones" — which is what separates a model retrying a
     * reworded request from a client paging through results.
     */
    public static function argsHash(mixed $args): string
    {
        // A tool that takes no arguments is called with `arguments` absent. That is an ordinary
        // call, not a failure, and it hashes as the empty object it is — otherwise every
        // no-argument tool shares one hash with every call whose arguments blew up.
        $value = $args ?? new \stdClass();

        try {
            return substr(hash('sha256', Canonical::canonicalize($value)), 0, 12);
        } catch (\Throwable) {
            // Arguments JSON cannot represent. The call still happened and still deserves a row;
            // it simply cannot be compared to another, so give it a constant that says exactly
            // that.
            return self::UNHASHABLE;
        }
    }

    /**
     * Hashes arguments still in their wire form.
     *
     * Decoding to objects rather than to associative arrays is load-bearing in PHP and nowhere
     * else: `json_decode($raw, true)` turns `{"0": "a"}` into `["a"]`, which is then
     * indistinguishable from the JSON array `["a"]` and hashes the same. Objects keep the two
     * apart, so a PHP server agrees with every other SDK on a case the associative form would get
     * silently wrong.
     */
    public static function argsHashRaw(string $raw): string
    {
        if ($raw === '') {
            return self::argsHash(null);
        }

        try {
            $decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return self::UNHASHABLE;
        }

        return self::argsHash($decoded);
    }

    /**
     * Identifies one run of the customer's server, so calls can be grouped and a cost-per-session
     * worked out.
     *
     * Random rather than derived — there is nothing about the process worth encoding here, and
     * anything derived from the machine would be an identifier we did not intend to collect.
     */
    public static function newSessionId(): string
    {
        try {
            return 's_' . bin2hex(random_bytes(6));
        } catch (\Throwable) {
            // The entropy source does not fail in practice, and a session with a constant id is
            // still better than a server that refuses to start over it.
            return 's_000000000000';
        }
    }
}
