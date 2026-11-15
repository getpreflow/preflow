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
        $this->assertStringContainsString('/old-spring → /spring', $body, 'a redirect: old → its current target');
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
        $this->assertStringContainsString('<span class="folio-activity-who">Former user</span>', $body, 'an author nobody has any more: a neutral label');
        $this->assertStringNotContainsString('ghost-id', $body, 'never the raw id');
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

    #[RunInSeparateProcess]
    public function test_a_type_keyed_like_a_reserved_subject_is_a_boot_error(): void
    {
        foreach (['redirect', 'asset', 'activity'] as $key) {
            $this->assertContains($key, \Preflow\Folio\Extension\Screen\ScreenRegistry::RESERVED);
        }
        $this->writeModel('redirect', ['label' => 'Redirect notes', 'fields' => ['name' => ['type' => 'string']]]);

        $this->expectException(\Preflow\Folio\Content\FrontendConfigException::class);
        $this->expectExceptionMessage('Type "redirect": the key is a path Folio uses itself');
        $this->app();
    }

    #[RunInSeparateProcess]
    public function test_reserved_names_are_granted_only_by_their_own_permissions_and_app_subjects_by_theirs(): void
    {
        $app = $this->app();
        $c = $app->container();
        $log = $c->get(ActivityLog::class);
        $log->record(new Activity('invoice', 'i1', 'Invoice 2026-17', ActivityKind::Created, by: 'u1'));
        $log->record(new Activity('redirect', 'r9', '/sneaky', ActivityKind::Created, by: 'u1'));
        $log->record(new Activity('secret', 's1', 'Secret plan', ActivityKind::Created, by: 'u1'));
        $declared = ['invoice.view', 'redirect.view', 'asset.view', 'activity.view', 'secret.view', 'post.view'];
        $recent = $this->recentChanges($c, $log, $declared, redirects: true);

        // Every declared permission held but redirects.manage: "redirect.view" grants nothing.
        $can = static fn (string $p): bool => $p !== 'redirects.manage' && $p !== 'secret.view';
        $keys = array_column($recent->subjects($can), 'key');
        $this->assertContains('invoice', $keys, 'an app\'s own subject with its declared {subject}.view');
        $this->assertContains('post', $keys);
        foreach (['redirect', 'asset', 'activity', 'secret'] as $not) {
            $this->assertNotContains($not, $keys);
        }
        $rows = $recent->page($can, 10)['rows'];
        $this->assertSame(['Invoice 2026-17'], array_column($rows, 'title'));
        $this->assertSame(['what' => 'Invoice · created', 'url' => null], ['what' => $rows[0]['what'], 'url' => $rows[0]['url']], 'no screen to link to');

        // redirects.manage alone grants "redirect".
        $rows = $recent->page(static fn (string $p): bool => $p === 'redirects.manage', 10)['rows'];
        $this->assertSame(['/sneaky'], array_column($rows, 'title'), 'gone: the stored title, no target');
        $this->assertNull($rows[0]['url']);
    }

    #[RunInSeparateProcess]
    public function test_an_app_subject_takes_its_label_from_folio_activity_subjects_else_its_readable_name(): void
    {
        // The site's own phrase (lang/en/folio.php), as for any Folio text.
        $this->write('lang/en/folio.php', "<?php\nreturn ['activity' => ['subjects' => ['invoice' => 'Billing']]];\n");
        $app = $this->app();
        $c = $app->container();
        $log = $c->get(ActivityLog::class);
        $log->record(new Activity('invoice', 'i1', 'Invoice 2026-17', ActivityKind::Created, by: 'u1'));
        $log->record(new Activity('work_order', 'w1', 'Order 5', ActivityKind::Created, by: 'u1'));
        $recent = $this->recentChanges($c, $log, ['invoice.view', 'work_order.view']);
        $can = static fn (string $p): bool => true;
        $previous = \Preflow\Folio\FolioText::use($c->get(\Preflow\I18n\Translator::class)); // as an admin request does

        $labels = array_column(array_filter($recent->subjects($can), static fn (array $s): bool => in_array($s['key'], ['invoice', 'work_order'], true)), 'label', 'key');
        $this->assertSame(['invoice' => 'Billing', 'work_order' => 'Work order'], $labels, 'the filter options');
        $what = array_column($recent->page($can, 10)['rows'], 'what', 'title');
        $this->assertSame('Billing · created', $what['Invoice 2026-17'], 'the row');
        $this->assertSame('Work order · created', $what['Order 5'], 'no phrase: the readable name');
        \Preflow\Folio\FolioText::use($previous);
    }

    #[RunInSeparateProcess]
    public function test_markers_show_their_label_and_an_erased_person_none(): void
    {
        $app = $this->app();
        $c = $app->container();
        $c->get(\Preflow\Core\Activity\ActivityActors::class)->register('nightly-import', 'Nightly import');
        $log = $c->get(ActivityLog::class);
        $log->record(new Activity('post', 'p1', 'Imported', ActivityKind::Created, by: 'nightly-import'));
        $log->record(new Activity('post', 'p2', 'Erased', ActivityKind::Created, by: ''));
        $log->record(new Activity('post', 'p3', 'Fallback title', ActivityKind::Created, by: 'u-9', titleKey: 'nowhere.activity.no_text'));
        $recent = $this->recentChanges($c, $log, []);
        $can = static fn (string $p): bool => $p === 'post.view';

        $rows = array_column($recent->page($can, 10)['rows'], null, 'title');
        $this->assertSame('Nightly import', $rows['Imported']['who'], 'a marker is no person: its label');
        $this->assertNull($rows['Erased']['who'], 'an erased person shows no one');
        $this->assertArrayHasKey('Fallback title', $rows, 'a titleKey without a text: the stored title');
        $this->assertSame('Former user', $rows['Fallback title']['who'], 'an id no provider finds');
        $this->assertSame([['id' => 'u-9', 'label' => 'Former user'], ['id' => 'nightly-import', 'label' => 'Nightly import']], $recent->people($can));
        $this->assertSame(\Preflow\Members\MemberEvents::DELETION_ACTOR, \Preflow\Folio\Members\PublishedMemberDeletion::BY, 'Folio writes the marker members names');
    }

    #[RunInSeparateProcess]
    public function test_a_broken_log_is_logged_and_said(): void
    {
        $this->signIn(['folio.dashboard', 'post.view']);
        $logger = new class extends \Psr\Log\AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->lines[] = $level . ': ' . $message;
            }
        };
        $c = $this->session->container();
        $c->instance(\Psr\Log\LoggerInterface::class, $logger);
        $c->instance(ActivityLog::class, new class implements ActivityLog {
            public function record(Activity $activity): void {}

            public function recent(int $limit, ?array $subjects = null, ?string $before = null, ?string $by = null): \Preflow\Core\Activity\ActivityPage
            {
                throw new \RuntimeException('disk on fire');
            }

            public function people(?array $subjects = null): array
            {
                throw new \RuntimeException('disk on fire');
            }

            public function prune(): int
            {
                return 0;
            }

            public function forget(string $subject, string $id): void {}
        });

        $body = $this->part();
        $this->assertStringContainsString('<p class="folio-dash-recent-empty is-failed" role="status">Couldn’t load recent changes.</p>', $body);
        $this->assertStringNotContainsString('Nothing changed yet', $body);
        $screen = $this->screen('/folio/activity');
        $this->assertStringContainsString('Couldn’t load the activity. Try again later.', $screen);
        $this->assertStringNotContainsString('folio-ink-empty', $screen);
        $this->assertContains('warning: Folio: could not read the activity log: disk on fire', $logger->lines);
    }

    /** @param list<string> $declared */
    private function recentChanges(\Preflow\Core\Container\Container $c, ActivityLog $log, array $declared, bool $redirects = false): \Preflow\Folio\Dashboard\RecentChanges
    {
        return new \Preflow\Folio\Dashboard\RecentChanges(
            $log,
            $c->get(\Preflow\Folio\Content\TypeCatalog::class),
            $c->get(\Preflow\Folio\Content\DisplayLabels::class),
            $c->get(\Preflow\Data\DataManager::class),
            '/folio',
            new \Preflow\Folio\Users\AuthorNames($c->get(\Preflow\Data\DataManager::class), static fn (): array => [], null, $c->get(\Preflow\Core\Activity\ActivityActors::class)),
            null,
            null,
            $redirects ? static fn (array $ids): array => [] : null,
            new AdminFormat(),
            static fn (): array => $declared,
        );
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
