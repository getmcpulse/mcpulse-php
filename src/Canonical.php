<?php

declare(strict_types=1);

namespace MCPulse;

/**
 * JSON Canonicalization Scheme (RFC 8785).
 *
 * `argsHash` only means anything if every MCPulse SDK, in every language, turns the same arguments
 * into the same bytes. PHP's `json_encode` does not get there on its own: it escapes `/` and all
 * non-ASCII by default, it writes `1.0` where ECMAScript writes `1`, and it has no notion of key
 * ordering at all. Each of those silently sends the same call to a different bucket than the
 * TypeScript SDK would, and the first-call-success metric built on top becomes noise the moment a
 * customer runs both.
 *
 * So none of the serialisation below goes through `json_encode`. Every rule is spelled out, and
 * `tests/canonical.json` — the same file every other MCPulse SDK runs — is what holds this class
 * to them.
 *
 * PHP has one extra trap the others do not: an array is both a list and a map, and `["0" => 1]` is
 * indistinguishable from `[1]` once it has been through `json_decode(..., true)`. That is why the
 * decoding path uses objects for JSON objects — see {@see Hashing::argsHashRaw()}.
 */
final class Canonical
{
    private const MAX_DEPTH = 1000;

    private const SHORT_ESCAPES = [
        0x08 => '\\b',
        0x09 => '\\t',
        0x0A => '\\n',
        0x0C => '\\f',
        0x0D => '\\r',
        0x22 => '\\"',
        0x5C => '\\\\',
    ];

    /**
     * The RFC 8785 canonical JSON form of a value.
     *
     * @throws NotJsonException for anything JSON cannot represent.
     */
    public static function canonicalize(mixed $value): string
    {
        $out = '';
        self::write($out, $value, 0);

        return $out;
    }

    private static function write(string &$out, mixed $value, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            // PHP cannot detect a cycle through an object graph cheaply, so depth is what stops
            // it — and no real tool argument is a thousand levels deep.
            throw new NotJsonException('nested too deeply');
        }

        if ($value === null) {
            $out .= 'null';

            return;
        }

        if (is_bool($value)) {
            $out .= $value ? 'true' : 'false';

            return;
        }

        if (is_string($value)) {
            self::writeString($out, $value);

            return;
        }

        if (is_int($value) || is_float($value)) {
            self::writeNumber($out, (float) $value);

            return;
        }

        if (is_array($value)) {
            // array_is_list is the only honest way to tell PHP's two array shapes apart.
            if (array_is_list($value)) {
                self::writeArray($out, $value, $depth);
            } else {
                self::writeObject($out, $value, $depth);
            }

            return;
        }

        if ($value instanceof \stdClass) {
            self::writeObject($out, (array) $value, $depth);

            return;
        }

        if ($value instanceof \JsonSerializable) {
            self::write($out, $value->jsonSerialize(), $depth + 1);

            return;
        }

