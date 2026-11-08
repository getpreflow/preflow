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
}
