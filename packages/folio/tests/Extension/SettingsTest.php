<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Extension;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Data\DataManager;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Extension\ExtensionException;
use Preflow\Folio\Extension\Settings\ExtensionSettings;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\Folio\Transfer\ContentSet;
use Preflow\Folio\Transfer\Exporter;
use Preflow\View\TemplateEngineInterface;

/**
 * A "members" extension (written by the test) declaring settings: a boolean, a string,
 * a translatable string (en/de) and an env reference. No auth, so admin POSTs need no
 * session or CSRF token.
 */
final class SettingsTest extends TestCase
{
    use PublishingScaffold;

    private const ENV = 'FOLIO_SETTINGS_TEST_MAIL_PASSWORD';

    private \Closure $autoload;

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
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
        putenv(self::ENV);
    }

    protected function tearDown(): void
    {
        spl_autoload_unregister($this->autoload);
        putenv(self::ENV);
        $this->removeScaffold();
    }

    #[RunInSeparateProcess]
    public function test_settings_form_listed_under_settings_section(): void
    {
        $this->members();
        $app = $this->app();

        $listing = $app->container()->get(TypeCatalog::class)->get('members_settings');
        $this->assertNotNull($listing);
        $this->assertSame('Members', $listing->label);
        $this->assertTrue($listing->singleton);
        $this->assertFalse($listing->publishing);
        $this->assertSame('settings', $listing->section);
        $this->assertSame('folio_settings_members', $app->container()->get(DataManager::class)->getTypeRegistry()->get('members_settings')->table);

        $page = (string) $app->handle((new \Nyholm\Psr7\Factory\Psr17Factory())->createServerRequest('GET', '/folio/members_settings'))->getBody();
        $this->assertMatchesRegularExpression(
            '#<span class="folio-nav-label">Settings</span>\s*<a href="/folio/members_settings" class="active" aria-current="page">\s*<svg class="folio-icon" aria-hidden="true"><use href="[^"]*\#ui-settings"></use></svg><span>Members</span>#',
            $page,
        );
        // Before the first save the form shows the declared defaults.
        $this->assertStringContainsString('action="/folio/members_settings/members_settings"', $page);
        $this->assertStringContainsString('value="office@example.org"', $page);
        $this->assertMatchesRegularExpression('#name="registration_open"[^>]*checked#', $page);
    }

    #[RunInSeparateProcess]
    public function test_defaults_before_first_save(): void
    {
        $this->members();
        $app = $this->app();
        $settings = $app->container()->get(ExtensionSettings::class);

        $this->assertTrue($settings->get('members', 'registration_open'));
        $this->assertSame('office@example.org', $settings->get('members', 'notice_address'));
        $this->assertNull($settings->get('members', 'book_code'));
        $this->assertSame('What brings you here?', $settings->get('members', 'screening_label'));
        $this->assertSame('Was führt dich her?', $settings->get('members', 'screening_label', 'de'));
        $this->assertNull($app->container()->get(DataManager::class)->findType('members_settings', 'members_settings'));

        $this->write('resources/folio/settings_probe.twig', '{{ folio_setting("members", "notice_address") }}|{{ folio_setting("members", "registration_open") ? "open" : "closed" }}');
        $this->assertSame('office@example.org|open', $app->container()->get(TemplateEngineInterface::class)->render('@folio/settings_probe.twig'));
    }

    #[RunInSeparateProcess]
    public function test_saved_value_wins_over_default(): void
    {
        $this->members();

        $res = $this->post('/folio/members_settings/members_settings', [
            'registration_open' => '0',
            'notice_address' => 'board@example.org',
            'book_code' => 'BOOK-42',
            'screening_label' => 'Why join?',
        ]);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio/members_settings?locale=en', $res->getHeaderLine('Location'));

        $settings = $this->app()->container()->get(ExtensionSettings::class);
        $this->assertFalse($settings->get('members', 'registration_open'));
        $this->assertSame('board@example.org', $settings->get('members', 'notice_address'));
        $this->assertSame('BOOK-42', $settings->get('members', 'book_code'));
    }

    #[RunInSeparateProcess]
    public function test_translatable_setting_per_locale(): void
    {
        $this->members();

        $this->post('/folio/members_settings/members_settings?locale=en', ['registration_open' => '1', 'screening_label' => 'Why join?']);
        $settings = $this->app()->container()->get(ExtensionSettings::class);
        $this->assertSame('Why join?', $settings->get('members', 'screening_label', 'en'));
        // Not translated yet: the content fallback (de -> en), as in content.
        $this->assertSame('Why join?', $settings->get('members', 'screening_label', 'de'));

        $this->post('/folio/members_settings/members_settings?locale=de', ['registration_open' => '1', 'screening_label' => 'Warum beitreten?']);
        $settings = $this->app()->container()->get(ExtensionSettings::class);
        $this->assertSame('Why join?', $settings->get('members', 'screening_label', 'en'));
        $this->assertSame('Warum beitreten?', $settings->get('members', 'screening_label', 'de'));
        $this->assertSame(['en' => 'Why join?', 'de' => 'Warum beitreten?'], $this->stored('members_settings', 'members_settings')->get('screening_label'));
    }

    #[RunInSeparateProcess]
    public function test_env_field_never_stored(): void
    {
        $this->members();
        putenv(self::ENV . '=env-secret-value');

        $res = $this->post('/folio/members_settings/members_settings', [
            'registration_open' => '1',
            'notice_address' => 'board@example.org',
            'mail_password' => 'posted-secret-value',
        ]);
        $this->assertSame(302, $res->getStatusCode());

        $record = $this->stored('members_settings', 'members_settings');
        $this->assertSame('board@example.org', $record->get('notice_address'));
        $this->assertArrayNotHasKey('mail_password', $record->toArray());
        foreach (glob($this->dir . '/storage/data/folio_settings_members/*') ?: [] as $file) {
            $this->assertStringNotContainsString('posted-secret-value', (string) file_get_contents($file));
            $this->assertStringNotContainsString('mail_password', (string) file_get_contents($file));
        }

        $page = (string) $this->get('/folio/members_settings')->getBody();
        $this->assertStringNotContainsString('posted-secret-value', $page);
        $this->assertStringNotContainsString('env-secret-value', $page);
        $this->assertSame('env-secret-value', $this->app()->container()->get(ExtensionSettings::class)->get('members', 'mail_password'));
    }

    #[RunInSeparateProcess]
    public function test_env_field_shows_set_state(): void
    {
        $this->members();

        $unset = (string) $this->get('/folio/members_settings')->getBody();
        $this->assertStringContainsString('<code>' . self::ENV . '</code> <span class="folio-badge">not set</span>', $unset);
        $this->assertStringNotContainsString('name="mail_password"', $unset);
        $this->assertNull($this->app()->container()->get(ExtensionSettings::class)->get('members', 'mail_password'));

        putenv(self::ENV . '=env-secret-value');
        $set = (string) $this->get('/folio/members_settings')->getBody();
        $this->assertStringContainsString('<code>' . self::ENV . '</code> <span class="folio-badge">set</span>', $set);
        $this->assertStringNotContainsString('env-secret-value', $set);
    }

    #[RunInSeparateProcess]
    public function test_settings_exported_like_content(): void
    {
        $this->members();
        $this->post('/folio/members_settings/members_settings', ['registration_open' => '1', 'notice_address' => 'board@example.org']);

        $app = $this->app();
        $stream = fopen('php://temp', 'w+b');
        (new Exporter(ContentSet::fromApp($app), $app->container()->get(DataManager::class)))->export($stream);
        rewind($stream);
        $names = [];
        foreach ((new \Preflow\Folio\Transfer\TarReader($stream))->entries() as [$name]) {
            $names[] = $name;
        }

        $this->assertArrayHasKey('folio_settings_members', ContentSet::fromApp($app)->tables());
        $this->assertContains('data/folio_settings_members/members_settings.json', $names);
    }

    #[RunInSeparateProcess]
    public function test_unknown_key_throws_in_debug(): void
    {
        $this->members();
        $settings = $this->app()->container()->get(ExtensionSettings::class);

        try {
            $settings->get('members', 'nope');
            $this->fail('expected an ExtensionException');
        } catch (ExtensionException $e) {
            $this->assertStringContainsString('"nope"', $e->getMessage());
        }

        // Not in debug: null.
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Pub', 'debug' => 0, 'engine' => 'twig', 'key' => 'folio-integration-test-key'];\n");
        putenv('APP_DEBUG=0');
        $quiet = $this->app()->container()->get(ExtensionSettings::class);
        $this->assertNull($quiet->get('members', 'nope'));
        $this->assertNull($quiet->get('ghost', 'nope'));
        $this->assertSame('office@example.org', $quiet->get('members', 'notice_address'));
    }

    #[RunInSeparateProcess]
    public function test_disabled_extension_settings_not_registered_and_permissions_disabled(): void
    {
        $this->members(enabled: false);
        $app = $this->app(); // no throw, even in debug

        $this->assertFalse($app->container()->get(TypeCatalog::class)->has('members_settings'));
        $this->assertSame(404, $app->handle((new \Nyholm\Psr7\Factory\Psr17Factory())->createServerRequest('GET', '/folio/members_settings'))->getStatusCode());
        $registry = $app->container()->get(\Preflow\Auth\Access\Access::class)->registry();
        foreach (['members_settings.view', 'members_settings.edit'] as $name) {
            $this->assertTrue($registry->isDeclared($name), $name);
            $this->assertSame('extension members', $registry->disabledReason($name), $name);
        }
        $this->assertSame('Members', $registry->all()['members_settings.view']['group']);
        $this->assertNull($app->container()->get(ExtensionSettings::class)->get('members', 'notice_address'));
    }

    /** The "members" extension with its settings form. */
    private function members(bool $enabled = true): void
    {
        $env = self::ENV;
        $this->write('app/Folio/Members/Extension.php', <<<PHP
            <?php
            namespace App\\Folio\\Members;
            #[\\Preflow\\Folio\\Extension\\Attributes\\FolioExtension(name: 'members', label: 'Members')]
            final class Extension extends \\Preflow\\Folio\\Extension\\Extension
            {
                public function settings(): array
                {
                    return [
                        'registration_open' => ['type' => 'boolean', 'label' => 'Registration open', 'default' => true],
                        'notice_address' => ['type' => 'string', 'label' => 'Approval notice address', 'default' => 'office@example.org'],
                        'book_code' => ['type' => 'string', 'label' => 'Book code (skips screening)'],
                        'screening_label' => ['type' => 'string', 'label' => 'Screening field label', 'translatable' => true,
                            'default' => ['en' => 'What brings you here?', 'de' => 'Was führt dich her?']],
                        'mail_password' => ['type' => 'env', 'label' => 'Mail password', 'env' => '{$env}'],
                    ];
                }
            }
            PHP);
        if ($enabled) {
            $this->write('config/folio/extensions.json', '{"enabled": {"members": {"disabled_parts": []}}}');
        }
    }
}
