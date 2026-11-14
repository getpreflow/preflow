<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Assets;

use PHPUnit\Framework\TestCase;

/**
 * Every text key the admin scripts ask for exists in en, de and fr, so no user sees an empty text or
 * a raw key (folioT and fields.js's t() give '' for a missing key). The scripts' keys are the
 * #folio-i18n dictionary's (FolioServiceProvider::jsTexts): "x" is folio.js.x, "fields.x" is
 * folio.fields.x and "redirects.prompt.x" is folio.redirects.prompt.x. admin.js's removal prompt has
 * its own t() that prefixes "redirects.prompt.".
 */
final class JsTextKeysTest extends TestCase
{
    private const array SCRIPTS = ['admin.js', 'fields.js', 'blocks.js', 'editor-frame.js'];

    public function test_every_js_text_key_exists_in_every_language(): void
    {
        $keys = [];
        foreach (self::SCRIPTS as $script) {
            foreach ($this->keys($script) as $key) {
                $keys[$key][] = $script;
            }
        }
        $this->assertGreaterThan(60, count($keys), 'the scan finds the scripts\' keys');
        $this->assertArrayHasKey('fields.upload_failed', $keys);
        $this->assertArrayHasKey('redirects.prompt.send_to', $keys); // through the removal prompt's t()

        foreach (['en', 'de', 'fr'] as $lang) {
            $texts = self::flatten(require dirname(__DIR__, 2) . "/lang/{$lang}/folio.php");
            foreach ($keys as $key => $scripts) {
                $langKey = str_starts_with($key, 'fields.') || str_starts_with($key, 'redirects.prompt.') ? $key : 'js.' . $key;
                $this->assertNotSame('', (string) ($texts[$langKey] ?? ''), "{$lang}: folio.{$langKey} (" . implode(', ', array_unique($scripts)) . ')');
            }
        }
    }

    public function test_admin_js_has_one_local_t_the_removal_prompt(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/admin.js');
        $this->assertSame(1, substr_count($js, "var t = function (key, params) { return folioT('redirects.prompt.' + key, params); };"));
        $this->assertSame(0, preg_match('/function t\(/', $js));
    }

    /** @return list<string> */
    private function keys(string $script): array
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/' . $script);
        $keys = [];
        preg_match_all("/(?<![\\w.\$])(folioT|t)\\(\\s*'([^']+)'/", $js, $calls, PREG_SET_ORDER);
        foreach ($calls as [, $fn, $key]) {
            if (str_ends_with($key, '.')) {
                continue; // a prefix helper: folioT('redirects.prompt.' + key)
            }
            $keys[] = $fn === 't' && $script === 'admin.js' ? 'redirects.prompt.' . $key : $key;
        }
        // Keys passed through a variable (admin.js's matrix labels, fields.js's row buttons and dates).
        preg_match_all("/'((?:fields|redirects\\.prompt)\\.[a-z_]+)'/", $js, $literals);
        foreach ($literals[1] as $key) {
            $keys[] = $key;
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param array<string, mixed> $texts
     * @return array<string, mixed>
     */
    private static function flatten(array $texts, string $prefix = ''): array
    {
        $out = [];
        foreach ($texts as $key => $value) {
            if (is_array($value)) {
                $out += self::flatten($value, $prefix . $key . '.');
            } else {
                $out[$prefix . $key] = $value;
            }
        }

        return $out;
    }
}
