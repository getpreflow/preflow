<?php

declare(strict_types=1);

namespace Preflow\Media\Preview;

use Preflow\Core\Container\Attributes\Singleton;

/**
 * The preview renderers, asked in registration order, then those registered as
 * fallback (the core's PDF renderer), so a site's own renderer for a type wins.
 *
 * One per container from its first resolution, whichever provider asks first,
 * so a renderer registered before MediaServiceProvider is kept.
 */
#[Singleton]
final class PreviewRenderers
{
    /** @var list<PreviewRenderer> */
    private array $renderers = [];

    /** @var list<PreviewRenderer> */
    private array $fallbacks = [];

    /** @param bool $fallback asked after every renderer registered without it */
    public function register(PreviewRenderer $renderer, bool $fallback = false): void
    {
        if ($fallback) {
            $this->fallbacks[] = $renderer;
        } else {
            $this->renderers[] = $renderer;
        }
    }

    /** The first renderer that supports the type; null when none does. */
    public function for(string $mime): ?PreviewRenderer
    {
        foreach ([...$this->renderers, ...$this->fallbacks] as $renderer) {
            if ($renderer->supports($mime)) {
                return $renderer;
            }
        }

        return null;
    }
}
