<?php

declare(strict_types=1);

namespace Preflow\Auth\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Auth\ArrayUser;

final class ArrayUserTest extends TestCase
{
    public function test_exposes_id_email_hash_roles(): void
    {
        $u = new ArrayUser('a@b.test', 'a@b.test', 'HASH', ['admin', 'editor']);
        $this->assertSame('a@b.test', $u->getAuthId());
        $this->assertSame('a@b.test', $u->getEmail());
        $this->assertSame('HASH', $u->getPasswordHash());
        $this->assertSame(['admin', 'editor'], $u->getRoles());
        $this->assertTrue($u->hasRole('admin'));
        $this->assertFalse($u->hasRole('root'));
    }
}
