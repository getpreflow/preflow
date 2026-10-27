<?php

declare(strict_types=1);

namespace Preflow\Members\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Members\Admission\AcceptOnConfirm;
use Preflow\Members\Admission\Admission;
use Preflow\Members\Links;
use Preflow\Members\MembersConfig;
use Preflow\Members\MembersDefaults;
use Preflow\Members\Tests\Support\MembersScaffold;

/** config/members.php: every key optional, defaults as smart-stack runs today; the provider reads the site's file. */
final class MembersConfigTest extends TestCase
{
    use MembersScaffold;

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    public function test_defaults(): void
    {
        $config = new MembersConfig([]);

        $this->assertSame('/members', $config->path());
        $this->assertSame('/members', $config->landing(), 'landing defaults to the path');
        $this->assertSame('@members/_layout.twig', $config->layout(), 'the package\'s own page layout');
        $this->assertNull($config->mailFromName());
        $this->assertSame('@members/mail/layout.twig', $config->mailLayout());
        $this->assertSame('#d42a34', $config->accent());
        $this->assertSame(AcceptOnConfirm::class, $config->admission(), 'a member once the address is confirmed');
        $this->assertSame([10, 900], $config->throttle('login'));
        $this->assertSame([5, 3600], $config->throttle('register'));
        $this->assertSame([5, 3600], $config->throttle('forgot'));
        $this->assertSame([3, 3600], $config->throttle('resend'));
        $this->assertSame(10, $config->passwordMin());
        $this->assertSame(86400, $config->confirmTtl());
        $this->assertSame(3600, $config->resetTtl());
        $this->assertSame(7, $config->retention('unconfirmed'));
        $this->assertSame(30, $config->retention('declined'));
    }

    public function test_a_package_may_set_defaults_and_the_site_file_wins(): void
    {
        $this->scaffold();
        $c = $this->app()->container();
        $this->assertSame('@members/_layout.twig', $c->get(MembersConfig::class)->layout(), 'without Folio: the package\'s own');

        $this->assertSame('@folio/frontend/_site.twig', (new MembersConfig([], ['layout' => '@folio/frontend/_site.twig']))->layout());
        $this->assertSame('@site/page.twig', (new MembersConfig(['layout' => '@site/page.twig'], ['layout' => '@folio/frontend/_site.twig']))->layout());

        $this->write('config/members.php', "<?php\nreturn ['path' => '/konto'];\n");
        $app = \Preflow\Core\Application::create($this->dir);
        $app->container()->instance(MembersDefaults::class, new MembersDefaults(['layout' => '@folio/frontend/_site.twig', 'path' => '/ignored']));
        $app->boot();
        $config = $app->container()->get(MembersConfig::class);
        $this->assertSame('@folio/frontend/_site.twig', $config->layout());
        $this->assertSame('/konto', $config->path());
    }

    public function test_site_values_win_key_by_key(): void
    {
        $config = new MembersConfig([
            'path' => 'konto/',
            'landing' => '/konto/objekte',
            'layout' => '@site/layout.twig',
            'mail' => ['from_name' => 'Holz', 'accent' => '#333'],
            'admission' => AcceptOnConfirm::class,
            'throttle' => ['login' => [3, 60]],
            'password_min' => 12,
            'retention' => ['declined_days' => 90],
        ]);

        $this->assertSame('/konto', $config->path(), 'one leading slash, no trailing one');
        $this->assertSame('/konto/objekte', $config->landing());
        $this->assertSame('@site/layout.twig', $config->layout());
        $this->assertSame('Holz', $config->mailFromName());
        $this->assertSame('@members/mail/layout.twig', $config->mailLayout(), 'mail keys merge');
        $this->assertSame('#333', $config->accent());
        $this->assertSame(AcceptOnConfirm::class, $config->admission());
        $this->assertSame([3, 60], $config->throttle('login'));
        $this->assertSame([5, 3600], $config->throttle('register'), 'throttle keys merge');
        $this->assertSame([0, 0], $config->throttle('unknown'));
        $this->assertSame(12, $config->passwordMin());
        $this->assertSame(90, $config->retention('declined'));
        $this->assertSame(7, $config->retention('unconfirmed'));
    }

    public function test_landing_without_path_follows_the_path(): void
    {
        $this->assertSame('/tools', (new MembersConfig(['path' => '/tools']))->landing());
    }

