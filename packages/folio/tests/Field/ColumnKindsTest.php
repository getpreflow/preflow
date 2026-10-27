<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Field;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Schema\ColumnKind;
use Preflow\Folio\Field\Types\BooleanFieldType;
use Preflow\Folio\Field\Types\DateFieldType;
use Preflow\Folio\Field\Types\EnvFieldType;
use Preflow\Folio\Field\Types\NumberFieldType;
use Preflow\Folio\Field\Types\RelationFieldType;
use Preflow\Folio\Field\Types\SelectFieldType;
use Preflow\Folio\Field\Types\StringFieldType;
use Preflow\Folio\Field\Types\TextFieldType;
use Preflow\Folio\Codes\Fields\PurposeFieldType;
use Preflow\Folio\Field\HasColumnKind;

final class ColumnKindsTest extends TestCase
{
    public function test_builtin_types_declare_their_kind(): void
    {
        $this->assertSame(ColumnKind::Number, (new NumberFieldType())->columnKind([]));
        $this->assertSame(ColumnKind::Boolean, (new BooleanFieldType())->columnKind([]));
        $this->assertSame(ColumnKind::Date, (new DateFieldType())->columnKind([]));
        $this->assertSame(ColumnKind::Short, (new StringFieldType())->columnKind([]));
        $this->assertSame(ColumnKind::Short, (new SelectFieldType())->columnKind([]));
        $this->assertSame(ColumnKind::Short, (new EnvFieldType())->columnKind([]));
        $this->assertSame(ColumnKind::Short, (new PurposeFieldType([]))->columnKind([]));
        $this->assertNotInstanceOf(HasColumnKind::class, new TextFieldType());
    }

    public function test_relation_is_short_unless_multiple(): void
    {
        $rel = (new \ReflectionClass(RelationFieldType::class))->newInstanceWithoutConstructor();
        $this->assertSame(ColumnKind::Short, $rel->columnKind([]));
        $this->assertSame(ColumnKind::Text, $rel->columnKind(['multiple' => true]));
    }
}
