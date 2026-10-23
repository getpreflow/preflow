<?php

declare(strict_types=1);

namespace Preflow\Members\Tests;

use PHPUnit\Framework\TestCase;

/** The package copy: the same keys and placeholders in every language, and no site's own words. */
final class MembersCopyTest extends TestCase
{
    private const array LOCALES = ['en', 'de', 'fr'];

    public function test_every_language_has_the_same_keys(): void
    {
        $en = $this->flat('en');
        foreach (['de', 'fr'] as $locale) {
            $this->assertSame(array_keys($en), array_keys($this->flat($locale)), $locale);
        }
    }

    public function test_the_groups_smart_stack_uses_and_the_mails(): void
    {
        $copy = $this->copy('en');
        foreach (['form', 'closed', 'errors', 'inbox', 'confirm', 'thanks', 'login', 'forgot', 'reset', 'resend', 'account', 'mail'] as $group) {
            $this->assertArrayHasKey($group, $copy, $group);
        }
        foreach (['confirm', 'existing', 'reset', 'notice', 'accepted', 'declined'] as $mail) {
            $this->assertIsString($copy['mail'][$mail]['subject'] ?? null, $mail);
            $this->assertIsString($copy['mail'][$mail]['body'] ?? null, $mail);
        }
        $this->assertArrayNotHasKey('mail', $copy['errors'], 'errors.mail was unused');
        foreach (['code', 'code_or_answer'] as $siteKey) {
            $this->assertArrayNotHasKey($siteKey, $copy['errors'], $siteKey);
        }
        foreach (['code', 'code_hint', 'or', 'answer', 'answer_hint'] as $siteKey) {
            $this->assertArrayNotHasKey($siteKey, $copy['form'], $siteKey);
        }
    }

    public function test_placeholders_match_across_languages(): void
    {
        $en = $this->flat('en');
        foreach (['de', 'fr'] as $locale) {
            foreach ($this->flat($locale) as $key => $text) {
                $this->assertSame(self::placeholders($en[$key]), self::placeholders($text), "{$locale} {$key}");
            }
        }
    }

    public function test_no_site_words(): void
    {
        foreach (self::LOCALES as $locale) {
            foreach ($this->flat($locale) as $key => $text) {
                $this->assertDoesNotMatchRegularExpression('/smart.?stack|steffen|toolbox|book|buch|livre/i', $text, "{$locale} {$key}");
            }
        }
    }

    /** @return list<string> */
    private static function placeholders(string $text): array
    {
        preg_match_all('/\{[a-z_]+\}|:[a-z_]+/', $text, $m);
        $found = array_values(array_unique($m[0]));
        sort($found);

        return $found;
    }

    /** @return array<string, mixed> */
    private function copy(string $locale): array
    {
        $copy = require dirname(__DIR__) . '/lang/' . $locale . '/members.php';
        $this->assertIsArray($copy);

        return $copy;
    }

    /** @return array<string, string> dotted key => text */
    private function flat(string $locale): array
    {
        $out = [];
        $walk = static function (array $a, string $prefix) use (&$walk, &$out): void {
            foreach ($a as $k => $v) {
                is_array($v) ? $walk($v, $prefix . $k . '.') : $out[$prefix . $k] = (string) $v;
            }
        };
        $walk($this->copy($locale), '');

        return $out;
    }
}
