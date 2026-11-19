<?php

declare(strict_types=1);

namespace Preflow\Testing;

use Nyholm\Psr7\ServerRequest;
use Preflow\Core\Context\FrozenClock;
use Preflow\Core\Context\Purpose;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\Site;

/**
 * Render contexts for tests. forTest() is a visitor's GET of the site's home page, purpose Test,
 * on a clock frozen at 2026-01-01T00:00:00Z, so two runs of a test see the same "now". Derive
 * what a test needs from it:
 *
 *     $ctx = ContextFactory::forTest()->in('de')->workingCopies();
 *     Contexts::run($ctx, fn () => …);
 *
 * The FrozenClock makes "now" fixed, but the context is not frozen with at(): it is still the
 * public default (isPublicDefault()). Use ->at($date) for a render as of a date.
 *
 * @since 0.81.0
 */
final class ContextFactory
{
    /** The time every forTest() context starts at. */
    public const NOW = '2026-01-01T00:00:00Z';

    /** @param Site|null $site null: http://localhost with the one locale 'en' */
    public static function forTest(?Site $site = null): RenderContext
    {
        $site ??= new Site('http://localhost', ['en'], 'en');

        return RenderContext::fromRequest(
            new ServerRequest('GET', rtrim($site->origin, '/') . '/'),
            $site,
            new FrozenClock(new \DateTimeImmutable(self::NOW)),
        )->because(Purpose::Test);
    }
}
