<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Dashboard;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Activity\Activity;
use Preflow\Core\Activity\ActivityKind;
use Preflow\Core\Activity\ActivityLog;
use Preflow\Core\Application;
use Preflow\Folio\Http\AdminFormat;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\Media\MediaLibrary;
use Psr\Http\Message\ResponseInterface;

/**
 * The dashboard's "Recent changes" (0.77, GET {prefix}?part=recent after the page): the 5 newest
 * rows of the activity log the person may see ({type}.view; assets: assets.view; redirects:
 * redirects.manage), each with its type tile, the title linking to the record (none once it is
 * deleted), what happened, who and when, and the link to All activity (Review Focus 1).
 */
final class RecentChangesTest extends TestCase
{
    use PublishingScaffold;

    private ?Application $session = null;

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $this->writeModel('event', [
            'label' => 'Events',
            'fields' => ['name' => ['type' => 'string']],
        ]);
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    /** Records and log rows: a post (edited, with a deleted sibling), an event, an asset, a redirect, a private file. */
    private function seed(): void
    {
        $app = $this->app();
        $this->working('post', 'p1', ['title' => ['en' => 'Spring launch'], 'slug' => ['en' => 'spring'], 'body' => ['en' => 'Hi']]);
        $this->working('event', 'e1', ['name' => 'Summer fair']);
        $f = new Psr17Factory();
        $image = imagecreatetruecolor(20, 10);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        $library = $app->container()->get(MediaLibrary::class);
        $asset = $library->upload($f->createUploadedFile($f->createStream($png), strlen($png), UPLOAD_ERR_OK, 'meadow-photo.png'), null);
        $library->upload($f->createUploadedFile($f->createStream($png), strlen($png), UPLOAD_ERR_OK, 'passport-scan.png'), null, private: true);
        $entries = $app->container()->get(\Preflow\Folio\Redirects\RedirectEntries::class);
        $entries->storeUnchecked(['path' => '/old-spring', 'target_url' => '/spring']);
        $redirect = $entries->at('', '/old-spring') ?? $entries->all()[0];

        $log = $app->container()->get(ActivityLog::class);
        $at = static fn (string $ago): string => (new \DateTimeImmutable($ago))->format(DATE_ATOM);
        $log->record(new Activity('post', 'gone', 'Old announcement', ActivityKind::Deleted, by: 'ghost-id', at: $at('-3 days')));
        $log->record(new Activity('redirect', (string) $redirect['id'], '/old-spring', ActivityKind::Created, by: 'admin@site.test', at: $at('-2 days')));
        $log->record(new Activity('event', 'e1', 'Summer fair', ActivityKind::Published, by: 'admin@site.test', at: $at('-50 minutes')));
        $log->record(new Activity('post', 'p1', 'Spring launch', ActivityKind::Saved, ['title', 'body', 'slug'], 'admin@site.test', $at('-5 minutes')));
        unset($asset);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    #[RunInSeparateProcess]
    public function test_rows_only_for_viewable_subjects(): void
    {
        $this->seed();
        $this->signIn(['folio.dashboard', 'post.view']);

        $body = $this->part();
        $this->assertStringContainsString('Spring launch', $body);
        $this->assertStringContainsString('Old announcement', $body);
        foreach (['Summer fair', 'meadow-photo', 'old-spring', 'Private file', 'passport'] as $other) {
            $this->assertStringNotContainsString($other, $body, 'nothing of a type the person may not open');
        }
        $screen = $this->screen('/folio/activity');
        foreach (['Summer fair', 'meadow-photo', 'old-spring', 'Private file', 'passport'] as $other) {
            $this->assertStringNotContainsString($other, $screen, 'nor on All activity');
        }
        // A type filter the person may not see reads nothing of it.
        $this->assertStringNotContainsString('Summer fair', $this->screen('/folio/activity?type=event'));

        // With the other permissions, each subject shows.
        $this->signIn(['folio.dashboard', 'post.view', 'event.view', 'assets.view', 'redirects.manage']);
        $body = $this->part();
        $this->assertStringContainsString('Summer fair', $body);
        $this->assertStringContainsString('meadow-photo.png', $body);
        $this->assertStringContainsString('Private file', $body, 'a private file is named by its kind, never its name');
        $this->assertStringNotContainsString('passport', $body);
        $this->assertStringNotContainsString('media.activity', $body, 'no raw key');
        $this->assertStringContainsString('/old-spring', $body);
        $this->assertStringNotContainsString('Old announcement', $body, 'five rows: the oldest is the sixth');
        $this->assertStringContainsString('Old announcement', $this->screen('/folio/activity'));
    }

    #[RunInSeparateProcess]
    public function test_the_section_rows(): void
    {
        $this->seed();
        $this->signIn(['folio.dashboard', 'post.view', 'event.view']);

        $page = $this->screen('/folio');
        $this->assertStringContainsString('<div class="folio-dash-later folio-dash-recent-later" hx-get="/folio?part=recent" hx-trigger="load" hx-swap="outerHTML"></div>', $page);
        $this->assertStringNotContainsString('Spring launch', $page, 'the log is read in the part, not the page');

        $body = $this->part();
        $this->assertStringStartsWith('<section class="folio-dash-section folio-dash-recent"', ltrim($body));
        $this->assertStringContainsString('<h2 id="folio-recent-title" class="folio-dash-title">Recent changes</h2>', $body);
        $this->assertStringContainsString('<a class="folio-dash-recent-all" href="/folio/activity">All activity</a>', $body);
        // A row: the tile of its family, the title to the record, what and when.
        $this->assertMatchesRegularExpression('#<li class="folio-activity-row">\s*<span class="folio-type-icon" data-family="page" aria-hidden="true"><svg class="folio-icon"><use href="[^"]*\#ui-file"></use></svg></span>\s*<span class="folio-activity-main"><a class="folio-activity-title" href="/folio/post/p1/edit">Spring launch</a><span class="folio-activity-what">Post · Title, Body and 1 more edited · draft saved</span></span>\s*<span class="folio-activity-meta"><span class="folio-activity-who">admin@site.test</span><time class="folio-activity-when" datetime="[^"]+" title="[^"]+">5 minutes ago</time></span>#', $body);
        $this->assertStringContainsString('Event · published', $body);
        $this->assertStringContainsString('50 minutes ago', $body);
        // Deleted: its stored title, no link.
        $this->assertMatchesRegularExpression('#<span class="folio-activity-title is-gone">Old announcement</span><span class="folio-activity-what">Post · deleted</span>#', $body);
        $this->assertStringNotContainsString('/folio/post/gone', $body);
        $this->assertStringContainsString('ghost-id', $body, 'an author nobody has any more shows as stored');
        // Newest first.
        $this->assertLessThan(strpos($body, 'Summer fair'), strpos($body, 'Spring launch'));
        $this->assertLessThan(strpos($body, 'Old announcement'), strpos($body, 'Summer fair'));
    }

    #[RunInSeparateProcess]
    public function test_nothing_yet_is_a_muted_line_and_nothing_to_see_is_no_section(): void
    {
        $this->signIn(['folio.dashboard', 'post.view']);
        $body = $this->part();
        $this->assertStringContainsString('<p class="folio-dash-recent-empty">Nothing changed yet.</p>', $body);
        $this->assertStringNotContainsString('folio-ink', $body, 'on the dashboard a muted line, not an ink card');

        $this->signIn(['folio.dashboard']);
        $this->assertSame('', $this->part(), 'no subject to see: no section');
        $this->assertStringNotContainsString('part=recent', $this->screen('/folio'), 'and nothing loaded');
    }

    public function test_the_relative_time(): void
    {
        $format = new AdminFormat(intl: false);
        $now = new \DateTimeImmutable('2026-11-15T15:30:00');
        $this->assertSame('Just now', $format->relative('2026-11-15T15:29:31', $now));
        $this->assertSame('1 minute ago', $format->relative('2026-11-15T15:29:00', $now));
        $this->assertSame('42 minutes ago', $format->relative('2026-11-15T14:48:00', $now));
        $this->assertSame('1 hour ago', $format->relative('2026-11-15T14:29:00', $now));
        $this->assertSame('15 hours ago', $format->relative('2026-11-15T00:10:00', $now), 'today: hours');
        $this->assertSame('Yesterday', $format->relative('2026-11-14T23:50:00', $now));
        $this->assertSame('Nov 12, 2026', $format->relative('2026-11-12T09:00:00', $now));
        $this->assertSame('Just now', $format->relative('2026-11-15T15:31:00', $now), 'a clock a little ahead');
        $this->assertSame('', $format->relative('not a date', $now));
    }

    public function test_the_layout_sits_beside_the_content_at_1280(): void
    {
        $css = (string) file_get_contents(__DIR__ . '/../../assets/admin.css');
        $this->assertMatchesRegularExpression('/@media \(min-width: 1280px\) \{\s*\.folio-dash-split:has\(> :nth-child\(2\)\) \{[^}]*grid-template-columns: minmax\(0, 1fr\) minmax\(0, 1\.4fr\);/', $css);
        $this->assertMatchesRegularExpression('/\.folio-activity-title \{[^}]*overflow-wrap: anywhere;/', $css, 'long titles wrap at 375');
        $this->assertMatchesRegularExpression('/\.folio-activity-main \{[^}]*min-width: 0;/', $css);
    }

    private function part(): string
    {
        $response = $this->send('/folio?part=recent', ['HX-Request' => 'true']);
        $this->assertSame(200, $response->getStatusCode());

        return (string) $response->getBody();
    }

    private function screen(string $uri): string
    {
        $response = $this->send($uri, ['Accept' => 'text/html']);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return (string) $response->getBody();
    }

    /** @param array<string, string> $headers */
    private function send(string $uri, array $headers = []): ResponseInterface
    {
        $request = (new Psr17Factory())->createServerRequest('GET', $uri);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

        return $this->session->handle($request->withQueryParams($query));
    }

    /** @param list<string> $permissions the one role's permissions */
    private function signIn(array $permissions): void
    {
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $this->write('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'config']],
            'providers' => ['config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [
                ['email' => 'admin@site.test', 'password_hash' => $hash, 'roles' => ['admin']],
            ]]],
            'session' => ['cookie' => 'folio_recent_test', 'path' => '/', 'secure' => false],
        ], true) . ";\n");
        $this->write('config/roles.php', "<?php\nreturn " . var_export(['staff' => ['admin' => $permissions]], true) . ";\n");
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class, \\Preflow\\Roles\\RolesServiceProvider::class];\n");
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $this->session = $this->app();
        $f = new Psr17Factory();
        $page = (string) $this->session->handle($f->createServerRequest('GET', '/folio/login'))->getBody();
        preg_match('/name="_csrf_token" value="([^"]+)"/', $page, $m);
        $login = $this->session->handle($f->createServerRequest('POST', '/folio/login')->withHeader('Sec-Fetch-Site', 'same-origin')->withParsedBody([
            '_csrf_token' => $m[1] ?? '', 'email' => 'admin@site.test', 'password' => 'secret',
        ]));
        $this->assertSame(302, $login->getStatusCode());
    }
}
