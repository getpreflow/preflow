<?php

declare(strict_types=1);

namespace Preflow\Rbac\Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Data\ModelLabelKeys;
use Preflow\Folio\Extension\Attributes\FolioExtension;
use Preflow\I18n\Translator;

/**
 * The labels of rbac's models (staff accounts, roles) are keys of its admin group "rbac": the
 * users screen and the roles screen show them in the admin's language.
 */
final class ModelLabelsTest extends TestCase
{
    use RbacAppScaffold;

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            $this->removeScaffold();
        }
    }

    public function test_the_extension_names_its_group_and_every_label_has_a_text_in_en_de_and_fr(): void
    {
        $meta = (new \ReflectionClass(\Preflow\Rbac\Extension::class))->getAttributes(FolioExtension::class)[0]->newInstance();
        $this->assertSame('rbac', $meta->translations);

        $translator = new Translator('/no/such/lang', 'en', 'en');
        $translator->addDefaults('rbac', dirname(__DIR__) . '/lang');
        foreach (['staff', 'rbac_role'] as $model) {
            $keys = ModelLabelKeys::derive(json_decode((string) file_get_contents(dirname(__DIR__) . "/src/models/{$model}.json"), true), 'rbac');
            foreach (['en', 'de', 'fr'] as $locale) {
                foreach (array_keys($keys) as $key) {
                    $this->assertTrue($translator->has($key, $locale), "{$key} in {$locale}");
                }
            }
        }
        $this->assertArrayNotHasKey('rbac.fields.staff.password_hash.label', ModelLabelKeys::derive(json_decode((string) file_get_contents(dirname(__DIR__) . '/src/models/staff.json'), true), 'rbac'));
    }

    #[RunInSeparateProcess]
    public function test_the_users_and_roles_screens_show_the_labels_in_the_admins_language(): void
    {
        $this->scaffold();
        $this->write('config/folio/extensions.json', '{"enabled": {"rbac": {"disabled_parts": [], "enabled_parts": ["model:staff"]}}}');
        $this->write('config/folio.php', "<?php\nreturn ['path' => '/folio', 'locale' => 'de'];\n");
        $this->install();
        $dm = $this->container()->get(\Preflow\Data\DataManager::class);
        $dm->saveType(\Preflow\Data\DynamicRecord::fromArray($dm->getTypeRegistry()->get('staff'), ['uuid' => 's-1', 'email' => 'ed@site.test', 'name' => 'Ed', 'roles' => [], 'active' => true]), false);
        $this->as('admin');

        $users = (string) $this->send('GET', '/folio/staff')->getBody();
        $headings = self::headings($users);
        foreach (['E-Mail', 'Name', 'Rollen', 'Aktiv', 'Letzte Anmeldung'] as $heading) {
            $this->assertContains($heading, $headings, $heading);
        }
        $this->assertStringContainsString('<span>Team</span>', $users);
        $this->assertDoesNotMatchRegularExpression('~>\s*(Email|Last sign-in|Staff)\s*<~', $users);

        $form = (string) $this->send('GET', '/folio/staff/new')->getBody();
        $this->assertStringContainsString('Ein inaktives Konto kann sich nicht anmelden.', $form);

        $roleForm = (string) $this->send('GET', '/folio/rbac_role/new')->getBody();
        foreach (['Bezeichnung', 'Personenkreis', 'Berechtigungen', '>Team</option>', '>Mitglieder</option>'] as $text) {
            $this->assertStringContainsString($text, $roleForm);
        }
    }

    /** @return list<string> the list's column headings */
    private static function headings(string $html): array
    {
        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        $out = [];
        foreach ((new \DOMXPath($doc))->query('//table//thead//th') ?: [] as $th) {
            $out[] = trim(preg_replace('/\s+/u', ' ', str_replace(['▲', '▼'], '', $th->textContent)) ?? '');
        }

        return $out;
    }
}
