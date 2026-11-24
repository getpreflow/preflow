<?php

declare(strict_types=1);

namespace Preflow\Components\Tests\State;

use Preflow\Components\SessionStateStore;
use Preflow\Components\StateStore;
use Preflow\Testing\ArraySession;
use Preflow\Testing\Contract\StateStoreContract;

final class SessionStateStoreTest extends StateStoreContract
{
    protected function store(): StateStore
    {
        return new SessionStateStore(new ArraySession());
    }

    public function test_the_page_carries_a_reference_not_the_state(): void
    {
        $carried = $this->current()->put('App\\X', 'x', ['secret' => 'value']);

        $this->assertStringNotContainsString('value', $carried);
    }

    public function test_an_older_open_page_still_works(): void
    {
        $first = $this->current()->put('App\\X', 'x', ['n' => 1]);
        $this->current()->put('App\\X', 'x', ['n' => 2]);

        $this->assertSame(['n' => 1], $this->current()->get('App\\X', 'x', $first));
    }
}
