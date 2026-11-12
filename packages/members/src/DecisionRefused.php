<?php

declare(strict_types=1);

namespace Preflow\Members;

/**
 * The owner's decision on a member was refused and nothing changed. $reason is 'unconfirmed'
 * (the member has not proven their address), 'not_allowed' (the decision does not fit the
 * member's state) or 'empty_message'.
 */
final class DecisionRefused extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Decision refused: ' . $reason);
    }
}
