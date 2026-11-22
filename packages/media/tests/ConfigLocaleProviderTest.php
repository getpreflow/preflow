<?php

declare(strict_types=1);

namespace Preflow\Media\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\Site;
use Preflow\Core\Context\SystemClock;
use Preflow\Media\ConfigLocaleProvider;
use Preflow\Media\LocaleProvider;
use Preflow\Media\MediaServiceProvider;

final class ConfigLocaleProviderTest extends TestCase
{
    private string $cwd = '';
    private string $base = '';

    /** Array-config apps take the working directory as base path: a temporary one, not the repository root. */
    protected function setUp(): void
    {
        $this->cwd = getcwd() ?: '.';
        $this->base = sys_get_temp_dir() . '/media_locale_' . bin2hex(random_bytes(5));
        mkdir($this->base, 0777, true);
        chdir($this->base);
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->base);
    }

    private static function ctx(string $locale): RenderContext
    {
        return RenderContext::console(new Site('https://site.test', ['en', 'de', 'fr', 'it'], 'en'), new SystemClock())->in($locale);
    }

    public function test_the_context_locale_wins_over_the_app_locale(): void
    {
        $locale = new ConfigLocaleProvider('en');

        $this->assertSame('de', $locale->localeOf(self::ctx('de')));
        $this->assertSame('en', $locale->default());
    }

    public function test_the_provider_binds_it_with_the_app_locale(): void
    {
        $app = Application::create(['app' => ['locale' => 'it']]);
        $app->registerProvider(new MediaServiceProvider());
        $c = $app->container();

        $this->assertSame('it', $c->get(LocaleProvider::class)->default());
        $this->assertSame('de', $c->get(LocaleProvider::class)->localeOf(self::ctx('de')));
    }

    public function test_without_app_locale_it_is_english(): void
    {
        $app = Application::create(['app' => []]);
        $app->registerProvider(new MediaServiceProvider());

        $this->assertSame('en', $app->container()->get(LocaleProvider::class)->default());
    }
}
