<?php

declare(strict_types=1);

namespace Preflow\Members\Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Data\ModelLabelKeys;
use Preflow\Folio\Extension\Attributes\FolioExtension;
use Preflow\I18n\TranslationRegistry;
use Preflow\I18n\Translator;
use Preflow\Members\Tests\Support\Dom;
use Preflow\Members\Tests\Support\MembersScaffold;

/**
 * The member model's labels (type, sidebar section, fields) are keys of the admin group
 * "members-admin" (lang/{en,de,fr}/members-admin.php): Folio shows them in the admin's
 * language (du-form German), apart from the public "members" group (Sie-form).
 */
final class ModelLabelsTest extends TestCase
{
    use MembersScaffold;

    protected function tearDown(): void
    {
        if (($this->dir ?? '') !== '') {
            $this->removeScaffold();
        }
    }

    public function test_the_package_declares_the_admin_group_and_the_extension_uses_it(): void
    {
        $root = dirname(__DIR__);
        $composer = json_decode((string) file_get_contents($root . '/composer.json'), true);
        $installed = sys_get_temp_dir() . '/preflow_installed_' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($installed, (string) json_encode(['packages' => [[...$composer, 'install-path' => $root]]]));
        try {
            $registry = TranslationRegistry::fromInstalled($installed);
        } finally {
            unlink($installed);
        }

        $this->assertSame('admin', $registry->scope('members-admin'));
        $this->assertSame('public', $registry->scope('members'));
        $meta = (new \ReflectionClass(\Preflow\Members\Extension::class))->getAttributes(FolioExtension::class)[0]->newInstance();
        $this->assertSame('members-admin', $meta->translations);
    }

    public function test_every_label_of_the_model_has_a_text_in_en_de_and_fr(): void
    {
        $translator = new Translator('/no/such/lang', 'en', 'en');
        $translator->addDefaults('members-admin', dirname(__DIR__) . '/lang');
        $schema = json_decode((string) file_get_contents(dirname(__DIR__) . '/src/models/member.json'), true);
        $keys = [...array_keys(ModelLabelKeys::derive($schema, 'members-admin')), ModelLabelKeys::section('members-admin', 'members')];

        $this->assertNotContains('members-admin.fields.member.password_hash.label', $keys, 'hidden');
        foreach (['en', 'de', 'fr'] as $locale) {
            foreach ($keys as $key) {
                $this->assertTrue($translator->has($key, $locale), "{$key} in {$locale}");
            }
            $this->assertEqualsCanonicalizing(array_keys($translator->entries('members-admin', 'en')), array_keys($translator->entries('members-admin', $locale)), $locale);
        }
    }

    #[RunInSeparateProcess]
    public function test_the_member_list_speaks_the_admins_language(): void
    {
        $this->scaffold();
        $this->write('config/folio.php', "<?php\nreturn ['path' => '/folio', 'locale' => 'de'];\n");
        $this->migrateAndApply();
        $this->saveRecord('member', ['uuid' => 'm-1', 'email' => 'reader@site.test', 'name' => 'Reader', 'registered_at' => '2026-10-01 10:00', 'roles' => ['member']]);
        $this->signIn();

        $list = $this->admin('/folio/member');
        $headings = array_map(static fn (\DOMElement $th): string => trim($th->textContent), Dom::all($list, '//table//thead//th[not(contains(@class, "folio-bulk-cell"))]'));
        $this->assertSame(['E-Mail', 'Name', 'Registriert am'], array_slice($headings, 0, 3));
        // The section heading and the type's entry; "Members settings" (the extension's settings type) is not the model's.
        $this->assertSame('Mitglieder', Dom::text($list, '//nav[contains(@class, "folio-nav")]//a[@href="/folio/member"]'));
        $this->assertContains('Mitglieder', array_map(static fn (\DOMElement $e): string => trim($e->textContent), Dom::all($list, '//nav//*[contains(@class, "folio-nav-label")]')));
    }
}
