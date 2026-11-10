<?php

declare(strict_types=1);

namespace Preflow\Core\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The request being handled, bound in the container for every request (Application::handle()).
 *
 * $request is the visitor's request as it reached the application (cookies, server params), so
 * code that has no request handed to it can read it: a live part of a cached page, rendered when
 * the page is served, reads the visitor's cookie from here. It is null in an internal render
 * (the SEO audit, GEO), which renders as an anonymous visitor.
 *
 * With URL locales ('/de/jobs'), $path and $request's URI are the path without the prefix
 * ('/jobs') once the locale middleware has run (since 0.63.2), as routes and pages see it.
 */
final readonly class RequestContext
{
    public function __construct(
        public string $path,
        public string $method,
        public ?ServerRequestInterface $request = null,
    ) {}
}
