<?php

declare(strict_types=1);

namespace Preflow\Publishing\Tests\Fixtures;

use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Attributes\Timestamps;
use Preflow\Data\Model;

/** A class-backed type with #[Timestamps]: a copy does not take over its created and updated values. */
#[Entity(table: 'stamped', storage: 'json', type: 'stamped')]
#[Timestamps]
final class Stamped extends Model
{
    #[Id]
    public ?string $uuid = null;

    #[Field]
    public string $title = '';

    #[Field]
    public ?string $createdAt = null;

    #[Field]
    public ?string $updatedAt = null;
}
