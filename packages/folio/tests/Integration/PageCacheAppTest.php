<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Psr\Http\Message\ResponseInterface;

/**
 * The page cache on a Folio site in web mode: what staff publish is what the next anonymous
 * visitor sees, with no stale step.
 */
final class PageCacheAppTest extends TestCase
{
    use PublishingScaffold;

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        putenv('APP_DEBUG');
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Pub', 'debug' => 0, 'engine' => 'twig', 'key' => 'folio-page-cache-test-key-0123', 'url' => 'https://site.test'];\n");
        $this->write('config/page_cache.php', "<?php\nreturn ['enabled' => true];\n");
        $this->write('resources/folio/frontend/post.twig', '<html><body><h1>{{ record.title }}</h1></body></html>');
        // The category list: a page of the site listing the published posts.
        $this->write('app/pages/category.twig', '<ul>{% for p in folio_list("post") %}<li>{{ p.title }}</li>{% endfor %}</ul>');
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    #[RunInSeparateProcess]
    public function test_the_approval_mail_case(): void
    {
        $this->publisher()->publish($this->working('post', 'p1', ['title' => ['en' => 'Awaiting approval'], 'slug' => ['en' => 'project']]), null);

        // Anonymous visitors: both pages are cached.
        foreach (['/project', '/category'] as $path) {
            $this->assertSame('miss', $this->get($path)->getHeaderLine('X-Page-Cache'), $path);
            $hit = $this->get($path);
            $this->assertSame('hit', $hit->getHeaderLine('X-Page-Cache'), $path);
            $this->assertStringContainsString('Awaiting approval', (string) $hit->getBody(), $path);
        }

        // Staff approve: the project is published with its new title.
        $published = $this->post('/folio/post/p1', ['title' => 'Approved project', 'slug' => 'project', '_locale' => 'en', '_action' => 'publish']);
        $this->assertSame(302, $published->getStatusCode(), (string) $published->getBody());

        // The owner follows the mail: the new state at once, on both pages, never a stale copy.
        foreach (['/project' => '<h1>Approved project</h1>', '/category' => '<li>Approved project</li>'] as $path => $expected) {
            $response = $this->get($path);
            $this->assertSame('miss', $response->getHeaderLine('X-Page-Cache'), $path);
            $this->assertStringContainsString($expected, (string) $response->getBody(), $path);
            $this->assertStringNotContainsString('Awaiting approval', (string) $response->getBody(), $path);
            $this->assertSame('hit', $this->get($path)->getHeaderLine('X-Page-Cache'), "{$path} is cached again");
        }
    }

    private function app(): Application
    {
        $app = Application::create($this->dir);
        $app->setRunningInConsole(false);
        $app->boot();

        return $app;
    }

    private function get(string $uri): ResponseInterface
    {
        return $this->app()->handle((new Psr17Factory())->createServerRequest('GET', 'https://site.test' . $uri));
    }

    /** @param array<string, mixed> $body */
    private function post(string $uri, array $body = []): ResponseInterface
    {
        return $this->app()->handle((new Psr17Factory())->createServerRequest('POST', 'https://site.test' . $uri)
            ->withHeader('Sec-Fetch-Site', 'same-origin')->withParsedBody($body));
    }
}
