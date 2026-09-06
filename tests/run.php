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
require __DIR__ . '/../src/Transport.php';
require __DIR__ . '/../src/MCPulse.php';

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

printf("\n%d passed, %d failed\n", $passed, $failed);
exit($failed > 0 ? 1 : 0);
