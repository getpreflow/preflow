<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Driver;

use PHPUnit\Framework\SkippedWithMessageException;

/**
 * A driver that declares accentFreeMatchingMissing() (@since 0.84.0): the tests of the accent-free
 * rule skip, every other edge case of the matching rule (case, ß, Kelvin sign, long s, width forms,
 * dotless ı, NOT LIKE) still runs and must pass. The inherited contract runs them; this pins the split.
 */
final class JsonContractWithoutAccentRuleTest extends JsonContractTest
{
    protected function accentFreeMatchingMissing(): ?string
    {
        return 'This driver ignores no accents.';
    }

    public function test_only_the_accent_tests_skip(): void
    {
        foreach (['test_like_ignores_accents', 'test_not_like_ignores_accents', 'test_loose_search_ignores_accents', 'test_the_dot_of_a_dotted_i_is_an_accent', 'test_equals_still_respects_accents_while_like_ignores_them'] as $accent) {
            try {
                $this->$accent();
                $this->fail("{$accent} should skip");
            } catch (SkippedWithMessageException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach (['test_sharp_s_is_one_letter', 'test_the_kelvin_sign_is_a_k_and_the_long_s_an_s', 'test_width_and_compatibility_forms_are_their_letter', 'test_not_like_follows_the_rule', 'test_loose_search_follows_the_rule', 'test_dotted_i_is_an_i_and_dotless_i_a_letter_of_its_own'] as $rule) {
            $this->$rule(); // a skip here would throw: the rule's other cases stay tested
            $this->addToAssertionCount(1);
        }
    }
}
