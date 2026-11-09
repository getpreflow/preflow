<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Fixtures\ModelSchema;

use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Model;

#[Entity(table: 'things', type: 'thing')]
final class UntypedArray extends Model
{
    #[Field]
    public array $body = [];
}
