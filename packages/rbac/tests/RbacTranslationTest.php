<?php

declare(strict_types=1);

namespace Preflow\Rbac\Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\I18n\Translator;

/**
 * rbac's admin texts (group "rbac", scope admin; German in "du") and the permissions on Folio's
 * roles screen: the matrix's verbs, the content types, Folio's own permissions and their
 * groups in the admin's language.
 */
final class RbacTranslationTest extends TestCase
{
    use RbacAppScaffold;

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            $this->removeScaffold();
        }
    }

    public function test_the_group_has_the_same_keys_in_en_de_and_fr_and_german_says_du(): void
    {
        $translator = new Translator('/no/such/lang', 'en', 'en');
        $translator->addDefaults('rbac', dirname(__DIR__) . '/lang');
        $keys = array_keys($translator->entries('rbac', 'en'));
        foreach (['de', 'fr'] as $locale) {
            $this->assertEqualsCanonicalizing($keys, array_keys($translator->entries('rbac', $locale)), $locale);
        }
        foreach ($translator->entries('rbac', 'de') as $key => $text) {
            $this->assertDoesNotMatchRegularExpression('/\b(Sie|Ihr|Ihre|Ihren|Ihnen)\b/u', $text, $key);
        }
        foreach (['matrix.everything', 'matrix.other', 'matrix.denied', 'roles.staff.admin', 'account.title', 'actions.invite', 'extension.label'] as $key) {
            $this->assertContains('rbac.' . $key, $keys, $key);
        }
    }

    #[RunInSeparateProcess]
    public function test_the_roles_screen_shows_the_permissions_in_german(): void
    {
        $this->scaffold();
        $this->write('config/folio/extensions.json', '{"enabled": {"rbac": {"disabled_parts": [], "enabled_parts": ["model:staff"]}}}');
        $this->write('config/folio.php', "<?php\nreturn ['path' => '/folio', 'locale' => 'de'];\n");
        $this->install();
        $this->as('admin');

        $form = html_entity_decode((string) $this->send('GET', '/folio/rbac_role/new')->getBody(), ENT_QUOTES);
        foreach (['>Typ</th>', '>Ansehen</th>', '>Erstellen</th>', '>Bearbeiten</th>', '>Veröffentlichen</th>', '>Löschen</th>', '>Alle</th>',
            '>Alles<', 'Weitere Berechtigungen', '>Rollen</th>', '>Team</th>',
            '<span>Übersicht</span>', '<span>Erweiterungen</span>', '<span>Übersetzungen</span>', '<span>Hochladen</span>', 'rbac-group-label">Medien<'] as $text) {
            $this->assertStringContainsString($text, $form);
        }
        foreach (['>Type</th>', '>View</th>', '>Everything<', 'Other permissions', '<span>Dashboard</span>', '<span>Extensions</span>', '<span>Upload</span>', 'rbac-group-label">Assets<', 'Grants every permission'] as $english) {
            $this->assertStringNotContainsString($english, $form);
        }
    }

    #[RunInSeparateProcess]
    public function test_the_staff_account_panel_and_the_seeded_roles_speak_french(): void
    {
        $this->scaffold();
        $this->write('config/folio/extensions.json', '{"enabled": {"rbac": {"disabled_parts": [], "enabled_parts": ["model:staff"]}}}');
        $this->write('config/folio.php', "<?php\nreturn ['path' => '/folio', 'locale' => 'fr'];\n");
        $this->install();
        $dm = $this->container()->get(\Preflow\Data\DataManager::class);
        $dm->saveType(\Preflow\Data\DynamicRecord::fromArray($dm->getTypeRegistry()->get('staff'), ['uuid' => 's-1', 'email' => 'ed@site.test', 'name' => 'Ed', 'roles' => [], 'active' => true]), false);
        $this->as('admin');

        $edit = html_entity_decode((string) $this->send('GET', '/folio/staff/s-1/edit')->getBody(), ENT_QUOTES);
        foreach (['Compte', 'Pas encore de mot de passe', 'Envoyer l’invitation', 'Désactiver', '> Administrateur</label>'] as $text) {
            $this->assertStringContainsString($text, $edit);
        }
        foreach (['No password yet', 'Send invitation', '>Disable<', '> Administrator</label>'] as $english) {
            $this->assertStringNotContainsString($english, $edit);
        }

        $dm->deleteType('staff', 's-1');
        $this->assertStringContainsString('Aucun membre de l’équipe pour l’instant.', html_entity_decode((string) $this->send('GET', '/folio/staff')->getBody(), ENT_QUOTES));
    }
}
