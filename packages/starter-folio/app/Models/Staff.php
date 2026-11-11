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
 *
 * The fields have the names of preflow/rbac's staff type (password_hash, active, auth_epoch,
 * last_login_at, created_at), so the table stays when roles move into Folio (README: "Roles
 * edited in Folio"). Until then `active` and `auth_epoch` are kept but not read.
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
    public ?string $name = null;

    #[Field]
    public string $password_hash = '';

    #[Field(transform: JsonTransformer::class)]
    public array $roles = [];

    #[Field]
    public bool $active = true;

    #[Field]
    public string $auth_epoch = '0';

    #[Field]
    public ?string $last_login_at = null;

    #[Field]
    public ?string $created_at = null;

    public function getPasswordHash(): string
    {
        return $this->password_hash;
    }
}
