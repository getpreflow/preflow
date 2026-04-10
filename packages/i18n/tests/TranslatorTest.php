<?php

declare(strict_types=1);

namespace Preflow\I18n\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\I18n\Translator;

final class TranslatorTest extends TestCase
{
    private string $langDir;

    protected function setUp(): void
    {
        $this->langDir = sys_get_temp_dir() . '/preflow_i18n_test_' . uniqid();

        // Create en/app.php
        $this->createLangFile('en', 'app', [
            'name' => 'Preflow',
            'welcome' => 'Welcome, :name!',
            'nested' => [
                'deep' => 'Nested value',
            ],
        ]);

        // Create en/blog.php
        $this->createLangFile('en', 'blog', [
            'title' => 'Blog',
            'post_count' => '{0} No posts|{1} One post|[2,*] :count posts',
            'published_at' => 'Published :date',
        ]);

        // Create de/app.php
        $this->createLangFile('de', 'app', [
            'name' => 'Preflow',
            'welcome' => 'Willkommen, :name!',
        ]);

        // Create de/blog.php
        $this->createLangFile('de', 'blog', [
            'title' => 'Blog',
            'post_count' => '{0} Keine Beiträge|{1} Ein Beitrag|[2,*] :count Beiträge',
        ]);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->langDir);
    }

    private function createLangFile(string $locale, string $group, array $translations): void
    {
        $dir = $this->langDir . '/' . $locale;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $content = '<?php return ' . var_export($translations, true) . ';';
        file_put_contents($dir . '/' . $group . '.php', $content);
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->deleteDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function test_simple_translation(): void
    {
        $t = new Translator($this->langDir, 'en', 'en');

        $this->assertSame('Blog', $t->get('blog.title'));
    }

    public function test_with_parameter_replacement(): void
    {
        $t = new Translator($this->langDir, 'en', 'en');

        $result = $t->get('app.welcome', ['name' => 'Steffen']);

        $this->assertSame('Welcome, Steffen!', $result);
    }

    public function test_nested_key(): void
    {
        $t = new Translator($this->langDir, 'en', 'en');

        $this->assertSame('Nested value', $t->get('app.nested.deep'));
    }

    public function test_pluralization(): void
    {
        $t = new Translator($this->langDir, 'en', 'en');

        $this->assertSame('No posts', $t->choice('blog.post_count', 0));
        $this->assertSame('One post', $t->choice('blog.post_count', 1));
        $this->assertSame('5 posts', $t->choice('blog.post_count', 5, ['count' => 5]));
    }

    public function test_locale_switching(): void
    {
        $t = new Translator($this->langDir, 'en', 'en');

        $this->assertSame('Blog', $t->get('blog.title'));

        $t->setLocale('de');

        $this->assertSame('Blog', $t->get('blog.title'));
    }

    public function test_german_translations(): void
    {
        $t = new Translator($this->langDir, 'de', 'en');

        $result = $t->get('app.welcome', ['name' => 'Steffen']);

        $this->assertSame('Willkommen, Steffen!', $result);
    }

    public function test_german_pluralization(): void
    {
        $t = new Translator($this->langDir, 'de', 'en');

        $this->assertSame('Keine Beiträge', $t->choice('blog.post_count', 0));
        $this->assertSame('Ein Beitrag', $t->choice('blog.post_count', 1));
        $this->assertSame('3 Beiträge', $t->choice('blog.post_count', 3, ['count' => 3]));
    }

    public function test_fallback_to_default_locale(): void
    {
        $t = new Translator($this->langDir, 'de', 'en');

        // 'published_at' only exists in en, not in de
        $result = $t->get('blog.published_at', ['date' => '2026-01-01']);

        $this->assertSame('Published 2026-01-01', $result);
    }

    public function test_missing_key_returns_key(): void
    {
        $t = new Translator($this->langDir, 'en', 'en');

        $this->assertSame('blog.nonexistent', $t->get('blog.nonexistent'));
    }

    public function test_missing_group_returns_key(): void
    {
        $t = new Translator($this->langDir, 'en', 'en');

        $this->assertSame('missing.key', $t->get('missing.key'));
    }

    public function test_get_locale(): void
    {
        $t = new Translator($this->langDir, 'en', 'en');

        $this->assertSame('en', $t->getLocale());

        $t->setLocale('de');
        $this->assertSame('de', $t->getLocale());
    }

    public function test_caches_loaded_files(): void
    {
        $t = new Translator($this->langDir, 'en', 'en');

        // First call loads from file
        $t->get('blog.title');

        // Delete the file
        unlink($this->langDir . '/en/blog.php');

        // Should still return cached value
        $this->assertSame('Blog', $t->get('blog.title'));
    }
}
