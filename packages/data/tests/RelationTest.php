<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\OnDelete;
use Preflow\Data\TypeRegistry;

final class RelationTest extends TestCase
{
    private string $tmpDir;
    private TypeRegistry $registry;
    private DataManager $dm;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/preflow_relation_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        $this->registry = new TypeRegistry($this->tmpDir);
        $this->dm = new DataManager([], 'default', $this->registry);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmpDir . '/*.json'));
        rmdir($this->tmpDir);
    }

    private function writeSchema(string $type, array $fields): void
    {
        file_put_contents($this->tmpDir . "/{$type}.json", json_encode([
            'key' => $type,
            'table' => $type . 's',
            'fields' => $fields,
        ]));
    }

    public function test_relation_is_read_from_field_config(): void
    {
        $this->writeSchema('game', [
            'title' => ['type' => 'string'],
            'designer' => ['type' => 'relation', 'relation' => [
                'to' => 'designer', 'multiple' => true, 'onDelete' => 'cascade', 'labelField' => 'name',
            ]],
        ]);

        $relations = $this->dm->relationsOf('game');

        $this->assertCount(1, $relations);
        $this->assertSame('game', $relations[0]->type);
        $this->assertSame('designer', $relations[0]->field);
        $this->assertSame('designer', $relations[0]->target);
        $this->assertTrue($relations[0]->multiple);
        $this->assertSame(OnDelete::Cascade, $relations[0]->onDelete);
        $this->assertSame('name', $relations[0]->labelField);
    }

    public function test_defaults_to_detach_single_without_label(): void
    {
        $this->writeSchema('game', ['designer' => ['type' => 'relation', 'relation' => ['to' => 'designer']]]);

        $relation = $this->dm->relationsOf('game')[0];

        $this->assertSame(OnDelete::Detach, $relation->onDelete);
        $this->assertFalse($relation->multiple);
        $this->assertNull($relation->labelField);
    }

    public function test_relation_without_target_is_ignored(): void
    {
        $this->writeSchema('game', ['designer' => ['type' => 'relation']]);

        $this->assertSame([], $this->dm->relationsOf('game'));
    }

    public function test_unknown_on_delete_throws_naming_type_and_field(): void
    {
        $this->writeSchema('game', ['designer' => ['type' => 'relation', 'relation' => ['to' => 'designer', 'onDelete' => 'explode']]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('game.designer');
        $this->dm->relationsOf('game');
    }

    public function test_relations_to_scans_all_types(): void
    {
        $this->writeSchema('designer', ['name' => ['type' => 'string']]);
        $this->writeSchema('game', ['designer' => ['type' => 'relation', 'relation' => ['to' => 'designer']]]);
        $this->registry->define([
            'key' => 'book', 'table' => 'books',
            'fields' => ['authors' => ['type' => 'relation', 'relation' => ['to' => 'designer', 'multiple' => true]]],
        ]);
        $this->writeSchema('publisher', ['x' => ['type' => 'relation', 'relation' => ['to' => 'game']]]);

        $found = array_map(fn ($r) => $r->type . '.' . $r->field, $this->dm->relationsTo('designer'));

        $this->assertSame(['book.authors', 'game.designer'], $found);
    }

    public function test_no_registry_means_no_relations(): void
    {
        $dm = new DataManager([]);

        $this->assertSame([], $dm->relationsOf('game'));
        $this->assertSame([], $dm->relationsTo('game'));
    }
}
