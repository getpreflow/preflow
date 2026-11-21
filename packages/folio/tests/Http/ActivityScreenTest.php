<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Activity\Activity;
use Preflow\Core\Activity\ActivityKind;
use Preflow\Core\Activity\ActivityLog;
use Preflow\Core\Application;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Psr\Http\Message\ResponseInterface;
use Preflow\Testing\ChecksFrameBalance;

/**
 * GET {prefix}/activity (ActivityController, 0.77): the activity log the person may see, 50 rows a
 * page with an "Older" link (the log's cursor) and "Newest", filters for the type and the person,
 * the ink empty state when nothing changed yet, a plain line when a filter finds nothing. It asks
 * folio.dashboard (the dashboard links to it), no permission of its own.
 */
final class ActivityScreenTest extends TestCase
{
    use ChecksFrameBalance;

    use PublishingScaffold;

    private ?Application $session = null;

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    /** 60 note rows by u-ada, then 3 post rows by u-bob (newest). */
    private function seed(): void
    {
        $log = $this->app()->container()->get(ActivityLog::class);
        $start = new \DateTimeImmutable('-2 days');
        for ($i = 1; $i <= 60; $i++) {
            $log->record(new Activity('note', "n{$i}", "Note {$i}", ActivityKind::Created, by: 'u-ada', at: $start->modify("+{$i} minutes")->format(DATE_ATOM)));
        }
        foreach (['b1', 'b2', 'b3'] as $i => $id) {
            $log->record(new Activity('post', $id, "Post {$id}", ActivityKind::Published, by: 'u-bob', at: $start->modify('+' . (100 + $i) . ' minutes')->format(DATE_ATOM)));
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    #[RunInSeparateProcess]
    public function test_pages_of_fifty_with_older_and_newest(): void
    {
        $this->seed();
        $this->signIn(['folio.dashboard', 'note.view', 'post.view']);

        $one = $this->screen('/folio/activity');
        $this->assertStringContainsString('<title>Folio — Activity</title>', $one);
        $this->assertSame(50, substr_count($one, '<li class="folio-activity-row">'));
        $this->assertLessThan(strpos($one, 'Note 60'), strpos($one, 'Post b3'), 'newest first');
        $this->assertStringContainsString('Note 14', $one);
        $this->assertStringNotContainsString('Note 13<', $one);
        $this->assertMatchesRegularExpression('#<nav class="folio-pager folio-activity-pager" aria-label="Pages">.*?<a class="btn btn-secondary btn-sm" href="/folio/activity\?before=([^"&]+)" rel="next">Older</a>#s', $one);
        $this->assertStringNotContainsString('>Newest</a>', $one, 'the first page has no way back');
        preg_match('#href="/folio/activity\?before=([^"&]+)" rel="next"#', $one, $m);

        $two = $this->screen('/folio/activity?before=' . $m[1]);
        $this->assertSame(13, substr_count($two, '<li class="folio-activity-row">'));
        $this->assertStringContainsString('Note 13', $two);
        $this->assertStringContainsString('Note 1<', $two);
        $this->assertStringNotContainsString('Note 14<', $two);
        $this->assertStringContainsString('<a class="btn btn-secondary btn-sm" href="/folio/activity">Newest</a>', $two);
        $this->assertStringNotContainsString('rel="next"', $two, 'the last page');
    }

    #[RunInSeparateProcess]
    public function test_filters_for_type_and_person(): void
    {
        $this->seed();
        $this->signIn(['folio.dashboard', 'note.view', 'post.view']);

        $all = $this->screen('/folio/activity');
        $this->assertMatchesRegularExpression('#<form class="folio-activity-toolbar" method="get" action="/folio/activity" role="search" data-folio-autosubmit>#', $all);
        // The type select: the types the person may view, by sidebar order; the person select: who did something.
        $this->assertMatchesRegularExpression('#<select name="type" aria-label="Type">\s*<option value="">All types</option>\s*<option value="note">Notes</option>\s*<option value="post">Posts</option>\s*</select>#', $all);
        $this->assertMatchesRegularExpression('#<select name="by" aria-label="Person">\s*<option value="">Everyone</option>\s*<option value="u-bob">Former user</option>\s*<option value="u-ada">Former user</option>\s*</select>#', $all, 'ids no provider finds: deleted accounts');

        $posts = $this->screen('/folio/activity?type=post');
        $this->assertSame(3, substr_count($posts, '<li class="folio-activity-row">'));
        $this->assertStringContainsString('<option value="post" selected>Posts</option>', $posts);
        $this->assertStringNotContainsString('Note 1', $posts);

        $ada = $this->screen('/folio/activity?by=u-ada');
        $this->assertSame(50, substr_count($ada, '<li class="folio-activity-row">'));
        $this->assertStringNotContainsString('Post b', $ada);
        // The filters stay on the next page.
        $this->assertMatchesRegularExpression('#href="/folio/activity\?by=u-ada&amp;before=[^"]+" rel="next"#', $ada);

        $none = $this->screen('/folio/activity?type=post&by=u-ada');
        $this->assertStringContainsString('<div class="folio-empty folio-no-matches"><p>No activity matches these filters.</p></div>', $none);
        $this->assertStringNotContainsString('folio-ink-empty', $none);

        // A type the person may not view (or none at all) is no filter: the select falls back to all.
        $this->signIn(['folio.dashboard', 'note.view']);
        $other = $this->screen('/folio/activity?type=post');
        $this->assertStringNotContainsString('Post b', $other);
        $this->assertStringContainsString('<option value="">All types</option>', $other);
        $this->assertStringNotContainsString('<option value="post"', $other);
        $this->assertStringNotContainsString('u-bob', $other, 'only people of what the person may see');
    }

    #[RunInSeparateProcess]
    public function test_nothing_yet_is_the_ink_empty_state(): void
    {
        $this->signIn(['folio.dashboard', 'note.view']);
        $body = $this->screen('/folio/activity');
        $this->assertStringContainsString('data-folio-empty="activity"', $body);
        $this->assertStringContainsString('<p class="folio-ink-title">Nothing changed yet</p>', $body);
        $this->assertStringNotContainsString('folio-pager', $body);
    }

    #[RunInSeparateProcess]
    public function test_it_asks_the_dashboards_permission(): void
    {
        $this->signIn(['note.view']);
        $this->assertSame(403, $this->send('/folio/activity', ['Accept' => 'text/html'])->getStatusCode());
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
            'session' => ['cookie' => 'folio_activity_test', 'path' => '/', 'secure' => false],
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
