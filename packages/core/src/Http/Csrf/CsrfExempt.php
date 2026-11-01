<?php

declare(strict_types=1);

namespace Preflow\Core\Http\Csrf;

/**
 * Exempts a controller or one of its routes from the CSRF check. The reason is
 * mandatory so every exemption documents why the route is safe without a token.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class CsrfExempt
{
    public function __construct(public string $reason)
    {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('#[CsrfExempt] needs a reason explaining why the route needs no CSRF token.');
        }
    }
}
