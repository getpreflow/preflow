<?php

declare(strict_types=1);

namespace Preflow\Auth\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Auth\NativePasswordHasher;

class NativePasswordHasherTest extends TestCase
{
    public function test_hash_produces_verifiable_hash(): void
    {
        $hasher = new NativePasswordHasher();
        $hash = $hasher->hash('secret123');

        $this->assertTrue($hasher->verify('secret123', $hash));
        $this->assertFalse($hasher->verify('wrong-password', $hash));
    }

    public function test_needs_rehash_returns_false_for_current_hash(): void
    {
        $hasher = new NativePasswordHasher();
        $hash = $hasher->hash('secret123');

        $this->assertFalse($hasher->needsRehash($hash));
    }

    public function test_needs_rehash_returns_true_for_outdated_hash(): void
    {
        $hasher = new NativePasswordHasher(options: ['cost' => 12]);
        $cheapHash = password_hash('secret123', PASSWORD_BCRYPT, ['cost' => 4]);

        $this->assertTrue($hasher->needsRehash($cheapHash));
    }
}
