<?php

declare(strict_types=1);

namespace Preflow\Members\Tests\Support;

use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Model;

/** A member type whose model class gives its members string ids. */
#[Entity(table: 'member', storage: 'default', type: 'member')]
final class StringIdMemberRow extends Model
{
    #[Id]
    public string $uuid = '';

    #[Field]
    public ?string $email = null;

    #[Field]
    public ?string $roles = null;
}
