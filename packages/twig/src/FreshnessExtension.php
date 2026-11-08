<?php

declare(strict_types=1);

namespace Preflow\Twig;

use Preflow\Core\PageCache\PageCache;
use Twig\Error\RuntimeError;
use Twig\Extension\AbstractExtension;
use Twig\Template;

/**
 * The page cache's freshness dial and page expiry in templates (PageCache::within(),
 * PageCache::expiresAt()):
 *
 *     {% freshness 'next-visit' %}…{% endfreshness %}
 *     {% freshness 'timed' every '12h' %}…{% endfreshness %}
 *     {% expires at event.startsAt %}
 *     {% expires in '1h' %}
 *
 * Without the page cache the body renders as it is and expires does nothing; a wrong name or
 * duration fails either way.
 */
final class FreshnessExtension extends AbstractExtension
{
    private readonly PageCache $pageCache;

    public function __construct(?PageCache $pageCache = null)
    {
        // Without one: a PageCache without a recorder checks the arguments and records nothing.
        $this->pageCache = $pageCache ?? new PageCache();
    }

    public function getTokenParsers(): array
    {
        return [new FreshnessTokenParser(), new ExpiresTokenParser()];
    }

    /**
     * A {% freshness %} block: its body rendered with what it reads recorded as $name.
     *
     * @internal compiled templates
     * @param \Closure(): iterable<string> $body
     */
    public function within(Template $template, mixed $name, mixed $every, \Closure $body): string
    {
        try {
            if (!is_string($name) && !$name instanceof \Preflow\Core\PageCache\Freshness) {
                throw new \InvalidArgumentException(sprintf('{%% freshness %%} takes a name, %s given.', get_debug_type($name)));
            }
            if ($every !== null && !is_string($every) && !is_int($every)) {
                throw new \InvalidArgumentException(sprintf('{%% freshness … every %%} takes a duration, %s given.', get_debug_type($every)));
            }
            $this->pageCache->enter($name, $every);
        } catch (\InvalidArgumentException $e) {
            throw new RuntimeError($e->getMessage(), -1, $template->getSourceContext(), $e);
        }
        try {
            return implode('', iterator_to_array($body(), false));
        } finally {
            $this->pageCache->leave();
        }
    }

    /**
     * {% expires at … %} / {% expires in … %}.
     *
     * @internal compiled templates
     */
    public function expires(Template $template, mixed $at, mixed $in): void
    {
        try {
            if ($in !== null && !is_string($in) && !is_int($in)) {
                throw new \InvalidArgumentException(sprintf('{%% expires in %%} takes a duration, %s given.', get_debug_type($in)));
            }
            $this->pageCache->expires($at, $in);
        } catch (\InvalidArgumentException $e) {
            throw new RuntimeError($e->getMessage(), -1, $template->getSourceContext(), $e);
        }
    }
}
