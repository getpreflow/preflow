<?php

declare(strict_types=1);

namespace Preflow\Blocks;

use Preflow\Components\ComponentRenderer;
use Preflow\Core\Container\Container;
use Preflow\Data\LocaleValue;

/**
 * Renders a blocks field through the blocks' own components, in the BlockLocale's
 * locale. Unknown and data-only blocks are skipped (data-only blocks are rendered by
 * their parent's template); a block that throws is contained by the component error
 * boundary. Blocks nested deeper than $maxDepth are not rendered.
 *
 * Preview mode reaches nested blocks: while a preview render runs, a block template
 * that renders its children with blocks(items) previews them too, and each block's
 * root element carries the BlockPreviewMarks attributes.
 */
final class BlockRenderer
{
    private int $previewDepth = 0;

    /** How many blocks are rendering now (a blocks() call inside a block's template is one level deeper). */
    private int $depth = 0;

    public function __construct(
        private readonly BlockRegistry $blocks,
        private readonly ComponentRenderer $renderer,
        private readonly Container $container,
        private readonly BlockLocale $locale,
        private readonly BlockTextCleaner $cleaner = new DefaultBlockTextCleaner(),
        private readonly BlockPreviewMarks $previewMarks = new DefaultBlockPreviewMarks(),
        private readonly int $maxDepth = BlocksConfig::DEFAULT_MAX_DEPTH,
    ) {}

    /** True while a preview render (of any depth) is running. */
    public function inPreview(): bool
    {
        return $this->previewDepth > 0;
    }

    public function render(mixed $blocks, bool $preview = false): string
    {
        $preview = $preview || $this->inPreview();
        if (is_string($blocks) && $blocks !== '') {
            $blocks = json_decode($blocks, true);
        }
        if (!is_array($blocks) || $this->depth >= $this->maxDepth) {
            return '';
        }
        $blocks = LocaleValue::resolveTree($blocks, $this->locale->locales(), $this->locale->get());

        $out = '';
        foreach ($blocks as $block) {
            if (is_array($block)) {
                $out .= $this->renderBlock($block, $preview);
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $block */
    public function renderBlock(array $block, bool $preview = false): string
    {
        $preview = $preview || $this->inPreview();
        $definition = $this->blocks->get((string) ($block['_block'] ?? ''));
        if ($definition === null || !$definition->isComponent || $this->depth >= $this->maxDepth) {
            return '';
        }

        // Values reach the block's template cleaned (rich text sanitized), nested blocks included.
        $block = $this->cleaner->block($block);

        try {
            $component = $this->container->make($definition->class);
            // "key" gives each instance its own DOM id (two heroes on one page).
            $component->setProps([...$block, 'key' => (string) ($block['_uid'] ?? '')]);
        } catch (\Throwable) {
            // Could not be built (unresolvable dependency, bad data): skip this block,
            // never the page. Render-time errors are handled by the error boundary, except
            // an HttpException (since 0.63.2): that is the page's status (a block whose
            // record is gone should render an empty state instead of throwing a 404).
            // A page missing a block is not stored by the page cache.
            if (class_exists(\Preflow\Core\PageCache\PageCache::class) && $this->container->bound(\Preflow\Core\PageCache\PageCache::class)) {
                $this->container->get(\Preflow\Core\PageCache\PageCache::class)->refuse('block-error');
            }

            return '';
        }

        $this->depth++;
        try {
            if (!$preview) {
                return $this->renderer->render($component);
            }

            // Preview: mark the component's own root element (no extra wrapper, so
            // the page's layout and selectors behave exactly as on the public site).
            foreach ($this->previewMarks->attributes($definition, $block) as $name => $value) {
                $component->setRootAttribute($name, $value);
            }
            $this->previewDepth++;
            try {
                return $this->renderer->render($component);
            } finally {
                $this->previewDepth--;
            }
        } finally {
            $this->depth--;
        }
    }
}
