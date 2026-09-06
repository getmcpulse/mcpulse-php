<?php

declare(strict_types=1);

namespace MCPulse;

/** How MCPulse measures what a payload costs a context window. */
final class Sizes
{
    /**
     * The length of a UTF-8 string in UTF-16 code units.
     *
     * `response_bytes` and `schema_bytes` are, today, what JavaScript's `String.length` returns —
     * code units, not bytes. The fields are named for bytes and hold code units, so `"café"`
     * measures 4 and an emoji measures 2.
     *
     * That is a known wart in the wire format, and fixing it is a pending decision. Until it is
     * made, every port reproduces the TypeScript behaviour rather than each inventing its own,
     * because the whole value of these numbers is that they are comparable across a customer's
     * servers. When the wire fixes it, this method is the one place that changes.
     *
     * PHP is the language where this takes the most work: a PHP string is bytes, so it has to be
     * converted to UTF-16 to be counted at all.
     */
    public static function utf16Length(string $text): int
    {
        $converted = @iconv('UTF-8', 'UTF-16BE//IGNORE', $text);

        return $converted === false ? strlen($text) : intdiv(strlen($converted), 2);
    }
}
