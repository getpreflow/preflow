<?php

declare(strict_types=1);

namespace Preflow\Notify\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Notify\Email;
use Preflow\Notify\Message;
use Preflow\Notify\Notifier;
use Preflow\Notify\SentMessage;
use Preflow\Notify\Transport;
use Preflow\Notify\TransportException;
use Preflow\Notify\TransportFactory;
use Psr\Log\AbstractLogger;

final class DeferredTest extends TestCase
{
    private TransportFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new TransportFactory(['mem' => ['driver' => 'memory'], 'bad' => ['driver' => 'picky']], sys_get_temp_dir());
        $this->factory->extend('picky', fn () => new class implements Transport {
            public function supports(Message $message): bool
            {
                return true;
            }

            public function send(Message $message): SentMessage
            {
                throw new TransportException('smtp down for secret@example.com');
            }
        });
    }

    private function notifier(string $transport = 'mem', ?\Psr\Log\LoggerInterface $logger = null, bool $defer = true): Notifier
    {
        return new Notifier($this->factory, ['email' => ['transport' => $transport]], $logger, $defer);
    }

    private function mail(string $to): Email
    {
        return new Email($to, 'S', 'T');
    }

    private function recipients(): array
    {
        return array_map(fn (SentMessage $s) => $s->message->to[0], $this->factory->get('mem')->sent());
    }

    public function test_queue_sends_in_order_on_flush(): void
    {
        $n = $this->notifier();
        $n->sendLater($this->mail('1@x.test'));
        $n->sendLater($this->mail('2@x.test'));
        $n->sendLater($this->mail('3@x.test'));

        $this->assertSame([], $this->recipients());
        $this->assertSame(3, $n->flushDeferred());
        $this->assertSame(['1@x.test', '2@x.test', '3@x.test'], $this->recipients());
        $this->assertSame(0, $n->flushDeferred());
    }

    public function test_failing_message_calls_on_failure_and_others_still_send(): void
    {
        $n = new Notifier($this->factory, ['email' => ['transport' => 'mem'], 'sms' => ['transport' => 'bad']]);
        $seen = [];
        $n->sendLater($this->mail('1@x.test'));
        $n->sendLater(new \Preflow\Notify\Sms('+41790000000', 'T'), function (\Throwable $e) use (&$seen) { $seen[] = $e; });
        $n->sendLater($this->mail('3@x.test'));

        $this->assertSame(2, $n->flushDeferred());
        $this->assertSame(['1@x.test', '3@x.test'], $this->recipients());
        $this->assertCount(1, $seen);
        $this->assertInstanceOf(TransportException::class, $seen[0]);
    }

    public function test_failure_without_callback_is_logged_and_does_not_throw(): void
    {
        $logger = new class extends AbstractLogger {
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message];
            }
        };
        $n = $this->notifier('bad', $logger);
        $n->sendLater($this->mail('secret@example.com'));

        $this->assertSame(0, $n->flushDeferred());
        $this->assertCount(1, $logger->records);
        $this->assertSame('error', $logger->records[0][0]);
        $this->assertStringNotContainsString('secret@example.com', $logger->records[0][1]);
    }

    public function test_failure_without_logger_does_not_throw(): void
    {
        $n = $this->notifier('bad');
        $n->sendLater($this->mail('secret@example.com'));
        $log = tempnam(sys_get_temp_dir(), 'el');
        $old = ini_set('error_log', $log);
        try {
            $this->assertSame(0, $n->flushDeferred());
        } finally {
            ini_set('error_log', $old === false ? '' : $old);
        }
        $this->assertStringNotContainsString('secret@example.com', (string) file_get_contents($log));
        $this->assertStringContainsString('Deferred', (string) file_get_contents($log));
        unlink($log);
    }

    public function test_defer_false_sends_immediately_and_routes_failure_to_callback(): void
    {
        $n = $this->notifier('mem', null, false);
        $n->sendLater($this->mail('1@x.test'));
        $this->assertSame(['1@x.test'], $this->recipients());
        $this->assertSame(0, $n->flushDeferred());

        $seen = null;
        $this->notifier('bad', null, false)->sendLater($this->mail('a@x.test'), function (\Throwable $e) use (&$seen) { $seen = $e; });
        $this->assertInstanceOf(TransportException::class, $seen);
    }
}
