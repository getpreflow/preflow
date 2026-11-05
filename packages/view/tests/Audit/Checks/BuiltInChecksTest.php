<?php

declare(strict_types=1);

namespace Preflow\View\Tests\Audit\Checks;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\View\Audit\AuditRegistry;
use Preflow\View\Audit\AuditRun;
use Preflow\View\Audit\Finding;
use Preflow\View\Audit\PageCheck;
use Preflow\View\Audit\PageRenderer;
use Preflow\View\Audit\RenderedPage;
use Preflow\View\Audit\RunCheck;
use Preflow\View\Seo\PublicPage;
use Preflow\View\Seo\PublicPages;

/** The built-in checks as the app registers them, and one audit of the app's pages with them. */
final class BuiltInChecksTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/audit_builtin_' . bin2hex(random_bytes(4));
        @mkdir($this->dir . '/config', 0777, true);
        @mkdir($this->dir . '/app/pages', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'audit-builtin-test-key-0123456789ab','url'=>'https://site.test'];\n");
        file_put_contents($this->dir . '/config/i18n.php', "<?php\nreturn ['default'=>'en','available'=>['en','de'],'fallback'=>'en','url_strategy'=>'prefix','cookie'=>true];\n");
        file_put_contents(
            $this->dir . '/app/pages/about.twig',
            '<html><head><title>About {{ current_locale() }}</title></head><body><main><h1>About us</h1><img src="/missing.png"><a href="/nowhere">x</a></main></body></html>',
        );
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    #[RunInSeparateProcess]
    public function test_the_app_registers_the_built_in_checks_before_the_sites_own(): void
    {
        $app = Application::create($this->dir);
        $app->boot();
        $registry = $app->container()->get(AuditRegistry::class);
        $own = new class implements PageCheck {
            public function id(): string { return 'site.own'; }
            public function check(RenderedPage $page): array { return []; }
        };
        $registry->addPage($own);

        $this->assertSame([
            'title.missing', 'description.missing', 'img.alt-missing', 'og.image-alt-missing', 'h1.count',
            'aria-hidden.focusable', 'canonical.missing', 'canonical.foreign-host', 'noindex.in-sitemap',
            'hreflang.x-default-missing', 'site.own',
        ], array_map(static fn (PageCheck $c): string => $c->id(), $registry->pageChecks()));
        $this->assertSame(
            ['title.duplicate', 'description.duplicate', 'hreflang.unpaired', 'link.broken'],
            array_map(static fn (RunCheck $c): string => $c->id(), $registry->runChecks()),
        );
        $this->assertSame([], $registry->siteChecks());
    }

    #[RunInSeparateProcess]
    public function test_an_audit_of_the_app_reports_with_the_built_in_checks(): void
    {
        $app = Application::create($this->dir);
        $app->boot();
        $container = $app->container();
        $pages = $container->get(PublicPages::class);
        $pages->source(static fn (string $locale): array => [new PublicPage('About', $locale === 'en' ? '/about' : '/de/about')]);

        $report = (new AuditRun(
            $container->get(AuditRegistry::class),
            $container->get(PageRenderer::class),
            $pages,
            $this->dir . '/storage/data/seo-audit.json',
            static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-11-05T10:00:00+00:00'),
        ))->run(600, 10, null, false, null);

        $found = array_map(static fn (Finding $f): string => "{$f->check}|{$f->severity->value}|{$f->url}|{$f->locale}", $report->findings);
        $this->assertContains('description.missing|warning|https://site.test/about|en', $found);
        $this->assertContains('description.missing|warning|https://site.test/de/about|de', $found);
        $this->assertContains('img.alt-missing|warning|https://site.test/about|en', $found);
        $this->assertContains('link.broken|critical|https://site.test/about|en', $found);
        $this->assertNotContains('title.missing|critical|https://site.test/about|en', $found);
        $this->assertNotContains('title.duplicate|warning|https://site.test/about|en', $found, 'About en and About de differ');
        $this->assertFileDoesNotExist($this->dir . '/storage/data/seo-audit.json');
    }
}
