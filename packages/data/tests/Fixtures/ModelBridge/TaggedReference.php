<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Fixtures\ModelBridge;

use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Model;

/** A class-backed type with a Folio tags field: a string list on a `?array` property (JSON text on SQL). */
#[Entity(table: 'reference', type: 'reference')]
final class TaggedReference extends Model
{
    #[Id]
    public ?string $uuid = null;

    #[Field]
    public ?string $name = null;

    #[Field(type: 'tags')]
    public ?array $uses = null;
}
