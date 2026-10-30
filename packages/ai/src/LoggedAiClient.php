<?php

declare(strict_types=1);

namespace Preflow\Ai;

use Preflow\Ai\Usage\Pricing;
use Preflow\Ai\Usage\UsageEntry;
use Preflow\Ai\Usage\UsageLog;

/**
 * Writes a usage entry after every call, so no caller has to remember to. A request that failed
 * is logged too (with 0 tokens, as the provider gave no usage), because failed calls still count
 * when you look at how often a run needed retries. A broken log never breaks the call itself.
 */
final class LoggedAiClient implements AiClient
{
    public function __construct(
        private readonly AiClient $inner,
        private readonly UsageLog $log,
        private readonly Pricing $pricing,
    ) {}

    public function complete(AiRequest $request): AiResponse
    {
        try {
            $response = $this->inner->complete($request);
        } catch (AiRequestFailed $e) {
            $this->record($request->purpose, $this->inner->provider(), $this->inner->model(), new TokenUsage(), true);
            throw $e;
        }
        $this->record($request->purpose, $response->provider, $response->model, $response->usage, false);

        return $response;
    }

    public function provider(): string
    {
        return $this->inner->provider();
    }

    public function model(): string
    {
        return $this->inner->model();
    }

    /** Priced by the model the provider reports, else by the configured one (providers may answer with a dated id the table lacks). */
    private function cost(string $model, TokenUsage $usage): ?float
    {
        return $this->pricing->cost($model, $usage) ?? $this->pricing->cost($this->inner->model(), $usage);
    }

    private function record(string $purpose, string $provider, string $model, TokenUsage $usage, bool $failed): void
    {
        try {
            $this->log->record(new UsageEntry(new \DateTimeImmutable(), $purpose, $provider, $model, $usage, $this->cost($model, $usage), $failed));
        } catch (\RuntimeException) {
            // Bookkeeping must not turn a good answer into an error.
        }
    }
}
