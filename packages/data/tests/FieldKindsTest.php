<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\FieldKinds;

final class FieldKindsTest extends TestCase
{
    public function test_defaults(): void
    {
        $kinds = new FieldKinds();

        $this->assertTrue($kinds->isText('string'));
        $this->assertTrue($kinds->isText('text'));
        $this->assertFalse($kinds->isText('integer'));
        $this->assertTrue($kinds->isRelation('relation'));
        $this->assertFalse($kinds->isRelation('string'));
    }

    public function test_registration(): void
    {
        $kinds = new FieldKinds();
        $kinds->text('markdown', 'slug');
        $kinds->relation('pick');

        $this->assertTrue($kinds->isText('markdown'));
        $this->assertTrue($kinds->isText('slug'));
        $this->assertTrue($kinds->isRelation('pick'));
        $this->assertTrue($kinds->isText('string'));
    }

    public function test_data_manager_shares_one_instance(): void
    {
        $dm = new DataManager([]);

        $this->assertSame($dm->fieldKinds(), $dm->fieldKinds());
    }

    public function test_a_unique_relation_is_short_unless_its_relation_is_multiple(): void
    {
        $kinds = new FieldKinds();
        $field = static fn (array $relation): \Preflow\Data\TypeFieldDefinition => new \Preflow\Data\TypeFieldDefinition('tags', 'relation', config: ['relation' => ['to' => 'tag'] + $relation], unique: true);

        $this->assertSame(\Preflow\Data\Schema\ColumnKind::Short, $kinds->columnKindOf($field([])));
        $this->assertSame(\Preflow\Data\Schema\ColumnKind::Text, $kinds->columnKindOf($field(['multiple' => true])), 'relation.multiple, as the relation field reads it');
    }
}
