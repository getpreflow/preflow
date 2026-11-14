<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Assets;

use PHPUnit\Framework\TestCase;

/**
 * The asset field's card (AssetFieldType, admin.js) and the asset library's screens (panel, page,
 * picker, grid) share admin.css. A bare rule for a class both use styles both: 0.75's field card
 * rule `.folio-asset-preview { width: 88px }` shrank the library's image to a thumbnail, so its focal
 * point could not be set. The card's preview has its own class; the classes both still use are
 * styled for the card only under the card's item.
 */
final class AssetCardClassesTest extends TestCase
{
    private const string FOLIO = __DIR__ . '/../..';

    /** @return list<string> the class names in class="…" attributes of $source */
    private static function classes(string $source): array
    {
        preg_match_all('/class="([^"{}]*)"/', $source, $m);
        $names = [];
        foreach ($m[1] as $list) {
            foreach (preg_split('/\s+/', trim($list)) ?: [] as $name) {
                if (str_starts_with($name, 'folio-asset')) {
                    $names[$name] = true;
                }
            }
        }

        return array_keys($names);
    }

    private static function css(): string
    {
        return (string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents(self::FOLIO . '/assets/admin.css'));
    }

    public function test_the_field_card_and_the_library_use_different_preview_classes(): void
    {
        $card = self::classes((string) file_get_contents(self::FOLIO . '/src/Field/Types/AssetFieldType.php'));
        $panel = self::classes((string) file_get_contents(self::FOLIO . '/templates/admin/assets/panel.twig'));

        $this->assertContains('folio-asset-card-preview', $card);
        $this->assertNotContains('folio-asset-preview', $card, 'the library panel\'s class');
        $this->assertContains('folio-asset-preview', $panel);
        $this->assertStringContainsString("querySelectorAll('.folio-asset-card-preview')", (string) file_get_contents(self::FOLIO . '/assets/admin.js'));
    }

    public function test_classes_both_use_are_styled_for_the_card_only_under_its_item(): void
    {
        $card = self::classes((string) file_get_contents(self::FOLIO . '/src/Field/Types/AssetFieldType.php'));
        $library = [];
        foreach (glob(self::FOLIO . '/templates/admin/assets/*.twig') ?: [] as $path) {
            $library = [...$library, ...self::classes((string) file_get_contents($path))];
        }
        $shared = array_values(array_intersect($card, $library));
        sort($shared);
        $this->assertSame(['folio-asset-meta', 'folio-asset-thumb'], $shared, 'a new shared class needs the same care');

        $css = self::css();
        // The card's own rules for them sit under .folio-asset-item; their bare rules are the library's.
        $this->assertStringContainsString('.folio-asset-item .folio-asset-thumb {', $css);
        $this->assertStringContainsString('.folio-asset-item .folio-asset-meta {', $css);
        preg_match_all('~(?:^|})\s*\.folio-asset-meta\s*\{~', $css, $bare);
        $this->assertSame([], $bare[0], 'no bare .folio-asset-meta rule (the library has .folio-asset-meta, .folio-help)');
    }

    public function test_the_card_preview_rules_are_not_the_panels(): void
    {
        $css = self::css();
        preg_match_all('~([^{}]*\.folio-asset-preview\b(?!-)[^{}]*)\{([^}]*)\}~', $css, $rules, PREG_SET_ORDER);
        $this->assertNotEmpty($rules);
        foreach ($rules as [, $selector, $body]) {
            $this->assertDoesNotMatchRegularExpression('/(^|;)\s*(width|aspect-ratio|overflow)\s*:/', $body, 'the library preview keeps its size: ' . trim($selector));
        }
        $this->assertMatchesRegularExpression('~\.folio-asset-card-preview\s*\{[^}]*width:\s*88px~', $css);
    }
}
