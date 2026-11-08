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

    /** The name of a block {% live %} declares (LiveTokenParser). */
    public const string BLOCK_PATTERN = '/^__live_\d+(_\d+)?$/';

    /**
     * A live block: its placeholder while the page renders for the page cache, else the block
     * rendered with only $with and the globals. The recipe names the template by its logical
     * name (as the loader finds it), or, for a component's own template (rendered by its file),
     * by the component class; a template that is neither (a string) renders the block in place
     * and keeps the page from being stored.
     *
     * @internal compiled templates
     */
    public function live(Environment $env, Template $template, string $block, mixed $componentClass, mixed $with): string
    {
        if (!is_array($with)) {
            throw new RuntimeError(sprintf('{%% live with … %%} takes a mapping of values, %s given.', get_debug_type($with)), -1, $template->getSourceContext());
        }
        try {
            LivePlaceholder::assertPlain($with);
            $placeholder = null;
            if ($this->parts !== null) {
                $name = $template->getTemplateName();
                $recipe = self::logical($name)
                    ? ['t' => $name, 'b' => $block, 'w' => $with]
                    : (is_string($componentClass) && $componentClass !== '' ? ['c' => $componentClass, 'b' => $block, 'w' => $with] : null);
                if ($recipe !== null) {
                    $placeholder = $this->parts->placeholder(self::KIND, $recipe);
                } else {
                    $this->parts->renderedInPlace('twig-template');
                }
            }
        } catch (\InvalidArgumentException $e) {
            throw new RuntimeError($e->getMessage(), -1, $template->getSourceContext(), $e);
        }

        return $placeholder ?? $template->renderBlock($block, $with + $env->getGlobals());
    }

    /** Whether $name is a loader name (page.twig, @folio/frontend/post.twig): no file path, no string template. */
    public static function logical(string $name): bool
    {
        return $name !== '' && !str_starts_with($name, '/') && !str_starts_with($name, '\\')
            && preg_match('#^[A-Za-z]:[\\\\/]#', $name) !== 1 && !str_contains($name, '..') && !str_contains($name, "\0")
            && !str_contains($name, ' (string template ') && !str_starts_with($name, '__string_template__');
    }
}
