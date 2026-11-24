<?php

declare(strict_types=1);

namespace Preflow\Testing\Contract;

use PHPUnit\Framework\TestCase;
use Preflow\Components\StateRefused;
use Preflow\Components\StateStore;

/**
 * What every StateStore does, as tests. Extend it, implement store(), run it with PHPUnit.
 * store() gives an empty store. current() builds it once per test and returns that same instance
 * for every put() and get() of the test, so a store that keeps state in the object works too.
 *
 * @since 0.86.0
 */
abstract class StateStoreContract extends TestCase
{
    private ?StateStore $store = null;

    abstract protected function store(): StateStore;

    final protected function current(): StateStore
    {
        return $this->store ??= $this->store();
    }

    public function test_round_trip(): void
    {
        $state = [
            'query' => 'pizza',
            'page' => 3,
            'open' => true,
            'closed' => false,
            'nothing' => null,
            'tags' => ['a', 'b', ['deep' => [1, 2, ['x' => null]]]],
            'map' => ['k' => 'v'],
        ];

        $carried = $this->current()->put('App\\Search', 'search-1', $state);

        $this->assertSame($state, $this->current()->get('App\\Search', 'search-1', $carried));
    }

    public function test_state_of_another_class_or_id_is_refused(): void
    {
        $carried = $this->current()->put('App\\Search', 'search-1', ['q' => 'x']);

        try {
            $this->current()->get('App\\Other', 'search-1', $carried);
            $this->fail('Another class must be refused.');
        } catch (StateRefused) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(StateRefused::class);
        $this->current()->get('App\\Search', 'search-2', $carried);
    }

    public function test_tampered_state_is_refused(): void
    {
        $carried = $this->current()->put('App\\Search', 'search-1', ['q' => 'x']);
        $tampered = $this->tamper($carried);

        $this->expectException(StateRefused::class);
        $this->current()->get('App\\Search', 'search-1', $tampered);
    }

    /** Changes a value inside the payload when the string is an encoded payload; else changes its first character. */
    private function tamper(string $carried): string
    {
        try {
            $decoded = sodium_base642bin($carried, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        } catch (\SodiumException) {
            $decoded = '';
        }

        if (str_contains($decoded, '"x"')) {
            return sodium_bin2base64(
                preg_replace('/"x"/', '"y"', $decoded, 1) ?? $decoded,
                SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING,
            );
        }

        return ($carried[0] === 'A' ? 'B' : 'A') . substr($carried, 1);
    }
}
