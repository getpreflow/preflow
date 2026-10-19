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

    public function test_masks_password_containing_at_sign(): void
    {
        $masked = TransportException::maskCredentials('Connection to smtp://user:p@ss@mail.test:587 failed');

        $this->assertStringContainsString('smtp://***@mail.test:587', $masked);
        $this->assertStringNotContainsString('p@ss', $masked);
        $this->assertStringNotContainsString('ss@mail', $masked);
    }

    public function test_masks_secret_query_parameters(): void
    {
        $masked = TransportException::maskCredentials(
            'HTTP 401 returned for "https://api.x.test/send?api_key=SECRET1&to=41" and smtp://host?password=SECRET2&token=SECRET3',
        );

        $this->assertStringContainsString('api_key=***&to=41', $masked);
        $this->assertStringContainsString('password=***', $masked);
        $this->assertStringContainsString('token=***', $masked);
        $this->assertStringNotContainsString('SECRET', $masked);
    }
}
