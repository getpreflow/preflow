<?php

declare(strict_types=1);

namespace Preflow\Core\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Marks a request the app makes of itself (the SEO audit rendering a page) rather than one
 * from a visitor. A marked request is answered as usual but leaves no trace in the site's
 * data: redirects count no hit and the not-found list records nothing, media makes no image
 * variant on request, no debug toolbar is added, and Application::handle() doesn't send the
 * mail queued during it. Sites can check the marker too.
 *
 * @since 0.54
 */
final class InternalRender
{
    /** The request attribute; true marks an internal render. */
    public const string ATTRIBUTE = 'preflow.internal_render';

    public static function is(ServerRequestInterface $request): bool
    {
        return $request->getAttribute(self::ATTRIBUTE) === true;
    }

    public static function mark(ServerRequestInterface $request): ServerRequestInterface
    {
        return $request->withAttribute(self::ATTRIBUTE, true);
    }
}
