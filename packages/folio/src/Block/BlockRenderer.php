<?php

declare(strict_types=1);

namespace Preflow\Folio\Block;

use Preflow\Components\ComponentRenderer;
use Preflow\Core\Container\Container;
use Preflow\Data\LocaleValue;
use Preflow\Folio\Content\ContentLocale;

/**
 * Renders a blocks field through the blocks' own components, in the
 * request's content locale. Unknown and data-only blocks are skipped (data-
 * only blocks are rendered by their parent's template); a block that throws
 * is contained by the component error boundary.
 */
final class BlockRenderer
{
    public function __construct(
        private readonly BlockRegistry $blocks,
        private readonly ComponentRenderer $renderer,
        private readonly Container $container,
        private readonly ContentLocale $contentLocale,
    ) {}

    public function render(mixed $blocks, bool $preview = false): string
    {
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
        $definition = $this->blocks->get((string) ($block['_block'] ?? ''));
        if ($definition === null || !$definition->isComponent) {
            return '';
        }

        try {
            $component = $this->container->make($definition->class);
            // "key" gives each instance its own DOM id (two heroes on one page).
            $component->setProps([...$block, 'key' => (string) ($block['_uid'] ?? '')]);
        } catch (\Throwable) {
            // Could not be built (unresolvable dependency, bad data): skip this block,
            // never the page. Render-time errors are handled by the error boundary.
            return '';
        }
        $html = $this->renderer->render($component);

        if (!$preview) {
            return $html;
        }

        $uid = htmlspecialchars((string) ($block['_uid'] ?? ''), ENT_QUOTES, 'UTF-8');

        return '<div class="folio-block" data-folio-block="' . $uid . '">' . $html . '</div>';
    }
}
