<?php

declare(strict_types=1);

namespace Preflow\Core\Http\Csrf;

use Preflow\Core\Exceptions\ForbiddenHttpException;

/**
 * A CSRF failure whose likely cause is an expired session: the session held no token before
 * this request, but the request carried one (from a form of the session that is gone).
 * Still a 403; the request is as untrusted as any other failure.
 */
final class CsrfSessionExpiredException extends ForbiddenHttpException
{
    public function __construct(string $message = 'CSRF token mismatch: session expired.', ?\Throwable $previous = null)
    {
        parent::__construct($message, $previous);
    }
}
