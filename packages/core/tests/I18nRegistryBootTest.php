<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\I18n\LocaleMiddleware;
use Preflow\I18n\TranslationRegistry;
use Preflow\I18n\Translator;

/**
 * A translator is always booted when preflow/i18n is installed (packages bring their texts
 * through extra.preflow.translations); LocaleMiddleware only when the site has lang/ or
 * config/i18n.php, so a site without either keeps its frontend unchanged.
 */
final class I18nRegistryBootTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/i18n_registry_boot_' . bin2hex(random_bytes(4));
        $this->write('config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'k','locale'=>'en'];\n");
        $this->write('app/pages/index.twig', '<p>{{ t("acme.hello") }}</p>');
        $this->write('vendor/acme/pkg/lang/en/acme.php', "<?php return ['hello' => 'Hello from acme'];");
        $this->write('vendor/composer/installed.json', (string) json_encode(['packages' => [
            ['name' => 'acme/pkg', 'install-path' => '../acme/pkg', 'extra' => ['preflow' => ['translations' => ['acme' => ['path' => 'lang', 'scope' => 'public']]]]],
        ]]));
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }

    private function write(string $relative, string $content): void
    {
        $path = $this->dir . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content);
    }

    private function boot(): Application
    {
        $app = Application::create($this->dir);
        $app->boot();

        return $app;
    }

    /** @return list<object> */
    private static function middleware(Application $app): array
    {
        $pipeline = (new \ReflectionProperty(Application::class, 'pipeline'))->getValue($app);

        return (new \ReflectionProperty($pipeline, 'middleware'))->getValue($pipeline);
    }

    private static function hasLocaleMiddleware(Application $app): bool
    {
        return array_filter(self::middleware($app), static fn (object $m): bool => $m instanceof LocaleMiddleware) !== [];
    }

    public function test_without_lang_and_config_a_translator_but_no_locale_middleware(): void
    {
        $app = $this->boot();

        $this->assertTrue($app->container()->has(Translator::class));
        $translator = $app->container()->get(Translator::class);
        $this->assertSame('Hello from acme', $translator->get('acme.hello'));
        $this->assertSame('public', $translator->scope('acme'));
        $this->assertSame('public', $app->container()->get(TranslationRegistry::class)->scope('acme'));
        $this->assertFalse(self::hasLocaleMiddleware($app));

        $body = (string) $app->handle((new Psr17Factory())->createServerRequest('GET', '/'))->getBody();
        $this->assertStringContainsString('<p>Hello from acme</p>', $body);
    }

    public function test_with_lang_it_behaves_as_today(): void
    {
        $this->write('lang/en/acme.php', "<?php return ['hello' => 'Hello from the site'];");

        $app = $this->boot();

        $this->assertTrue(self::hasLocaleMiddleware($app));
        $this->assertSame('Hello from the site', $app->container()->get(Translator::class)->get('acme.hello'));
        $body = (string) $app->handle((new Psr17Factory())->createServerRequest('GET', '/'))->getBody();
        $this->assertStringContainsString('<p>Hello from the site</p>', $body);
    }

    public function test_without_installed_json_boot_still_has_a_translator(): void
    {
        unlink($this->dir . '/vendor/composer/installed.json');
        $this->write('app/pages/index.twig', '<p>plain</p>');

        $app = $this->boot();

        $this->assertTrue($app->container()->has(Translator::class));
        $this->assertSame([], $app->container()->get(TranslationRegistry::class)->groups());
        $this->assertFalse(self::hasLocaleMiddleware($app));
    }
}
