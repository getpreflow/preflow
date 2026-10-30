<?php

declare(strict_types=1);

namespace Preflow\Ai\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Ai\AiResponse;
use Preflow\Ai\AiResponseInvalid;
use Preflow\Ai\StopReason;
use Preflow\Ai\TokenUsage;

final class AiResponseTest extends TestCase
{
    private function response(?string $text): AiResponse
    {
        return new AiResponse($text, StopReason::Complete, new TokenUsage(), 'fake', 'm');
    }

    public function test_json_strips_fence(): void
    {
        $this->assertSame([1], $this->response("```json\n[1]\n```")->json());
        $this->assertSame(['a' => 1], $this->response("```\n{\"a\":1}\n```")->json());
        $this->assertSame([1], $this->response('[1]')->json());
    }

    public function test_json_throws_on_null_text_and_bad_json(): void
    {
        try {
            $this->response(null)->json();
            $this->fail('expected AiResponseInvalid');
        } catch (AiResponseInvalid) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(AiResponseInvalid::class);
        $this->response('not json')->json();
    }
}
