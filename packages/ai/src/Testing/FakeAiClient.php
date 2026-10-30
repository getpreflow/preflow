<?php

declare(strict_types=1);

namespace Preflow\Ai\Testing;

use Preflow\Ai\AiClient;
use Preflow\Ai\AiRequest;
use Preflow\Ai\AiResponse;
use Preflow\Ai\StopReason;
use Preflow\Ai\TokenUsage;

/**
 * Scripted stand-in for a provider so tests never call a real one. Lives in preflow/ai
 * so that testing needs no extra dependency.
 */
final class FakeAiClient implements AiClient
{
    /** @var list<AiRequest> */
    private array $requests = [];
    private int $position = 0;

    /** @param list<AiResponse|\Throwable|callable(AiRequest): (AiResponse|\Throwable)> $script consumed in order; the last entry repeats */
    public function __construct(private readonly array $script = [], private readonly string $model = 'fake-model') {}

    /** A Complete response whose text is the JSON encoding of $data. */
    public static function json(mixed $data, TokenUsage $usage = new TokenUsage(10, 10)): AiResponse
    {
        return new AiResponse(json_encode($data, JSON_THROW_ON_ERROR), StopReason::Complete, $usage, 'fake', 'fake-model');
    }

    public function complete(AiRequest $request): AiResponse
    {
        $this->requests[] = $request;

        if ($this->script === []) {
            throw new \LogicException('FakeAiClient has an empty script.');
        }

        $entry = $this->script[min($this->position, count($this->script) - 1)];
        $this->position++;

        if (!$entry instanceof AiResponse && !$entry instanceof \Throwable) {
            $entry = $entry($request);
        }
        if ($entry instanceof \Throwable) {
            throw $entry;
        }

        return $entry;
    }

    public function provider(): string
    {
        return 'fake';
    }

    public function model(): string
    {
        return $this->model;
    }

    /** @return list<AiRequest> */
    public function requests(): array
    {
        return $this->requests;
    }
}
