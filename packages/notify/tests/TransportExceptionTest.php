<?php

declare(strict_types=1);

namespace Preflow\Notify\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Notify\TransportException;

final class TransportExceptionTest extends TestCase
{
    public function test_exception_message_masks_dsn_credentials(): void
    {
        $previous = new \RuntimeException('Connection to smtp://user:s3cret@mail.test:587 failed');
        $e = TransportException::fromProvider('smtp', $previous);

        $this->assertStringContainsString('Transport "smtp" failed:', $e->getMessage());
        $this->assertStringContainsString('smtp://***@mail.test', $e->getMessage());
        $this->assertStringNotContainsString('s3cret', $e->getMessage());
        $this->assertStringNotContainsString('user:', $e->getMessage());
        $this->assertSame($previous, $e->getPrevious());
    }
}
