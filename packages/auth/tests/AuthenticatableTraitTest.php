<?php

declare(strict_types=1);

namespace Preflow\Auth\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Auth\Tests\Fixtures\TestUser;

class AuthenticatableTraitTest extends TestCase
{
    public function test_get_auth_id(): void
    {
        $user = new TestUser(uuid: 'abc-123');

        $this->assertSame('abc-123', $user->getAuthId());
    }

    public function test_get_password_hash(): void
    {
        $user = new TestUser(passwordHash: '$2y$10$hash');

        $this->assertSame('$2y$10$hash', $user->getPasswordHash());
    }

    public function test_roles(): void
    {
        $user = new TestUser(roles: ['admin', 'editor']);

        $this->assertSame(['admin', 'editor'], $user->getRoles());
        $this->assertTrue($user->hasRole('admin'));
        $this->assertFalse($user->hasRole('superadmin'));
    }

    public function test_empty_roles(): void
    {
        $user = new TestUser();

        $this->assertSame([], $user->getRoles());
        $this->assertFalse($user->hasRole('anything'));
    }
}
