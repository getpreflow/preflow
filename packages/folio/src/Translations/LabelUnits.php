<?php

declare(strict_types=1);

namespace Preflow\Folio\Translations;

use Preflow\Folio\Http\InterfaceLocales;
use Preflow\Folio\Http\TranslationsController;
use Preflow\I18n\Machine\Scope;
use Preflow\I18n\Machine\TextFormat;
use Preflow\I18n\Machine\TranslationUnit;

/**
 * The AI units of Settings → Translations: the texts of one page of the screen (same group,
 * language, search and page, so every unit has its input on the page the editor looks at), as
 * the screen shows them. The unit id is the text's key. Needed means the language has no text of
 * its own and no override while the source language has one; All every text with a source.
 * The source is English (the delivered column), with its own override applied.
 */
final class LabelUnits
{
    public function __construct(
        private readonly EditableTexts $texts,
        private readonly StoredOverrides $overrides,
        private readonly InterfaceLocales $locales,
    ) {}

    /** The language to translate into, as the screen offers it for the group; the source language of it, or null when there is none. */
    public function sourceFor(string $group, string $locale): ?string
    {
        if (!in_array($group, $this->texts->groups(), true)
            || !in_array($locale, $this->texts->locales($group, $this->locales->all()), true)) {
            return null;
        }

        return $this->texts->sourceLocale($locale);
    }

    /**
     * @return list<TranslationUnit>|null null for a group or language the screen does not offer
     */
    public function forPage(string $group, string $locale, string $search, int $page, Scope $scope): ?array
    {
        $from = $this->sourceFor($group, $locale);
        if ($from === null) {
            return null;
        }
        $overrides = $this->overrides->all($locale);
        $sourceOverrides = $this->overrides->all($from);

        $rows = [];
        foreach ($this->texts->rows($group, $locale) as $key => $row) {
            $row['override'] = $overrides[$key] ?? null;
            if ($search === '' || TranslationsController::matches($search, $row)) {
                $rows[] = $row;
            }
        }
        $last = max(1, (int) ceil(count($rows) / TranslationsController::PER_PAGE));
        $page = min($last, max(1, $page));

        $units = [];
        foreach (array_slice($rows, ($page - 1) * TranslationsController::PER_PAGE, TranslationsController::PER_PAGE) as $row) {
            $key = $row['key'];
            $source = $sourceOverrides[$key] ?? $this->texts->ownText($key, $from);
            if ($source === null) {
                continue;
            }
            $current = $row['override'] ?? $this->texts->ownText($key, $locale);
            if ($scope === Scope::Needed && $current !== null) {
                continue;
            }
            $units[] = new TranslationUnit(
                id: $key,
                label: $key,
                source: $source,
                to: $locale,
                from: $from,
                format: EditableTexts::markup($source) ? TextFormat::Html : TextFormat::Plain,
                kind: 'label',
                current: $current,
                context: ['group' => $group],
            );
        }

        return $units;
    }
}
