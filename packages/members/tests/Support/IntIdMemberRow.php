<?php

declare(strict_types=1);

namespace Preflow\Members\Tests\Support;

use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Model;

/** A member type whose model class numbers its members: no role view can have such a member. */
#[Entity(table: 'member', storage: 'default', type: 'member')]
final class IntIdMemberRow extends Model
{
    #[Id]
    public int $id = 0;

    #[Field]
    public ?string $email = null;

    #[Field]
    public ?string $roles = null;
}
