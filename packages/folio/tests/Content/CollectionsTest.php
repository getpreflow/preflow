<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Content;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Locales;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\Collections;
use Preflow\Folio\Content\ContentLocale;
use Preflow\Folio\Content\LiveContent;
use Preflow\Folio\Content\RecordValidator;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\Types\StringFieldType;
use Preflow\Folio\Publishing\Publisher;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidatorFactory;

final class CollectionsTest extends TestCase
{
    private string $dir;
    private DataManager $dm;
    private Publisher $publisher;
    private LiveContent $live;
    private ContentLocale $locale;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/coll_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0777, true);
        $this->model('app', ['publishing' => true, 'list' => ['orderable' => true], 'fields' => [
            'name' => ['type' => 'string', 'translatable' => true],
        ]]);
        $this->model('msg', ['list' => ['sort' => '-received_at'], 'fields' => [
            'name' => ['type' => 'string'],
            'received_at' => ['type' => 'string'],
        ]]);

        $registry = new TypeRegistry($this->dir . '/models', new Locales(['en', 'de'], 'en'));
        $this->dm = new DataManager(
            drivers: ['json' => new JsonFileDriver($this->dir . '/store')],
            defaultDriver: 'json',
            typeRegistry: $registry,
            validatorFactory: new ValidatorFactory(new RuleFactory()),
        );
        $fieldTypes = new FieldTypeRegistry();
        $fieldTypes->register(new StringFieldType());
        $catalog = new TypeCatalog($this->dir . '/models');
        $this->publisher = new Publisher($this->dm, $catalog, new RecordValidator($fieldTypes, $this->dm));
        $this->live = new LiveContent($this->dm, $catalog);
        $this->locale = new ContentLocale(new Locales(['en', 'de'], 'en'));
    }

    protected function tearDown(): void
    {
        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($rii as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }

    public function test_list_returns_published_snapshots_in_order(): void
    {
        $this->publishApp('a', 'Thirty', 30);
        $this->publishApp('b', 'Ten', 10);
        $this->publishApp('c', 'Twenty', 20);

        $this->assertSame(['Ten', 'Twenty', 'Thirty'], array_column($this->collections()->list('app'), 'name'));
        $this->assertSame('b', $this->collections()->list('app')[0]['uuid']);
    }

    public function test_unpublished_and_draft_edits_never_listed(): void
    {
        $this->publisher->saveDraft($this->app('draft', 'Draft only', 1), null);
        $this->publishApp('pub', 'Published', 2);
        $edited = $this->dm->findType('app', 'pub') ?? throw new \RuntimeException();
        $edited->set('name', ['en' => 'Edited']);
        $this->publisher->saveDraft($edited, null);

        $this->assertSame(['Published'], array_column($this->collections()->list('app'), 'name'));
        $this->assertNull($this->collections()->find('app', 'draft'));
        $this->assertSame('Published', $this->collections()->find('app', 'pub')['name'] ?? null);
    }

    public function test_working_copies_when_requested(): void
    {
        $this->publishApp('pub', 'Published', 1);
        $edited = $this->dm->findType('app', 'pub') ?? throw new \RuntimeException();
        $edited->set('name', ['en' => 'Edited']);
        $this->publisher->saveDraft($edited, null);

        $this->live->showWorkingCopies();

        $this->assertSame(['Edited'], array_column($this->collections()->list('app'), 'name'));
    }

    public function test_sort_descending(): void
    {
        foreach (['m1' => '2026-10-01', 'm2' => '2026-10-03', 'm3' => '2026-10-02'] as $id => $at) {
            $this->dm->saveType(DynamicRecord::fromArray($this->def('msg'), ['uuid' => $id, 'name' => $id, 'received_at' => $at]), false);
        }

        $this->assertSame(['m2', 'm3', 'm1'], array_column($this->collections()->list('msg'), 'name'));
    }

    public function test_values_are_localized(): void
    {
        $record = DynamicRecord::fromArray($this->def('app'), ['uuid' => 'x', '_order' => 1, 'name' => ['en' => 'A', 'de' => 'B'], '_locales' => ['en', 'de']]);
        $this->dm->saveType($record, false);
        $this->publisher->publish($this->dm->findType('app', 'x') ?? throw new \RuntimeException(), null);
        $this->locale->set('de');

        $this->assertSame('B', $this->collections()->list('app')[0]['name']);
        $this->assertSame('B', $this->collections()->find('app', 'x')['name'] ?? null);
    }

    public function test_find_returns_null_for_unpublished(): void
    {
        $this->publisher->saveDraft($this->app('d', 'Draft', 1), null);

        $this->assertNull($this->collections()->find('app', 'd'));
        $this->assertNull($this->collections()->find('app', 'missing'));
    }

    public function test_unknown_type_lists_nothing(): void
    {
        $this->assertSame([], $this->collections()->list('nope'));
        $this->assertNull($this->collections()->find('nope', 'x'));
    }

    private function collections(): Collections
    {
        return new Collections($this->live, new TypeCatalog($this->dir . '/models'), $this->locale);
    }

    private function publishApp(string $id, string $name, int $order): void
    {
        $this->publisher->publish($this->app($id, $name, $order), null);
    }

    private function app(string $id, string $name, int $order): DynamicRecord
    {
        return DynamicRecord::fromArray($this->def('app'), ['uuid' => $id, '_order' => $order, 'name' => ['en' => $name]]);
    }

    private function def(string $type): \Preflow\Data\TypeDefinition
    {
        return ($this->dm->getTypeRegistry() ?? throw new \RuntimeException())->get($type);
    }

    /** @param array<string, mixed> $config */
    private function model(string $key, array $config): void
    {
        file_put_contents($this->dir . "/models/{$key}.json", json_encode(
            ['key' => $key, 'table' => $key, 'storage' => 'json', 'id_field' => 'uuid'] + $config,
        ));
    }
}
