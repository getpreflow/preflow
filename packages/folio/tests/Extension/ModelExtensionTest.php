<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Extension;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Folio\Content\FrontendConfigException;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Extension\ExtensionException;
use Preflow\Folio\Extension\ModelExtension;
use Preflow\Folio\Extension\Settings\ExtensionSettings;
use Preflow\Folio\Tests\Support\PublishingScaffold;

/**
 * A site model file with "extends": true adds fields (and list columns) to a type an enabled
 * extension ships, its model or its settings singleton. Unit cases on merge(), then boot cases
 * with a "members" extension written by the test (model "member", settings "members_settings").
 */
final class ModelExtensionTest extends TestCase
{
    use PublishingScaffold;

    private \Closure $autoload;

    private const array MEMBER = [
        'key' => 'member',
        'label' => 'Members',
        'table' => 'member',
        'storage' => 'json',
        'id_field' => 'uuid',
        'users' => 'members',
        'list' => ['columns' => ['email'], 'orderable' => false],
        'fields' => [
            'email' => ['type' => 'string', 'validate' => ['required']],
            'name' => ['type' => 'string'],
        ],
    ];

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
    }

    protected function tearDown(): void
    {
        spl_autoload_unregister($this->autoload);
        $this->removeScaffold();
    }

    public function test_merge_appends_site_fields_after_the_extensions(): void
    {
        $merged = ModelExtension::merge(self::MEMBER, [
            'key' => 'member',
            'extends' => true,
            'fields' => ['company' => ['type' => 'string', 'label' => 'Company'], 'vat' => ['type' => 'string']],
        ]);

        $this->assertSame(['email', 'name', 'company', 'vat'], array_keys($merged['fields']));
        $this->assertSame(['type' => 'string', 'label' => 'Company'], $merged['fields']['company']);
        $this->assertSame(['columns' => ['email'], 'orderable' => false], $merged['list']);
        $this->assertArrayNotHasKey('extends', $merged);
        foreach (['key', 'label', 'table', 'storage', 'id_field', 'users'] as $option) {
            $this->assertSame(self::MEMBER[$option], $merged[$option], $option);
        }
    }

    public function test_merge_places_a_site_field_after_the_field_it_names(): void
    {
        // The edit form follows the field order: a site field can sit next to the fields it belongs with.
        $merged = ModelExtension::merge(self::MEMBER, [
            'key' => 'member',
            'extends' => true,
            'fields' => [
                'company' => ['type' => 'string', 'after' => 'email'],
                'vat' => ['type' => 'string', 'after' => 'company'],
                'note' => ['type' => 'text'],
            ],
        ]);

        $this->assertSame(['email', 'company', 'vat', 'name', 'note'], array_keys($merged['fields']));
        $this->assertSame(['type' => 'string'], $merged['fields']['company'], '"after" is placement only, not field config');
    }

    public function test_merge_refuses_after_an_unknown_field(): void
    {
        $this->expectException(FrontendConfigException::class);
        $this->expectExceptionMessage('Model "member" extends the extension type: field "company" goes "after" "phone", which the type does not have (yet)');

        ModelExtension::merge(self::MEMBER, ['key' => 'member', 'extends' => true, 'fields' => ['company' => ['type' => 'string', 'after' => 'phone']]]);
    }

    public function test_merge_site_list_columns_replace_the_extensions(): void
    {
        $merged = ModelExtension::merge(self::MEMBER, [
            'key' => 'member',
            'extends' => true,
            'list' => ['columns' => ['email', 'company']],
            'fields' => ['company' => ['type' => 'string']],
        ]);

        $this->assertSame(['columns' => ['email', 'company'], 'orderable' => false], $merged['list']);
    }

    public function test_merge_accepts_equal_locked_options(): void
    {
        $merged = ModelExtension::merge(self::MEMBER, [
            'key' => 'member', 'extends' => true, 'table' => 'member', 'storage' => 'json', 'users' => 'members', 'id_field' => 'uuid',
        ]);

        $this->assertSame(self::MEMBER['fields'], $merged['fields']);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function lockedOptions(): iterable
    {
        yield 'storage' => ['storage', 'sqlite'];
        yield 'table' => ['table', 'members'];
        yield 'users' => ['users', 'staff'];
        yield 'id_field' => ['id_field', 'id'];
        yield 'key' => ['key', 'person'];
    }

    #[DataProvider('lockedOptions')]
    public function test_merge_refuses_a_changed_locked_option(string $option, mixed $value): void
    {
        $this->expectException(FrontendConfigException::class);
        $this->expectExceptionMessage("Model \"member\" extends the extension type: \"{$option}\" can't be changed");

        ModelExtension::merge(self::MEMBER, ['key' => 'member', 'extends' => true, $option => $value]);
    }

    public function test_merge_refuses_a_field_the_extension_defines(): void
    {
        $this->expectException(FrontendConfigException::class);
        $this->expectExceptionMessage('Model "member" extends the extension type: field "name" is already defined by the extension');

        ModelExtension::merge(self::MEMBER, ['key' => 'member', 'extends' => true, 'fields' => ['company' => ['type' => 'string'], 'name' => ['type' => 'text']]]);
    }

    #[RunInSeparateProcess]
    public function test_site_file_extends_extension_model(): void
    {
        $this->members();
        $this->write('config/models/member.json', (string) json_encode([
            'key' => 'member',
            'extends' => true,
            'list' => ['columns' => ['email', 'company']],
            'fields' => ['company' => ['type' => 'string', 'label' => 'Company']],
        ]));
        $app = $this->app();

        $def = $app->container()->get(DataManager::class)->getTypeRegistry()->get('member');
        $this->assertSame(['email', 'name', 'company'], array_keys($def->fields));
        $this->assertSame('member', $def->table);
        $listing = $app->container()->get(TypeCatalog::class)->get('member');
        $this->assertSame('Members', $listing?->label);
        $this->assertSame(['email', 'company'], $listing->list['columns']);
        // Listed once: the patch file is no type of its own.
        $this->assertCount(1, array_filter($app->container()->get(TypeCatalog::class)->all(), static fn ($l) => $l->key === 'member'));
    }

    #[RunInSeparateProcess]
    public function test_site_file_extends_extension_settings(): void
    {
        $this->members();
        $this->write('config/models/members_settings.json', (string) json_encode([
            'key' => 'members_settings',
            'extends' => true,
            'fields' => ['book_code' => ['type' => 'string', 'label' => 'Book code', 'default' => 'BOOK-1']],
        ]));
        $app = $this->app();

        $def = $app->container()->get(DataManager::class)->getTypeRegistry()->get('members_settings');
        $this->assertSame(['registration_open', 'book_code'], array_keys($def->fields));
        $this->assertSame('folio_settings_members', $def->table);
        $listing = $app->container()->get(TypeCatalog::class)->get('members_settings');
        $this->assertTrue($listing?->singleton);
        $this->assertSame('settings', $listing->section);
        $settings = $app->container()->get(ExtensionSettings::class);
        $this->assertSame('BOOK-1', $settings->get('members', 'book_code'));
        $this->assertTrue($settings->get('members', 'registration_open'));

        // The stored singleton keeps working: same record, same table, the site field saved beside the extension's.
        $res = $this->post('/folio/members_settings/members_settings', ['registration_open' => '0', 'book_code' => 'BOOK-42']);
        $this->assertSame(302, $res->getStatusCode());
        $settings = $this->app()->container()->get(ExtensionSettings::class);
        $this->assertFalse($settings->get('members', 'registration_open'));
        $this->assertSame('BOOK-42', $settings->get('members', 'book_code'));
        $this->assertSame('BOOK-42', $this->stored('members_settings', 'members_settings')->get('book_code'));
        $this->assertFileExists($this->dir . '/storage/data/folio_settings_members/members_settings.json');
        $this->assertStringContainsString('value="BOOK-42"', (string) $this->get('/folio/members_settings')->getBody());
    }

    #[RunInSeparateProcess]
    public function test_extension_prepares_its_model_at_boot_before_site_patches(): void
    {
        // prepareModel(): the extension picks a storage and options from the site's config at boot.
        $this->write('app/Folio/Members/Extension.php', <<<'PHP'
            <?php
            namespace App\Folio\Members;
            #[\Preflow\Folio\Extension\Attributes\FolioExtension(name: 'members', label: 'Members')]
            final class Extension extends \Preflow\Folio\Extension\Extension
            {
                public function prepareModel(array $schema, \Preflow\Core\Container\Container $c): array
                {
                    $drivers = $c->get(\Preflow\Data\DataManager::class)->driverNames();
                    $schema['storage'] = in_array('members', $drivers, true) ? 'members' : 'default';
                    $schema['fields']['name']['label'] = 'Locales: ' . implode(',', $c->get(\Preflow\Data\Locales::class)->available);

                    return $schema;
                }
            }
            PHP);
        $this->write('app/Folio/Members/models/member.json', (string) json_encode(['storage' => 'members'] + self::MEMBER));
        $this->write('config/folio/extensions.json', '{"enabled": {"members": {"disabled_parts": []}}}');
        $this->write('config/models/member.json', (string) json_encode([
            'key' => 'member',
            'extends' => true,
            'fields' => ['company' => ['type' => 'string']],
        ]));

        $def = $this->app()->container()->get(DataManager::class)->getTypeRegistry()->get('member');

        $this->assertSame('default', $def->storage);
        $this->assertSame('Locales: en,de', $def->fields['name']->label);
        $this->assertSame(['email', 'name', 'company'], array_keys($def->fields));
    }

    #[RunInSeparateProcess]
    public function test_site_file_without_extends_keeps_the_duplicate_error(): void
    {
        $this->members();
        $this->write('config/models/member.json', (string) json_encode(['key' => 'member', 'table' => 'member', 'fields' => []]));

        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessage('Extension model "member": Type "member" already exists');
        $this->app();
    }

    #[RunInSeparateProcess]
    public function test_site_file_redefining_a_field_fails_at_boot(): void
    {
        $this->members();
        $this->write('config/models/member.json', (string) json_encode(['key' => 'member', 'extends' => true, 'fields' => ['email' => ['type' => 'text']]]));

        $this->expectException(FrontendConfigException::class);
        $this->expectExceptionMessage('Model "member" extends the extension type: field "email" is already defined by the extension');
        $this->app();
    }

    #[RunInSeparateProcess]
    public function test_site_file_changing_storage_fails_at_boot(): void
    {
        $this->members();
        $this->write('config/models/member.json', (string) json_encode(['key' => 'member', 'extends' => true, 'storage' => 'sqlite']));

        $this->expectException(FrontendConfigException::class);
        $this->expectExceptionMessage('Model "member" extends the extension type: "storage" can\'t be changed');
        $this->app();
    }

    #[RunInSeparateProcess]
    public function test_site_file_extending_no_extension_type_fails_at_boot(): void
    {
        $this->members();
        $this->write('config/models/client.json', (string) json_encode(['key' => 'client', 'extends' => true, 'fields' => ['company' => ['type' => 'string']]]));

        $this->expectException(FrontendConfigException::class);
        $this->expectExceptionMessage('Model "client" extends an extension type, but no enabled extension defines it');
        $this->app();
    }

    #[RunInSeparateProcess]
    public function test_site_file_extending_a_disabled_extension_fails_at_boot(): void
    {
        $this->members(enabled: false);
        $this->write('config/models/member.json', (string) json_encode(['key' => 'member', 'extends' => true, 'fields' => ['company' => ['type' => 'string']]]));

        $this->expectException(FrontendConfigException::class);
        $this->expectExceptionMessage('Model "member" extends an extension type, but no enabled extension defines it');
        $this->app();
    }

    #[RunInSeparateProcess]
    public function test_boot_error_names_the_patch_file_and_the_recovery_command(): void
    {
        $this->members(enabled: false);
        $this->write('config/models/member.json', (string) json_encode(['key' => 'member', 'extends' => true, 'fields' => ['company' => ['type' => 'string']]]));

        try {
            $this->app();
            $this->fail('expected a FrontendConfigException');
        } catch (FrontendConfigException $e) {
            $this->assertStringContainsString('Model "member" extends an extension type, but no enabled extension defines it', $e->getMessage());
            $this->assertStringContainsString($this->dir . '/config/models/member.json', $e->getMessage());
            $this->assertStringContainsString('preflow folio:extensions:enable members', $e->getMessage());
        }
    }

    #[RunInSeparateProcess]
    public function test_boot_error_for_a_disabled_part_names_the_part(): void
    {
        $this->members();
        $this->write('config/folio/extensions.json', '{"enabled": {"members": {"disabled_parts": ["settings"]}}}');
        $this->write('config/models/members_settings.json', (string) json_encode(['key' => 'members_settings', 'extends' => true, 'fields' => ['book_code' => ['type' => 'string']]]));

        $this->expectException(FrontendConfigException::class);
        $this->expectExceptionMessage('preflow folio:extensions:enable members --part=settings');
        $this->app();
    }

    #[RunInSeparateProcess]
    public function test_admin_toggle_refuses_to_disable_an_extended_extension(): void
    {
        $this->members();
        $this->write('config/models/member.json', (string) json_encode(['key' => 'member', 'extends' => true, 'fields' => ['company' => ['type' => 'string']]]));
        $before = (string) file_get_contents($this->dir . '/config/folio/extensions.json');

        $res = $this->post('/folio/extensions/members', ['enabled' => '0']);

        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('Can\'t disable extension "members"', (string) $res->getBody());
        $this->assertStringContainsString($this->dir . '/config/models/member.json extends its type "member"', (string) $res->getBody());
        $this->assertSame($before, (string) file_get_contents($this->dir . '/config/folio/extensions.json'));
        $this->assertSame(200, $this->get('/folio/member')->getStatusCode()); // still boots
    }

    #[RunInSeparateProcess]
    public function test_admin_toggle_refuses_to_disable_an_extended_part(): void
    {
        $this->members();
        $this->write('config/models/members_settings.json', (string) json_encode(['key' => 'members_settings', 'extends' => true, 'fields' => ['book_code' => ['type' => 'string']]]));

        $res = $this->post('/folio/extensions/members', ['part' => 'settings', 'enabled' => '0']);

        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('Can\'t disable part "settings" of extension "members"', (string) $res->getBody());
        $this->assertStringContainsString('members_settings.json extends its type "members_settings"', (string) $res->getBody());
        // Parts the patch does not touch still toggle.
        $this->assertSame(302, $this->post('/folio/extensions/members', ['part' => 'model:member', 'enabled' => '0'])->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_cli_refuses_to_disable_an_extended_extension_or_part(): void
    {
        $this->members();
        $this->write('config/models/member.json', (string) json_encode(['key' => 'member', 'extends' => true, 'fields' => ['company' => ['type' => 'string']]]));
        $before = (string) file_get_contents($this->dir . '/config/folio/extensions.json');

        [$code, $out] = $this->cli(new \Preflow\Folio\Console\ExtensionsDisableCommand($this->dir), ['members']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Can\'t disable extension "members": the site model file ' . $this->dir . '/config/models/member.json extends its type "member"', $out);

        [$code, $out] = $this->cli(new \Preflow\Folio\Console\ExtensionsDisableCommand($this->dir), ['members', '--part=model:member']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Can\'t disable part "model:member" of extension "members"', $out);
        $this->assertSame($before, (string) file_get_contents($this->dir . '/config/folio/extensions.json'));
    }

    #[RunInSeparateProcess]
    public function test_cli_enable_recovers_from_the_patch_boot_error(): void
    {
        $this->members(enabled: false);
        $this->write('config/models/member.json', (string) json_encode(['key' => 'member', 'extends' => true, 'fields' => ['company' => ['type' => 'string']]]));

        [$code, $out] = $this->cli(new \Preflow\Folio\Console\ExtensionsEnableCommand($this->dir), ['members']);

        $this->assertSame(0, $code, $out);
        $this->assertSame(['email', 'name', 'company'], array_keys($this->app()->container()->get(DataManager::class)->getTypeRegistry()->get('member')->fields));
    }

    /**
     * @param list<string> $args
     * @return array{int, string}
     */
    private function cli(object $command, array $args): array
    {
        ob_start();
        $code = $command->execute($args);

        return [$code, (string) ob_get_clean()];
    }

    /** The "members" extension: a "member" model and a settings form. */
    private function members(bool $enabled = true): void
    {
        $this->write('app/Folio/Members/Extension.php', <<<'PHP'
            <?php
            namespace App\Folio\Members;
            #[\Preflow\Folio\Extension\Attributes\FolioExtension(name: 'members', label: 'Members')]
            final class Extension extends \Preflow\Folio\Extension\Extension
            {
                public function settings(): array
                {
                    return ['registration_open' => ['type' => 'boolean', 'label' => 'Registration open', 'default' => true]];
                }
            }
            PHP);
        $member = self::MEMBER;
        unset($member['users'], $member['list']);
        $this->write('app/Folio/Members/models/member.json', (string) json_encode($member + ['list' => ['columns' => ['email']]]));
        if ($enabled) {
            $this->write('config/folio/extensions.json', '{"enabled": {"members": {"disabled_parts": []}}}');
        }
    }
}
