<?php

declare(strict_types=1);

namespace Preflow\View\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\View\NonceGenerator;

final class NonceGeneratorAdoptTest extends TestCase
{
    public function test_a_page_nonce_is_adopted(): void
    {
        $nonce = new NonceGenerator();
        $nonce->get();

        $this->assertTrue($nonce->adopt('cGFnZS1ub25jZS0xMjM0NQ=='));
        $this->assertSame('cGFnZS1ub25jZS0xMjM0NQ==', $nonce->get());
    }

    public function test_anything_but_a_base64_nonce_is_ignored(): void
    {
        $nonce = new NonceGenerator();
        $own = $nonce->get();

        foreach (['', 'short', 'x" onload="alert(1)', str_repeat('a', 80), "abc\ndefghijklmnopq"] as $bad) {
            $this->assertFalse($nonce->adopt($bad), $bad);
        }
        $this->assertSame($own, $nonce->get());
    }
}
