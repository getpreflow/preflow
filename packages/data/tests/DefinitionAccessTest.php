<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeRegistry;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidatorFactory;

/** Records of a code-built TypeDefinition (e.g. a shadow table) without the registry. */
final class DefinitionAccessTest extends TestCase
{
    private string $dir;
    private DataManager $dm;
    private TypeRegistry $registry;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/da_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0777, true);
        file_put_contents($this->dir . '/models/page.json', json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => 'json', 'id_field' => 'uuid',
            'fields' => ['title' => ['type' => 'string', 'validate' => ['required']]],
        ]));
        $this->registry = new TypeRegistry($this->dir . '/models');
        $this->dm = new DataManager(
            drivers: ['json' => new JsonFileDriver($this->dir . '/store')],
            defaultDriver: 'json',
            typeRegistry: $this->registry,
            validatorFactory: new ValidatorFactory(new RuleFactory()),
        );
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

    public function test_shadow_definition_round_trip(): void
    {
        $page = $this->registry->get('page');
        $shadow = new TypeDefinition(key: 'page__published', table: 'page__published', storage: $page->storage, fields: $page->fields, idField: $page->idField);

        $this->dm->saveType(DynamicRecord::fromArray($shadow, ['uuid' => 'p1', 'title' => 'Live']));

        $this->assertSame('Live', $this->dm->findIn($shadow, 'p1')?->get('title'));
        $this->assertSame(1, $this->dm->queryIn($shadow)->get()->total());
        $this->assertNull($this->dm->findType('page', 'p1'));

        $this->dm->deleteIn($shadow, 'p1');
        $this->assertNull($this->dm->findIn($shadow, 'p1'));
    }

    public function test_validate_throws_without_saving(): void
    {
        $record = DynamicRecord::fromArray($this->registry->get('page'), ['uuid' => 'p1', 'title' => '']);

        try {
            $this->dm->validate($record);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('title', $e->errors());
        }
        $this->assertNull($this->dm->findType('page', 'p1'));
    }
}
