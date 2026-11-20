<?php

declare(strict_types=1);

namespace Preflow\Notify\Tests\Deferred;

use PHPUnit\Framework\TestCase;
use Preflow\Notify\Deferred\DeferredQueue;
use Preflow\Notify\Deferred\InMemoryQueue;

/** notify's own queue, used without a render frame: as 0.80's Notifier queue was. */
final class InMemoryQueueTest extends TestCase
{
    public function test_flush_runs_what_was_pushed_in_order_once(): void
    {
        $queue = new InMemoryQueue();
        $ran = [];
        foreach (['a', 'b', 'c'] as $name) {
            $queue->push(static function () use (&$ran, $name): void {
                $ran[] = $name;
            }, 'Mail ' . $name);
        }

        $this->assertSame([], $ran);
        $queue->flush();
        $queue->flush();
        $this->assertSame(['a', 'b', 'c'], $ran);
    }

    public function test_what_a_flush_queues_goes_out_in_the_same_flush_up_to_the_limit(): void
    {
        $logged = [];
        $queue = new InMemoryQueue(limit: 3, log: static function (string $line) use (&$logged): void {
            $logged[] = $line;
        });
        $runs = 0;
        $again = static function () use (&$again, &$runs, $queue): void {
            $runs++;
            $queue->push($again, 'again');
        };
        $queue->push($again, 'again');

        $queue->flush();

        $this->assertSame(3, $runs);
        $this->assertSame(['Deferred notifications: flush limit reached, 1 left unsent.'], $logged);
    }

    public function test_discarding_drops_what_is_pushed_nests_and_ends_on_an_exception(): void
    {
        $queue = new InMemoryQueue();
        $ran = [];
        $push = static function (string $name) use ($queue, &$ran): void {
            $queue->push(static function () use (&$ran, $name): void {
                $ran[] = $name;
            }, $name);
        };
        $push('before');

        $this->assertSame(DeferredQueue::DELIVER, $queue->policy());
        $result = $queue->discarding(function () use ($queue, $push): string {
            $this->assertSame(DeferredQueue::DISCARD, $queue->policy());
            $push('during');
            $queue->discarding(static fn () => $push('nested'));
            $push('after nested');

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
        $this->assertSame(['before', 'after'], $ran);
    }
}
