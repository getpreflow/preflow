<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Models;

use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Model;

/** A class-backed type with a file field: no publishing, and listed only where an admin file exists. */
#[Entity(table: 'poster', storage: 'sqlite', type: 'poster')]
final class Poster extends Model
{
    #[Id]
    public ?string $uuid = null;

    #[Field]
    public string $name = '';

    #[Field(type: 'asset')]
    public ?string $image = null;
}
