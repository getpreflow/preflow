<?php

declare(strict_types=1);

namespace Preflow\Components\Tests\State;

use Preflow\Components\SignedStateStore;
use Preflow\Components\StateRefused;
use Preflow\Components\StateStore;
use Preflow\Testing\Contract\StateStoreContract;

final class SignedStateStoreTest extends StateStoreContract
{
    private const KEY = 'test-secret-key-32-chars-long!!';

    protected function store(): StateStore
    {
        return new SignedStateStore(self::KEY);
    }

    private function at(int $now, string $key = self::KEY): SignedStateStore
    {
        return new class($key, $now) extends SignedStateStore {
            public function __construct(string $key, private int $now)
            {
                parent::__construct($key);
            }

            protected function currentTime(): int
            {
                return $this->now++;
            }
        };
    }

    public function test_equal_state_gives_equal_strings_within_a_request(): void
    {
        $store = $this->at(1_800_000_000);

        $this->assertSame(
            $store->put('App\\X', 'x', ['a' => 1]),
            $store->put('App\\X', 'x', ['a' => 1]),
        );
    }

    public function test_expired_state_is_refused(): void
    {
        $carried = $this->at(1_800_000_000)->put('App\\X', 'x', ['a' => 1]);

        $this->assertSame(['a' => 1], $this->at(1_800_000_000 + 86400)->get('App\\X', 'x', $carried));

        $this->expectException(StateRefused::class);
        $this->at(1_800_000_000 + 86401)->get('App\\X', 'x', $carried);
    }

    public function test_state_signed_with_another_key_is_refused(): void
    {
        $carried = $this->at(1_800_000_000, 'another-secret-key-32-chars-long')->put('App\\X', 'x', []);

        $this->expectException(StateRefused::class);
        $this->at(1_800_000_000)->get('App\\X', 'x', $carried);
    }

    public function test_garbage_is_refused(): void
    {
        $this->expectException(StateRefused::class);
        $this->at(1_800_000_000)->get('App\\X', 'x', '!!not base64!!');
    }
}
