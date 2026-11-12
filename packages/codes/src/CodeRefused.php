<?php

declare(strict_types=1);

namespace Preflow\Codes;

/**
 * A code or a change to one that Codes refuses. It is an InvalidArgumentException; $reason
 * (one of the constants) names what was wrong, so an admin can show its own words for it.
 */
final class CodeRefused extends \InvalidArgumentException
{
    public const string UNKNOWN_PURPOSE = 'purpose';
    public const string NOT_READABLE = 'not_readable';
    public const string FORMAT = 'format';
    public const string TAKEN = 'taken';
    public const string UNKNOWN_CODE = 'unknown_code';
    public const string REVOKED = 'revoked';
    public const string USED_UP = 'used_up';
    public const string EXPIRY_PAST = 'expiry_past';
    public const string MAX_USES_LOW = 'max_uses_low';
    public const string CHANGED = 'changed';
    public const string BOUND_SINGLE_USE = 'bound_single_use';

    public function __construct(public readonly string $reason, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
