<?php

declare(strict_types=1);

namespace Preflow\Publishing\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\IdKind;
use Preflow\Data\Schema\RecordTableSync;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Publishing\ShadowTypes;

/** The published snapshots carry the record's indexes, plain; a translatable unique field gets none, as on the working table. */
final class ShadowTypesTest extends TestCase
{
    public function test_published_indexes(): void
    {
        $def = new TypeDefinition('page', 'page', 'sqlite', [
            'slug' => new TypeFieldDefinition('slug', unique: ['section']),
            'section' => new TypeFieldDefinition('section'),
            'title' => new TypeFieldDefinition('title', translatable: true, unique: true),
            'state' => new TypeFieldDefinition('state'),
            'rank' => new TypeFieldDefinition('rank', 'number'),
        ], indexes: [['state', 'rank']]);

        $published = ShadowTypes::published($def);

        $this->assertSame('page__published', $published->table);
        $this->assertSame([], $published->uniqueFields());
        $this->assertSame(['slug', 'section'], $published->indexedFields());
        $this->assertSame([['state', 'rank']], $published->indexes);
        $this->assertSame([], ShadowTypes::revisions($def)->indexes);
    }

    /** A one-field composite over a scope field is that field's index in the snapshots, not a second one of the same name. */
    public function test_a_composite_over_a_scope_field_is_its_index_in_the_snapshots(): void
    {
        $def = new TypeDefinition('page', 'page', 'sqlite', [
            'slug' => new TypeFieldDefinition('slug', unique: ['section']),
            'section' => new TypeFieldDefinition('section'),
        ], indexes: [['section']]);

        $published = ShadowTypes::published($def);

        $this->assertSame(['slug'], $published->indexedFields());
        $this->assertSame([['section']], $published->indexes);
    }

    /** What would clash in the snapshots is refused when the type is defined, never when it publishes. */
    public function test_a_type_whose_snapshots_would_clash_is_refused_at_definition(): void
    {
        $fields = [
            'slug' => new TypeFieldDefinition('slug', unique: true),
            'a' => new TypeFieldDefinition('a'),
            'b' => new TypeFieldDefinition('b'),
            'a_b' => new TypeFieldDefinition('a_b', unique: true),
        ];
        foreach ([[['slug']], [['a', 'b']]] as $indexes) {
            try {
                ShadowTypes::published(new TypeDefinition('page', 'page', 'sqlite', $fields, indexes: $indexes));
                $this->fail('accepted ' . json_encode($indexes));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringStartsWith('Type "page" has the index (', $e->getMessage());
            }
        }
    }

    /**
     * A version-column view keeps the id kind a model class states; the published copy states none:
     * its table is the sync's (a text key) and its records always get the working record's id, so
     * the class's kind would make up a conflict with that key (DataManager::idKind()).
     */
    public function test_copies_and_the_id_kind(): void
    {
        foreach ([IdKind::Int, IdKind::String, null] as $kind) {
            $def = new TypeDefinition('page', 'page', 'sqlite', ['title' => new TypeFieldDefinition('title')], idField: 'id', idKind: $kind);

            $this->assertNull(ShadowTypes::published($def)->idKind, 'published, ' . ($kind->value ?? 'none'));
            $column = ShadowTypes::versionColumn($def, '_version');
            $this->assertSame($kind, $column->idKind, 'version column, ' . ($kind->value ?? 'none'));
            $this->assertSame(['page', 'page', 'sqlite', 'id', ['_version']], [$column->key, $column->table, $column->storage, $column->idField, array_keys($column->fields)]);
            $this->assertTrue($column->fields['_version']->exact);
        }
    }

    /** An int-class type's published copy on SQLite: its synced text key gives a string id, no conflict. */
    public function test_an_int_class_types_published_copy_has_no_id_kind_conflict(): void
    {
        $def = new TypeDefinition('page', 'page', 'sqlite', ['title' => new TypeFieldDefinition('title')], idField: 'id', idKind: IdKind::Int);
        $dm = new DataManager(['sqlite' => new SqliteDriver(new \PDO('sqlite::memory:'))]);
        $published = ShadowTypes::published($def);
        (new RecordTableSync($dm))->sync($published);

        $this->assertSame(IdKind::String, $dm->idKind($published));
    }
}
