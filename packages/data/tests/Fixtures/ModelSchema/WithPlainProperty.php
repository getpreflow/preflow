<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Fixtures\ModelSchema;

use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Model;

#[Entity(table: 'things', type: 'thing')]
final class WithPlainProperty extends Model
{
    #[Field]
    public string $name = '';

    public string $scratch = '';
}
