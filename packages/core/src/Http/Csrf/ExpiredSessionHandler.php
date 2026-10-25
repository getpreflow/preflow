<?php

declare(strict_types=1);

namespace Preflow\Core\Http\Csrf;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Answers a POST that failed the CSRF check because the session expired (see
 * CsrfSessionExpiredException), before the app runs. Bind it in the container.
 *
 * The request is NOT verified: never apply its data. At most keep it in the (new) session to
 * prefill the form after the visitor signs in again, and send them there.
 */
interface ExpiredSessionHandler
{
    /**
     * @param ServerRequestInterface $request with the new session (SessionInterface attribute)
     *                                        and its fresh CsrfToken attribute
     * @return ResponseInterface|null a response to send instead of the 403; null throws
     *                                CsrfSessionExpiredException as without a handler
     */
    public function handle(ServerRequestInterface $request): ?ResponseInterface;
}
