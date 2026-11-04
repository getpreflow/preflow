<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Access\Access;
use Preflow\Auth\Access\PermissionResolver;
use Preflow\Auth\AuthManager;
use Preflow\Auth\Authenticatable;
use Preflow\Core\Application;
use Preflow\Core\Http\Session\SessionInterface;
use Preflow\Folio\Redirects\RecordAddresses;
use Preflow\Folio\Redirects\RedirectEntries;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\Testing\ArraySession;
use Psr\Http\Message\ResponseInterface;

/**
 * Automatic redirects through the admin and the public site: a publishing "page" at "/{slug}"
 * (translated slugs) and a non-publishing "news" type at "/news/{slug}" shown when its status
 * is "published"; en/de with prefixes. One app per test.
 */
final class AutomaticRedirectsTest extends TestCase
{
    use PublishingScaffold;

    private ?Application $booted = null;

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        unlink($this->dir . '/config/models/post.json');
        $this->writeModel('page', ['label' => 'Pages', 'publishing' => true, 'frontend' => ['route' => '/{slug}'], 'fields' => [
            'title' => ['type' => 'string', 'translatable' => true],
            'slug' => ['type' => 'string', 'translatable' => true],
        ]]);
        $this->writeModel('news', ['label' => 'News', 'publishing' => false, 'frontend' => ['route' => '/news/{slug}'], 'fields' => [
            'title' => ['type' => 'string'],
            'slug' => ['type' => 'string'],
            'status' => ['type' => 'string'],
        ]]);
        $this->write('resources/folio/frontend/page.twig', '<h1>{{ record.title }}</h1>');
        $this->write('resources/folio/frontend/news.twig', '<h1>{{ record.title }}</h1>');
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

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
        return $this->app()->handle((new Psr17Factory())->createServerRequest('GET', $uri));
    }

    /** @param array<string, mixed> $body */
    private function send(string $uri, array $body, ?SessionInterface $session = null): ResponseInterface
    {
        $request = (new Psr17Factory())->createServerRequest('POST', $uri)
            ->withHeader('Sec-Fetch-Site', 'same-origin')
            ->withParsedBody($body);

        return $this->app()->handle($session === null ? $request : $request->withAttribute(SessionInterface::class, $session));
    }

    private function entries(): RedirectEntries
    {
        return $this->app()->container()->get(RedirectEntries::class);
    }

    private function addresses(): RecordAddresses
    {
        return $this->app()->container()->get(RecordAddresses::class);
    }

    /** @param array<string, string> $data */
    private function saveNews(string $id, array $data, ?SessionInterface $session = null): ResponseInterface
    {
        return $this->send('/folio/news/' . $id, $data + ['_locale' => 'en'], $session);
    }

    public function test_non_publishing_save_with_new_slug_redirects(): void
    {
        $this->working('news', 'n1', ['title' => 'Hello', 'slug' => 'hello', 'status' => 'published']);
        $this->assertSame(302, $this->saveNews('n1', ['title' => 'Hello', 'slug' => 'hello', 'status' => 'published'])->getStatusCode());
        $this->assertSame(['en' => '/news/hello', 'de' => '/news/hello'], $this->addresses()->of('news', 'n1'));

        $this->assertSame(302, $this->saveNews('n1', ['title' => 'Hello', 'slug' => 'hello-world', 'status' => 'published'])->getStatusCode());

        $this->assertSame('auto', $this->entries()->at('en', '/news/hello')['kind'] ?? null);
        $res = $this->fetch('/news/hello?ref=x');
        $this->assertSame(301, $res->getStatusCode());
        $this->assertSame('/news/hello-world?ref=x', $res->getHeaderLine('Location'));
        $this->assertSame('/de/news/hello-world', $this->fetch('/de/news/hello')->getHeaderLine('Location'));
        $this->assertSame(200, $this->fetch('/news/hello-world')->getStatusCode());
    }

    public function test_non_publishing_status_change_takes_it_off_and_flashes(): void
    {
        $this->working('news', 'n1', ['title' => 'Hello', 'slug' => 'hello', 'status' => 'published']);
        $this->saveNews('n1', ['title' => 'Hello', 'slug' => 'hello', 'status' => 'published']);

        $session = new ArraySession();
        $res = $this->saveNews('n1', ['title' => 'Hello', 'slug' => 'hello', 'status' => 'draft'], $session);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertStringContainsString('Hello', (string) $session->getFlash('warning'));
        $this->assertSame(['url' => '/folio/redirects?record=news:n1', 'label' => 'Send its old links to…'], $session->getFlash('warning_link'));
        $this->assertSame('removed', $this->entries()->at('en', '/news/hello')['kind'] ?? null);
        $this->assertSame(404, $this->fetch('/news/hello')->getStatusCode(), 'no target picked: the old address stays a 404');

        // The admin layout shows the warning with its link.
        $session->start();
        $this->app()->container()->instance(SessionInterface::class, $session);
        $html = (string) $this->fetch('/folio/news')->getBody();
        $this->assertStringContainsString('folio-alert-warning', $html);
        $this->assertStringContainsString('<a href="/folio/redirects?record=news:n1">Send its old links to…</a>', $html);
    }

    public function test_flash_has_no_link_without_the_redirects_permission(): void
    {
        $this->working('news', 'n1', ['title' => 'Hello', 'slug' => 'hello', 'status' => 'published']);
        $this->saveNews('n1', ['title' => 'Hello', 'slug' => 'hello', 'status' => 'published']);
        $this->signInWithout('redirects.manage');

        $session = new ArraySession();
        $this->saveNews('n1', ['title' => 'Hello', 'slug' => 'hello', 'status' => 'draft'], $session);

        $this->assertStringContainsString('Hello', (string) $session->getFlash('warning'));
        $this->assertNull($session->getFlash('warning_link'));
    }

    public function test_bulk_delete_posts_one_target_for_all(): void
    {
        foreach (['n1' => 'one', 'n2' => 'two', 'n3' => 'keep'] as $id => $slug) {
            $this->working('news', $id, ['title' => $slug, 'slug' => $slug, 'status' => 'published']);
        }
        $this->addresses()->seed();

        $res = $this->send('/folio/news/_bulk/delete', ['ids' => ['n1', 'n2'], '_redirect_to' => 'news:n3']);

        $this->assertSame(302, $res->getStatusCode());
        foreach (['/news/one', '/news/two'] as $path) {
            $entry = $this->entries()->at('en', $path);
            $this->assertSame(['news', 'n3', 'removed'], [$entry['target_type'] ?? null, $entry['target_id'] ?? null, $entry['kind'] ?? null], $path);
        }
        $this->assertSame('/news/keep', $this->fetch('/news/one')->getHeaderLine('Location'));
        $this->assertSame('/de/news/keep', $this->fetch('/de/news/two')->getHeaderLine('Location'));

        // A single delete with a free URL target.
        $this->send('/folio/news/n3/delete', ['_redirect_to' => 'https://example.org/archive']);
        $this->assertSame('https://example.org/archive', $this->entries()->at('en', '/news/keep')['target_url'] ?? null);
        $this->assertSame('https://example.org/archive', $this->fetch('/news/one')->getHeaderLine('Location'), 'entries that pointed to n3 moved along');
    }

    public function test_unpublish_posts_a_target(): void
    {
        $this->publisher()->publish($this->working('page', 'p1', ['title' => ['en' => 'About'], 'slug' => ['en' => 'about'], '_locales' => ['en']]), null);
        $this->publisher()->publish($this->working('page', 'p2', ['title' => ['en' => 'Team'], 'slug' => ['en' => 'team'], '_locales' => ['en']]), null);

        $this->assertSame(302, $this->send('/folio/page/p1/unpublish', ['_redirect_to' => 'page:p2'])->getStatusCode());

        $res = $this->fetch('/about');
        $this->assertSame(301, $res->getStatusCode());
        $this->assertSame('/team', $res->getHeaderLine('Location'));
    }

    public function test_old_address_answers_301_end_to_end(): void
    {
        $page = ['title' => ['en' => 'About', 'de' => 'Über uns'], '_locales' => ['en', 'de']];
        $this->publisher()->publish($this->working('page', 'p1', $page + ['slug' => ['en' => 'about', 'de' => 'ueber-uns']]), null);
        $this->assertSame(200, $this->fetch('/de/ueber-uns')->getStatusCode());

        $this->publisher()->publish($this->working('page', 'p1', $page + ['slug' => ['en' => 'about-us', 'de' => 'wir']]), null);

        $en = $this->fetch('/about');
        $this->assertSame(301, $en->getStatusCode());
        $this->assertSame('/about-us', $en->getHeaderLine('Location'));
        $de = $this->fetch('/de/ueber-uns');
        $this->assertSame(301, $de->getStatusCode());
        $this->assertSame('/de/wir', $de->getHeaderLine('Location'));
        $this->assertSame(200, $this->fetch('/about-us')->getStatusCode());
    }

    public function test_migrate_records_the_addresses_of_live_pages(): void
    {
        $this->working('news', 'n1', ['title' => 'Hello', 'slug' => 'hello', 'status' => 'published']);
        $steps = $this->app()->container()->get(\Preflow\Data\Migration\MigrationRegistry::class)->steps();
        $names = array_keys($steps);
        $this->assertGreaterThan(array_search('folio.tables', $names, true), array_search('folio.addresses', $names, true), 'after the tables exist');

        $this->assertSame(['folio: recorded the public addresses of 1 record.'], $steps['folio.addresses']());
        $this->assertSame(['en' => '/news/hello', 'de' => '/news/hello'], $this->addresses()->of('news', 'n1'));
    }

    public function test_admin_save_and_delete_work_without_the_tables(): void
    {
        // A site upgraded but not migrated: Folio's internal storage has no redirect tables.
        $container = $this->app()->container();
        $dm = $container->get(\Preflow\Data\DataManager::class);
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $bare = new \Preflow\Data\DataManager(['json' => $dm->driver('json'), 'default' => new \Preflow\Data\Driver\SqliteDriver($pdo)], 'json', $dm->getTypeRegistry());
        $resolver = $container->get(\Preflow\Folio\Content\FrontendResolver::class);
        $container->instance(RecordAddresses::class, new RecordAddresses(
            $bare,
            new RedirectEntries($bare),
            $resolver,
            new \Preflow\Folio\Redirects\LiveRecords($bare, $this->publisher(), $resolver),
            notFound: new \Preflow\Folio\Redirects\NotFoundEntries($bare),
        ));
        $this->working('news', 'n1', ['title' => 'Hello', 'slug' => 'hello', 'status' => 'published']);

        $this->assertSame(302, $this->saveNews('n1', ['title' => 'Hello', 'slug' => 'hello-world', 'status' => 'published'])->getStatusCode());
        $this->assertSame('hello-world', $this->stored('news', 'n1')->get('slug'));
        $this->assertSame(302, $this->saveNews('n1', ['title' => 'Hello', 'slug' => 'hello-world', 'status' => 'draft'])->getStatusCode());
        $this->assertSame(302, $this->send('/folio/news/n1/delete', ['_redirect_to' => '/elsewhere'])->getStatusCode());
        $this->assertNull($dm->findType('news', 'n1'));
    }

    /** A signed-in user who may do everything but $permission. */
    private function signInWithout(string $permission): void
    {
        $container = $this->app()->container();
        $user = new class implements Authenticatable {
            public function getAuthId(): string { return 'u1'; }
            public function getPasswordHash(): string { return ''; }
            public function getRoles(): array { return ['editor']; }
            public function hasRole(string $role): bool { return $role === 'editor'; }
        };
        $auth = new AuthManager([], 'web');
        $auth->setUser($user, 'web');
        $access = new Access($auth, $container->get(Access::class)->registry(), false, ['web' => 'staff']);
        $access->setResolver(new class($permission) implements PermissionResolver {
            public function __construct(private readonly string $denied) {}

            public function allows(Authenticatable $user, string $population, string $permission, ?object $subject): bool
            {
                return $permission !== $this->denied;
            }
        });
        $container->instance(Access::class, $access);
    }
}
