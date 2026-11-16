<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Routing\Redirect\AreaMode;
use Preflow\Routing\Redirect\Redirect;
use Preflow\Routing\Redirect\RedirectStore;
use Psr\Http\Message\ResponseInterface;

/** A bare address a redirect entry answers goes to the visitor's language (redirect_bare), in two hops. */
final class LocaleUrlsRedirectEntryBootTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/locale_urls_entry_boot_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        mkdir($this->dir . '/app/pages', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'k','locale'=>'en'];\n");
        file_put_contents($this->dir . '/app/pages/index.twig', '<html><head></head><body>home</body></html>');
        file_put_contents($this->dir . '/app/pages/help.twig', '<html><head></head><body>help</body></html>');
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    private function i18n(string $extra = ''): void
    {
        file_put_contents($this->dir . '/config/routing.php', "<?php\nreturn ['canonical_paths'=>true];\n");
        file_put_contents($this->dir . '/config/i18n.php', "<?php\nreturn ['default'=>'en','available'=>['en','de'],'url_strategy'=>'prefix','prefix_default'=>true,'redirect_bare'=>true{$extra}];\n");
    }

    private function store(): RedirectStore
    {
        return new class implements RedirectStore {
            public function exact(string $path, string $locale): ?Redirect
            {
                if ($path === '/favicon/old.ico') {
                    return new Redirect('c', $path, '', false, AreaMode::One, null, null, '/favicon.ico', 301);
                }
                if ($path === '/old-help/any') {
                    return new Redirect('d', $path, '', false, AreaMode::One, null, null, '/help', 301);
                }
                if ($path === '/old-help/lang' && $locale === 'en') {
                    return new Redirect('e', $path, 'en', false, AreaMode::One, null, null, '/en/help', 301);
                }

                return $path === '/old-help' && $locale === 'de'
                    ? new Redirect('a', '/old-help', 'de', false, AreaMode::One, null, null, '/de/help', 301)
                    : null;
            }

            public function areas(string $locale): array
            {
                return $locale === 'de' ? [new Redirect('b', '/old-area', 'de', true, AreaMode::Prefix, null, null, '/de/area', 302)] : [];
            }

            public function hit(Redirect $redirect, \DateTimeImmutable $at): void {}
        };
    }

    private function app(bool $withStore, bool $beforeBoot = false): Application
    {
        $app = Application::create($this->dir);
        if ($withStore && $beforeBoot) {
            // Bound before boot, so the redirect middleware answers the entry itself.
            $app->container()->instance(RedirectStore::class, $this->store());
        }
        $app->boot();
        if ($withStore && !$beforeBoot) {
            // Bound after boot, the way an admin package's provider does.
            $app->container()->instance(RedirectStore::class, $this->store());
        }

        return $app;
    }

    private function get(Application $app, string $path, string $language = 'de'): ResponseInterface
    {
        return $app->handle((new Psr17Factory())->createServerRequest('GET', 'http://localhost' . $path)->withHeader('Accept-Language', $language));
    }

    public function test_a_bare_address_with_an_entry_goes_to_the_visitors_language(): void
    {
        $this->i18n();
        $app = $this->app(true);

        $exact = $this->get($app, '/old-help?x=1');
        $this->assertSame(302, $exact->getStatusCode());
        $this->assertSame('/de/old-help?x=1', $exact->getHeaderLine('Location'));

        $area = $this->get($app, '/old-area/deep');
        $this->assertSame(302, $area->getStatusCode());
        $this->assertSame('/de/old-area/deep', $area->getHeaderLine('Location'));
    }

    public function test_an_entry_of_another_language_does_not_count(): void
    {
        $this->i18n();
        $english = $this->get($this->app(true), '/old-help', 'en');

        $this->assertNotSame(302, $english->getStatusCode());
        $this->assertFalse($english->hasHeader('Location'));
    }

    public function test_with_no_entry_and_no_page_the_address_is_served_as_before(): void
    {
        $this->i18n();

        // A store, but no entry for the address; and no store at all.
        foreach ([[true, '/nothing-here'], [false, '/old-help']] as [$withStore, $path]) {
            $response = $this->get($this->app($withStore), $path);
            $this->assertNotSame(302, $response->getStatusCode());
            $this->assertFalse($response->hasHeader('Location'));
        }
    }

    public function test_a_page_still_redirects_as_before(): void
    {
        $this->i18n();

        $this->assertSame('/de/help', $this->get($this->app(false), '/help')->getHeaderLine('Location'));
    }

    public function test_an_entry_for_every_language_answers_in_one_301(): void
    {
        $this->i18n();
        $response = $this->get($this->app(true, true), '/favicon/old.ico');

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('/favicon.ico', $response->getHeaderLine('Location'));
    }

    public function test_an_entry_for_one_language_still_goes_through_the_language_redirect(): void
    {
        $this->i18n();
        $response = $this->get($this->app(true), '/old-help');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/de/old-help', $response->getHeaderLine('Location'));
    }

    public function test_an_untidy_bare_address_with_a_language_entry_gets_the_clean_301(): void
    {
        $this->i18n();
        $response = $this->get($this->app(true), '/old-help/lang/');

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('/old-help/lang', $response->getHeaderLine('Location'));
    }

    public function test_an_untidy_bare_address_with_an_every_language_entry_answers_its_target_in_one_hop(): void
    {
        $this->i18n();
        $response = $this->get($this->app(true), '/old-help/any/');

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('/help', $response->getHeaderLine('Location'));
    }
}
