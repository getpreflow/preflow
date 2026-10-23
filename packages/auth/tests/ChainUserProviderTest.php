<?php

declare(strict_types=1);

namespace Preflow\Auth\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Auth\ChainUserProvider;
use Preflow\Auth\ConfigUserProvider;

final class ChainUserProviderTest extends TestCase
{
    private function chain(): ChainUserProvider
    {
        return new ChainUserProvider([
            new ConfigUserProvider([['email' => 'both@x.test', 'password_hash' => 'first', 'roles' => ['a']], ['email' => 'one@x.test', 'password_hash' => 'h']]),
            new ConfigUserProvider([['email' => 'both@x.test', 'password_hash' => 'second'], ['email' => 'two@x.test', 'password_hash' => 'h']]),
        ]);
    }

    public function test_the_first_provider_with_a_hit_wins(): void
    {
        $this->assertSame('first', $this->chain()->findById('both@x.test')?->getPasswordHash());
        $this->assertSame('first', $this->chain()->findByCredentials(['email' => 'both@x.test'])?->getPasswordHash());
    }

    public function test_an_id_known_to_one_provider_only_resolves_from_it(): void
    {
        $this->assertSame('two@x.test', $this->chain()->findById('two@x.test')?->getAuthId());
        $this->assertSame('one@x.test', $this->chain()->findByCredentials(['email' => 'one@x.test'])?->getAuthId());
        $this->assertSame('two@x.test', $this->chain()->findByCredentials(['email' => 'two@x.test'])?->getAuthId());
    }

    public function test_no_hit_and_an_empty_chain_resolve_nobody(): void
    {
        $this->assertNull($this->chain()->findById('nobody'));
        $this->assertNull($this->chain()->findByCredentials(['email' => 'nobody']));
        $this->assertNull((new ChainUserProvider([]))->findById('x'));
    }
}
