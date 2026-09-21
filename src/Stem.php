<?php

declare(strict_types=1);

namespace MCPulse;

/**
 * The Porter stemmer, for one job: deciding whether two tool descriptions are the same description.
 *
 * ## Why this is in an analytics SDK at all
 *
 * The startup payload carries a hash of each tool's description so churn can be tracked without the
 * text ever leaving the customer's process. An exact hash answers "did this change". It does not
 * answer "is this the same description", and those are different questions:
 *
 *     "Search for threads in the user's mailbox"
 *     "Search threads in a user's mailbox"
 *
 * Two exact hashes, one description. So a second, normalised hash is sent alongside: lowercased,
 * stopwords dropped, stemmed, tokens sorted. Two tools on one server landing on the same normalised
 * hash have converged on the same wording independently — which means their author could not find a
 * way to tell them apart either, and that is the strongest signal of a real collision this package
 * can produce.
 *
 * ## Why it is written out rather than installed
 *
 * There are ten MCPulse SDKs and the normalised hash has to be the same hash in all of them, or one
 * description reported from a PHP server and a Go one looks like two. So there is one algorithm,
 * written ten times, held together by `tests/fixtures/stem.json` — the same fixture the schema
 * study and the web checker are held to.
 *
 * Porter (1980) as published, with one deviation: words of three letters or fewer are left alone,
 * because MCP schemas are full of three-letter acronyms and Porter reads their trailing `s` as a
 * plural (`ats` -> `at`, `ids` -> `id`).
 */
final class Stem
{
    /** Below this length a word is returned unchanged. See the class comment. */
    public const MIN_STEM_LENGTH = 4;

    /**
     * Words already stemmed. Porter is pure, so every answer after the first is free, and the
     * startup path stems every word of every description. Cleared wholesale at the cap rather than
     * evicted one at a time: the input is a customer's tool list, not an unbounded stream.
     *
     * @var array<string, string>
     */
    private static array $cache = [];

    private const CACHE_LIMIT = 50000;

    /**
     * First matching rule wins, longest suffix first.
     *
     * Ordered by length rather than left in Porter's letter-switched order, which is an
     * implementation detail of his C rather than part of the algorithm. Longest-first gives the
     * same answers — `ization` before `ation`, `ement` before `ment` before `ent` — and it is the
     * one property the nine other implementations have to reproduce.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const STEP2 = [
        ['ational', 'ate'], ['ization', 'ize'], ['iveness', 'ive'], ['fulness', 'ful'],
        ['ousness', 'ous'], ['tional', 'tion'], ['biliti', 'ble'], ['alism', 'al'],
        ['aliti', 'al'], ['ation', 'ate'], ['entli', 'ent'], ['iviti', 'ive'],
        ['ousli', 'ous'], ['abli', 'able'], ['alli', 'al'], ['anci', 'ance'],
        ['ator', 'ate'], ['enci', 'ence'], ['izer', 'ize'], ['eli', 'e'],
    ];

    /** @var list<array{0: string, 1: string}> */
    private const STEP3 = [
        ['alize', 'al'], ['ative', ''], ['icate', 'ic'], ['iciti', 'ic'],
        ['ical', 'ic'], ['ness', ''], ['ful', ''],
    ];

    /** @var list<array{0: string, 1: string}> */
    private const STEP4 = [
        ['ement', ''], ['able', ''], ['ance', ''], ['ence', ''], ['ible', ''], ['ment', ''],
        ['ant', ''], ['ate', ''], ['ent', ''], ['ism', ''], ['iti', ''], ['ive', ''],
        ['ize', ''], ['ous', ''], ['al', ''], ['er', ''], ['ic', ''], ['ou', ''],
    ];

    /** Reduces a word to its stem. Lowercase in, lowercase out. */
    public static function of(string $word): string
    {
        if (isset(self::$cache[$word])) {
            return self::$cache[$word];
        }

        $result = self::porter($word);
        if (count(self::$cache) >= self::CACHE_LIMIT) {
            self::$cache = [];
        }
        self::$cache[$word] = $result;

        return $result;
    }

    /** Porter's definition: not a vowel, and not a `y` preceded by a consonant. */
    private static function consonant(string $word, int $i): bool
    {
        $letter = $word[$i];
        if ($letter === 'a' || $letter === 'e' || $letter === 'i' || $letter === 'o' || $letter === 'u') {
            return false;
        }
        if ($letter === 'y') {
            return $i === 0 || !self::consonant($word, $i - 1);
        }

        return true;
    }

