<?php

declare(strict_types=1);

/**
 * The cross-language contract, plus the parts of the package that only PHP can get wrong.
 *
 * tests/canonical.json is the shared conformance suite, copied from
 * packages/schemas/fixtures in the mcpulse monorepo. Every other
 * MCPulse SDK runs the same file. If it passes in all of them, their hashes are interchangeable
 * and a customer running more than one sees one set of numbers rather than several.
 *
 * Never edit a fixture to make a failure go away — these hashes are in the product's history, and
 * rewriting one rewrites what every stored row means.
 *
 * A plain script rather than PHPUnit, so the suite runs with nothing but a PHP binary.
 */

require __DIR__ . '/../src/NotJsonException.php';
require __DIR__ . '/../src/Canonical.php';
require __DIR__ . '/../src/Hashing.php';
require __DIR__ . '/../src/Options.php';
require __DIR__ . '/../src/Sizes.php';
require __DIR__ . '/../src/Emptiness.php';
require __DIR__ . '/../src/Stem.php';
require __DIR__ . '/../src/Describe.php';
require __DIR__ . '/../src/Policy.php';
require __DIR__ . '/../src/Version.php';
require __DIR__ . '/../src/Agree.php';
require __DIR__ . '/../src/Transport.php';
require __DIR__ . '/../src/MCPulse.php';

use MCPulse\Agree;
use MCPulse\Describe;
use MCPulse\Policy;
use MCPulse\Stem;
use MCPulse\Version;
use MCPulse\AgreementTracker;
use MCPulse\Canonical;
use MCPulse\Emptiness;
use MCPulse\Hashing;
use MCPulse\MCPulse;
use MCPulse\Options;
use MCPulse\Sizes;

$passed = 0;
$failed = 0;

function check(string $what, mixed $expected, mixed $actual): void
{
    global $passed, $failed;

    if ($expected === $actual) {
        $passed++;

        return;
    }
    $failed++;
    printf("FAIL %s\n  want %s\n  got  %s\n", $what, var_export($expected, true), var_export($actual, true));
}

function refute(string $what, mixed $forbidden, mixed $actual): void
{
    global $passed, $failed;

    if ($forbidden !== $actual) {
        $passed++;

        return;
    }
    $failed++;
    printf("FAIL %s: got the forbidden value %s\n", $what, var_export($forbidden, true));
}

function checkThrows(string $what, callable $body): void
{
    global $passed, $failed;

    try {
        $body();
        $failed++;
        printf("FAIL %s: nothing was thrown\n", $what);
    } catch (\Throwable) {
        $passed++;
    }
}

// ─── The shared fixtures ─────────────────────────────────────────────────────

$file = json_decode(file_get_contents(__DIR__ . '/canonical.json'), false, 512, JSON_THROW_ON_ERROR);

check('algorithm is pinned', 'sha256/rfc8785/hex12', $file->algorithm);
check('wire version is pinned', 1, $file->wire_version);
check('the full suite is present', true, count($file->fixtures) >= 23);

foreach ($file->fixtures as $fixture) {
    check("canonical: {$fixture->name}", $fixture->canonical, Canonical::canonicalize($fixture->input));
    check("hash: {$fixture->name}", $fixture->args_hash, Hashing::argsHash($fixture->input));

    // Each fixture's hash must match its own canonical form, so a corrupted file is caught rather
    // than silently agreed with.
    check(
        "self-consistent: {$fixture->name}",
        $fixture->args_hash,
        substr(hash('sha256', $fixture->canonical), 0, 12),
    );
}

// ─── ECMAScript Number::toString ─────────────────────────────────────────────

$numbers = [
    ['1.0 loses the decimal', 1.0, '1'],
    ['negative zero', -0.0, '0'],
    ['2.5', 2.5, '2.5'],
    ['1e21', 1e21, '1e+21'],
    ['1e-7', 1e-7, '1e-7'],
    ['1e-6', 1e-6, '0.000001'],
    ['0.1', 0.1, '0.1'],
    ['min subnormal', 5e-324, '5e-324'],
    ['max double', 1.7976931348623157e308, '1.7976931348623157e+308'],
    ['-1.5e-9', -1.5e-9, '-1.5e-9'],
    ['2^53-1', 9007199254740991, '9007199254740991'],
    ['a million stays plain', 1000000, '1000000'],
    ['plain integers', 42, '42'],
];
foreach ($numbers as [$what, $value, $want]) {
    check("number: {$what}", $want, Canonical::canonicalize($value));
}

