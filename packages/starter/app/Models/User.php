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

#[Entity(table: 'users', storage: 'default')]
final class User extends Model implements Authenticatable
{
    use AuthenticatableTrait;

    #[Id]
    public string $uuid = '';

    #[Field(searchable: true)]
    public string $email = '';

    #[Field]
    public string $passwordHash = '';

    #[Field(transform: JsonTransformer::class)]
    public array $roles = [];

    #[Field]
    public ?string $createdAt = null;
}
