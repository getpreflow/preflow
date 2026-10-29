<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Field;

use Preflow\Data\Locales;
use Preflow\Folio\Content\TranslatableText;
use Preflow\Folio\Field\ReportsTranslatableTexts;
use Preflow\Folio\Field\Types\AbstractScalarFieldType;

/** A custom field type storing [{id, text: {en, de}}] that reports its texts to the translation status. */
final class FactsFieldType extends AbstractScalarFieldType implements ReportsTranslatableTexts
{
    public function key(): string
    {
        return 'facts';
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
}
