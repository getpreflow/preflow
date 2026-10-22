<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Extension;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Extension\ExtensionException;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Psr\Http\Message\ResponseInterface;

/**
 * Panel-only record actions, badge list columns, side panels and tabs, on a "member" type
 * customized by a "polish" extension the test writes.
 */
final class AdminPolishTest extends TestCase
{
    use PublishingScaffold;

    private ?Application $session = null;
    private string $token = '';
    private \Closure $autoload;

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $this->writeModel('member', [
            'label' => 'Members',
            'fields' => [
                'name' => ['type' => 'string', 'validate' => ['required']],
                'state' => ['type' => 'string'],
            ],
        ]);
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $this->write('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'config']],
            'providers' => ['config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [
                ['email' => 'admin@site.test', 'password_hash' => $hash, 'roles' => ['admin']],
                ['email' => 'editor@site.test', 'password_hash' => $hash, 'roles' => ['editor']],
            ]]],
            'session' => ['cookie' => 'folio_polish_test', 'path' => '/', 'secure' => false],
        ], true) . ";\n");
        $this->write('config/roles.php', "<?php\nreturn " . var_export(['staff' => [
            'admin' => ['*'],
            'editor' => ['folio.dashboard', 'member.view', 'member.edit'], // not polish.decide
        ]], true) . ";\n");
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class, \\Preflow\\Roles\\RolesServiceProvider::class];\n");

        $root = $this->dir . '/app/Folio/';
        $this->autoload = static function (string $class) use ($root): void {
            if (str_starts_with($class, 'App\\Folio\\')) {
                $file = $root . str_replace('\\', '/', substr($class, strlen('App\\Folio\\'))) . '.php';
                if (is_file($file)) {
                    require $file;
                }
            }
        };
        spl_autoload_register($this->autoload);
    }

    protected function tearDown(): void
    {
        spl_autoload_unregister($this->autoload);
        $this->removeScaffold();
    }

    #[RunInSeparateProcess]
    public function test_toolbar_false_action_not_in_header_but_routed(): void
    {
        $this->polish();
        $this->as('admin');
        $this->seed('m1', ['name' => 'Ada', 'state' => 'waiting']);

        $edit = (string) $this->send('GET', '/folio/member/m1/edit')->getBody();
        $this->assertStringContainsString('/folio/member/m1/_action/toolbar-on"', $edit); // the header button
        $this->assertStringNotContainsString('/_action/panel-only"', substr($edit, 0, (int) strpos($edit, 'folio-form folio-editor')));
        // The panel's own form posts to it.
        $this->assertStringContainsString('action="/folio/member/m1/_action/panel-only"', $edit);

        $res = $this->send('POST', '/folio/member/m1/_action/panel-only');
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('decided', $this->stored('m1')?->get('state'));

        // Still permission-checked.
        $this->as('editor');
        $this->seed('m2', ['name' => 'Bob', 'state' => 'waiting']);
        $this->assertSame(403, $this->send('POST', '/folio/member/m2/_action/panel-only')->getStatusCode());
        $this->assertSame('waiting', $this->stored('m2')?->get('state'));
    }

    #[RunInSeparateProcess]
    public function test_list_column_badge_renders_with_tone_and_escapes(): void
    {
        $this->polish();
        $this->as('admin');
        $this->seed('m1', ['name' => 'Ada <b>', 'state' => 'published']);

        $body = (string) $this->send('GET', '/folio/member')->getBody();
        $this->assertStringContainsString('<td><span class="folio-badge is-published">Live &lt;i&gt;now&lt;/i&gt;</span></td>', $body);
    }

    #[RunInSeparateProcess]
    public function test_list_column_unknown_tone_falls_back(): void
    {
        $this->polish();
        $this->as('admin');
        $this->seed('m1', ['name' => 'Ada', 'state' => 'published']);

        $body = (string) $this->send('GET', '/folio/member')->getBody();
        $this->assertStringContainsString('<td><span class="folio-badge">Odd</span></td>', $body);
        $this->assertStringContainsString('<td><span class="folio-badge">Plain</span></td>', $body);
        $this->assertStringNotContainsString('is-nonsense', $body);
        $this->assertStringContainsString('<td>text cell</td>', $body);
    }

    #[RunInSeparateProcess]
    public function test_side_panel_renders_in_side_slot(): void
    {
        $this->polish();
        $this->as('admin');
        $this->seed('m1', ['name' => 'Ada', 'state' => 'waiting']);

        $edit = (string) $this->send('GET', '/folio/member/m1/edit')->getBody();
        $this->assertStringContainsString('folio-editor has-side', $edit);
        $aside = substr($edit, (int) strpos($edit, '<aside class="folio-side"'));
        $this->assertStringContainsString('<h2 class="folio-panel-title">Decision</h2>', $aside);
        $this->assertStringContainsString('data-folio-tabs', $aside);
        // Right after the form, before the extras section; the "after" panel stays there.
        $this->assertGreaterThan(strpos($edit, '</form>'), strpos($edit, '<aside class="folio-side"'));
        $this->assertLessThan(strpos($edit, 'class="folio-form-extras"'), strpos($edit, '<aside class="folio-side"'));
        $extras = substr($edit, (int) strpos($edit, 'class="folio-form-extras"'));
        $this->assertStringContainsString('Plain after', $extras);
        $this->assertStringNotContainsString('Decision', $extras);
        // No side panel, no side column.
        $this->assertStringNotContainsString('has-side', (string) $this->send('GET', '/folio/member/new')->getBody());
    }

    #[RunInSeparateProcess]
    public function test_unknown_panel_position_is_rejected(): void
    {
        $this->polish();
        $file = $this->dir . '/app/Folio/Polish/Types/MemberAdmin.php';
        file_put_contents($file, str_replace("position: 'side'", "position: 'left'", (string) file_get_contents($file)));

        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessage('position must be "before", "after" or "side"');
        $this->app();
    }

    public function test_tabs_markup_documented(): void
    {
        $js = (string) file_get_contents(__DIR__ . '/../../assets/admin.js');
        foreach (['data-folio-tabs', 'data-folio-tab', 'data-folio-tabpanel', 'data-folio-tab-active', 'tablist', 'aria-selected', 'ArrowRight'] as $needle) {
            $this->assertStringContainsString($needle, $js);
        }
        $css = (string) file_get_contents(__DIR__ . '/../../assets/admin.css');
        $this->assertStringContainsString('.folio-editor.has-side', $css);
        $this->assertStringContainsString('min-width: 1280px', $css);

        // The README example renders as documented markup (panels show without JS).
        $readme = (string) file_get_contents(__DIR__ . '/../../README.md');
        $this->assertStringContainsString('<div data-folio-tabs>', $readme);
        $this->assertStringContainsString('data-folio-tabpanel="decline"', $readme);
        $this->assertStringContainsString('_action/decline', $readme);
        $this->assertStringContainsString('toolbar: false', $readme);
        $this->assertStringContainsString('new Badge(', $readme);
        $this->assertStringContainsString("position: 'side'", $readme);
    }

    #[RunInSeparateProcess]
    public function test_panel_tabs_template_renders_all_panels_stacked(): void
    {
        $this->polish();
        $this->as('admin');
        $this->seed('m1', ['name' => 'Ada', 'state' => 'waiting']);

        $edit = (string) $this->send('GET', '/folio/member/m1/edit')->getBody();
        $this->assertMatchesRegularExpression('#<div class="folio-segmented">\s*<button type="button" data-folio-tab="accept">Accept</button>#', $edit);
        $this->assertStringContainsString('<div data-folio-tabpanel="accept">', $edit);
        $this->assertStringContainsString('<div data-folio-tabpanel="decline">', $edit);
        $box = substr($edit, (int) strpos($edit, '<div data-folio-tabs>'));
        $box = substr($box, 0, (int) strpos($box, '</form>'));
        $this->assertStringNotContainsString('hidden', $box);
    }

    private function polish(): void
    {
        $this->write('app/Folio/Polish/Extension.php', <<<'PHP'
            <?php
            namespace App\Folio\Polish;
            #[\Preflow\Folio\Extension\Attributes\FolioExtension(name: 'polish', label: 'Polish')]
            final class Extension extends \Preflow\Folio\Extension\Extension {}
            PHP);
        $this->write('app/Folio/Polish/templates/decision.twig', <<<'TWIG'
            <form method="post" action="{{ prefix }}/{{ type }}/{{ id }}/_action/panel-only">
                <input type="hidden" name="_csrf_token" value="{{ csrf }}">
                <div data-folio-tabs>
                    <div class="folio-segmented">
                        <button type="button" data-folio-tab="accept">Accept</button>
                        <button type="button" data-folio-tab="decline">Decline</button>
                    </div>
                    <div data-folio-tabpanel="accept"><p>Welcome note</p></div>
                    <div data-folio-tabpanel="decline"><p>Decline note</p></div>
                </div>
                <button type="submit" class="btn btn-primary">Send</button>
            </form>
            TWIG);
        $this->write('app/Folio/Polish/Types/MemberAdmin.php', <<<'PHP'
            <?php
            namespace App\Folio\Polish\Types;

            use Preflow\Data\DataManager;
            use Preflow\Data\DynamicRecord;
            use Preflow\Folio\Extension\Badge;
            use Preflow\Folio\Extension\Screen\ScreenView;

            #[\Preflow\Folio\Extension\Attributes\FolioType('member')]
            final class MemberAdmin
            {
                public function __construct(private readonly DataManager $dm) {}

                #[\Preflow\Folio\Extension\FormPanel('Decision', position: 'side')]
                public function decision(DynamicRecord $record): ScreenView
                {
                    return new ScreenView('@polish/decision.twig');
                }

                #[\Preflow\Folio\Extension\FormPanel('Plain after')]
                public function after(DynamicRecord $record): string
                {
                    return 'Plain after';
                }

                #[\Preflow\Folio\Extension\RecordAction('toolbar-on', 'In header', 'polish.decide')]
                public function header(DynamicRecord $record): null
                {
                    return null;
                }

                #[\Preflow\Folio\Extension\RecordAction('panel-only', 'Decide', 'polish.decide', toolbar: false)]
                public function decide(DynamicRecord $record): null
                {
                    $record->set('state', 'decided');
                    $this->dm->saveType($record, false);

                    return null;
                }

                #[\Preflow\Folio\Extension\ListColumn('Live', position: 10)]
                public function live(DynamicRecord $record): Badge
                {
                    return new Badge('Live <i>now</i>', 'published');
                }

                #[\Preflow\Folio\Extension\ListColumn('Odd', position: 20)]
                public function odd(DynamicRecord $record): Badge
                {
                    return new Badge('Odd', 'nonsense');
                }

                #[\Preflow\Folio\Extension\ListColumn('Plain', position: 30)]
                public function plain(DynamicRecord $record): Badge
                {
                    return new Badge('Plain');
                }

                #[\Preflow\Folio\Extension\ListColumn('Text', position: 40)]
                public function text(DynamicRecord $record): string
                {
                    return 'text cell';
                }
            }
            PHP);
        $this->write('config/folio/extensions.json', (string) json_encode(['enabled' => ['polish' => ['disabled_parts' => []]]]));
    }

    /** @param array<string, mixed> $data */
    private function seed(string $id, array $data): void
    {
        $dm = $this->session->container()->get(DataManager::class);
        $dm->saveType(DynamicRecord::fromArray($dm->getTypeRegistry()->get('member'), ['uuid' => $id] + $data), false);
    }

    private function stored(string $id): ?DynamicRecord
    {
        return $this->session->container()->get(DataManager::class)->findType('member', $id);
    }

    private function as(string $role): void
    {
        $this->session = $this->app();
        $f = new Psr17Factory();
        $page = (string) $this->session->handle($f->createServerRequest('GET', '/folio/login'))->getBody();
        preg_match('/name="_csrf_token" value="([^"]+)"/', $page, $m);
        $this->token = $m[1] ?? '';
        $login = $this->session->handle($f->createServerRequest('POST', '/folio/login')->withParsedBody([
            '_csrf_token' => $this->token, 'email' => "{$role}@site.test", 'password' => 'secret',
        ]));
        $this->assertSame(302, $login->getStatusCode());
    }

    /** @param array<string, mixed> $body */
    private function send(string $method, string $uri, array $body = []): ResponseInterface
    {
        $req = (new Psr17Factory())->createServerRequest($method, $uri);
        if ($method === 'POST') {
            $req = $req->withParsedBody(['_csrf_token' => $this->token] + $body);
        }

        return $this->session->handle($req);
    }
}
