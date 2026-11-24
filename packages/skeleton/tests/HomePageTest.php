<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tests\Support\SkeletonApp;

final class HomePageTest extends TestCase
{
    use SkeletonApp;

    protected function setUp(): void
    {
        $this->bootApp();
    }

    protected function tearDown(): void
    {
        $this->removeApp();
    }

    public function test_home_answers_in_english(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('<html lang="en">')
            ->assertSee('folio:install')
            ->assertSee('app/pages');
    }

    public function test_home_answers_in_german(): void
    {
        $this->get('/de')
            ->assertOk()
            ->assertSee('<html lang="de">')
            ->assertSee('folio:install')
            ->assertSee('app/pages');
    }

    public function test_german_text_differs_from_english(): void
    {
        $en = $this->get('/')->body();
        $de = $this->get('/de')->body();

        $this->assertStringContainsString('Next steps', $en);
        $this->assertStringContainsString('Nächste Schritte', $de);
    }

    public function test_unknown_page_is_a_404_page(): void
    {
        $this->get('/does-not-exist')->assertStatus(404);
    }
}