checkThrows('NAN is refused', static fn () => Canonical::canonicalize(NAN));
checkThrows('INF is refused', static fn () => Canonical::canonicalize(INF));

// ─── Strings and key order ───────────────────────────────────────────────────

check('non-ascii is literal', '"café"', Canonical::canonicalize('café'));
check('emoji is literal', '"🚀"', Canonical::canonicalize('🚀'));

// json_encode escapes both of these by default, and either alone would put every PHP server's
// hashes in a different bucket from every other SDK's.
check('html is not escaped', '"a<b>c&d"', Canonical::canonicalize('a<b>c&d'));
check('slashes are not escaped', '"a/b"', Canonical::canonicalize('a/b'));

check('short escapes', '"\b\t\n\f\r\"\\\\"', Canonical::canonicalize("\x08\t\n\x0c\r\"\\"));
check('other control chars', '"\u0000\u0001\u001f"', Canonical::canonicalize("\x00\x01\x1f"));

check(
    'sorts at every depth',
    '{"o":{"a":2,"z":1}}',
    Canonical::canonicalize((object) ['o' => (object) ['z' => 1, 'a' => 2]]),
);

// U+1F680 is the surrogate pair D83D DE80, so it sorts before U+FFFD. PHP's byte-order strcmp
// puts it after.
check(
    'utf-16 key order',
    '{"a":4,"é":3,"🚀":2,"' . "\u{FFFD}" . '":1}',
    Canonical::canonicalize((object) ["\u{FFFD}" => 1, '🚀' => 2, 'é' => 3, 'a' => 4]),
);

check('array order is left alone', '[2,1]', Canonical::canonicalize([2, 1]));

// PHP's one unique trap: json_decode(..., true) turns {"0":"a"} into ["a"], which is then
// indistinguishable from the JSON array ["a"]. Decoding to objects keeps them apart.
refute(
    'a numeric-keyed object is not a list',
    Hashing::argsHashRaw('["a"]'),
    Hashing::argsHashRaw('{"0":"a"}'),
);

// ─── argsHash ────────────────────────────────────────────────────────────────

// A no-argument tool is an ordinary call. Sharing the failure sentinel would make every such tool
// look broken.
check('absent args are {}', Hashing::argsHash(new stdClass()), Hashing::argsHash(null));
refute('absent args are not the sentinel', Hashing::UNHASHABLE, Hashing::argsHash(null));

check('NAN gets the sentinel', Hashing::UNHASHABLE, Hashing::argsHash((object) ['n' => NAN]));
check('bad JSON gets the sentinel', Hashing::UNHASHABLE, Hashing::argsHashRaw('not json'));
check('empty raw is absent args', Hashing::argsHash(null), Hashing::argsHashRaw(''));
check(
    'the raw and decoded paths agree',
    Hashing::argsHash((object) ['a' => 1, 'b' => 2]),
    Hashing::argsHashRaw('{"b":2,"a":1}'),
);

$hash = Hashing::argsHash((object) ['q' => 'anything']);
check('twelve characters', 12, strlen($hash));
check('lowercase hex', $hash, strtolower($hash));

$id = Hashing::newSessionId();
check('session id shape', true, str_starts_with($id, 's_') && strlen($id) === 14);
refute('session ids differ', $id, Hashing::newSessionId());

check('utf16 length counts code units', 4, Sizes::utf16Length('café'));
check('an emoji is two code units', 2, Sizes::utf16Length('🚀'));

// ─── Emptiness ───────────────────────────────────────────────────────────────

$textResult = static fn (string $value): array => [
    'content' => [['type' => 'text', 'text' => $value]],
];

check('null is empty', true, Emptiness::isEmptyResult(null));
check('no parts is empty', true, Emptiness::isEmptyResult(['content' => []]));
check('a serialised empty list is empty', true, Emptiness::isEmptyResult($textResult('[]')));
check('blank text is empty', true, Emptiness::isEmptyResult($textResult('   ')));
check('prose is not empty', false, Emptiness::isEmptyResult($textResult('no rows found')));

