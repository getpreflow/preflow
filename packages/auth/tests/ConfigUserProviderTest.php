<?php

declare(strict_types=1);

namespace Preflow\Auth\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Auth\ArrayUser;
use Preflow\Auth\ConfigUserProvider;

final class ConfigUserProviderTest extends TestCase
{
    private function provider(): ConfigUserProvider
    {
        return new ConfigUserProvider([
            ['email' => 'admin@site.test', 'password_hash' => 'HASH1', 'roles' => ['admin']],
            ['email' => 'ed@site.test', 'password_hash' => 'HASH2'],
        ]);
    }

    public function test_find_by_credentials_matches_email(): void
    {
        $u = $this->provider()->findByCredentials(['email' => 'admin@site.test', 'password' => 'x']);
        $this->assertInstanceOf(ArrayUser::class, $u);
        $this->assertSame('admin@site.test', $u->getAuthId());
        $this->assertSame('HASH1', $u->getPasswordHash());
        $this->assertSame(['admin'], $u->getRoles());
    }

    public function test_find_by_id_matches_email_and_defaults_roles(): void
    {
        $u = $this->provider()->findById('ed@site.test');
        $this->assertInstanceOf(ArrayUser::class, $u);
        $this->assertSame([], $u->getRoles());
    }

    public function test_unknown_returns_null(): void
    {
        $this->assertNull($this->provider()->findByCredentials(['email' => 'nobody@site.test']));
        $this->assertNull($this->provider()->findByCredentials([]));
        $this->assertNull($this->provider()->findById('nobody@site.test'));
    }
}
