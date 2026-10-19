<?php

declare(strict_types=1);

namespace Preflow\Notify\Tests\Transport;

use PHPUnit\Framework\TestCase;
use Preflow\Notify\Email;
use Preflow\Notify\Message;
use Preflow\Notify\SentMessage;
use Preflow\Notify\Sms;
use Preflow\Notify\Transport;
use Preflow\Notify\Transport\FailoverTransport;
use Preflow\Notify\Transport\InMemoryTransport;
use Preflow\Notify\Transport\LogTransport;
use Preflow\Notify\Transport\NullTransport;
use Preflow\Notify\TransportException;

final class BuiltInTransportsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/notify_log_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/logs/messages.log');
        @rmdir($this->dir . '/logs');
        @rmdir($this->dir);
    }

    public function test_null_transport_drops_messages(): void
    {
        $sent = (new NullTransport())->send(new Email('a@b.test', 'S', 'T'));

        $this->assertSame('null', $sent->transport);
    }

    public function test_memory_transport_records_and_resets(): void
    {
        $memory = new InMemoryTransport();
        $memory->send(new Email('a@b.test', 'S', 'T'));
        $memory->send(new Sms('+41790000000', 'T'));

        $this->assertCount(2, $memory->sent());
        $this->assertSame('memory', $memory->sent()[0]->transport);

        $memory->reset();
        $this->assertCount(0, $memory->sent());
    }

    public function test_log_transport_appends_summary_and_body(): void
    {
        $file = $this->dir . '/logs/messages.log';
        $log = new LogTransport($file);

        $log->send(new Email('a@b.test', 'Hello', 'Body'));
        $first = (string) file_get_contents($file);

        $this->assertStringContainsString('email', $first);
        $this->assertStringContainsString('to=a@b.test', $first);
        $this->assertStringContainsString('subject=Hello', $first);
        $this->assertStringContainsString('Body', $first);

        $log->send(new Sms('+41790000000', 'Code 4711'));
        $second = (string) file_get_contents($file);

        $this->assertStringStartsWith($first, $second);
        $this->assertStringContainsString('to=+41790000000', $second);
        $this->assertStringContainsString('Code 4711', $second);
    }

    public function test_failover_uses_first_successful(): void
    {
        $failover = new FailoverTransport([
            ['broken', $this->throwing()],
            ['memory', new InMemoryTransport()],
        ]);

        $sent = $failover->send(new Email('a@b.test', 'S', 'T'));

        $this->assertSame('memory', $sent->transport);
    }

    public function test_failover_skips_transports_that_do_not_support_the_message(): void
    {
        $unsupporting = new class implements Transport {
            public bool $called = false;

            public function supports(Message $message): bool
            {
                return false;
            }

            public function send(Message $message): SentMessage
            {
                $this->called = true;
                throw new TransportException('must not be called');
            }
        };

        $failover = new FailoverTransport([
            ['smtp', $unsupporting],
            ['memory', new InMemoryTransport()],
        ]);

        $sent = $failover->send(new Sms('+41790000000', 'T'));

        $this->assertSame('memory', $sent->transport);
        $this->assertFalse($unsupporting->called);
    }

    public function test_failover_exhausted_names_all_transports(): void
    {
        $failover = new FailoverTransport([
            ['a', $this->throwing()],
            ['b', $this->throwing()],
        ]);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessageMatches('/a, b/');
        $failover->send(new Email('a@b.test', 'S', 'T'));
    }

    private function throwing(): Transport
    {
        return new class implements Transport {
            public function supports(Message $message): bool
            {
                return true;
            }

            public function send(Message $message): SentMessage
            {
                throw new TransportException('down');
            }
        };
    }
}
