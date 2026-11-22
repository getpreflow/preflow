<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Fixtures\ModelBridge;

use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Model;

/** A class-backed type without an updated field: its change date is the system key _updated_at. */
#[Entity(table: 'plain_note', type: 'plain_note')]
final class PlainNote extends Model
{
    #[Id]
    public ?string $uuid = null;

    #[Field]
    public ?string $title = null;
}
