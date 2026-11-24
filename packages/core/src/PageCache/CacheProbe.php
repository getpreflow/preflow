<?php

declare(strict_types=1);

namespace Preflow\Core\PageCache;

/**
 * What the page cache middleware found out about one probe render (Cacheability): the request
 * carries it as PageCacheMiddleware::PROBE, the middleware fills it in. The page is rendered the
 * way a rebuild renders it, and compared the way verify compares it, but nothing is stored,
 * served or recorded in the index.
 *
 * @internal
 */
final class CacheProbe
{
    /** Whether the middleware answered the probe at all. */
    public bool $reached = false;

    /** The status of the first render, null when it was not rendered. */
    public ?int $status = null;

    /** Why the page would not be stored, in the log's words (`set-cookie`, `refused:x`, `differs`…), null when it would. */
    public ?string $code = null;

    /** For `differs`: where the two renders differ first, as the log line says it. */
    public ?string $difference = null;
}
