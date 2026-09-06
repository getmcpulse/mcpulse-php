<?php

declare(strict_types=1);

namespace MCPulse;

/**
 * Did this call succeed while returning nothing useful?
 *
 * This is the metric that catches the failures nobody reports: a search that finds no rows, a
 * lookup that misses, a query that comes back `[]`. The protocol calls all of those success, the
 * model gets nothing it can use, and the author never hears about it.
 *
 * Only ever asked of a call that already succeeded — an error has its own outcome and is not also
 * "empty".
 */
final class Emptiness
{
    public static function isEmptyResult(mixed $result): bool
    {
        if ($result === null) {
            return true;
        }

        $structured = self::member($result, 'structuredContent');
        if ($structured !== null) {
            $inner = self::unwrapResultEnvelope($structured);

            // What comes out of the envelope is whatever the tool returned. When that is a string
            // it gets the same reading a text part does.
            return is_string($inner) ? self::isHollowText($inner) : self::isHollow($inner);
        }

        $content = self::member($result, 'content');
        if (is_array($content) && array_is_list($content)) {
            return self::isEmptyContent($content);
        }

        // Not a tool result shape at all — judge the thing itself.
        return self::isHollow($result);
    }

    /**
     * Undoes a single-key `{"result": …}` wrapper.
     *
     * SDKs that derive an output schema from a handler's return type wrap a non-object return: a
     * tool that returns `"[]"` arrives as `{"result": "[]"}`. Judging the envelope would quietly
     * kill this metric — every result would be an object with one key, so nothing would ever be
     * empty, and the one thing is_empty exists to catch would never fire.
     */
    private static function unwrapResultEnvelope(mixed $structured): mixed
    {
        $map = $structured instanceof \stdClass ? (array) $structured : $structured;
        if (is_array($map) && count($map) === 1 && array_key_exists('result', $map)) {
            return $map['result'];
        }

        return $structured;
    }

    /**
     * MCP returns content as a list of parts.
     *
     * No parts is empty. One text part is the common case, and it is empty when the text is blank
     * or when the text is itself a serialised empty collection — `"[]"` is the single most common
     * way a tool says "nothing found" while reporting success.
     *
     * @param list<mixed> $content
     */
    private static function isEmptyContent(array $content): bool
    {
        if ($content === []) {
            return true;
        }
        if (count($content) > 1) {
            return false;
        }

        $part = $content[0];
        if (self::member($part, 'type') !== 'text') {
            return false;
        }

        $text = self::member($part, 'text');

        return is_string($text) && self::isHollowText($text);
    }

    private static function isHollowText(string $text): bool
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return true;
        }

        try {
            return self::isHollow(json_decode($trimmed, false, 512, JSON_THROW_ON_ERROR));
        } catch (\JsonException) {
            // Prose, not JSON. A tool that answers in a sentence has said something.
            return false;
        }
    }

    /** Empty list, empty object, blank string, or nothing at all. */
    private static function isHollow(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value)) {
            return trim($value) === '';
        }
        if (is_array($value)) {
            return $value === [];
        }
        if ($value instanceof \stdClass) {
            return (array) $value === [];
        }

        // A number or a boolean is an answer. 0 and false are results, not absences, and counting
        // them as empty would report working tools as broken.
        return false;
    }

    /** Reads a named member off an array, an stdClass, or an object with a property. */
    public static function member(mixed $value, string $name): mixed
    {
        if (is_array($value)) {
            return $value[$name] ?? null;
        }
        if ($value instanceof \stdClass) {
            return $value->{$name} ?? null;
        }
        if (is_object($value) && property_exists($value, $name)) {
            return $value->{$name};
        }

        return null;
    }
}
