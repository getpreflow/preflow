<?php

declare(strict_types=1);

namespace Preflow\I18n\Tests\Machine;

use PHPUnit\Framework\TestCase;
use Preflow\I18n\Machine\TranslationPrompt;

final class TranslationPromptTest extends TestCase
{
    public function test_rules_name_no_domain(): void
    {
        $rules = TranslationPrompt::rules('en', 'de');

        $this->assertContains('Never translate proper names or brand names.', $rules);
        $this->assertStringNotContainsStringIgnoringCase('game', implode("\n", $rules));
    }
}
