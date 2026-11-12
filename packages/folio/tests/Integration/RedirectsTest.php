<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Folio\Content\FrontendGate;
use Preflow\Folio\Redirects\NotFoundEntries;
use Preflow\Folio\Redirects\RedirectEntries;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Psr\Http\Message\ResponseInterface;

/**
 * Redirect entries and the not-found list on a Folio site: a publishing "page" type at
 * "/{slug}" and a gated "vault" type (frontend.permission, no login), en/de with prefixes.
 */
final class RedirectsTest extends TestCase
{
    use PublishingScaffold;

    private ?Application $booted = null;

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        unlink($this->dir . '/config/models/post.json');
        $fields = [
            'title' => ['type' => 'string', 'translatable' => true],
            'slug' => ['type' => 'string', 'translatable' => true],
        ];
        $this->writeModel('page', ['label' => 'Pages', 'publishing' => true, 'frontend' => ['route' => '/{slug}'], 'fields' => $fields]);
        $this->writeModel('vault', ['label' => 'Vault', 'publishing' => true, 'frontend' => ['route' => '/vault/{slug}', 'permission' => 'members.access'], 'fields' => $fields]);
        $this->write('resources/folio/frontend/page.twig', '<h1>{{ record.title }}</h1>');
        $this->write('resources/folio/frontend/vault.twig', '<h1>{{ record.title }}</h1>');
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    /** One app per test, so the gate swapped in below stays. */
    private function app(): Application
    {
        if ($this->booted === null) {
            $this->booted = Application::create($this->dir);
            $this->booted->boot();
        }

        return $this->booted;
    }

    private function fetch(string $uri): ResponseInterface
    {
        $request = (new Psr17Factory())->createServerRequest('GET', $uri);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

        return $this->app()->handle($request->withQueryParams($query));
    }

    /** @param array<string, string> $slug */
    private function publish(string $type, string $id, array $slug): void
    {
        $this->publisher()->publish($this->working($type, $id, ['title' => $slug, 'slug' => $slug, '_locales' => array_keys($slug)]), null);
    }

    private function entries(): RedirectEntries
    {
        return $this->app()->container()->get(RedirectEntries::class);
    }

    private function notFound(): NotFoundEntries
    {
        return $this->app()->container()->get(NotFoundEntries::class);
    }

    /** @return list<string> "locale path hits" of every not-found row */
    private function notFoundRows(): array
    {
        $rows = [];
        foreach ($this->dm()->queryIn(NotFoundEntries::definition())->get()->items() as $row) {
            $rows[] = $row->get('locale') . ' ' . $row->get('path') . ' ' . $row->get('hits');
        }
        sort($rows);

        return $rows;
    }

    public function test_manual_entry_redirects_an_unknown_path(): void
    {
        $this->publish('page', 'p1', ['en' => 'about', 'de' => 'ueber-uns']);
        $this->entries()->save(['path' => '/old-about', 'locale' => '', 'target_type' => 'page', 'target_id' => 'p1']);

        $res = $this->fetch('/old-about?ref=news');
        $this->assertSame(301, $res->getStatusCode());
        $this->assertSame('/about?ref=news', $res->getHeaderLine('Location'));
        $this->assertSame('public, max-age=86400', $res->getHeaderLine('Cache-Control'));

        $de = $this->fetch('/de/old-about');
        $this->assertSame(301, $de->getStatusCode());
        $this->assertSame('/de/ueber-uns', $de->getHeaderLine('Location'));

        $this->assertSame(2, $this->entries()->at('', '/old-about')['hits']);
        $this->assertSame([], $this->notFoundRows());
    }

    public function test_a_page_wins_over_an_entry_at_its_address(): void
    {
        $this->publish('page', 'p1', ['en' => 'about']);
        $this->entries()->store(['path' => '/about', 'locale' => '', 'target_url' => '/elsewhere']);

        $res = $this->fetch('/about');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('<h1>about</h1>', (string) $res->getBody());
        $this->assertSame(0, $this->entries()->at('', '/about')['hits']);
    }

    public function test_gated_404_is_not_recorded(): void
    {
        $this->publish('vault', 'v1', ['en' => 'secret']);
        $this->entries()->store(['path' => '/vault/secret', 'locale' => '', 'target_url' => '/elsewhere']);
        // Nobody may see the vault (a gate without access); the page answers with a 404 response.
        $this->app()->container()->instance(FrontendGate::class, new FrontendGate(null));

        $res = $this->fetch('/vault/secret');
        $this->assertSame(404, $res->getStatusCode());
        $this->assertSame('', $res->getHeaderLine('Location'));
        $this->assertSame([], $this->notFoundRows());
        $this->assertSame(0, $this->entries()->at('', '/vault/secret')['hits']);
    }

    public function test_folio_admin_404_is_not_recorded(): void
    {
        $this->entries()->save(['path' => '/folio/nothing-here', 'locale' => '', 'target_url' => '/elsewhere']);

        $this->assertTrue($this->app()->container()->get(\Preflow\Core\Http\NonPublicPaths::class)->matches('/folio/nothing-here'));
        $res = $this->fetch('/folio/nothing-here/at-all');
        $this->assertSame(404, $res->getStatusCode());
        $this->assertSame(404, $this->fetch('/folio/nothing-here')->getStatusCode());
        $this->assertSame([], $this->notFoundRows());
    }

    public function test_unknown_public_path_is_counted_at_most_once_a_minute(): void
    {
        $this->assertSame(404, $this->fetch('/nowhere')->getStatusCode());
        $this->assertSame(404, $this->fetch('/nowhere/?utm_source=x')->getStatusCode());
        $this->assertSame(404, $this->fetch('/de/nirgends')->getStatusCode());
        $this->assertSame(404, $this->fetch('/wp-login.php')->getStatusCode());

        // The second hit within the minute is dropped, not counted.
        $this->assertSame(['de /nirgends 1', 'en /nowhere 1'], $this->notFoundRows());
        $this->assertSame([], $this->notFound()->visible());

        // A minute later the same address counts again.
        $id = NotFoundEntries::id('en', '/nowhere');
        $row = $this->notFound()->find($id);
        $row['last_hit_at'] = (new \DateTimeImmutable('-2 minutes', new \DateTimeZone('UTC')))->format(DATE_ATOM);
        $this->dm()->saveType(\Preflow\Data\DynamicRecord::fromArray(NotFoundEntries::definition(), $row), false);
        $this->assertSame(404, $this->fetch('/nowhere')->getStatusCode());

        $this->assertSame(['de /nirgends 1', 'en /nowhere 2'], $this->notFoundRows());
        $this->assertSame(['/nowhere'], array_column($this->notFound()->visible(), 'path'));
    }
}
