<?php

declare(strict_types=1);

namespace Preflow\Fields;

use Preflow\Core\Context\Contexts;
use Preflow\Data\Locales;
use Preflow\Fields\Values\Link;
use Preflow\View\TemplateExtensionProvider;
use Preflow\View\TemplateFunctionDefinition;

/**
 * The template functions of the field values, for every engine (TemplateFunctionDefinitions):
 *
 * - link_href(value, locale = null): a link field's address (Link::href()), or null;
 * - link_attrs(value, locale = null): its <a> attributes as markup, ` href="…"` plus
 *   ` target="_blank" rel="noopener noreferrer"` for a new tab, escaped; '' without an href;
 * - link_label(value, locale = null): its label in the locale (Link::label(), along the
 *   content locales' fallback chain), or null.
 *
 * value is the field's value as a template gets it (the stored object, or JSON text of it). The
 * locale is the render's (Contexts::current()->locale) unless given. A record link resolves
 * through the RecordAddresses bound when the function runs.
 *
 * @since 0.91.0
 */
final class FieldsExtensionProvider implements TemplateExtensionProvider
{
    /** @param \Closure(): ?RecordAddresses $addresses the bound RecordAddresses, asked on each call */
    public function __construct(
        private readonly \Closure $addresses,
        private readonly ?Locales $locales = null,
    ) {}

    public function getTemplateFunctions(): array
    {
        return [
            new TemplateFunctionDefinition(
                name: 'link_href',
                callable: fn (mixed $value, ?string $locale = null): ?string => Link::fromStored($value)?->href(($this->addresses)(), $this->locale($locale)),
            ),
            new TemplateFunctionDefinition(
                name: 'link_attrs',
                callable: fn (mixed $value, ?string $locale = null): string => self::markup(Link::fromStored($value)?->attributes(($this->addresses)(), $this->locale($locale)) ?? []),
                isSafe: true,
            ),
            new TemplateFunctionDefinition(
                name: 'link_label',
                callable: fn (mixed $value, ?string $locale = null): ?string => Link::fromStored($value)?->label($this->locale($locale), $this->locales),
            ),
        ];
    }

    public function getTemplateGlobals(): array
    {
        return [];
    }

    private function locale(?string $given): string
    {
        return $given ?? Contexts::current()->locale;
    }

    /** @param array<string, string> $attributes */
    private static function markup(array $attributes): string
    {
        $html = '';
        foreach ($attributes as $name => $value) {
            $html .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }

        return $html;
    }
}
