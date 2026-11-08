<?php

declare(strict_types=1);

namespace Preflow\Twig;

use Preflow\Core\PageCache\LiveParts;
use Preflow\Core\PageCache\LivePlaceholder;
use Twig\Environment;
use Twig\Error\RuntimeError;
use Twig\Extension\AbstractExtension;
use Twig\Template;

/** The {% live %} tag (LiveTokenParser) and what it prints. */
final class LiveExtension extends AbstractExtension
{
    /** The kind of a Twig live block's placeholder (LiveParts). */
    public const string KIND = 'twig';

    public function __construct(private readonly ?LiveParts $parts = null) {}

    public function getTokenParsers(): array
    {
        return [new LiveTokenParser()];
    }

    /**
     * A live block: its placeholder while the page renders for the page cache, else the block
     * rendered with only $with and the globals.
     *
     * @internal compiled templates
     */
    public function live(Environment $env, Template $template, string $block, mixed $with): string
    {
        if (!is_array($with)) {
            throw new RuntimeError(sprintf('{%% live with … %%} takes a mapping of values, %s given.', get_debug_type($with)), -1, $template->getSourceContext());
        }
        try {
            LivePlaceholder::assertPlain($with);
            $placeholder = $this->parts?->placeholder(self::KIND, ['t' => $template->getTemplateName(), 'b' => $block, 'w' => $with]);
        } catch (\InvalidArgumentException $e) {
            throw new RuntimeError($e->getMessage(), -1, $template->getSourceContext(), $e);
        }

        return $placeholder ?? $template->renderBlock($block, $with + $env->getGlobals());
    }
}
