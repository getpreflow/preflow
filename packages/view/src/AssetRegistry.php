<?php

declare(strict_types=1);

namespace Preflow\View;

/**
 * The CSS, JS and head tags one render collected, what of it was printed already and the CSS
 * scope in effect: AssetCollector's state, one per render frame (a frame slot), and the
 * collector's own outside every render.
 *
 * @internal AssetCollector
 * @since 0.81.0
 */
final class AssetRegistry
{
    public const array NOTHING_PRINTED = ['css' => [], 'jsHead' => [], 'jsBody' => [], 'jsInline' => [], 'headTags' => []];

    /** @var array<string, string> hash => css */
    public array $css = [];

    /** @var array<string, string> hash => js */
    public array $jsHead = [];

    /** @var array<string, string> hash => js */
    public array $jsBody = [];

    /** @var array<string, string> hash => js */
    public array $jsInline = [];

    /** @var array<string|int, string> extra tags for <head> (library script tags, the htmx config) */
    public array $headTags = [];

    /** The CSS scope addCss() wraps rules in (a component with scoping), null for none. */
    public ?string $cssScope = null;

    /** @var array<string, array<string|int, true>> keys already printed by renderHead()/renderAssets(), per registry */
    public array $printed = self::NOTHING_PRINTED;

    /** A registry holding what this one registered, with nothing printed and no CSS scope. */
    public function registered(): self
    {
        $copy = new self();
        $copy->css = $this->css;
        $copy->jsHead = $this->jsHead;
        $copy->jsBody = $this->jsBody;
        $copy->jsInline = $this->jsInline;
        $copy->headTags = $this->headTags;

        return $copy;
    }
}