// 0 and false are results, not absences. Counting them as empty would report working tools as
// broken.
check('zero is an answer', false, Emptiness::isEmptyResult($textResult('0')));
check('false is an answer', false, Emptiness::isEmptyResult($textResult('false')));

// The {"result": …} envelope some SDKs add must not hide an empty answer.
check(
    'the result envelope is opened',
    true,
    Emptiness::isEmptyResult(['structuredContent' => ['result' => '[]']]),
);

// ─── Recording ───────────────────────────────────────────────────────────────

$recorded = [];
MCPulse::reset();
MCPulse::$sink = static function (array $payload) use (&$recorded): void {
    $recorded[] = $payload;
};
MCPulse::configure(new Options('mp_test_key', 'http://127.0.0.1:1'));

$result = MCPulse::record(
    'echo',
    (object) ['text' => 'sensitive-argument-value'],
    static fn () => $textResult('hello'),
    'test-client',
);

$calls = array_values(array_filter($recorded, static fn ($p) => $p['type'] === 'call'));
check('one call recorded', 1, count($calls));
check('tool name', 'echo', $calls[0]['tool_name']);
check('outcome', 'ok', $calls[0]['outcome']);
check('wire version', 1, $calls[0]['v']);
check('client name', 'test-client', $calls[0]['client_name']);
check('result passes through', 'hello', $result['content'][0]['text']);
check('is_empty', false, $calls[0]['is_empty']);
check('args_hash is twelve characters', 12, strlen($calls[0]['args_hash']));
check(
    'no argument value on the wire',
    false,
    str_contains(json_encode($recorded), 'sensitive-argument-value'),
);

// Argument order must not change the hash.
$recorded = [];
MCPulse::record('two', (object) ['a' => 1, 'b' => 2], static fn () => $textResult('x'));
MCPulse::record('two', (object) ['b' => 2, 'a' => 1], static fn () => $textResult('x'));
check('reordered arguments hash the same', $recorded[0]['args_hash'], $recorded[1]['args_hash']);

// A throwing handler is crashed, and the exception still reaches the server.
$recorded = [];
$rethrown = false;
try {
    MCPulse::record('explode', null, static function (): never {
        throw new RuntimeException('boom');
    });
} catch (RuntimeException) {
    $rethrown = true;
}
check('the exception still reaches the server', true, $rethrown);
check('outcome is crashed', 'crashed', $recorded[0]['outcome']);

// An isError result is a tool error, not a crash.
$recorded = [];
MCPulse::record('failing', null, static fn () => ['content' => [], 'isError' => true]);
check('outcome is tool_error', 'tool_error', $recorded[0]['outcome']);

// An empty answer is flagged.
$recorded = [];
MCPulse::record('nothing', null, static fn () => $textResult('[]'));
check('an empty result is flagged', true, $recorded[0]['is_empty']);

// Startup, once.
$recorded = [];
MCPulse::recordStartup([(object) ['name' => 'echo', 'inputSchema' => (object) ['type' => 'object']]], 'test-client');
MCPulse::recordStartup([(object) ['name' => 'echo']]);
check('startup is sent once', 1, count($recorded));
check('startup type', 'startup', $recorded[0]['type']);
check('schema_bytes is measured', true, $recorded[0]['tools'][0]['schema_bytes'] > 0);

// Two configurations for the same destination are one session, not two.
//
// The bug this guards against is invisible in a stdio server and fatal in an HTTP one: a server
// configured per request would open a session per request, so a retry could never be detected and
// first-call success would report a perfect score however badly the server was doing.
MCPulse::configure(new Options('mp_test_key', 'http://127.0.0.1:1'));
$firstSession = MCPulse::sessionId();
MCPulse::configure(new Options('mp_test_key', 'http://127.0.0.1:1'));
check('the same destination is one session', $firstSession, MCPulse::sessionId());
check('the session is a real id', true, is_string($firstSession) && str_starts_with($firstSession, 's_'));

