<?php

declare(strict_types=1);

namespace Preflow\Folio\Extension\Attributes;

/**
 * An admin page of an extension, under the Folio prefix at {prefix}/{path}. Its methods
 * carry #[Get]/#[Post] with paths relative to the screen ('/', '/{id}/approve').
 * The permission is required (it has a null default only so it can follow the optional
 * arguments; ScreenRegistry refuses a screen without one).
 * The label is shown as written or, when it is a translation key and the translator has a text
 * for it, in the staff member's language: 'site.screens.queue' from the site's lang/{locale}/site.php.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class FolioScreen
{
    public function __construct(
        public readonly string $path,
        public readonly string $label,
        public readonly string $icon = 'ui-file',
        public readonly string $section = 'settings',
        public readonly int $order = 100,
        public readonly ?string $permission = null,
        /** @var list<string> files the page needs: 'file.js', '@folio/htmx.js', '@folio/trix', '@other/file.css' */
        public readonly array $assets = [],
    ) {}
}
