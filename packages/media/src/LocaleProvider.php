<?php

declare(strict_types=1);

namespace Preflow\Media;

/**
 * The language assets render in (alt text): the current one, and the default to fall back to.
 * Since 0.81 an implementation also implements ContextLocaleProvider, which takes the render
 * context; in 0.83 its localeOf() moves here and current() goes.
 */
interface LocaleProvider
{
    /**
     * @deprecated 0.81, removed in 0.83 inside a render: implement ContextLocaleProvider::localeOf(RenderContext $ctx);
     *             ImageTag still asks it outside every render (a script, a unit test)
     */
    public function current(): string;

    public function default(): string;
}