    public function test_the_fixed_names(): void
    {
        $this->assertSame('member', MembersConfig::TYPE);
        $this->assertSame('members', MembersConfig::GUARD);
        $this->assertSame('members.access', MembersConfig::PERMISSION);
        $this->assertSame('members.decide', MembersConfig::DECIDE);
        $this->assertSame('members.confirm', MembersConfig::CONFIRM_PURPOSE);
        $this->assertSame('members.reset', MembersConfig::RESET_PURPOSE);
        $this->assertSame('members.login', MembersConfig::THROTTLE_LOGIN);
        $this->assertSame('members.register', MembersConfig::THROTTLE_REGISTER);
        $this->assertSame('members.forgot', MembersConfig::THROTTLE_FORGOT);
        $this->assertSame('members.resend', MembersConfig::THROTTLE_RESEND);
    }

    public function test_register_defaults_to_the_package_page_under_the_path(): void
    {
        $this->assertSame('/members/register', (new MembersConfig([]))->register());
        $this->assertSame('/konto/register', (new MembersConfig(['path' => '/konto']))->register());
        $this->assertSame('/tools', (new MembersConfig(['path' => '/tools', 'register' => '/tools']))->register());
    }

    public function test_privacy_url_is_one_url_or_one_per_locale(): void
    {
        $this->assertSame('', (new MembersConfig([]))->privacyUrl('en', 'en'), 'none by default');
        $this->assertSame('/privacy', (new MembersConfig(['privacy_url' => '/privacy']))->privacyUrl('de', 'en'), 'one URL for every locale');
        $map = new MembersConfig(['privacy_url' => ['en' => '/privacy', 'de' => '/de/datenschutz']]);
        $this->assertSame('/de/datenschutz', $map->privacyUrl('de', 'en'));
        $this->assertSame('/privacy', $map->privacyUrl('fr', 'en'), 'a locale without one gets the default locale\'s');
        $this->assertSame('', (new MembersConfig(['privacy_url' => ['de' => '/de/datenschutz']]))->privacyUrl('fr', 'en'));
    }

    /** @return array<string, array{mixed}> */
    public static function rootPaths(): array
    {
        return ['empty' => [''], 'slash' => ['/'], 'slashes' => ['//'], 'null' => [null]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rootPaths')]
    public function test_a_path_at_the_site_root_is_refused(mixed $path): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('config/members.php path');
        new MembersConfig(['path' => $path]);
    }

    public function test_provider_reads_config_members_php(): void
    {
        $this->scaffold();
        $this->write('config/members.php', "<?php\nreturn ['path' => '/tools', 'password_min' => 14, 'admission' => " . AcceptOnConfirm::class . "::class];\n");
        $c = $this->app()->container();

        $config = $c->get(MembersConfig::class);
        $this->assertSame('/tools', $config->path());
        $this->assertSame(14, $config->passwordMin());
        $this->assertSame([10, 900], $config->throttle('login'));
        $this->assertInstanceOf(AcceptOnConfirm::class, $c->get(Admission::class));
    }

    public function test_provider_without_config_file_uses_defaults(): void
    {
        $this->scaffold();
        $c = $this->app()->container();

        $this->assertSame('/members', $c->get(MembersConfig::class)->path());
        $this->assertInstanceOf(AcceptOnConfirm::class, $c->get(Admission::class));
    }

    public function test_an_admission_class_that_is_no_admission_fails_at_boot(): void
    {
        $this->scaffold();
        $this->write('config/members.php', "<?php\nreturn ['admission' => \\stdClass::class];\n");

        $this->expectExceptionMessage('config/members.php admission: stdClass does not implement ' . Admission::class);
        $this->app();
    }

    public function test_a_bad_next_allow_entry_fails_at_boot(): void
    {
        $this->scaffold();
        $this->write('config/members.php', "<?php\nreturn ['next' => ['allow' => ['//evil.example']]];\n");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('config/members.php next.allow: "//evil.example" is no path of this site');
        $this->app();
    }

    public function test_links_never_use_the_request_host(): void
    {
        $this->scaffold();
        $links = $this->app()->container()->get(Links::class);

        $this->assertSame('https://site.test/members/reset/x', $links->url('en', '/reset/x'));
        $this->assertSame('https://site.test/de/members', $links->url('de'));
        $this->assertSame('https://site.test/members/login', $links->url('xx', '/login'), 'an unknown locale gets the default');
    }

    public function test_links_follow_path_and_locales(): void
    {
        $this->scaffold(locales: ['de', 'fr']);
        $this->write('config/members.php', "<?php\nreturn ['path' => '/konto'];\n");
        $links = $this->app()->container()->get(Links::class);

        $this->assertSame('https://site.test/konto/login', $links->url('de', '/login'));
        $this->assertSame('https://site.test/fr/konto/login', $links->url('fr', '/login'));
    }
}
