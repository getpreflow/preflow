<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

require_once __DIR__ . '/Fixtures/PipelineCounter/PipelineCounter.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Tests\Fixtures\PipelineCounter\PipelineCounter;
use Preflow\Htmx\ComponentToken;
use Psr\Http\Message\ResponseInterface;

/** Component URLs made on a /de/… page keep /de, so the action runs in German. */
final class ComponentLocalePrefixTest extends TestCase
{
    private string $dir;

    private const KEY = 'locale-prefix-test-key-0123456789ab';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cmp_locale_' . bin2hex(random_bytes(4));
        @mkdir($this->dir . '/config', 0777, true);
        @mkdir($this->dir . '/app/pages', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'" . self::KEY . "'];\n");
        file_put_contents($this->dir . '/config/i18n.php', "<?php\nreturn ['default'=>'en','available'=>['en','de'],'fallback'=>'en','url_strategy'=>'prefix'];\n");
        file_put_contents($this->dir . '/config/auth.php', "<?php\nreturn ['session' => ['cookie' => 'loc_test', 'secure' => false]];\n");
        file_put_contents(
            $this->dir . '/app/pages/index.twig',
            '<html><head></head><body><a href="{{ hd.actionUrl(\'bump\', \'' . addslashes(PipelineCounter::class) . '\', {count: 1}) }}">bump</a></body></html>',
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

    private function app(): Application
    {
        $app = Application::create($this->dir);
        $app->boot();

        return $app;
    }

    private function post(string $path): ResponseInterface
    {
        $token = (new ComponentToken(self::KEY))->encode(PipelineCounter::class, ['count' => 1], 'bump');

        return $this->app()->handle((new Psr17Factory())->createServerRequest('POST', $path)
            ->withParsedBody(['token' => $token])->withHeader('HX-Request', 'true'));
    }

    #[RunInSeparateProcess]
    public function test_german_page_builds_prefixed_action_urls(): void
    {
        $app = $this->app();
        $factory = new Psr17Factory();

        $de = (string) $app->handle($factory->createServerRequest('GET', '/de/'))->getBody();
        $this->assertStringContainsString('"/de/--component/action?token=', $de);

        // Same app (same worker): the next unprefixed page must not inherit /de.
        $en = (string) $app->handle($factory->createServerRequest('GET', '/'))->getBody();
        $this->assertStringContainsString('"/--component/action?token=', $en);
        $this->assertStringNotContainsString('/de/--component', $en);
    }

    #[RunInSeparateProcess]
    public function test_prefixed_action_runs_in_that_locale(): void
    {
        $res = $this->post('/de/--component/action');

        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $this->assertStringContainsString('Count: 2', (string) $res->getBody());
        $this->assertSame('de', PipelineCounter::$seen?->getAttribute('locale'));
    }

    #[RunInSeparateProcess]
    public function test_rerendered_fragment_keeps_the_prefix(): void
    {
        $res = $this->post('/de/--component/action');

        $this->assertStringContainsString('/de/--component/action?token=', (string) $res->getBody());
    }
}
