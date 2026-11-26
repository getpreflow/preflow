<?php

declare(strict_types=1);

namespace Preflow\Fields;

use Preflow\Core\Context\Contexts;
use Preflow\Data\Locales;
use Preflow\Fields\Values\Code;
use Preflow\Fields\Values\Email;
use Preflow\Fields\Values\JsonText;
use Preflow\Fields\Values\Link;
use Preflow\Fields\Values\Phone;
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
 * - mailto_href(value): an email field's `mailto:` address (Email::href()), or null;
 * - tel_href(value): a phone field's `tel:` address (Phone::href(): the E.164 form, else the digits), or null;
 * - code_html(value, language = null): a code field (language: its field's "language" option, for the class) as `<pre><code class="language-…">` with the code escaped (Code::html()), '' for none;
 * - json_data(value): a json field decoded (array, text, number), null when empty or invalid.
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
            new TemplateFunctionDefinition(
                name: 'mailto_href',
                callable: static fn (mixed $value): ?string => Email::fromStored($value)?->href(),
            ),
            new TemplateFunctionDefinition(
                name: 'tel_href',
                callable: static fn (mixed $value): ?string => Phone::fromStored($value)?->href(),
            ),
            new TemplateFunctionDefinition(
                name: 'code_html',
                callable: static fn (mixed $value, ?string $language = null): string => Code::fromStored($value, $language)?->html() ?? '',
                isSafe: true,
            ),
            new TemplateFunctionDefinition(
                name: 'json_data',
                callable: static fn (mixed $value): mixed => JsonText::decode($value),
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
