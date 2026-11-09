<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Models;

use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Model;

/** An ordinary model: its Entity names no type. */
#[Entity(table: 'note_plain', storage: 'sqlite')]
final class NotAType extends Model
{
    #[Id]
    public ?string $uuid = null;

    #[Field]
    public string $text = '';
}