// Configured off means nothing is recorded, and the handler still runs.
$recorded = [];
MCPulse::reset();
MCPulse::configure(new Options(''));
check('a disabled handler still runs', 'hello', MCPulse::record('echo', null, static fn () => $textResult('hello'))['content'][0]['text']);
check('an empty key records nothing', 0, count($recorded));

MCPulse::$sink = null;
MCPulse::reset();

// ── Cross-tool agreement ─────────────────────────────────────────────────────
//
// The failure under test is the one every other metric in this package reports as healthy: two
// tools return the same underlying field, they disagree, and the calls all succeed with non-empty
// results and no retries. Nothing else here can see it, so nothing else here can catch a regression
// in it.
//
// Two properties are load-bearing and both are asserted rather than assumed. **Representation must
// not be a divergence** — 25.22, 25.220 and "25.22" are one number, and the design exists because
// hashing gets that wrong. And **no value may leave the process**: a verdict that carried the
// figure would be the third rule broken by the feature written to respect it.
//
// These mirror tests/agree.test.ts in the TypeScript SDK. The verdict is a shared wire contract, so
// a customer running two languages has to get the same answer from both.

$silent = static function (string $message): void {};

$declaration = [
    'global_liquidity' => [
        ['tool' => 'getGlobalLiquidity', 'path' => 'globalLiquidity.value_t'],
        ['tool' => 'getPillars', 'path' => 'pillars.global_liquidity.value'],
    ],
];

$trackerFor = static function (?array $decl = null, ?float $window = null, ?float $tolerance = null) use ($declaration, $silent): AgreementTracker {
    $config = Agree::resolve($decl ?? $declaration, $window, $tolerance, $silent);

    return new AgreementTracker($config, 's_7f2a91', $silent);
};

// A result shaped the way most servers answer: JSON inside a text part.
$textPayload = static fn (array $value): array => ['content' => [['type' => 'text', 'text' => json_encode($value)]]];

$observe = static function (AgreementTracker $tracker, string $tool, mixed $result, float $at): array {
    $out = [];
    $tracker->observe($tool, $result, $at, static function (array $verdict) use (&$out): void {
        $out[] = $verdict;
    });

    return $out;
};

$tol = Agree::TOLERANCE;

// The whole reason the comparison is in-process and not at the API: each of these hashes
// differently, so a hash-based check reports three divergences where there is none.
check('25.22 equals itself', true, Agree::agrees(25.22, 25.22, $tol));
check('25.22 equals 25.22000', true, Agree::agrees(25.22, 25.22000, $tol));
check('25.22 equals "25.22"', true, Agree::agrees(25.22, '25.22', $tol));
check('"25.220" equals 25.22', true, Agree::agrees('25.220', 25.22, $tol));

check('a float inside tolerance agrees', true, Agree::agrees(1000.0, 1000.00001, $tol));
check('a float outside tolerance does not', false, Agree::agrees(1000.0, 1002.5, $tol));

// A count that is off by one is off by one. Rounding it into agreement would hide the only kind of
// disagreement a count can have.
check('integers compare exactly', false, Agree::agrees(1000000, 1000001, $tol));
check('42 equals "42"', true, Agree::agrees(42, '42', $tol));

check('identical strings agree', true, Agree::agrees('risk-on', 'risk-on', $tol));
check('case differs, so they differ', false, Agree::agrees('risk-on', 'Risk-On', $tol));
check('"" is not 0', false, Agree::agrees('', 0, $tol));
// PHP reads `true` as 1 in almost every numeric context. A flag from one tool must not agree with
// a count of one from another, which is a mistake only this language and Ruby can make.
check('a flag is not a count', false, Agree::agrees(true, 1, $tol));
// A cast would read "12abc" as 12 and agree with a real 12 from the other tool.
check('a half-numeric string is not a number', false, Agree::agrees('12abc', 12, $tol));
check('key order is not a divergence', true, Agree::agrees(['a' => 1, 'b' => 2], ['b' => 2, 'a' => 1], $tol));

check('nothing declared is nothing watched', null, Agree::resolve(null, null, null, $silent));
check('an empty declaration is nothing watched', null, Agree::resolve([], null, null, $silent));