    /** `m` — the number of vowel-consonant sequences in [C](VC)^m[V]. */
    private static function measure(string $stem): int
    {
        $m = 0;
        $i = 0;
        $n = strlen($stem);

        while ($i < $n && self::consonant($stem, $i)) {
            $i++;
        }
        while ($i < $n) {
            while ($i < $n && !self::consonant($stem, $i)) {
                $i++;
            }
            if ($i === $n) {
                break;
            }
            $m++;
            while ($i < $n && self::consonant($stem, $i)) {
                $i++;
            }
        }

        return $m;
    }

    private static function hasVowel(string $stem): bool
    {
        for ($i = 0, $n = strlen($stem); $i < $n; $i++) {
            if (!self::consonant($stem, $i)) {
                return true;
            }
        }

        return false;
    }

    private static function doubleConsonant(string $stem): bool
    {
        $n = strlen($stem);

        return $n >= 2 && $stem[$n - 1] === $stem[$n - 2] && self::consonant($stem, $n - 1);
    }

    /** Ends consonant-vowel-consonant, the last not `w`, `x` or `y`. */
    private static function cvc(string $stem): bool
    {
        $n = strlen($stem);
        if ($n < 3) {
            return false;
        }

        return self::consonant($stem, $n - 3)
            && !self::consonant($stem, $n - 2)
            && self::consonant($stem, $n - 1)
            && strpos('wxy', $stem[$n - 1]) === false;
    }

    /** @param list<array{0: string, 1: string}> $rules */
    private static function replace(string $word, array $rules, int $minMeasure): string
    {
        foreach ($rules as [$suffix, $replacement]) {
            if (!str_ends_with($word, $suffix)) {
                continue;
            }
            $stem = substr($word, 0, strlen($word) - strlen($suffix));

            return self::measure($stem) > $minMeasure ? $stem . $replacement : $word;
        }

        return $word;
    }

    private static function porter(string $word): string
    {
        if (strlen($word) < self::MIN_STEM_LENGTH) {
            return $word;
        }

        // 1a: plurals.
        if (str_ends_with($word, 'sses')) {
            $word = substr($word, 0, -2);
        } elseif (str_ends_with($word, 'ies')) {
            $word = substr($word, 0, -2);
        } elseif (str_ends_with($word, 'ss')) {
            // kept whole
        } elseif (str_ends_with($word, 's')) {
            $word = substr($word, 0, -1);
        }

        // 1b: past tense and gerunds.
        $stripped = false;
        if (str_ends_with($word, 'eed')) {
            if (self::measure(substr($word, 0, -3)) > 0) {
                $word = substr($word, 0, -1);
            }
        } elseif (str_ends_with($word, 'ed') && self::hasVowel(substr($word, 0, -2))) {
            $word = substr($word, 0, -2);
            $stripped = true;
        } elseif (str_ends_with($word, 'ing') && self::hasVowel(substr($word, 0, -3))) {
            $word = substr($word, 0, -3);
            $stripped = true;
        }

        if ($stripped) {
            if (str_ends_with($word, 'at') || str_ends_with($word, 'bl') || str_ends_with($word, 'iz')) {
                $word .= 'e';
            } elseif (self::doubleConsonant($word) && strpos('lsz', $word[strlen($word) - 1]) === false) {
                $word = substr($word, 0, -1);
            } elseif (self::measure($word) === 1 && self::cvc($word)) {
                $word .= 'e';
            }
        }

        // 1c: terminal y.
        if (str_ends_with($word, 'y') && self::hasVowel(substr($word, 0, -1))) {
            $word = substr($word, 0, -1) . 'i';
        }

        $word = self::replace($word, self::STEP2, 0);
        $word = self::replace($word, self::STEP3, 0);

        // 4: the suffix comes off entirely, and `ion` needs a stem to hang on.
        if (str_ends_with($word, 'ion')) {
            $base = substr($word, 0, -3);
            if (self::measure($base) > 1 && (str_ends_with($base, 's') || str_ends_with($base, 't'))) {
                $word = $base;
            } else {
                $word = self::replace($word, self::STEP4, 1);
            }
        } else {
            $word = self::replace($word, self::STEP4, 1);
        }

        // 5a: terminal e.
        if (str_ends_with($word, 'e')) {
            $without = substr($word, 0, -1);
            $m = self::measure($without);
            if ($m > 1 || ($m === 1 && !self::cvc($without))) {
                $word = $without;
            }
        }

        // 5b: doubled l.
        if (self::measure($word) > 1 && self::doubleConsonant($word) && str_ends_with($word, 'l')) {
            $word = substr($word, 0, -1);
        }

        return $word;
    }
}
