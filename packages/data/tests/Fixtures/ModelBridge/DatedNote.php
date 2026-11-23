<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Fixtures\ModelBridge;

use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Model;

/** A class-backed type with an updated field of its own: content, which the change date (ChangeDates) leaves alone. */
#[Entity(table: 'dated_note', type: 'dated_note')]
final class DatedNote extends Model
{
    #[Id]
    public ?string $uuid = null;

    #[Field]
    public ?string $title = null;

    #[Field(type: 'date')]
    public ?string $updatedAt = null;
}
