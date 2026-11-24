<?php

declare(strict_types=1);

namespace Preflow\Components;

use Preflow\Core\Exceptions\ForbiddenHttpException;

/**
 * Carried state that cannot be used: tampered, of another component, expired, unreadable, or of
 * a shape the component no longer has. Answers 403 like a refused action token.
 *
 * SecurityException is final, so this extends its parent, ForbiddenHttpException.
 *
 * @since 0.86.0
 */
final class StateRefused extends ForbiddenHttpException
{
    public function __construct(string $message = 'Component state refused.', ?\Throwable $previous = null)
    {
        parent::__construct($message, $previous);
    }
}
