<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Tests\Support\PublishingScaffold;

/**
 * An SQL-stored extension type ("member" of a "members" extension written by the test) that a
 * site extends with a field: the migrate step adds the column, and the admin form shows the field.
 */
final class ExtendedTypeAppTest extends TestCase
{
    use PublishingScaffold;

    private \Closure $autoload;

    protected function setUp(): void
    {
        $this->scaffoldPublishing('sqlite');
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
    public function test_site_field_on_extension_type_gets_a_column_and_a_form_input(): void
    {
        $this->write('app/Folio/Members/Extension.php', <<<'PHP'
            <?php
            namespace App\Folio\Members;
            #[\Preflow\Folio\Extension\Attributes\FolioExtension(name: 'members', label: 'Members')]
            final class Extension extends \Preflow\Folio\Extension\Extension {}
            PHP);
        $this->write('app/Folio/Members/models/member.json', (string) json_encode([
            'key' => 'member', 'label' => 'Members', 'table' => 'member', 'storage' => 'sqlite', 'id_field' => 'uuid',
            'fields' => ['email' => ['type' => 'string', 'validate' => ['required']]],
        ]));
        $this->write('config/folio/extensions.json', '{"enabled": {"members": {"disabled_parts": []}}}');
        $this->assertContains('created table member', $this->migrateStep());

        $this->write('config/models/member.json', (string) json_encode([
            'key' => 'member',
            'extends' => true,
            'fields' => ['company' => ['type' => 'string', 'label' => 'Company']],
        ]));
        $this->assertContains('added column member.company', $this->migrateStep());
        $this->assertSame([], $this->migrateStep());

        $dm = $this->dm();
        $dm->saveType(DynamicRecord::fromArray($dm->getTypeRegistry()->get('member'), ['uuid' => 'm1', 'email' => 'ada@example.org', 'company' => 'Holz AG']), false);
        $this->assertSame('Holz AG', $this->dm()->findType('member', 'm1')?->get('company'));

        $edit = $this->get('/folio/member/m1/edit');
        $this->assertSame(200, $edit->getStatusCode());
        $page = (string) $edit->getBody();
        $this->assertMatchesRegularExpression('#name="company"[^>]*value="Holz AG"|value="Holz AG"[^>]*name="company"#', $page);
        $this->assertStringContainsString('Company', $page);
    }

    /** @return list<string> */
    private function migrateStep(): array
    {
        return ($this->app()->container()->get(\Preflow\Data\Migration\MigrationRegistry::class)->steps()['folio.tables'])();
    }
}
