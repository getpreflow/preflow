<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Fixtures\ModelBridge;

use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Model;

/** A class-backed type with a rich text field: cleaned on every write like a typed one. */
#[Entity(table: 'rich_note', type: 'rich_note')]
final class RichNote extends Model
{
    #[Id]
    public ?string $uuid = null;

    #[Field(type: 'richtext')]
    public ?string $body = null;
}
