<?php

declare(strict_types=1);

namespace MCPulse;

/**
 * Tools a model is *right* not to call, declared so they stop being penalised.
 *
 * ## This is a sign error, not a gap
 *
 * Every other signal in this product measures something that is missing. This one corrects a
 * measurement that points the wrong way.
 *
 * A model can correctly decline a perfectly well-described tool because the user has not authorised
 * its side effect, or because the identity it needs is unverified. That is the system working.
 * Measured as selection rate it reads as poor discoverability, and the advice that follows —
 * "rewrite the description so it gets called more often" — makes the server *less safe*.
 *
 * ## The five stages, and which of them are observable
 *
 *     candidate -> eligible    policy filter. No schema change touches it.
 *     eligible  -> selected    discoverability. Everything else in the product.
 *     selected  -> attempted   arguments the model could not fill.
 *     attempted -> succeeded   the handler.
 *
 * Three of those four gaps are visible from inside the server. The first is not: the decision
 * happens in the client and the protocol reports nothing back about it. A tool that was filtered out
 * and a tool that was never chosen look identical from here, and no amount of instrumentation
 * changes that.
 *
 * So it is author-declared. That is a weaker kind of data than the rest of this package collects,
 * and it is the only kind available — the alternative is not better data, it is a metric that keeps
 * telling people to remove a safety check.
 */
final class Policy
{
    /**
     * The declaration for one tool, or `null` when it has none.
     *
     * `null` rather than a pair of falses, so "no policy" and "a policy that requires nothing" stay
     * distinguishable at ingest. Only the first excludes a tool from the discoverability metrics.
     *
     * Both spellings of each key are accepted. The docs are written in the TypeScript spelling, and
     * a PHP author writing snake_case is not making a mistake.
     *
     * @param  array<string, mixed>|null $policy
     * @return array{requires_authorization: bool, requires_verified_identity: bool}|null
     */
    public static function forTool(?array $policy, string $tool): ?array
    {
        if ($policy === null || !isset($policy[$tool]) || !is_array($policy[$tool])) {
            return null;
        }
        $declared = $policy[$tool];

        $authorization = ($declared['requiresAuthorization'] ?? $declared['requires_authorization'] ?? null) === true;
        $identity = ($declared['requiresVerifiedIdentity'] ?? $declared['requires_verified_identity'] ?? null) === true;

        // A declaration that requires nothing is a mistake rather than a request to exclude the
        // tool, and treating it as a policy would silently drop the tool out of every
        // discoverability figure the author is trying to read.
        if (!$authorization && !$identity) {
            return null;
        }

        return [
            'requires_authorization' => $authorization,
            'requires_verified_identity' => $identity,
        ];
    }
}