// One tool cannot disagree with itself, so a field named once has no comparison in it.
check('a field only one tool declares', null, Agree::resolve(
    ['global_liquidity' => [['tool' => 'getGlobalLiquidity', 'path' => 'globalLiquidity.value_t']]],
    null,
    null,
    $silent,
));

$tracker = $trackerFor();
check('one tool alone emits nothing', 0, count($observe(
    $tracker,
    'getGlobalLiquidity',
    $textPayload(['globalLiquidity' => ['value_t' => 25.22]]),
    1000,
)));

$verdicts = $observe(
    $tracker,
    'getPillars',
    $textPayload(['pillars' => ['global_liquidity' => ['value' => '25.220']]]),
    2000,
);
check('a verdict is emitted', 1, count($verdicts));
check('it agrees across representations', true, $verdicts[0]['agreed']);
check('it names both tools', ['getGlobalLiquidity', 'getPillars'], [$verdicts[0]['tool_a'], $verdicts[0]['tool_b']]);
check('it carries the window', 300000, $verdicts[0]['window_ms']);
$keys = array_keys($verdicts[0]);
sort($keys);
check('the verdict has exactly these keys', ['agreed', 'checked_at', 'field', 'session_id', 'tool_a', 'tool_b', 'type', 'v', 'window_ms'], $keys);

$tracker = $trackerFor();
$observe($tracker, 'getGlobalLiquidity', $textPayload(['globalLiquidity' => ['value_t' => 25.22]]), 1000);
$diverged = $observe($tracker, 'getPillars', $textPayload(['pillars' => ['global_liquidity' => ['value' => 24.01]]]), 2000);
check('a genuine divergence is reported', false, $diverged[0]['agreed']);

// No value, no difference, no hash of a value.
$wire = json_encode($diverged);
check('the verdict leaks no value', false, str_contains($wire, '25.22') || str_contains($wire, '24.01'));
check('nor the difference', false, str_contains($wire, '1.21'));

// Same declaration, structured output instead of a JSON text part. The author should not have to
// remember which envelope they used.
$tracker = $trackerFor();
$observe($tracker, 'getGlobalLiquidity', ['structuredContent' => ['globalLiquidity' => ['value_t' => 25.22]]], 1000);
$structured = $observe($tracker, 'getPillars', ['structuredContent' => ['pillars' => ['global_liquidity' => ['value' => 25.22]]]], 2000);
check('structured output resolves the same paths', true, count($structured) === 1 && $structured[0]['agreed']);

$tracker = $trackerFor([
    'vix' => [
        ['tool' => 'getVix', 'path' => 'rows[0].value'],
        ['tool' => 'getPillars', 'path' => 'pillars.vix.value'],
    ],
]);
$observe($tracker, 'getVix', $textPayload(['rows' => [['value' => 14.2]]]), 1000);
$indexed = $observe($tracker, 'getPillars', $textPayload(['pillars' => ['vix' => ['value' => 14.2]]]), 2000);
check('an index in a path resolves', true, count($indexed) === 1 && $indexed[0]['agreed']);

// A low-traffic sibling can leave a field silently wrong for a long time. Silence here is correct,
// and is also why that case is counted rather than passed over as a clean run.
$tracker = $trackerFor();
$observe($tracker, 'getGlobalLiquidity', $textPayload(['globalLiquidity' => ['value_t' => 25.22]]), 1000);
$alone = $observe($tracker, 'getGlobalLiquidity', $textPayload(['globalLiquidity' => ['value_t' => 99.9]]), 2000);
check('one tool cannot disagree with itself', 0, count($alone));

// Two observations either side of a refresh are two different figures. Comparing them would report
// every refresh as a divergence.
$tracker = $trackerFor(null, 1000);
$observe($tracker, 'getGlobalLiquidity', $textPayload(['globalLiquidity' => ['value_t' => 25.22]]), 1000);
$expired = $observe($tracker, 'getPillars', $textPayload(['pillars' => ['global_liquidity' => ['value' => 24.01]]]), 6000);
check('an expired window emits nothing', 0, count($expired));
check('the lonely window is counted', 1, $tracker->stats()['alone']['global_liquidity']);

