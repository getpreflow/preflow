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

    /**
     * @param (\Closure(string): ?string)|null $componentOf the component class whose own template
     *        has the given Twig name, null for none (since 0.82.0; TwigEngine knows them)
     */
    public function __construct(
        private readonly ?LiveParts $parts = null,
        private readonly ?\Closure $componentOf = null,
    ) {}

    public function getTokenParsers(): array
    {
        return [new LiveTokenParser()];
    }

    public function getNodeVisitors(): array
    {
        return [new LiveBlockNames(), new LiveBlockNesting(), new LiveEmbeddedBlocks()];
    }

    /** The name of a block {% live %} declares (LiveTokenParser). */
    public const string BLOCK_PATTERN = '/^__live_\d+(_\d+)?$/';

    /**
     * A live block: its placeholder while the page renders for the page cache, else the block
     * rendered with only $with and the globals. The recipe names the template by its logical
     * name (as the loader finds it), or, for a component's own template (rendered by its file),
     * by the component class; a template that is neither (a string) renders the block in place
     * and keeps the page from being stored. The class comes from the template's file (since
     * 0.82.0; $componentOf), never from its variables: an {% embed … only %} or a
     * `with {componentClass: …}` changes nothing. A block inside an {% embed %} is named the same way
     * (the embed's template has the file's name), and the file answers for it (since 0.82.0,
     * LiveEmbeddedBlocks).
     *
     * @internal compiled templates
     * @since 0.82.0
     */
    public function part(Environment $env, Template $template, string $block, mixed $with): string
    {
        if (!is_array($with)) {
            throw new RuntimeError(sprintf('{%% live with … %%} takes a mapping of values, %s given.', get_debug_type($with)), -1, $template->getSourceContext());
        }
        try {
            LivePlaceholder::assertPlain($with);
            $placeholder = null;
            if ($this->parts !== null) {
                $name = $template->getTemplateName();
                $class = self::logical($name) || $this->componentOf === null ? null : ($this->componentOf)($name);
                $recipe = self::logical($name)
                    ? ['t' => $name, 'b' => $block, 'w' => $with]
                    : ($class !== null ? ['c' => $class, 'b' => $block, 'w' => $with] : null);
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
