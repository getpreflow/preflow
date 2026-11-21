<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\Testing\ChecksFrameBalance;

/**
 * The ⌘K palette's screens and actions (partials/_commands.twig, CommandPalette): one JSON block per
 * admin page, with the nonce, built from the sidebar the person sees and the types they may create;
 * the g-chord targets (g d, g a, g p) and the dashboard tip that names g p only when it exists.
 */
final class CommandsDataTest extends TestCase
{
    use ChecksFrameBalance;

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

    /** @return array{0: string, 1: array<string, mixed>} the page and its commands */
    private function page(string $uri): array
    {
        $res = $this->session->handle((new Psr17Factory())->createServerRequest('GET', $uri));
        $this->assertSame(200, $res->getStatusCode());
        $html = (string) $res->getBody();
        $this->assertSame(1, preg_match_all('#<script type="application/json" id="folio-commands" nonce="([^"]+)">(.*?)</script>#s', $html, $m), 'one commands block');
        $this->assertSame(1, preg_match('#<meta name="csp-nonce" content="([^"]+)">#', $html, $nonce));
        $this->assertSame($nonce[1], $m[1][0], 'the JSON carries the page nonce');

        return [$html, json_decode(html_entity_decode($m[2][0]), true, flags: JSON_THROW_ON_ERROR)];
    }

    #[RunInSeparateProcess]
    public function test_screens_actions_and_jumps_follow_permissions(): void
    {
        $this->signIn(['folio.dashboard', 'post.view', 'post.create', 'site.view', 'site.edit', 'site.create', 'assets.view']);
        [$html, $commands] = $this->page('/folio');

        $this->assertSame('/folio/_search', $commands['search']);
        $labels = array_column($commands['screens'], 'label');
        $this->assertSame(['Dashboard', 'Posts', 'Site', 'Assets'], $labels);
        $this->assertSame('/folio/post', $commands['screens'][1]['url']);
        $this->assertSame('page', $commands['screens'][1]['family']);
        $this->assertSame([['label' => 'New post', 'url' => '/folio/post/new', 'icon' => 'ui-file', 'family' => 'page']], $commands['actions'], 'no New for a singleton');
        $this->assertSame(['d' => ['label' => 'Dashboard', 'url' => '/folio'], 'a' => ['label' => 'Assets', 'url' => '/folio/assets'], 'p' => ['label' => 'Posts', 'url' => '/folio/post']], $commands['jumps']);
        $this->assertStringNotContainsString('Events', $html, 'nothing of a type the person may not view');
        $this->assertStringContainsString('Press G then P to jump to Posts', $html, 'the tip names g p');
    }

    #[RunInSeparateProcess]
    public function test_without_a_page_type_no_g_p_and_the_plain_tip(): void
    {
        $this->signIn(['folio.dashboard', 'event.view']);
        [$html, $commands] = $this->page('/folio');

        $this->assertSame(['Dashboard', 'Events'], array_column($commands['screens'], 'label'));
        $this->assertSame([], $commands['actions']);
        $this->assertSame(['d' => ['label' => 'Dashboard', 'url' => '/folio']], $commands['jumps'], 'no assets, no page type');
        $this->assertStringNotContainsString('Posts', $html);
        $this->assertStringNotContainsString('Press G then P', $html);
        $this->assertStringContainsString('Press ? for shortcuts', $html);
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
            'session' => ['cookie' => 'folio_commands_test', 'path' => '/', 'secure' => false],
        ], true) . ";\n");
        $this->write('config/roles.php', "<?php\nreturn " . var_export(['staff' => ['admin' => $permissions]], true) . ";\n");
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class, \\Preflow\\Roles\\RolesServiceProvider::class];\n");

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
