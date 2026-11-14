<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\View;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Folio's stylesheets read one token set (`:root` in admin.css). Guards: every var(--x) resolves, no
 * colour literal sits outside `:root` unless allow-listed with a reason, and the muted text colours
 * stay readable (WCAG AA 4.5:1) on both surfaces in both themes.
 */
final class TokensTest extends TestCase
{
    private const ASSETS = __DIR__ . '/../../assets';

    /** Folio's own stylesheets (vendor files keep their own colours). */
    private const SHEETS = ['admin.css', 'ai-translate.css', 'image-editor.css', 'preview-editor.css', 'rbac/rbac.css'];

    /**
     * Colour literals that may stay outside `:root`: file => [exact selector => reason].
     *
     * @var array<string, array<string, string>>
     */
    private const LITERAL_ALLOWED = [
        'admin.css' => [
            '.folio-focal-marker' => 'white ring and black outline: must stay visible on any image, whatever the theme',
            '.folio-crop-dot' => 'the focal marker in each crop preview: the same white ring and black outline, on any image',
            '.folio-swatch-color' => 'black inset outline for a swatch of any user colour; no token is neutral on every colour',
            '.folio-preview-frame' => 'a site page is white regardless of the admin theme',
        ],
        'preview-editor.css' => [
            '.folio-pe-overlay' => 'injected into the site page where Folio\'s tokens do not exist: this rule is its own small token set',
        ],
    ];

    /** @return list<string> */
    private static function sheets(): array
    {
        return self::SHEETS;
    }

    private static function css(string $file): string
    {
        $css = (string) file_get_contents(self::ASSETS . '/' . $file);

        return (string) preg_replace('~/\*.*?\*/~s', '', $css);
    }

    /** @return array<string, string> custom property => raw value, from every `:root { ... }` rule of admin.css */
    private static function rootTokens(): array
    {
        $tokens = [];
        foreach (self::rules(self::css('admin.css')) as [$selector, $body]) {
            if (trim($selector) !== ':root') {
                continue;
            }
            if (preg_match_all('~(--[\w-]+)\s*:\s*((?:[^;()]|\((?:[^()]|\([^()]*\))*\))*);~', $body, $m, PREG_SET_ORDER)) {
                foreach ($m as $d) {
                    $tokens[$d[1]] = trim($d[2]);
                }
            }
        }

        return $tokens;
    }

    /** @return list<array{0: string, 1: string}> innermost [selector, body] pairs (at-rule wrappers are skipped) */
    private static function rules(string $css): array
    {
        $out = [];
        if (preg_match_all('~([^{}]+)\{([^{}]*)\}~', $css, $m, PREG_SET_ORDER)) {
            foreach ($m as $r) {
                $out[] = [trim($r[1]), $r[2]];
            }
        }

        return $out;
    }

    public function testEveryVarUsedIsDefined(): void
    {
        $root = self::rootTokens();
        $this->assertNotEmpty($root);
        $missing = [];
        foreach (self::sheets() as $file) {
            $css = self::css($file);
            // preview-editor.css runs on the site's page: it defines its own custom properties.
            $local = [];
            if (preg_match_all('~(--[\w-]+)\s*:~', $css, $d)) {
                $local = array_flip($d[1]);
            }
            if (preg_match_all('~var\(\s*(--[\w-]+)~', $css, $m)) {
                foreach (array_unique($m[1]) as $name) {
                    if (isset($root[$name])) {
                        continue;
                    }
                    // Local custom properties (set on a component, e.g. --folio-chevron) are fine
                    // when the same file sets them; Folio tokens must come from :root.
                    if (isset($local[$name]) && !str_starts_with($name, '--c-')) {
                        continue;
                    }
                    $missing[] = $file . ' uses ' . $name;
                }
            }
        }
        $this->assertSame([], $missing, "var() without a definition in :root:\n" . implode("\n", $missing));
    }

    public function testNoColourLiteralsOutsideRoot(): void
    {
        $offenders = [];
        foreach (self::sheets() as $file) {
            foreach (self::rules(self::css($file)) as [$selector, $body]) {
                if ($selector === ':root') {
                    continue;
                }
                $plain = (string) preg_replace('~url\((?:[^()"\']|"[^"]*"|\'[^\']*\')*\)~', '', $body);
                if (!preg_match('~#[0-9a-fA-F]{3,8}\b|\b(?:rgba?|hsla?|hwb|oklch|oklab|lab|lch)\(~', $plain)) {
                    continue;
                }
                $selectors = array_map('trim', explode(',', $selector));
                $allowed = self::LITERAL_ALLOWED[$file] ?? [];
                foreach ($selectors as $s) {
                    if (!isset($allowed[$s])) {
                        $offenders[] = $file . ': ' . $selector;
                        break;
                    }
                }
            }
        }
        $this->assertSame([], array_values(array_unique($offenders)), "Colour literals outside :root (use a token or allow-list with a reason):\n" . implode("\n", array_unique($offenders)));
    }

    public function testAllowListEntriesStillExist(): void
    {
        foreach (self::LITERAL_ALLOWED as $file => $entries) {
            $selectors = [];
            foreach (self::rules(self::css($file)) as [$selector, $body]) {
                foreach (explode(',', $selector) as $s) {
                    $selectors[trim($s)] = true;
                }
            }
            foreach ($entries as $selector => $reason) {
                $this->assertArrayHasKey($selector, $selectors, "Stale allow-list entry {$file}: {$selector}");
                $this->assertNotSame('', $reason);
            }
        }
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function textPairs(): iterable
    {
        foreach (['--c-text-subtle', '--c-text-muted'] as $text) {
            foreach (['--c-surface', '--c-bg'] as $bg) {
                foreach ([0 => 'light', 1 => 'dark'] as $i => $theme) {
                    yield "$text on $bg ($theme)" => [$text, $bg, $i];
                }
            }
        }
    }

    #[DataProvider('textPairs')]
    public function testMutedTextContrast(string $text, string $bg, int $half): void
    {
        $root = self::rootTokens();
        $fg = $this->half($root[$text], $half);
        $bgc = $this->half($root[$bg], $half);
        $ratio = $this->contrast($fg, $bgc);
        $this->assertGreaterThanOrEqual(4.5, $ratio, sprintf('%s %s on %s %s = %.2f', $text, $fg, $bg, $bgc, $ratio));
    }

    private function half(string $value, int $index): string
    {
        $this->assertSame(1, preg_match('~^light-dark\(\s*(#[0-9a-fA-F]{6})\s*,\s*(#[0-9a-fA-F]{6})\s*\)$~', $value, $m), "not a light-dark(hex, hex): $value");

        return $m[$index + 1];
    }

    private function luminance(string $hex): float
    {
        $c = array_map(static function (string $h): float {
            $v = hexdec($h) / 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, str_split(ltrim($hex, '#'), 2));

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    private function contrast(string $a, string $b): float
    {
        $la = $this->luminance($a);
        $lb = $this->luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }
}
