<?php

declare(strict_types=1);

namespace Preflow\Publishing\Tests;

use PHPUnit\Framework\TestCase;
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
}
