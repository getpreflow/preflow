<?php

declare(strict_types=1);

namespace Preflow\Core\Http;

use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\Deprecations;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Whether the app renders for itself (the SEO audit rendering a page) rather than for a visitor.
 * Such a render is answered as usual but leaves no trace in the site's data: redirects count no
 * hit and the not-found list records nothing, media makes no image variant on request, no debug
 * toolbar is added, and Application::handle() doesn't send the mail queued during it. Sites can
 * check it too.
 *
 * Since 0.81 the render context says it: a render whose purpose leaves no trace (audit, GEO,
 * rebuild, preview; Purpose::leavesNoTrace()). A request marked with the attribute (mark(),
 * deprecated) still counts, also outside every render.
 *
 * @since 0.54
 */
final class InternalRender
{
    /** The request attribute; true marks an internal render. */
    public const string ATTRIBUTE = 'preflow.internal_render';

    public static function is(ServerRequestInterface $request): bool
    {
        if (Contexts::peek()?->purpose->leavesNoTrace() === true) {
            return true;
        }

        return $request->getAttribute(self::ATTRIBUTE) === true;
    }

    /**
     * @deprecated 0.81, removed in 0.83: render through Application::render() with
     *             RenderContext::internal(), whose purpose says it
     */
    public static function mark(ServerRequestInterface $request): ServerRequestInterface
    {
        Deprecations::once('Preflow\Core\Http\InternalRender::mark() is deprecated since 0.81 and is removed in 0.83; use Application::render() with RenderContext::internal().');

        return $request->withAttribute(self::ATTRIBUTE, true);
    }
}
