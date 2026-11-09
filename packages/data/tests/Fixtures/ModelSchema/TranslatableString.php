<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Fixtures\ModelSchema;

use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Model;

#[Entity(table: 'things', type: 'thing')]
final class TranslatableString extends Model
{
    #[Field(translatable: true)]
    public ?string $title = null;
}
