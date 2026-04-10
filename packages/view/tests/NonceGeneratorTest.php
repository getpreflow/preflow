<?php
declare(strict_types=1);
namespace Preflow\View\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\View\NonceGenerator;

final class NonceGeneratorTest extends TestCase
{
    public function test_generates_base64_string(): void
    {
        $gen = new NonceGenerator();
        $nonce = $gen->get();
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9+\/=]+$/', $nonce);
    }

    public function test_returns_same_nonce_per_instance(): void
    {
        $gen = new NonceGenerator();
        $this->assertSame($gen->get(), $gen->get());
    }

    public function test_different_instances_produce_different_nonces(): void
    {
        $this->assertNotSame((new NonceGenerator())->get(), (new NonceGenerator())->get());
    }

    public function test_nonce_is_at_least_16_bytes_encoded(): void
    {
        $this->assertGreaterThanOrEqual(22, strlen((new NonceGenerator())->get()));
    }
}
