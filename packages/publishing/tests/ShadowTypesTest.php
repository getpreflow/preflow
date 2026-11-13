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
}