        throw new NotJsonException('cannot canonicalize ' . get_debug_type($value));
    }

    /** @param list<mixed> $items */
    private static function writeArray(string &$out, array $items, int $depth): void
    {
        $out .= '[';
        $first = true;
        foreach ($items as $item) {
            if (!$first) {
                $out .= ',';
            }
            $first = false;
            self::write($out, $item, $depth + 1);
        }
        $out .= ']';
    }

    /** @param array<array-key, mixed> $map */
    private static function writeObject(string &$out, array $map, int $depth): void
    {
        $keys = [];
        foreach (array_keys($map) as $key) {
            // PHP silently turns a numeric string key into an int. Casting back is correct rather
            // than lossy: the key came from JSON as a string and goes back out as one.
            $keys[] = (string) $key;
        }

        usort($keys, self::compareUtf16(...));

        $out .= '{';
        $first = true;
        foreach ($keys as $key) {
            if (!$first) {
                $out .= ',';
            }
            $first = false;
            self::writeString($out, $key);
            $out .= ':';
            // The lookup uses the original key shape, which PHP may hold as an int.
            $original = array_key_exists($key, $map) ? $key : (int) $key;
            self::write($out, $map[$original], $depth + 1);
        }
        $out .= '}';
    }

    /**
     * Orders two strings by their UTF-16 code units, as RFC 8785 §3.2.3 asks.
     *
     * PHP's `strcmp` compares bytes, which is UTF-8 order. The two agree for everything in the
     * Basic Multilingual Plane and disagree above it: U+1F680 encodes as the surrogate pair
     * D83D DE80, which sorts *before* U+FFFD in UTF-16 and *after* it in UTF-8.
     */
    private static function compareUtf16(string $left, string $right): int
    {
        // Fast path: pure ASCII keys, which is every key any real tool has, order the same either
        // way and never need re-encoding.
        if (self::isAscii($left) && self::isAscii($right)) {
            return strcmp($left, $right);
        }

        return strcmp(self::toUtf16Be($left), self::toUtf16Be($right));
    }

    private static function isAscii(string $text): bool
    {
        return $text === '' || !preg_match('/[\x80-\xFF]/', $text);
    }

    private static function toUtf16Be(string $text): string
    {
        // UTF-16BE compares bytewise in exactly code-unit order, which is what JCS wants.
        $converted = @iconv('UTF-8', 'UTF-16BE//IGNORE', $text);

        return $converted === false ? $text : $converted;
    }

    // ─── Strings ─────────────────────────────────────────────────────────────

    /**
     * A JSON string per JCS §3.2.2.2, which is ECMAScript's escaping: the short escapes where one
     * exists, lowercase `\u00xx` for the rest of the C0 range, and nothing else touched.
     *
     * In particular `/` and every non-ASCII character are written literally. `json_encode` escapes
     * both by default, and either alone would put every PHP server's hashes in a different bucket
     * from every other SDK's.
     */
    private static function writeString(string &$out, string $text): void
    {
        $out .= '"';

        $length = strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $byte = ord($text[$i]);

            if (isset(self::SHORT_ESCAPES[$byte])) {
                $out .= self::SHORT_ESCAPES[$byte];
                continue;
            }

            if ($byte < 0x20) {
                $out .= sprintf('\\u%04x', $byte);
                continue;
            }

            // Everything else, including every byte of a multi-byte UTF-8 sequence, is copied
            // through untouched.
            $out .= $text[$i];
        }

        $out .= '"';
    }

    // ─── Numbers ─────────────────────────────────────────────────────────────

    /**
     * ECMAScript `Number::toString`, which JCS §3.2.2.3 defers to.
     *
     * PHP's own formatting is governed by the `serialize_precision` ini setting and writes `1.0E+21`
     * where ECMAScript writes `1e+21`. Neither is something to leave to a customer's php.ini.
     *
     * Every number is treated as an IEEE-754 double, including PHP's `int`: RFC 8785 limits JSON to
     * double precision, and matching JavaScript is the entire point. An integer past 2^53 therefore
     * loses precision here exactly as it would there.
     */
    private static function writeNumber(string &$out, float $value): void
    {
        if (is_nan($value) || is_infinite($value)) {
            // Not JSON. Coercing to null the way some encoders do would hand two genuinely
            // different calls the same hash.
            throw new NotJsonException('non-finite number');
        }

        if ($value === 0.0) {
            // Covers -0.0, which JCS writes as "0".
            $out .= '0';

            return;
        }

        if ($value < 0) {
            $out .= '-';
            $value = -$value;
        }

        [$digits, $n] = self::shortest($value);
        $k = strlen($digits);

        // The five cases of ECMAScript Number::toString, in its own order.
        if ($k <= $n && $n <= 21) {
            $out .= $digits . str_repeat('0', $n - $k);
        } elseif ($n > 0 && $n <= 21) {
            $out .= substr($digits, 0, $n) . '.' . substr($digits, $n);
        } elseif ($n > -6 && $n <= 0) {
            $out .= '0.' . str_repeat('0', -$n) . $digits;
        } else {
            $exponent = $n - 1;
            $out .= $k === 1 ? $digits : $digits[0] . '.' . substr($digits, 1);
            $out .= 'e' . ($exponent >= 0 ? '+' : '-') . abs($exponent);
        }
    }

    /**
     * Decomposes a positive finite double into its shortest round-tripping digits and the position
     * of the decimal point: the value is `digits * 10^(n - strlen(digits))`.
     *
     * Written as a search rather than read off a formatter, because PHP's default float printing
     * depends on the `serialize_precision` ini setting — a customer could change it and quietly
     * change every hash their server produces. Increasing the precision until the value
     * round-trips is exact regardless of how their php.ini is written.
     *
     * @return array{0: string, 1: int}
     */
    private static function shortest(float $value): array
    {
        // The counter is digits *after* the point, so it starts at zero — one significant digit.
        // Starting at one would skip that case, and the shortest form of the smallest subnormal
        // really is a single digit: 5e-324 round-trips, and so does the longer 4.9e-324, so the
        // search would settle on the wrong one and disagree with every other SDK.
        for ($afterPoint = 0; $afterPoint <= 16; $afterPoint++) {
            $formatted = sprintf('%.' . $afterPoint . 'e', $value);
            if ((float) $formatted === $value) {
                return self::decompose($formatted);
            }
        }

        return self::decompose(sprintf('%.16e', $value));
    }

    /** @return array{0: string, 1: int} */
    private static function decompose(string $formatted): array
    {
        [$mantissa, $exponentText] = explode('e', $formatted, 2);
        $exponent = (int) $exponentText;

        $parts = explode('.', $mantissa, 2);
        $integerPart = $parts[0];
        $digits = $integerPart . ($parts[1] ?? '');

        // The mantissa always has exactly one digit before the point, so the decimal point sits
        // one place further right than the exponent says.
        $n = $exponent + strlen($integerPart);

        $trimmedStart = ltrim($digits, '0');
        $n -= strlen($digits) - strlen($trimmedStart);
        $digits = rtrim($trimmedStart, '0');

        return $digits === '' ? ['0', 1] : [$digits, $n];
    }
}
