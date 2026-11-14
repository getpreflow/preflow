<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Http;

use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Http\CookieQueue;

final class CookieQueueTest extends TestCase
{
    public function test_has_names_a_queued_cookie_until_it_is_applied(): void
    {
        $queue = new CookieQueue();
        $queue->queue('preflow_auth=1; Path=/');

        $this->assertTrue($queue->has('preflow_auth'));
        $this->assertFalse($queue->has('preflow'), 'the whole name');
        $this->assertFalse($queue->has('remember_session'));

        $response = $queue->apply(new Response());
        $this->assertSame(['preflow_auth=1; Path=/'], $response->getHeader('Set-Cookie'));
        $this->assertFalse($queue->has('preflow_auth'));
    }

    public function test_isolated_sets_the_queue_aside_and_puts_it_back_even_after_a_throw(): void
    {
        $queue = new CookieQueue();
        $queue->queue('remember_session=rotated');

        $result = $queue->isolated(function () use ($queue): string {
            $this->assertSame([], $queue->pull(), 'the render starts with an empty queue');
            $queue->queue('preflow_auth=');

            return 'page';
        });

        $this->assertSame('page', $result);
        $this->assertSame(['remember_session=rotated'], $queue->pull(), 'the request\'s own back, the render\'s dropped');

        $queue->queue('a=1');
        try {
            $queue->isolated(static fn () => throw new \RuntimeException('render failed'));
        } catch (\RuntimeException) {
        }
        $this->assertSame(['a=1'], $queue->pull());
    }
}
