<?php

declare(strict_types=1);

namespace Preflow\Auth;

use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Model;

#[Entity(table: 'user_tokens', storage: 'default')]
final class PersonalAccessToken extends Model
{
    #[Id] public string $uuid = '';
    #[Field] public string $tokenHash = '';
    #[Field] public string $userId = '';
    #[Field] public string $name = '';
    #[Field] public ?string $createdAt = null;

    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    public static function generatePlainToken(): string
    {
        return bin2hex(random_bytes(32));
    }
}
