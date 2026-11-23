<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Translations;

use PHPUnit\Framework\TestCase;

/** Every screen subtitle is one line (as on the boards): no more than 80 characters in en, de and fr. */
final class SubtitleLengthTest extends TestCase
{
    private const int MAX = 80;

    /** @return list<string> the translation keys the admin templates put in `{% block subtitle %}` */
    private static function keys(): array
    {
        $keys = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/templates/admin', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!str_ends_with((string) $file, '.twig')) {
                continue;
            }
            if (preg_match_all('/\{% block subtitle %\}(.*?)\{% endblock %\}/s', (string) file_get_contents((string) $file), $blocks)) {
                foreach ($blocks[1] as $block) {
                    preg_match_all("/t\\('(folio\\.[a-z_.]+)'/", $block, $found);
                    array_push($keys, ...$found[1]);
                }
            }
        }

        return array_values(array_unique($keys));
    }

    public function test_the_templates_have_subtitles_to_check(): void
    {
        $this->assertGreaterThanOrEqual(10, count(self::keys()));
    }

    public function test_no_subtitle_is_longer_than_one_line(): void
    {
        foreach (['en', 'de', 'fr'] as $locale) {
            $lang = require dirname(__DIR__, 2) . '/lang/' . $locale . '/folio.php';
            foreach (self::keys() as $key) {
                $value = $lang;
                foreach (explode('.', substr($key, strlen('folio.'))) as $part) {
                    $value = is_array($value) ? ($value[$part] ?? null) : null;
                }
                $this->assertIsString($value, "{$locale} {$key}");
                $this->assertLessThanOrEqual(self::MAX, mb_strlen($value), "{$locale} {$key} is " . mb_strlen($value) . ' characters: ' . $value);
            }
        }
    }
}
