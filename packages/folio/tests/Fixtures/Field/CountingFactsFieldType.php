<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Field;

use Preflow\Data\Locales;
use Preflow\Folio\Content\TranslatableText;
use Preflow\Folio\Field\ReportsTranslatableTexts;
use Preflow\Folio\Field\Types\AbstractScalarFieldType;

/** FactsFieldType's shape ([{id, text: {en, de}}]), counting how often it is asked for its texts. */
final class CountingFactsFieldType extends AbstractScalarFieldType implements ReportsTranslatableTexts
{
    public static int $calls = 0;

    public function key(): string
    {
        return 'counted_facts';
    }

    protected function inputType(): string
    {
        return 'text';
    }

    public function translatableTexts(mixed $value, array $config, Locales $locales): array
    {
        self::$calls++;
        $texts = [];
        foreach (is_array($value) ? $value : [] as $fact) {
            $texts[] = TranslatableText::of($fact['id'] . '.text', 'Fact ' . $fact['id'], $fact['text'] ?? null, $locales);
        }

        return $texts;
    }
}
