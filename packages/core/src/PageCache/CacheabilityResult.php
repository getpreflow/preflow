<?php

declare(strict_types=1);

namespace Preflow\Core\PageCache;

/**
 * The answer of Cacheability::check(): whether the page cache would store a page, and when it
 * would not, why (`reason`) and what usually fixes it (`hint`, null when nothing general can be said).
 *
 * @since 0.85.0
 */
final class CacheabilityResult
{
    public function __construct(
        public readonly bool $cacheable,
        public readonly ?string $reason = null,
        public readonly ?string $hint = null,
        /** The status of the page's first render, null when it was not rendered (a 404 is "not found", not "differs"). */
        public readonly ?int $status = null,
    ) {}
}
