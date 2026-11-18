<?php

declare(strict_types=1);

namespace Preflow\Core\Routing;

/**
 * A router that can ask, when it must, whether a page answers a path. Core gives it the page lookups
 * ({@see PageLookups}) once the router is set; preflow/routing's Router uses the check to answer 405
 * for a wrong method behind a catch-all route only where no page answers the path.
 *
 * @since 0.79.0
 */
interface AnswersPages
{
    /**
     * @param (\Closure(string $locale, string $path): bool)|null $check $locale is '' or the request's
     *        locale, $path the path without its language prefix (as the page lookups expect it)
     */
    public function answerPagesWith(?\Closure $check): void;
}
