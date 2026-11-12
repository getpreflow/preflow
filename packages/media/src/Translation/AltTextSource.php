<?php

declare(strict_types=1);

namespace Preflow\Media\Translation;

use Preflow\Data\Locales;
use Preflow\I18n\Machine\Scope;
use Preflow\I18n\Machine\TextSource;
use Preflow\I18n\Machine\TranslationUnit;
use Preflow\I18n\Machine\WriteOutcome;
use Preflow\I18n\Machine\WriteOutcomeStatus;
use Preflow\Media\MediaLibrary;

/**
 * The alt texts of the asset library as a text source: one item per live asset, one unit
 * "alt.<locale>" per language, translated from the default language's alt text. A job can ask for
 * it like for any collection (it never acts on its own), and Folio's alt button uses the same units.
 *
 * Alt texts carry no machine mark and no review state, and there is no publishing step: a write is
 * public right away (writesLive). Needed means the language has no alt text yet; a text that is
 * there is never outdated, so only Scope::All offers it again. A write leaves a text alone that was
 * saved since units() read it: the memory of what was read lives in this instance, which
 * TextSources keeps for the request a run step runs in.
 */
final class AltTextSource implements TextSource
{
    public const string COLLECTION = 'media_alt';

    /** @var array<string, string> "id|locale" => the alt text units() saw ('' for none) */
    private array $seen = [];

    public function __construct(
        private readonly MediaLibrary $media,
        private readonly Locales $locales,
    ) {}

    public function collection(): string
    {
        return self::COLLECTION;
    }

    public function label(): string
    {
        return 'Alt texts';
    }

    public function items(): array
    {
        $default = $this->locales->default;
        $ids = [];
        foreach ($this->media->all() as $asset) {
            if (trim($asset->alt[$default] ?? '') !== '') {
                $ids[] = $asset->id;
            }
        }

        return $ids;
    }

    public function title(string $id): string
    {
        return $this->media->find($id)?->label() ?? $id;
    }

    public function units(string $id, string $to, Scope $scope): array
    {
        $default = $this->locales->default;
        $asset = $this->media->find($id);
        if ($asset === null || $to === $default || !$this->locales->has($to)) {
            return [];
        }
        $source = trim($asset->alt[$default] ?? '');
        $current = trim($asset->alt[$to] ?? '');
        $this->seen[$id . '|' . $to] = $current;
        if ($source === '' || ($scope === Scope::Needed && $current !== '')) {
            return [];
        }

        return [new TranslationUnit(
            id: 'alt.' . $to,
            label: 'Alt text',
            source: $source,
            to: $to,
            from: $default,
            kind: 'alt',
            current: $current === '' ? null : $asset->alt[$to],
            context: ['asset' => $asset->label()],
            type: self::COLLECTION,
        )];
    }

    public function write(string $id, string $to, array $texts): WriteOutcome
    {
        $text = trim($texts['alt.' . $to] ?? '');
        $asset = $this->media->find($id);
        if ($asset === null) {
            return new WriteOutcome(WriteOutcomeStatus::Skipped, 'The asset is gone.');
        }
        if ($text === '') {
            return new WriteOutcome(WriteOutcomeStatus::Skipped, 'Nothing to write.');
        }
        if (trim($asset->alt[$to] ?? '') !== ($this->seen[$id . '|' . $to] ?? '')) {
            return new WriteOutcome(WriteOutcomeStatus::Conflict, 'Changed since it was read, left alone: alt.' . $to . '.', false, ['alt.' . $to]);
        }
        $this->media->update($id, ['alt' => [$to => $text]]);

        return new WriteOutcome(WriteOutcomeStatus::Written);
    }

    public function writesLive(): bool
    {
        return true;
    }
}
