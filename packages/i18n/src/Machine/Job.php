<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

/**
 * A translation run over a collection, kept as JSON so it survives between requests: a browser
 * or a cron job advances it one item at a time.
 */
final class Job
{
    /** @var array<string, array{status: string, translated: int, failed: int, warnings: int, message: ?string, draft_changes: bool}> */
    public array $items = [];

    public function __construct(
        public string $id,
        public string $collection,
        /** @var list<string> */
        public array $locales,
        public Scope $scope,
        public ?string $note,
        public Estimate $estimate,
        /** 'planned' | 'running' | 'stopped' | 'done' */
        public string $state = 'planned',
        /** Actual cost in USD of this job's own priced requests (see $costUnknown). */
        public float $costUsd = 0.0,
        /** Why the job stopped, when it did not stop by request. */
        public ?string $message = null,
        /** A request had no price (the model is not in the price table): $costUsd is then a lower bound. */
        public bool $costUnknown = false,
    ) {}

    /** @return list<string> ids still to do */
    public function pending(): array
    {
        // PHP turns numeric ids into int keys; callers get strings back.
        return array_map('strval', array_keys(array_filter($this->items, static fn (array $i): bool => $i['status'] === 'pending')));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'collection' => $this->collection,
            'locales' => $this->locales,
            'scope' => $this->scope->value,
            'note' => $this->note,
            'state' => $this->state,
            'message' => $this->message,
            'items' => $this->items,
            'estimate' => get_object_vars($this->estimate),
            'cost_usd' => $this->costUsd,
            'cost_unknown' => $this->costUnknown,
        ];
    }

    /** @param array<string, mixed> $d */
    public static function fromArray(array $d): self
    {
        $e = $d['estimate'] ?? [];
        $job = new self(
            (string) $d['id'],
            (string) $d['collection'],
            array_values(array_map('strval', (array) ($d['locales'] ?? []))),
            Scope::from((string) ($d['scope'] ?? 'needed')),
            isset($d['note']) ? (string) $d['note'] : null,
            new Estimate(
                (int) ($e['units'] ?? 0),
                (int) ($e['characters'] ?? 0),
                (int) ($e['inputTokens'] ?? 0),
                (int) ($e['outputTokens'] ?? 0),
                isset($e['costUsd']) ? (float) $e['costUsd'] : null,
                (int) ($e['requests'] ?? 0),
            ),
            (string) ($d['state'] ?? 'planned'),
            (float) ($d['cost_usd'] ?? 0.0),
            isset($d['message']) ? (string) $d['message'] : null,
            (bool) ($d['cost_unknown'] ?? false),
        );
        $job->items = (array) ($d['items'] ?? []);

        return $job;
    }
}
