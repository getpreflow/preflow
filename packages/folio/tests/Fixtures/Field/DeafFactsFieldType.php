<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Field;

use Preflow\Data\Locales;
use Preflow\Folio\Content\TranslatableText;
use Preflow\Folio\Field\AcceptsTranslatedTexts;
use Preflow\Folio\Field\ReportsTranslatableTexts;
use Preflow\Folio\Field\Types\AbstractScalarFieldType;

/** Reports its texts and claims to accept translations, but keeps its value as it was: a path it reports but cannot take. */
final class DeafFactsFieldType extends AbstractScalarFieldType implements ReportsTranslatableTexts, AcceptsTranslatedTexts
{
    public function key(): string
    {
        return 'deaf_facts';
    }

    protected function inputType(): string
    {
        return 'text';
    }

    public function translatableTexts(mixed $value, array $config, Locales $locales): array
    {
        $texts = [];
        foreach (is_array($value) ? $value : [] as $fact) {
            $texts[] = TranslatableText::of($fact['id'] . '.text', 'Fact ' . $fact['id'], $fact['text'] ?? null, $locales);
        }

        return $texts;
    }

    public function withTranslatedTexts(mixed $value, array $texts, string $locale, array $config, Locales $locales): mixed
    {
        return $value;
    }
}
