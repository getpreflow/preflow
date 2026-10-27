<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Members;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\DevTools\Command\MigrateCommand;
use Preflow\Folio\Console\ExtensionsApplyCommand;
use Preflow\Folio\Extension\ExtensionRegistry;
use Preflow\Folio\Extension\ExtensionState;
use Preflow\Folio\Members\Extension;
use Preflow\Folio\Members\FolioMembersSettings;
use Preflow\Members\Tests\Support\Dom;
use Preflow\Folio\Tests\Members\Support\MembersScaffold;
use Preflow\Members\MembersSettings;

/**
 * Folio carries the members admin: its built-in extension "members", there when preflow/members
 * is installed (as Folio carries media's library), under the name, type keys and permissions
 * the package's own extension had, so a site's enable state and stored settings carry over.
 */
final class MembersBuiltInTest extends TestCase
{
    use MembersScaffold;

    protected function setUp(): void
    {
        $this->scaffold();
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    public function test_folio_discovers_the_members_admin_when_members_is_installed(): void
    {
        $this->write('vendor/composer/installed.json', '{"packages": [{"name": "preflow/members"}]}');
        $this->write('config/folio/extensions.json', '{"enabled": {}}');

        $definition = ExtensionRegistry::discover($this->dir, debug: true)->get('members');

        $this->assertSame(Extension::class, $definition->class);
        $this->assertSame('members-admin', $definition->translations);
        $this->assertSame(['model:member', 'type:member', 'type:members_settings', 'settings'], array_column($definition->parts, 'id'));
    }

    public function test_without_preflow_members_there_is_no_members_admin(): void
    {
        $this->write('config/folio/extensions.json', '{"enabled": {}}');

        $this->write('vendor/composer/installed.json', '{"packages": []}');
        $this->assertSame([], ExtensionRegistry::discover($this->dir, debug: true)->all(), 'Composer does not list it');

        $this->write('vendor/composer/installed.json', '{"packages": [{"name": "preflow/members"}]}');
        $registry = ExtensionRegistry::discover($this->dir, debug: true, builtIn: [Extension::class => ['package' => 'preflow/members', 'class' => 'Preflow\\Members\\NotInstalled']]);
        $this->assertSame([], $registry->all(), 'its classes are missing');
    }

    public function test_an_older_members_packages_declaration_of_its_extension_is_ignored(): void
    {
        // A site's installed.json written by Composer before this release still names the old class.
        $this->write('vendor/composer/installed.json', (string) json_encode(['packages' => [
            ['name' => 'preflow/rbac', 'extra' => ['folio' => ['extension' => 'Preflow\\Rbac\\Extension']]],
            ['name' => 'preflow/members', 'extra' => ['folio' => ['extension' => 'Preflow\\Members\\Extension']]],
        ]]));

        $registry = ExtensionRegistry::discover($this->dir, debug: true);

        $this->assertSame(Extension::class, $registry->get('members')->class);
        $this->assertTrue($registry->isEnabled('members'));
    }

    #[RunInSeparateProcess] // declares App\\Folio\\Members\\Extension, as other tests do
    public function test_a_sites_own_extension_named_members_wins_over_the_built_in(): void
    {
        $this->write('app/Folio/Members/Extension.php', <<<'PHP'
            <?php
            namespace App\Folio\Members;
            #[\Preflow\Folio\Extension\Attributes\FolioExtension(name: 'members', label: 'Own members')]
            final class Extension extends \Preflow\Folio\Extension\Extension {}
            PHP);
        require_once $this->dir . '/app/Folio/Members/Extension.php';

        $registry = ExtensionRegistry::discover($this->dir, debug: true);

        $this->assertSame('App\\Folio\\Members\\Extension', $registry->get('members')->class);
    }

    public function test_folio_binds_its_members_settings(): void
    {
        $this->migrateAndApply();

        $this->assertInstanceOf(FolioMembersSettings::class, $this->container()->get(MembersSettings::class));
    }

    /**
     * A site of the previous release: members enabled in config/folio/extensions.json, installed
     * at 1.2.0, its Settings → Members record stored where the package's extension stored it.
     */
    public function test_an_existing_sites_enable_state_and_settings_carry_over(): void
    {
        [$code, $out] = $this->command(new MigrateCommand(fn () => $this->app()));
        $this->assertSame(0, $code, $out);
        $this->container()->get(ExtensionState::class)->markInstalled('members', '1.2.0');
        $this->container()->get(ExtensionState::class)->markInstalled('rbac', $this->container()->get(ExtensionRegistry::class)->get('rbac')->version);
        $this->write('storage/data/folio_settings_members/members_settings.json', (string) json_encode([
            'uuid' => 'members_settings',
            'registration_open' => false,
            'notice_email' => 'owner@site.test',
            'accept_message' => ['en' => 'Welcome, {name}.', 'de' => 'Willkommen, {name}.'],
            'decline_message' => ['en' => 'Sorry, {name}.'],
        ]));
        $this->flow = null;

        [$code, $out] = $this->command(new ExtensionsApplyCommand(fn () => $this->app()));
        $this->assertSame(0, $code, $out);
        $this->assertStringNotContainsString('installed members', $out, 'already installed at this version');

        $c = $this->container();
        $this->assertTrue($c->get(ExtensionRegistry::class)->isEnabled('members'));
        $settings = $c->get(MembersSettings::class);
        $this->assertFalse($settings->registrationOpen());
        $this->assertSame('owner@site.test', $settings->noticeEmail());
        $this->assertSame('Welcome, {name}.', $settings->acceptMessage('en'));
        $this->assertSame('Willkommen, {name}.', $settings->acceptMessage('de'));
        $this->assertSame('Sorry, {name}.', $settings->declineMessage('en'));
        $this->assertNull($settings->declineMessage('de'), 'no text stored for de: the lang files word it');
    }

    #[RunInSeparateProcess]
    public function test_the_carried_over_settings_show_in_settings_members(): void
    {
        $this->migrateAndApply();
        $this->write('storage/data/folio_settings_members/members_settings.json', (string) json_encode([
            'uuid' => 'members_settings', 'registration_open' => false, 'notice_email' => 'owner@site.test',
        ]));
        $this->signIn();

        $html = $this->admin('/folio/members_settings');
        $this->assertSame('owner@site.test', Dom::first($html, '//input[@name="notice_email"]')?->getAttribute('value'));
    }

    #[RunInSeparateProcess]
    public function test_folio_boots_without_preflow_members(): void
    {
        // Simulated: the members classes can't be loaded (the package is not installed).
        $loader = require dirname(__DIR__, 4) . '/vendor/autoload.php';
        $loader->setPsr4('Preflow\\Members\\', [$this->dir . '/no-such-dir']);
        $this->assertFalse(class_exists(\Preflow\Members\Members::class));
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class, \\Preflow\\Rbac\\RbacServiceProvider::class];\n");
        $this->write('vendor/composer/installed.json', (string) json_encode(['packages' => [
            ['name' => 'preflow/rbac', 'extra' => ['folio' => ['extension' => 'Preflow\\Rbac\\Extension']]],
        ]]));
        $this->write('config/folio/extensions.json', '{"enabled": {"rbac": {"disabled_parts": []}}}');
        $this->migrateAndApply();
        $this->signIn();

        $this->assertFalse($this->container()->get(ExtensionRegistry::class)->isEnabled('members'));
        $this->assertSame([], array_filter(array_map(static fn ($d) => $d->name, $this->container()->get(ExtensionRegistry::class)->all()), static fn (string $n): bool => $n === 'members'));
        $dashboard = $this->admin('/folio');
        $this->assertNull(Dom::first($dashboard, '//nav[contains(@class, "folio-nav")]//a[@href="/folio/member"]'));
        $this->assertStringNotContainsString('Members settings', $dashboard);
    }
}
