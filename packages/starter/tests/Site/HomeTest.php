<?php

declare(strict_types=1);

namespace Tests\Site;

use PHPUnit\Framework\TestCase;
use Tests\Support\StarterApp;

final class HomeTest extends TestCase
{
    use StarterApp;

    protected function setUp(): void
    {
        $this->bootStarter();
    }

    protected function tearDown(): void
    {
        $this->removeStarter();
    }

    public function test_home_answers_200(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('<h1>Preflow Jobs</h1>');
    }
}
