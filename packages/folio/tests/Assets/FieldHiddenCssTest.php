<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Assets;

use PHPUnit\Framework\TestCase;

/**
 * The field controls' scripts show and hide parts with the hidden attribute (fields.js, admin.js). A
 * class whose rule sets display beats the browser's [hidden] rule, so each such class needs its own
 * [hidden] rule (0.75: "No alt text yet" showed on every picked card).
 */
final class FieldHiddenCssTest extends TestCase
{
    /** Classes the field scripts toggle with hidden. */
    private const array TOGGLED = [
        'folio-asset-warn', 'folio-asset-meta', 'folio-asset-card-preview', 'folio-asset-focal', 'folio-relation-meta',
        'folio-relation-results', 'folio-input-action', 'form-error', 'folio-date-hint',
    ];

    public function test_every_toggled_class_that_sets_display_has_a_hidden_rule(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/admin.css');
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);
        $this->assertStringContainsString(':where(.form-group) [hidden] { display: none !important; }', $css, 'the field area catch-all');

        $missing = [];
        foreach (self::TOGGLED as $class) {
            // A rule whose selector ends in the class itself and that sets display (not to none).
            preg_match_all('~([^{}]*\.' . preg_quote($class, '~') . ')\s*(?:,[^{}]*)?\{([^}]*)\}~', $css, $rules, PREG_SET_ORDER);
            $sets = false;
            foreach ($rules as [, , $body]) {
                if (preg_match('/(^|;)\s*display:\s*(?!none)/', $body) === 1) {
                    $sets = true;
                }
            }
            if ($sets && !preg_match('~\.' . preg_quote($class, '~') . '\[hidden\]~', $css)) {
                $missing[] = $class;
            }
        }
        $this->assertSame([], $missing, 'classes that set display without a [hidden] rule');
    }
}
