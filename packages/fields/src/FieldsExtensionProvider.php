<?php

declare(strict_types=1);

namespace Preflow\Fields;

use Preflow\Core\Context\Contexts;
use Preflow\Data\Locales;
use Preflow\Fields\Values\Code;
use Preflow\Fields\Values\DateRange;
use Preflow\Fields\Values\DateTimeValue;
use Preflow\Fields\Values\Email;
use Preflow\Fields\Values\JsonText;
use Preflow\Fields\Values\Link;
use Preflow\Fields\Values\Location;
use Preflow\Fields\Values\Phone;
use Preflow\Fields\Values\Table;
use Preflow\Fields\Values\TimeOfDay;
use Preflow\View\AssetCollector;
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
 * - datetime_local(value, format = "Y-m-d H:i", zone = null): a datetime field's instant written with a
 *   PHP date() format in the site's zone (app.timezone), or the zone named (a LogicException for a name that is no zone), or '' for none;
 * - time_format(value, format = "H:i"): a time field's "HH:MM" written with a date() format ("g:i a"), or '';
 * - daterange_start(value, format = null, zone = null), daterange_end(...): an end of a daterange
 *   field written with a date() format (default "Y-m-d", or "Y-m-d H:i" for a range with a time, in
 *   the site's zone or the zone named), or '' for none.
 *
 * - embed_player(value, title = null, locale = null): an embed field's player (EmbedOutput::html():
 *   the bound EmbedPlayer's markup, PrivacyEmbedPlayer by default, which loads nothing from the
 *   video's host before a click), its script and layout rules added to the page's assets; '' for none;
 * - embed_data(value): an embed field's parts for your own markup (provider, id, url, poster,
 *   player, label, poster_url), or null.
 *
 * - table_html(value, locale = null): a table field as an escaped `<table class="preflow-table">`
 *   with its header row in `<thead>`, each cell in the locale (along the fallback chain), or '';
 * - table_data(value, locale = null): its texts as {head: list|null, rows: list<list>}, or null.
 *
 * - location_data(value): a location field's {lat, lng, address}, or null;
 * - geo_href(value): its `geo:lat,lng` address (opens the map app on a phone), or null.
 *
 * value is the field's value as a template gets it (the stored object, or JSON text of it). The
 * locale is the render's (Contexts::current()->locale) unless given. A record link resolves
 * through the RecordAddresses bound when the function runs.
 *
 * @since 0.91.0
 */
final class FieldsExtensionProvider implements TemplateExtensionProvider
{
    private readonly EmbedOutput $embeds;

    /**
     * @param \Closure(): ?RecordAddresses $addresses the bound RecordAddresses, asked on each call
     * @param (\Closure(): EmbedProviders)|null $providers the app's video hosts (default: YouTube and Vimeo)
     * @param (\Closure(): EmbedPlayer)|null $player the bound EmbedPlayer (default: PrivacyEmbedPlayer)
     * @param (\Closure(): ?AssetCollector)|null $assets the page's assets, for the player's script and rules
     */
    public function __construct(
        private readonly \Closure $addresses,
        private readonly ?Locales $locales = null,
        ?\Closure $providers = null,
        ?\Closure $player = null,
        ?\Closure $assets = null,
    ) {
        $this->embeds = new EmbedOutput(
            $providers ?? EmbedProviders::lazyBuiltIn(),
            $player ?? static fn (): EmbedPlayer => new PrivacyEmbedPlayer(),
            $addresses,
            $assets ?? static fn (): ?AssetCollector => null,
        );
    }

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
                name: 'datetime_local',
                callable: static fn (mixed $value, string $format = 'Y-m-d H:i', ?string $zone = null): string => DateTimeValue::fromStored($value)?->format($format, self::zone($zone)) ?? '',
            ),
            new TemplateFunctionDefinition(
                name: 'time_format',
                callable: static fn (mixed $value, string $format = 'H:i'): string => TimeOfDay::fromStored($value)?->format($format) ?? '',
            ),
            new TemplateFunctionDefinition(
                name: 'daterange_start',
                callable: static fn (mixed $value, ?string $format = null, ?string $zone = null): string => DateRange::fromStored($value)?->format(false, self::zone($zone), $format) ?? '',
            ),
            new TemplateFunctionDefinition(
                name: 'daterange_end',
                callable: static fn (mixed $value, ?string $format = null, ?string $zone = null): string => DateRange::fromStored($value)?->format(true, self::zone($zone), $format) ?? '',
            ),
            new TemplateFunctionDefinition(
                name: 'embed_player',
                callable: fn (mixed $value, ?string $title = null, ?string $locale = null): string => $this->embeds->html($value, $title, $this->locale($locale)),
                isSafe: true,
            ),
            new TemplateFunctionDefinition(
                name: 'embed_data',
                callable: fn (mixed $value): ?array => $this->embeds->data($value),
            ),
            new TemplateFunctionDefinition(
                name: 'table_html',
                callable: fn (mixed $value, ?string $locale = null): string => Table::fromStored($value)?->html($this->locale($locale), $this->locales) ?? '',
                isSafe: true,
            ),
            new TemplateFunctionDefinition(
                name: 'table_data',
                callable: fn (mixed $value, ?string $locale = null): ?array => Table::fromStored($value)?->texts($this->locale($locale), $this->locales),
            ),
            new TemplateFunctionDefinition(
                name: 'location_data',
                callable: static fn (mixed $value): ?array => Location::fromStored($value)?->toStored(),
            ),
            new TemplateFunctionDefinition(
                name: 'geo_href',
                callable: static fn (mixed $value): ?string => Location::fromStored($value)?->geoHref(),
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

    /**
     * The zone named, else the site's (app.timezone).
     *
     * @throws \LogicException for a name that is no time zone
     */
    private static function zone(?string $name): \DateTimeZone
    {
        try {
            return new \DateTimeZone($name ?? date_default_timezone_get());
        } catch (\Exception) {
            throw new \LogicException('Unknown time zone "' . $name . '"; use a name such as "Europe/Zurich" or "UTC", or leave it out for the site\'s zone.');
        }
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
