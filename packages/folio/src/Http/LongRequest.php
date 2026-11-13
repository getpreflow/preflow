<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Preflow\Core\Http\Session\SessionInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A request that works for a while (an AI translation, a step of the SEO audit). A closed tab must
 * not cut it off halfway, and a host's short PHP time limit is raised to $seconds where it may be;
 * a limit that is already longer (or none, 0) is left alone, and where set_time_limit is disabled
 * nothing changes.
 *
 * The session is closed first: a file session stays locked while it is open, and every other
 * request of the same browser (pages, assets, autosave) would wait until the work ends. Nothing
 * after this point may write to the session.
 *
 * @internal
 * @since 0.74
 */
final class LongRequest
{
    public static function allow(ServerRequestInterface $request, int $seconds): void
    {
        $session = $request->getAttribute(SessionInterface::class);
        if ($session instanceof SessionInterface) {
            $session->close();
        }
        ignore_user_abort(true);
        $limit = (int) ini_get('max_execution_time');
        if ($limit !== 0 && $limit < $seconds) {
            @set_time_limit($seconds);
        }
    }
}
