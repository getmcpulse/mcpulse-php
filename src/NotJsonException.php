<?php

declare(strict_types=1);

namespace MCPulse;

/** Thrown for anything JSON cannot represent: a NAN, an infinity, a cycle, an unknown type. */
final class NotJsonException extends \RuntimeException
{
}
