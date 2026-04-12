<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use App\Components\LocaleSwitcher\LocaleSwitcher;
use Preflow\Core\Http\RequestContext;
use Preflow\I18n\Translator;

final class LocaleSwitcherTest extends TestCase
{
    private function createComponent(string $locale, string $path, array $locales = ['en', 'de']): LocaleSwitcher
    {
        $langDir = sys_get_temp_dir() . '/preflow_ls_test_' . uniqid();
        mkdir($langDir . '/en', 0755, true);
        mkdir($langDir . '/de', 0755, true);
        file_put_contents($langDir . '/en/app.php', '<?php return [];');
        file_put_contents($langDir . '/de/app.php', '<?php return [];');

        $translator = new Translator($langDir, $locale, 'en');
        $requestContext = new RequestContext(path: $path, method: 'GET');

        $component = new LocaleSwitcher($translator, $requestContext);
        $component->setProps(['locales' => $locales]);
        $component->resolveState();

        // Cleanup temp dirs
        foreach (glob($langDir . '/{en,de}/app.php', GLOB_BRACE) as $f) {
            unlink($f);
        }
        rmdir($langDir . '/en');
        rmdir($langDir . '/de');
        rmdir($langDir);

        return $component;
    }

    public function test_renders_all_locales(): void
    {
        $component = $this->createComponent('en', '/blog');
        $this->assertCount(2, $component->locales);
        $this->assertSame('EN', $component->locales[0]['label']);
        $this->assertSame('DE', $component->locales[1]['label']);
    }

    public function test_marks_current_locale_active(): void
    {
        $component = $this->createComponent('de', '/blog');
        $this->assertFalse($component->locales[0]['active']); // EN
        $this->assertTrue($component->locales[1]['active']);   // DE
    }

    public function test_generates_correct_urls(): void
    {
        $component = $this->createComponent('en', '/blog');
        $this->assertSame('/en/blog', $component->locales[0]['url']);
        $this->assertSame('/de/blog', $component->locales[1]['url']);
    }

    public function test_handles_root_path(): void
    {
        $component = $this->createComponent('en', '/');
        $this->assertSame('/en', $component->locales[0]['url']);
        $this->assertSame('/de', $component->locales[1]['url']);
    }
}
