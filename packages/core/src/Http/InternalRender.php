<?php

declare(strict_types=1);

namespace Preflow\Core\Http;

use Preflow\Core\Context\Contexts;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Whether the app renders for itself (the SEO audit rendering a page) rather than for a visitor.
 * Such a render is answered as usual but leaves no trace in the site's data: redirects count no
 * hit and the not-found list records nothing, media makes no image variant on request, no debug
 * toolbar is added, and Application::handle() doesn't send the mail queued during it. Sites can
 * check it too.
 *
 * Since 0.81 the render context says it: a render whose purpose leaves no trace (audit, GEO,
 * rebuild, preview; Purpose::leavesNoTrace()), not the request. Outside every render nothing is
 * internal. An internal render goes through Application::render() with RenderContext::internal().
 *
 * @since 0.54
 */
final class InternalRender
{
    /** @param ServerRequestInterface $request kept for callers; the current render's purpose decides */
    public static function is(ServerRequestInterface $request): bool
    {
        return Contexts::peek()?->purpose->leavesNoTrace() === true;
    }
}
