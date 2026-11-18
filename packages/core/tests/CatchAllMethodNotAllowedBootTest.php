<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Routing\PageExists;
use Preflow\Core\Routing\PageLookups;
use Preflow\Core\Routing\RouteMode;
use Preflow\Core\Routing\RouterInterface;
use Preflow\Routing\RouteEntry;
use Psr\Http\Message\ResponseInterface;

/** A wrong method behind a catch-all is a 405, unless a registered page lookup says a page answers the path. */
final class CatchAllMethodNotAllowedBootTest extends TestCase
{
    private string $dir;

    /** @var list<array{string, string}> what the lookup was asked: locale, path */
    public static array $asked = [];

    protected function setUp(): void
    {
        self::$asked = [];
        $this->dir = sys_get_temp_dir() . '/catch_all_405_boot_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        mkdir($this->dir . '/app/pages', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'k','locale'=>'en'];\n");
        file_put_contents($this->dir . '/app/pages/[...path].twig', '<html><head></head><body>catch-all</body></html>');
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    /** @param bool|null $lookupAnswers whether the registered page lookup says a page answers /newsletter; null: no lookup registered */
    private function app(bool $prefixed, ?bool $lookupAnswers = true): Application
    {
        if ($prefixed) {
            file_put_contents($this->dir . '/config/i18n.php', "<?php\nreturn ['default'=>'en','available'=>['en','de'],'url_strategy'=>'prefix','prefix_default'=>true];\n");
        }
        $app = Application::create($this->dir);
        $app->boot();
        $app->container()->get(RouterInterface::class)->getCollection()->add(
            new RouteEntry('/newsletter', 'App\\Hook@receive', 'POST', RouteMode::Action, [], [], '#^/newsletter$#', false),
        );
        if ($lookupAnswers !== null) {
            $app->container()->instance(NewsletterLookup::class, new NewsletterLookup($lookupAnswers));
            $app->container()->get(PageLookups::class)->add(NewsletterLookup::class);
        }

        return $app;
    }

    private function get(Application $app, string $path): ResponseInterface
    {
        return $app->handle((new Psr17Factory())->createServerRequest('GET', 'http://localhost' . $path));
    }

    public function test_a_page_at_the_posted_address_still_answers_get(): void
    {
        $response = $this->get($this->app(false), '/newsletter');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertContains(['', '/newsletter'], array_map(static fn (array $a): array => [$a[0] === 'en' ? '' : $a[0], $a[1]], self::$asked));
    }

    public function test_the_lookup_is_asked_the_path_without_the_language_prefix(): void
    {
        $response = $this->get($this->app(true), '/de/newsletter');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertContains(['de', '/newsletter'], self::$asked);
    }

    public function test_a_posted_address_no_page_answers_is_405_with_allow(): void
    {
        foreach ([false, true] as $prefixed) {
            foreach ([false, null] as $lookupAnswers) { // a lookup saying no; no lookup at all (an API-only app)
                $app = $this->app($prefixed, $lookupAnswers);
                $response = $this->get($app, $prefixed ? '/de/newsletter' : '/newsletter');
                $this->assertSame(405, $response->getStatusCode(), ($prefixed ? 'prefixed' : 'plain') . ($lookupAnswers === null ? ', no lookup' : ''));
                $this->assertSame('POST', $response->getHeaderLine('Allow'));
                $this->tearDown();
                $this->setUp();
            }
        }
    }

    public function test_an_address_without_a_post_route_reaches_the_catch_all(): void
    {
        $this->assertSame(200, $this->get($this->app(false, false), '/other')->getStatusCode());
    }
}

final class NewsletterLookup implements PageExists
{
    public function __construct(private readonly bool $answers) {}

    public function exists(string $locale, string $path): bool
    {
        CatchAllMethodNotAllowedBootTest::$asked[] = [$locale, $path];

        return $this->answers && $path === '/newsletter';
    }
}
