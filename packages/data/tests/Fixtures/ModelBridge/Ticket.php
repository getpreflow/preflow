<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Fixtures\ModelBridge;

use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Model;

/** A class-backed type with an auto-increment id, as an app's own SQL table has. */
#[Entity(table: 'ticket', type: 'ticket')]
final class Ticket extends Model
{
    #[Id]
    public ?int $id = null;

    #[Field]
    public ?string $title = null;
}
