<?php

declare(strict_types=1);

namespace MCPulse;

/**
 * What a tool's description and schema hash to, and why there are three hashes.
 *
 * ## The failure this catches
 *
 * From a 123-tool operator: descriptions churn more often and more silently than schemas. A schema
 * gets reviewed because it is an interface. The wording gets edited because it is "just wording" —
 * and then the call pattern moves and nothing in the diff explains why.
 *
 * ## Three hashes, three different questions
 *
 *     schema_hash       did the interface change?
 *     description_hash  did the wording change?  (exact)
 *     description_norm  is this the same description?  (normalised)
 *
 * The third exists because the second cannot answer it. "Search for threads in the user's mailbox"
 * and "Search threads in a user's mailbox" are one description and two exact hashes. Normalising —
 * lowercase, drop stopwords, stem, sort — collapses them to one.
 *
 * That turns the hash from a change detector into an identity, and an identity finds convergence as
 * well as churn: two tools on one server landing on the same normalised hash have arrived at the
 * same wording independently, which means their author could not find a way to distinguish them
 * either.
 *
 * ## No text leaves
 *
 * Hashes only. The startup payload has never carried a description and still does not.
 * Normalisation happens here, inside the customer's process, for that reason and no other — it
 * would be far easier to do server-side.
 */
final class Describe
{
    /** What a schema hashes to when it cannot be canonicalised at all. */
    public const UNFINGERPRINTABLE = '000000000000';

    /**
     * Words dropped before a description is normalised.
     *
     * Plain English glue only. It deliberately does not strip `list`, `get` or `search`: on an MCP
     * server those are the content, and dropping them would make `list_orders` and `get_orders`
     * converge on the same hash when what they share is precisely the thing worth telling apart.
     *
     * Fixed, and identical in all ten SDKs. Adding a word here changes what every stored hash
     * means, so it is pinned by `tests/fixtures/description_norm.json`.
     */
    private const STOP = 'a about above after again against all am an and any are aren as at be '
        . 'because been before being below between both but by can cannot could couldn did didn do '
        . 'does doesn doing don down during each few for from further had hadn has hasn have haven '
        . 'having he her here hers herself him himself his how i if in into is isn it its itself '
        . 'let me more most mustn my myself no nor not of off on once only or other ought our ours '
        . 'ourselves out over own same shan she should shouldn so some such than that the their '
        . 'theirs them themselves then there these they this those through to too under until up '
        . 'very was wasn we were weren what when where which while who whom why with won would '
        . 'wouldn you your yours yourself yourselves use used uses using via without will may '
        . 'might must shall';

    /** @var array<string, true>|null */
    private static ?array $stop = null;

    /** @return array<string, true> */
    private static function stop(): array
    {
        return self::$stop ??= array_fill_keys(explode(' ', self::STOP), true);
    }

    /**
     * ASCII-only lowercasing, on purpose.
     *
     * `mb_strtolower` is locale- and encoding-sensitive, and the equivalent in several of the ten
     * languages this has to agree across maps `I` to a dotless `ı` under a Turkish locale. A hash
     * that depends on the server's locale is a hash that changes when nothing changed. Only A-Z is
     * folded; every other byte is left exactly as it arrived.
     */
    private static function asciiLower(string $text): string
    {
        return strtr($text, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
    }

    /**
     * A description reduced to its content, in a fixed order.
     *
     * Lowercase, drop stopwords, stem, sort, join with one space. Sorting is what makes this an
     * identity rather than a fingerprint of word order — the two mailbox descriptions above differ
     * only in order and a filler word.
     *
     * Split on ASCII whitespace and ASCII punctuation, and nothing else: Unicode character classes
     * are spelled differently, and are subtly different, in every one of the ten languages, and
     * ASCII is the only definition that is unambiguously the same everywhere. A run of CJK text
     * becomes one token rather than several — a worse tokenisation than a language-aware one, and a
     * better hash than one that depends on which SDK observed it.
     */
    public static function normalise(string $description): string
    {
        $stop = self::stop();
        $parts = preg_split('/[\s!-\/:-@\[-`{-~]+/', self::asciiLower($description), -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false) {
            return '';
        }

        $kept = [];
        foreach ($parts as $raw) {
            // A single character is never content. The case that makes this worth a rule is the
            // apostrophe: `user's` splits on ASCII punctuation into `user` and `s`, and that `s`
            // would otherwise be the only thing separating "the user's mailbox" from "a user
            // mailbox".
            //
            // Counted in code POINTS. "🚀" is one code point, four UTF-8 bytes and two UTF-16
            // units, so a length in bytes here would keep a token that other SDKs drop — a
            // different hash for one description depending on which server saw it.
            if (mb_strlen($raw, 'UTF-8') < 2 || isset($stop[$raw])) {
                continue;
            }
            $word = Stem::of($raw);
            // A stopword's inflection is still a stopword: `used` is filler by the list and `using`
            // only by its stem.
            if (isset($stop[$word])) {
                continue;
            }
            $kept[] = $word;
        }

        sort($kept, SORT_STRING);

        return implode(' ', $kept);
    }

    /**
     * The three hashes for one tool. Every hash is the same 12 hex characters of SHA-256 the
     * argument hash uses.
     *
     * @return array{schema_hash: string, description_hash: string, description_norm: string}
     */
    public static function fingerprint(?string $description, mixed $inputSchema): array
    {
        $text = $description ?? '';

        try {
            $schemaHash = self::sha256_12(Canonical::canonicalize($inputSchema ?? new \stdClass()));
        } catch (\Throwable) {
            // A schema that will not serialise still ships to the model somehow, but it cannot be
            // compared with the one before it. A constant says exactly that, rather than a hash of
            // nothing that would read as "unchanged".
            $schemaHash = self::UNFINGERPRINTABLE;
        }

        return [
            'schema_hash' => $schemaHash,
            'description_hash' => self::sha256_12($text),
            'description_norm' => self::sha256_12(self::normalise($text)),
        ];
    }

    /** The first 12 hex characters of the SHA-256 of a UTF-8 string. */
    public static function sha256_12(string $text): string
    {
        return substr(hash('sha256', $text), 0, 12);
    }
}