$tracker = $trackerFor(null, 60000);
$observe($tracker, 'getGlobalLiquidity', $textPayload(['globalLiquidity' => ['value_t' => 25.22]]), 1000);
$inside = $observe($tracker, 'getPillars', $textPayload(['pillars' => ['global_liquidity' => ['value' => 24.01]]]), 2000);
check('a shortened window still compares inside itself', 60000, $inside[0]['window_ms']);

// A typo'd declaration produces no verdict rather than a false clean one, and says so where someone
// can find it.
$tracker = $trackerFor([
    'global_liquidity' => [
        ['tool' => 'getGlobalLiquidity', 'path' => 'globalLiquidity.typo_here'],
        ['tool' => 'getPillars', 'path' => 'pillars.global_liquidity.value'],
    ],
]);
$observe($tracker, 'getGlobalLiquidity', $textPayload(['globalLiquidity' => ['value_t' => 25.22]]), 1000);
$missed = $observe($tracker, 'getPillars', $textPayload(['pillars' => ['global_liquidity' => ['value' => 24.01]]]), 2000);
check('an unresolved path emits nothing', 0, count($missed));
check('and is counted', 1, $tracker->stats()['unresolved']['global_liquidity|getGlobalLiquidity']);

// Every one of these is wrong in a different way, and none of them may stop a server booting.
$messy = Agree::resolve([
    'a' => [],
    'b' => [['tool' => 'getPillars']],
    'global_liquidity' => $declaration['global_liquidity'],
], null, null, $silent);
check('a malformed declaration keeps the usable part', 2, $messy['site_count']);
$watched = array_keys($messy['by_tool']);
sort($watched);
check('and watches only the two real tools', ['getGlobalLiquidity', 'getPillars'], $watched);

// ─── The stemmer and the normalised description hash ────────────────────────
//
// There are ten MCPulse SDKs, and a description hashed in a PHP server has to equal the same
// description hashed in a Go one — otherwise one tool looks like two the moment a customer runs a
// polyglot fleet, and the churn timeline fills with changes that never happened.
//
// Both halves are pinned by fixtures rather than by prose. Never regenerate either to make a
// failing check pass: these hashes are in the product's history, and rewriting them rewrites what
// every stored row means.
echo "\n── the stemmer matches every other SDK\n";

$stemFixture = json_decode((string) file_get_contents(__DIR__ . '/fixtures/stem.json'), true);
$wrong = [];
foreach ($stemFixture['cases'] as $word => $want) {
    if (Stem::of((string) $word) !== $want) {
        $wrong[] = (string) $word;
    }
}
check('all 13,160 fixture words agree with scripts/stem.py', [], array_slice($wrong, 0, 5));
check('the fixture has not shrunk', true, count($stemFixture['cases']) > 13000);

// analyze_job_description: "…what a job posting actually screens on."
// optimize_resume:         "…so it passes ATS screening."
check('screens and screening are one word', Stem::of('screening'), Stem::of('screens'));
check('and that word is screen', 'screen', Stem::of('screening'));
// Porter reads the trailing s as a plural and returns "at", which is also a stopword — so the
// default would delete the most diagnostic noun on a resume server rather than sharpen it.
check('three-letter acronyms survive', 'ats', Stem::of('ats'));

echo "\n── the normalised description hash matches every other SDK\n";

$normFixture = json_decode((string) file_get_contents(__DIR__ . '/fixtures/description_norm.json'), true);
$normWrong = [];
$hashWrong = [];
foreach ($normFixture['fixtures'] as $case) {
    if (Describe::normalise($case['description']) !== $case['normalised']) {
        $normWrong[] = $case['name'];
    }
    if (Describe::sha256_12($case['normalised']) !== $case['description_norm']) {
        $hashWrong[] = $case['name'];
    }
}
check('every conformance case normalises identically', [], $normWrong);
check('and hashes identically', [], $hashWrong);

// The reason a second hash exists at all. Exact hashing calls these two different, and they are not.
check(
    'the two descriptions that are one description',
    Describe::normalise("Search threads in a user's mailbox"),
    Describe::normalise("Search for threads in the user's mailbox")
);
// list and get are content on an MCP server, not filler. Dropping them would converge the two tools
// most worth telling apart.
check(
    'tools that merely share a verb stay apart',
    false,
    Describe::normalise('List the orders') === Describe::normalise('Get the order')
);

