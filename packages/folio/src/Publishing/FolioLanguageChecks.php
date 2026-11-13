<?php

declare(strict_types=1);

namespace Preflow\Folio\Publishing;

use Preflow\Data\DynamicRecord;
use Preflow\Folio\Content\TranslatableText;
use Preflow\Folio\Content\TranslationStatus;
use Preflow\Folio\FolioText;
use Preflow\Publishing\LanguageChecks;

/**
 * The languages of a record at publishing, as Folio tracks them (TranslationStatus): publishing
 * clears the machine marks of the visible languages, and a visible language that lacks texts the
 * default language has is named in the admin's words (folio.publish.incomplete). Outdated
 * translations never block.
 */
final class FolioLanguageChecks implements LanguageChecks
{
    public function __construct(
        private readonly TranslationStatus $translations,
    ) {}

    public function clearMachineMarks(DynamicRecord $record): void
    {
        $cleared = $this->translations->clearLocales($record, $record->availableLocales())->toArray();
        if (array_key_exists(TranslationStatus::MACHINE, $cleared)) {
            $record->set(TranslationStatus::MACHINE, $cleared[TranslationStatus::MACHINE]);
        }
    }

    public function incomplete(DynamicRecord $record): array
    {
        $def = $record->getType();
        $errors = [];
        foreach ($record->availableLocales() as $locale) {
            if ($locale === $def->locales->default) {
                continue;
            }
            $missing = $this->translations->missing($def, $record, $locale);
            if ($missing === []) {
                continue;
            }
            $errors['_translations.' . $locale] = [FolioText::get('folio.publish.incomplete', [
                'language' => self::languageName($locale),
                'texts' => self::labelList($missing),
            ])];
        }

        return $errors;
    }

    public function stateKeys(): array
    {
        return [TranslationStatus::KEY, TranslationStatus::SOURCES, TranslationStatus::MACHINE];
    }

    private static function languageName(string $locale): string
    {
        $name = FolioText::get('folio.languages.' . $locale);

        return $name !== 'folio.languages.' . $locale ? $name : strtoupper($locale);
    }

    /** @param list<TranslatableText> $texts the first five labels, then "(+N)" */
    private static function labelList(array $texts): string
    {
        $labels = array_map(static fn (TranslatableText $t): string => $t->label, $texts);

        return implode(', ', array_slice($labels, 0, 5)) . (count($labels) > 5 ? ' … (+' . (count($labels) - 5) . ')' : '');
    }
}
