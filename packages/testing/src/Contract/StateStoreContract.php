<?php

declare(strict_types=1);

namespace Preflow\Testing\Contract;

use PHPUnit\Framework\TestCase;
use Preflow\Components\StateRefused;
use Preflow\Components\StateStore;

/**
 * What every StateStore does, as tests. Extend it, implement store(), run it with PHPUnit.
 * store() is called once per test and must give an empty store; a test that needs a second
 * request calls it again through the same instance only if the store keeps nothing in the object.
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
        $tampered = ($carried[0] === 'A' ? 'B' : 'A') . substr($carried, 1);

        $this->expectException(StateRefused::class);
        $this->current()->get('App\\Search', 'search-1', $tampered);
    }
}
