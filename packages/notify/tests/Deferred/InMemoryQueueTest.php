<?php

declare(strict_types=1);

namespace Preflow\Notify\Tests\Deferred;

use PHPUnit\Framework\TestCase;
use Preflow\Notify\Deferred\Deferred;
use Preflow\Notify\Deferred\DeferredQueue;
use Preflow\Notify\Deferred\InMemoryQueue;
use Preflow\Notify\Email;

/** notify's own queue, used without a render frame: as 0.80's Notifier queue was. */
final class InMemoryQueueTest extends TestCase
{
    /** @var list<string> */
    private array $sent = [];

    private function queue(int $limit = 100, ?\Closure $log = null, ?\Closure $send = null): InMemoryQueue
    {
        return new InMemoryQueue($send ?? function (Deferred $item): void {
            $this->sent[] = $item->message instanceof Email ? $item->message->to[0] : '?';
        }, $limit, $log);
    }

    private static function item(string $to): Deferred
    {
        return new Deferred(new Email($to, 'S', 'T'), 'email', null, 'Email to ' . $to . ': S');
    }

    public function test_flush_sends_what_was_pushed_in_order_once(): void
    {
        $queue = $this->queue();
        foreach (['a', 'b', 'c'] as $name) {
            $queue->push(self::item($name . '@x.test'));
        }

        $this->assertSame([], $this->sent);
        $queue->flush();
        $queue->flush();
        $this->assertSame(['a@x.test', 'b@x.test', 'c@x.test'], $this->sent);
    }

    public function test_what_a_flush_queues_goes_out_in_the_same_flush_up_to_the_limit(): void
    {
        $logged = [];
        $runs = 0;
        $queue = null;
        $queue = $this->queue(3, static function (string $line) use (&$logged): void {
            $logged[] = $line;
        }, static function (Deferred $item) use (&$runs, &$queue): void {
            $runs++;
            $queue->push($item);
        });
        $queue->push(self::item('again@x.test'));

        $queue->flush();

        $this->assertSame(3, $runs);
        $this->assertSame(['Deferred notifications: flush limit reached, 1 left unsent.'], $logged);
    }

    public function test_discarding_drops_what_is_pushed_nests_and_ends_on_an_exception(): void
    {
        $queue = $this->queue();
        $push = static fn (string $name) => $queue->push(self::item($name . '@x.test'));
        $push('before');

        $this->assertSame(DeferredQueue::DELIVER, $queue->policy());
        $result = $queue->discarding(function () use ($queue, $push): string {
            $this->assertSame(DeferredQueue::DISCARD, $queue->policy());
            $push('during');
            $queue->discarding(static fn () => $push('nested'));
            $push('after-nested');

            return 'rendered';
        });
        try {
            $queue->discarding(static function () use ($push): never {
                $push('failing');
                throw new \RuntimeException('render failed');
            });
        } catch (\RuntimeException) {
        }
        $push('after');
        $queue->record('a send() that did not go out'); // nothing captures without a frame

        $this->assertSame('rendered', $result);
        $this->assertSame(DeferredQueue::DELIVER, $queue->policy());
        $queue->flush();
        $this->assertSame(['before@x.test', 'after@x.test'], $this->sent);
    }
}
