<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Extension;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Folio\Extension\ExtensionException;
use Preflow\Folio\Tests\Support\PublishingScaffold;

/**
 * A full app with auth and preflow/roles and a "crm" extension (written by the test) whose
 * #[NavBadge] methods count waiting contacts; the count shows on the sidebar item.
 */
final class NavBadgeTest extends TestCase
{
    use PublishingScaffold;

    private \Closure $autoload;
    /** @var array<string, array{disabled_parts: list<string>}> */
    private array $enabled = [];

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $this->writeModel('contact', ['label' => 'Contacts', 'fields' => ['name' => ['type' => 'string']]]);
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $this->write('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'config']],
            'providers' => ['config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [
                ['email' => 'admin@site.test', 'password_hash' => $hash, 'roles' => ['admin']],
                ['email' => 'editor@site.test', 'password_hash' => $hash, 'roles' => ['editor']],
            ]]],
            'session' => ['cookie' => 'folio_badge_test', 'path' => '/', 'secure' => false],
        ], true) . ";\n");
        $this->write('config/roles.php', "<?php\nreturn " . var_export(['staff' => [
            'admin' => ['*'],
            'editor' => ['folio.dashboard', 'note.view'], // no contact.view
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
    public function test_badge_count_shown_on_type_item(): void
    {
        $this->crm(['count' => 3]);

        $nav = $this->dashboardAs('admin');
        // Two NavBadge methods (3 and 4) add up; the markup sits after the label.
        $this->assertStringContainsString('<span>Contacts</span><span class="folio-nav-badge" aria-label="7 waiting">7</span>', $nav);
    }

    #[RunInSeparateProcess]
    public function test_zero_or_null_hides_badge(): void
    {
        $this->crm(['count' => 'null', 'more' => 0]);

        $this->assertStringNotContainsString('folio-nav-badge"', $this->dashboardAs('admin'));
    }

    #[RunInSeparateProcess]
    public function test_badge_not_computed_when_item_hidden(): void
    {
        $this->crm(['count' => 3]);

        $html = $this->dashboardAs('editor');
        $this->assertStringNotContainsString('Contacts', $html);
        $this->assertStringNotContainsString('folio-nav-badge"', $html);
        $this->assertSame(0, \App\Folio\Crm\Log::$calls);
    }

    #[RunInSeparateProcess]
    public function test_throwing_badge_callback_shows_no_badge_and_keeps_others(): void
    {
        $this->crm(['count' => 3, 'more' => 0]);
        $this->noteBadge('throw new \\RuntimeException("db down");');

        $html = $this->dashboardAs('admin');
        $this->assertStringContainsString('aria-label="3 waiting">3</span>', $html);
        $this->assertSame(1, substr_count($html, 'folio-nav-badge"'));
    }

    #[RunInSeparateProcess]
    public function test_non_int_badge_return_shows_no_badge(): void
    {
        $this->crm(['count' => 'null', 'more' => 0]);
        $this->noteBadge('return "5";');

        $this->assertStringNotContainsString('folio-nav-badge"', $this->dashboardAs('admin'));
    }

    #[RunInSeparateProcess]
    public function test_badge_callbacks_run_once_per_request(): void
    {
        $this->crm(['count' => 3, 'more' => 0]);
        $app = $this->app();
        $customizations = $app->container()->get(\Preflow\Folio\Extension\Customization\Customizations::class);

        $this->assertSame(3, $customizations->navBadge('contact'));
        $this->assertSame(3, $customizations->navBadge('contact'));
        $this->assertSame(2, \App\Folio\Crm\Log::$calls); // two callbacks, one pass
    }

    #[RunInSeparateProcess]
    public function test_invalid_navbadge_method_throws(): void
    {
        $this->crm(['count' => 3]);
        $this->extension('bad', 'StaticBadge', <<<'PHP'
            #[\Preflow\Folio\Extension\Attributes\FolioType('contact')]
            final class StaticBadge
            {
                #[\Preflow\Folio\Extension\NavBadge]
                public static function count(): ?int { return 1; }
            }
            PHP);

        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessage('NavBadge');
        $this->app();
    }

    #[RunInSeparateProcess]
    public function test_two_navbadge_methods_in_one_class_throw(): void
    {
        $this->crm(['count' => 3]);
        $this->extension('bad', 'TwoBadges', <<<'PHP'
            #[\Preflow\Folio\Extension\Attributes\FolioType('contact')]
            final class TwoBadges
            {
                #[\Preflow\Folio\Extension\NavBadge]
                public function a(): ?int { return 1; }
                #[\Preflow\Folio\Extension\NavBadge]
                public function b(): ?int { return 1; }
            }
            PHP);

        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessage('more than one #[NavBadge]');
        $this->app();
    }

    #[RunInSeparateProcess]
    public function test_non_public_navbadge_method_throws(): void
    {
        $this->crm(['count' => 3]);
        $this->extension('bad', 'Hidden', <<<'PHP'
            #[\Preflow\Folio\Extension\Attributes\FolioType('contact')]
            final class Hidden
            {
                #[\Preflow\Folio\Extension\NavBadge]
                private function count(): ?int { return 1; }
            }
            PHP);

        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessage('public, non-static');
        $this->app();
    }

    #[RunInSeparateProcess]
    public function test_badge_count_shown_on_screen_item(): void
    {
        $this->crm(['count' => 3]);
        $this->queueScreen('return 4;');

        $this->assertStringContainsString('<span>Queue</span><span class="folio-nav-badge" aria-label="4 waiting">4</span>', $this->dashboardAs('admin'));
    }

    /** @return array<string, array{string}> */
    public static function noScreenBadge(): array
    {
        return ['zero' => ['return 0;'], 'null' => ['return null;'], 'string' => ['return "3";'], 'throw' => ['throw new \\RuntimeException("db down");']];
    }

    #[RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\DataProvider('noScreenBadge')]
    public function test_screen_badge_zero_null_non_int_or_throwing_shows_none(string $body): void
    {
        $this->crm(['count' => 'null', 'more' => 0]);
        $this->queueScreen($body);

        $html = $this->dashboardAs('admin');
        $this->assertStringContainsString('Queue', $html);
        $this->assertStringNotContainsString('folio-nav-badge"', $html);
    }

    #[RunInSeparateProcess]
    public function test_screen_badge_not_called_for_a_user_without_the_permission(): void
    {
        $this->crm(['count' => 'null', 'more' => 0]);
        $marker = $this->dir . '/badge-called';
        $this->queueScreen('file_put_contents(' . var_export($marker, true) . ', "x"); return 4;');

        $html = $this->dashboardAs('editor');
        $this->assertStringNotContainsString('Queue', $html);
        $this->assertFileDoesNotExist($marker);
    }

    #[RunInSeparateProcess]
    public function test_static_screen_badge_throws_at_boot(): void
    {
        $this->crm(['count' => 'null', 'more' => 0]);
        $this->queueScreen('return 1;', 'public static function waiting(): mixed');

        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessage('App\\Folio\\Crm\\Screens\\Queue::waiting must be a public, non-static method to carry #[NavBadge].');
        $this->app();
    }

    #[RunInSeparateProcess]
    public function test_second_screen_badge_throws_at_boot(): void
    {
        $this->crm(['count' => 'null', 'more' => 0]);
        $this->queueScreen('return 1;', 'public function waiting(): mixed', "#[\\Preflow\\Folio\\Extension\\NavBadge]\n    public function other(): mixed { return 2; }");

        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessage('App\\Folio\\Crm\\Screens\\Queue has more than one #[NavBadge]: waiting and other.');
        $this->app();
    }

    /** The crm extension's "queue" screen (permission crm.queue); $body is its badge method's body. */
    private function queueScreen(string $body, string $signature = 'public function waiting(): mixed', string $extra = ''): void
    {
        $this->write('app/Folio/Crm/Screens/Queue.php', <<<PHP
            <?php
            namespace App\\Folio\\Crm\\Screens;
            use Preflow\\Folio\\Extension\\Attributes\\FolioScreen;
            use Preflow\\Routing\\Attributes\\Get;
            #[FolioScreen('queue', 'Queue', section: 'content', permission: 'crm.queue')]
            final class Queue
            {
                #[Get('/')]
                public function index(): string { return 'queue'; }

                #[\\Preflow\\Folio\\Extension\\NavBadge]
                {$signature} { {$body} }

                {$extra}
            }
            PHP);
        $this->enabled['crm'] = ['disabled_parts' => []];
        $this->write('config/folio/extensions.json', (string) json_encode(['enabled' => $this->enabled]));
    }

    /**
     * The "crm" extension customizing contact with up to two badges. $badges: 'count' => the
     * first badge's return value (int or 'null'), 'more' => an optional second class's.
     *
     * @param array<string, int|string> $badges
     */
    private function crm(array $badges): void
    {
        $this->write('app/Folio/Crm/Extension.php', <<<'PHP'
            <?php
            namespace App\Folio\Crm;
            #[\Preflow\Folio\Extension\Attributes\FolioExtension(name: 'crm', label: 'CRM')]
            final class Extension extends \Preflow\Folio\Extension\Extension {}
            PHP);
        $this->write('app/Folio/Crm/Log.php', <<<'PHP'
            <?php
            namespace App\Folio\Crm;
            final class Log { public static int $calls = 0; }
            PHP);
        $first = var_export($badges['count'], true);
        $this->extension('crm', 'ContactAdmin', <<<PHP
            #[\Preflow\Folio\Extension\Attributes\FolioType('contact')]
            final class ContactAdmin
            {
                #[\Preflow\Folio\Extension\NavBadge]
                public function waiting(): ?int
                {
                    \App\Folio\Crm\Log::\$calls++;

                    return {$first} === 'null' ? null : {$first};
                }
            }
            PHP);
        $more = $badges['more'] ?? ($badges['count'] === 3 ? 4 : 0);
        $this->extension('zed', 'ContactMore', <<<PHP
            #[\Preflow\Folio\Extension\Attributes\FolioType('contact')]
            final class ContactMore
            {
                #[\Preflow\Folio\Extension\NavBadge]
                public function more(): ?int
                {
                    \App\Folio\Crm\Log::\$calls++;

                    return {$more};
                }
            }
            PHP);
    }

    /** A badge on the note type from its own extension; $body is the method body (untyped, so it can return anything). */
    private function noteBadge(string $body): void
    {
        $this->extension('nb', 'NoteBadge', <<<PHP
            #[\\Preflow\\Folio\\Extension\\Attributes\\FolioType('note')]
            final class NoteBadge
            {
                #[\\Preflow\\Folio\\Extension\\NavBadge]
                public function count() { {$body} }
            }
            PHP);
    }

    /** Another extension customizing contact with the given class body (attribute line first). */
    private function extension(string $name, string $class, string $body): void
    {
        $ns = 'App\\Folio\\' . ucfirst($name);
        $this->write('app/Folio/' . ucfirst($name) . '/Extension.php', "<?php\nnamespace {$ns};\n#[\\Preflow\\Folio\\Extension\\Attributes\\FolioExtension(name: '{$name}', label: '{$name}')]\nfinal class Extension extends \\Preflow\\Folio\\Extension\\Extension {}\n");
        $this->write('app/Folio/' . ucfirst($name) . "/Types/{$class}.php", "<?php\nnamespace {$ns}\\Types;\n" . $body . "\n");
        $this->enabled[$name] = ['disabled_parts' => []];
        $this->write('config/folio/extensions.json', (string) json_encode(['enabled' => $this->enabled]));
    }

    private function dashboardAs(string $role): string
    {
        $app = $this->app();
        $f = new Psr17Factory();
        $page = (string) $app->handle($f->createServerRequest('GET', '/folio/login'))->getBody();
        preg_match('/name="_csrf_token" value="([^"]+)"/', $page, $m);
        $login = $app->handle($f->createServerRequest('POST', '/folio/login')->withParsedBody([
            '_csrf_token' => $m[1] ?? '', 'email' => "{$role}@site.test", 'password' => 'secret',
        ]));
        $this->assertSame(302, $login->getStatusCode());

        return (string) $app->handle($f->createServerRequest('GET', '/folio'))->getBody();
    }
}
