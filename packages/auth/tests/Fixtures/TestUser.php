<?php

declare(strict_types=1);

namespace Preflow\Auth\Tests\Fixtures;

use Preflow\Auth\Authenticatable;
use Preflow\Auth\AuthenticatableTrait;

final class TestUser implements Authenticatable
{
    use AuthenticatableTrait;

    public function __construct(
        public string $uuid = 'user-1',
        public string $email = 'test@example.com',
        public string $passwordHash = '',
        public array $roles = [],
    ) {}
}
