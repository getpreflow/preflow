<?php

declare(strict_types=1);

namespace Preflow\Ai\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Ai\AiRequest;
use Preflow\Ai\AiRequestFailed;
use Preflow\Ai\Testing\FakeAiClient;

final class FakeAiClientTest extends TestCase
{
    public function test_script_in_order_last_repeats_and_requests_recorded(): void
    {
        $client = new FakeAiClient([FakeAiClient::json(1), FakeAiClient::json(2)]);

        $this->assertSame(1, $client->complete(new AiRequest('t', 'a'))->json());
        $this->assertSame(2, $client->complete(new AiRequest('t', 'b'))->json());
        $this->assertSame(2, $client->complete(new AiRequest('t', 'c'))->json());
        $this->assertSame(['a', 'b', 'c'], array_map(fn (AiRequest $r) => $r->prompt, $client->requests()));
        $this->assertSame('fake', $client->provider());
        $this->assertSame('fake-model', $client->model());
    }

    public function test_throwable_entry_is_thrown(): void
    {
        $client = new FakeAiClient([new AiRequestFailed('boom')]);

        $this->expectException(AiRequestFailed::class);
        $client->complete(new AiRequest('t', 'a'));
    }

    public function test_callable_entry_receives_request(): void
    {
        $client = new FakeAiClient([fn (AiRequest $r) => FakeAiClient::json(strtoupper($r->prompt))]);

        $this->assertSame('HI', $client->complete(new AiRequest('t', 'hi'))->json());
    }
}
