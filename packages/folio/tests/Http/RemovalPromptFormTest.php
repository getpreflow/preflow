<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Access\Access;
use Preflow\Auth\Access\PermissionResolver;
use Preflow\Auth\AuthManager;
use Preflow\Auth\Authenticatable;
use Preflow\Core\Application;
use Preflow\Folio\Redirects\RedirectEntries;
use Preflow\Folio\Tests\Support\PublishingScaffold;

/**
 * The editor's side of redirects: the "Old addresses" note (entries that send visitors to the
 * record) and the address count the unpublish form hands to the "Send its old links to…" prompt.
 * A publishing "page" at "/{slug}" with translated slugs, en/de with prefixes.
 */
final class RemovalPromptFormTest extends TestCase
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
        $this->write('resources/folio/frontend/page.twig', '<h1>{{ record.title }}</h1>');
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

    private function editor(string $id): string
    {
        $res = $this->app()->handle((new Psr17Factory())->createServerRequest('GET', '/folio/page/' . $id . '/edit'));
        $this->assertSame(200, $res->getStatusCode());

        return (string) $res->getBody();
    }

    private function entries(): RedirectEntries
    {
        return $this->app()->container()->get(RedirectEntries::class);
    }

    /** @param array<string, string> $slugs locale => slug */
    private function publishPage(string $id, array $slugs): void
    {
        $this->publisher()->publish($this->working('page', $id, [
            'title' => array_map('ucfirst', $slugs),
            'slug' => $slugs,
            '_locales' => array_keys($slugs),
        ]), null);
    }

    public function test_editor_marks_the_address_field(): void
    {
        $this->publishPage('p1', ['en' => 'about']);
        $html = $this->editor('p1');

        $this->assertMatchesRegularExpression('#<div class="folio-field-wrap" data-folio-address="segment">\s*<div[^>]*>\s*<label for="[^"]*slug#', $html);
        $this->assertSame(1, substr_count($html, 'data-folio-address='), 'only the slug builds the address');
    }

    public function test_editor_shows_old_addresses(): void
    {
        $this->publishPage('p1', ['en' => 'about', 'de' => 'ueber-uns']);
        $this->publishPage('p2', ['en' => 'team']);
        $this->assertStringNotContainsString('Old addresses', $this->editor('p1'), 'no note while nothing redirects here');

        $this->publishPage('p1', ['en' => 'about-us', 'de' => 'wir']);
        $html = $this->editor('p1');

        $this->assertStringContainsString('Old addresses', $html);
        // As visitors see them: the other language's prefix, the umlaut readable.
        $this->assertStringContainsString('<li>/about</li>', $html);
        $this->assertStringContainsString('<li>/de/ueber-uns</li>', $html);
        $this->assertStringContainsString('href="/folio/redirects?record=page:p1"', $html);
        $this->assertStringNotContainsString('Old addresses', $this->editor('p2'));

        // At most ten, then how many more.
        for ($i = 1; $i <= 11; $i++) {
            $this->entries()->save(['path' => sprintf('/old-%02d', $i), 'locale' => 'en', 'target_type' => 'page', 'target_id' => 'p1', 'kind' => 'manual']);
        }
        $html = $this->editor('p1');
        $this->assertSame(10, preg_match_all('#<li>/[^<]*</li>#', $html));
        $this->assertStringContainsString('+3', $html);
    }

    public function test_old_addresses_link_needs_the_redirects_permission(): void
    {
        $this->publishPage('p1', ['en' => 'about']);
        $this->publishPage('p1', ['en' => 'about-us']);
        $this->signInWithout('redirects.manage');

        $html = $this->editor('p1');

        $this->assertStringContainsString('<li>/about</li>', $html);
        $this->assertStringNotContainsString('/folio/redirects?record=', $html);
    }

    public function test_unpublish_form_carries_the_address_count(): void
    {
        $this->publishPage('p1', ['en' => 'about', 'de' => 'ueber-uns']);

        $html = $this->editor('p1');

        $this->assertMatchesRegularExpression('#<form method="post" action="/folio/page/p1/unpublish"[^>]* data-removal-addresses="2"#', $html);
    }

    public function test_removal_forms_name_the_record_for_the_dialog_title(): void
    {
        // The dialog asks "Delete “About”?" / "Unpublish “About”?" (admin.js reads data-removal-label).
        $this->publishPage('p1', ['en' => 'about', 'de' => 'ueber-uns']);

        $html = $this->editor('p1');
        $this->assertMatchesRegularExpression('#<form method="post" action="/folio/page/p1/unpublish"[^>]* data-removal-addresses="2" data-removal-label="About"#', $html);
        $this->assertMatchesRegularExpression('#<form method="post" action="/folio/page/p1/delete"[^>]* data-removal-label="About"#', $html);

        $list = (string) $this->app()->handle((new Psr17Factory())->createServerRequest('GET', '/folio/page'))->getBody();
        $this->assertMatchesRegularExpression('#<form method="post" action="/folio/page/p1/unpublish"[^>]* data-removal-label="About">#', $list);
        $this->assertMatchesRegularExpression('#<form method="post" action="/folio/page/p1/delete"[^>]* data-removal-label="About">#', $list);
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
