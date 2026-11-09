<?php

declare(strict_types=1);

namespace App\Models;

use Preflow\Auth\Authenticatable;
use Preflow\Auth\AuthenticatableTrait;
use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Model;
use Preflow\Data\Transform\JsonTransformer;

/**
 * A staff account: signs in to Folio (guard "staff"). Its roles (admin, editor) are defined in
 * config/roles.php. A table of its own, not users, so an employer can never become staff by a
 * role and a staff account never posts as a company.
 */
#[Entity(table: 'staff', storage: 'default')]
final class Staff extends Model implements Authenticatable
{
    use AuthenticatableTrait;

    #[Id]
    public string $uuid = '';

    #[Field(searchable: true, unique: true)]
    public string $email = '';

    #[Field]
    public string $passwordHash = '';

    #[Field(transform: JsonTransformer::class)]
    public array $roles = [];

    #[Field]
    public ?string $name = null;

    #[Field]
    public ?string $createdAt = null;
}