echo "\n── fingerprinting separates a wording change from an interface change\n";

$beforePrint = Describe::fingerprint("Search the threads in a user's mailbox.", ['type' => 'object']);
$afterPrint = Describe::fingerprint("Search threads in the user's mailbox.", ['type' => 'object']);
check('the schema hash holds', $beforePrint['schema_hash'], $afterPrint['schema_hash']);
check('the exact hash moves', false, $beforePrint['description_hash'] === $afterPrint['description_hash']);
// And the normalised hash says the description did not really change, which is what stops a
// reworded sentence showing up as churn worth investigating.
check('the normalised hash holds', $beforePrint['description_norm'], $afterPrint['description_norm']);

echo "\n── per-field windows and tolerances\n";

// The design partner's constraint: "a quote 30s old is fine for a chat answer, fatal for a trade."
$perField = Agree::resolve(
    ['global_liquidity' => ['sources' => $declaration['global_liquidity'], 'window_ms' => 30000]],
    300000,
    null,
    $silent
);
check('a field keeps its own window', 30000, $perField['bars']['global_liquidity'][0]);
check('and the default is untouched', 300000, $perField['window_ms']);
// Falls back one at a time: its own window, the default tolerance.
check('overrides fall back one at a time', Agree::TOLERANCE, $perField['bars']['global_liquidity'][1]);

$bare = Agree::resolve(['global_liquidity' => $declaration['global_liquidity']], 42000, null, $silent);
check('the bare-list form still works', 42000, $bare['bars']['global_liquidity'][0]);

echo "\n── a declared policy is carried, and an empty one is not\n";

check(
    'a declared precondition survives',
    ['requires_authorization' => true, 'requires_verified_identity' => false],
    Policy::forTool(['delete_account' => ['requiresAuthorization' => true]], 'delete_account')
);
check(
    'the snake_case spelling works too',
    ['requires_authorization' => false, 'requires_verified_identity' => true],
    Policy::forTool(['wire' => ['requires_verified_identity' => true]], 'wire')
);
// A declaration that requires nothing is a mistake rather than a request to exclude the tool, and
// treating it as a policy would silently drop the tool out of every discoverability figure.
check('a policy that requires nothing is not a policy', null, Policy::forTool(['x' => []], 'x'));
check('an undeclared tool has no policy', null, Policy::forTool([], 'x'));

echo "\n── the transport vocabulary is small and fixed\n";
check('stdio', 'stdio', Version::transportOf('StdioServerTransport'));
check('streamable http', 'http', Version::transportOf('StreamableHttpTransport'));
check('sse', 'sse', Version::transportOf('SseServerTransport'));
// Anything unrecognised is excluded from the wire-mapping table rather than guessed at.
check('anything else is unknown', 'unknown', Version::transportOf('SomethingNew'));
check('and so is nothing', 'unknown', Version::transportOf(null));

echo "\n── the agreement declaration on the wire\n";

// Coverage without this can only say which tools produced a verdict. A field whose path is mistyped
// never produces one, so it reads as a check that keeps passing — the failure mode worth catching.
$declaredConfig = Agree::resolve(['global_liquidity' => $declaration['global_liquidity']], null, null, $silent);
check(
    'the declaration is rebuilt from what is watched',
    [['field' => 'global_liquidity', 'tools' => ['getGlobalLiquidity', 'getPillars']]],
    $declaredConfig['declared']
);

// A field naming one tool watches nothing, so reporting it would overstate what is checked.
$lonely = Agree::resolve(
    [
        'lonely' => [['tool' => 'getGlobalLiquidity', 'path' => 'a.b']],
        'global_liquidity' => $declaration['global_liquidity'],
    ],
    null,
    null,
    $silent
);
check(
    'a field only one tool declares is not reported as covered',
    ['global_liquidity'],
    array_column($lonely['declared'], 'field')
);

// A path describes the shape of a customer's results. Coverage does not need it.
check(
    'the declaration carries no paths',
    false,
    str_contains(json_encode($declaredConfig['declared']), 'globalLiquidity.value_t')
);

printf("\n%d passed, %d failed\n", $passed, $failed);
exit($failed > 0 ? 1 : 0);
