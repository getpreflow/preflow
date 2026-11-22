<?php

declare(strict_types=1);

namespace Preflow\I18n\Tests\Machine;

use PHPUnit\Framework\TestCase;
use Preflow\I18n\Machine\TranslationTuning;

/** The instructions that go with a text to translate: i18n's own type, so a translator needs no AI package. */
final class TranslationTuningTest extends TestCase
{
    public function test_with_appends_trimmed_instructions_and_drops_empty_ones(): void
    {
        $tuning = (new TranslationTuning(['Formal.']))->with('  Be brief. ', '', '   ');

        $this->assertSame(['Formal.', 'Be brief.'], $tuning->instructions);
        $this->assertSame(['Formal.'], (new TranslationTuning(['Formal.']))->instructions, 'immutable');
    }

    public function test_the_constructor_trims_and_drops_empty_instructions_like_with(): void
    {
        $this->assertTrue((new TranslationTuning(['']))->isEmpty());
        $this->assertSame(['Formal.', 'Be brief.'], (new TranslationTuning([' Formal. ', '', '  ', 'Be brief.']))->instructions);
    }

    public function test_is_empty_without_instructions(): void
    {
        $this->assertTrue((new TranslationTuning())->isEmpty());
        $this->assertFalse((new TranslationTuning(['A']))->isEmpty());
    }
}
