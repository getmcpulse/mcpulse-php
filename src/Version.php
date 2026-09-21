<?php

declare(strict_types=1);

namespace MCPulse;

/**
 * Who is reporting, and over what.
 *
 * ## Why the wire-mapping table needs this
 *
 * MCPulse instruments the server layer, not the wire. A thrown exception and a deliberately
 * returned `isError` are distinguishable here, because this package wraps the tool callbacks — and
 * they arrive at the model identically once the MCP server has normalised them.
 *
 * How that normalisation behaves is a property of the MCP SDK version and the transport, not of any
 * customer's server. So it is derived once in CI against a fixture server and applied to everyone's
 * data, which works on stdio servers nobody can reach and adds no synthetic traffic to anyone's
 * analytics. But it can only be applied to a customer's rows if their rows say which version and
 * which transport produced them.
 *
 * Everything here is best effort and nothing here may throw. A version we cannot determine is
 * reported as absent, and the table is simply not applied — which is the honest outcome, and much
 * better than guessing at the nearest version we have tested.
 */
final class Version
{
    public const SDK_NAME = 'getmcpulse/mcpulse';

    /**
     * This package's own version.
     *
     * Read from Composer's installed-versions registry where there is one, and falling back to a
     * constant — a checkout used directly, or a phar, has nothing to read, and a boot path is not
     * the place to care about that. `tests/run.php` asserts the constant matches `composer.json`.
     */
    public const FALLBACK_VERSION = '0.1.6';

    private static ?string $sdk = null;
    private static ?string $mcp = null;
    private static bool $lookedUp = false;

    private static function lookUp(): void
    {
        if (self::$lookedUp) {
            return;
        }
        self::$lookedUp = true;
        self::$sdk = self::FALLBACK_VERSION;

        if (!class_exists(\Composer\InstalledVersions::class)) {
            return;
        }

        try {
            if (\Composer\InstalledVersions::isInstalled(self::SDK_NAME)) {
                $version = \Composer\InstalledVersions::getPrettyVersion(self::SDK_NAME);
                if (is_string($version) && $version !== '') {
                    self::$sdk = ltrim($version, 'v');
                }
            }
            // The MCP PHP SDKs, under the names they publish as. Absent rather than guessed when
            // none of them is installed.
            foreach (['logiscape/mcp-sdk-php', 'php-mcp/server', 'modelcontextprotocol/sdk'] as $package) {
                if (\Composer\InstalledVersions::isInstalled($package)) {
                    $version = \Composer\InstalledVersions::getPrettyVersion($package);
                    if (is_string($version) && $version !== '') {
                        self::$mcp = ltrim($version, 'v');
                        break;
                    }
                }
            }
        } catch (\Throwable) {
            // A registry shape this version does not know. Absent is the answer.
        }
    }

    public static function sdk(): string
    {
        self::lookUp();

        return self::$sdk ?? self::FALLBACK_VERSION;
    }

    /** The MCP PHP SDK version, or `null` when it cannot be determined. */
    public static function mcpSdk(): ?string
    {
        self::lookUp();

        return self::$mcp;
    }

    /**
     * Maps a transport's name to the small fixed vocabulary the wire-mapping table is keyed on.
     *
     * Class names are not a public interface and have been renamed before, whereas `stdio`, `http`
     * and `sse` are what the table is keyed on. Anything unrecognised reports as `unknown` rather
     * than as a guess: a row that says "we do not know" can be excluded from the mapping, and a row
     * that says `stdio` when it was SSE cannot be found at all.
     */
    public static function transportOf(?string $declared): string
    {
        if ($declared === null || $declared === '') {
            return 'unknown';
        }
        $name = strtolower($declared);

        if (str_contains($name, 'stdio')) {
            return 'stdio';
        }
        // Checked before `sse`, because the streamable-HTTP transport's own name contains neither
        // word in every SDK version and this is the broader match.
        if (str_contains($name, 'streamable')) {
            return 'http';
        }
        if (str_contains($name, 'sse')) {
            return 'sse';
        }
        if (str_contains($name, 'http')) {
            return 'http';
        }

        return 'unknown';
    }
}
