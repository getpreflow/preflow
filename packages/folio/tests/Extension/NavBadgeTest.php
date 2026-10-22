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
