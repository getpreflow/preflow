<?php

declare(strict_types=1);

namespace Preflow\Folio\Block;

use Preflow\Components\ComponentRenderer;
use Preflow\Core\Container\Container;
use Preflow\Data\LocaleValue;
use Preflow\Folio\Content\ContentLocale;
use Preflow\Folio\Content\SafeRichText;

/**
 * Renders a blocks field through the blocks' own components, in the
 * request's content locale. Unknown and data-only blocks are skipped (data-
 * only blocks are rendered by their parent's template); a block that throws
 * is contained by the component error boundary.
 *
 * Preview mode reaches nested blocks: while a preview render runs, a block
 * template that renders its children with folio_blocks(items) previews them
 * too, and folio_editable() emits its field targets.
 */
final class BlockRenderer
{
    private int $previewDepth = 0;

    public function __construct(
        private readonly BlockRegistry $blocks,
        private readonly ComponentRenderer $renderer,
        private readonly Container $container,
        private readonly ContentLocale $contentLocale,
        private readonly ?SafeRichText $richText = null,
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
        if (!is_array($blocks)) {
            return '';
        }
        $blocks = LocaleValue::resolveTree($blocks, $this->contentLocale->locales(), $this->contentLocale->get());

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
        if ($definition === null || !$definition->isComponent) {
            return '';
        }

        // Rich text reaches the block's template sanitized, nested blocks included.
        $block = $this->richText?->block($block) ?? $block;

        try {
            $component = $this->container->make($definition->class);
            // "key" gives each instance its own DOM id (two heroes on one page).
            $component->setProps([...$block, 'key' => (string) ($block['_uid'] ?? '')]);
        } catch (\Throwable) {
            // Could not be built (unresolvable dependency, bad data): skip this block,
            // never the page. Render-time errors are handled by the error boundary.
            return '';
        }
        if (!$preview) {
            return $this->renderer->render($component);
        }

        // Preview: mark the component's own root element (no extra wrapper, so
        // the page's layout and selectors behave exactly as on the public site).
        $component->setRootAttribute('data-folio-block', (string) ($block['_uid'] ?? ''));
        $component->setRootAttribute('data-folio-label', $definition->label);
        $this->previewDepth++;
        try {
            return $this->renderer->render($component);
        } finally {
            $this->previewDepth--;
        }
    }
}
