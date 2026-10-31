<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

/**
 * A translation run over a collection, kept as JSON so it survives between requests: a browser
 * or a cron job advances it one item at a time.
 */
final class Job
{
    /**
     * "progress" is there only while a driver translates the item (see JobRunner).
     *
     * @var array<string, array{status: string, translated: int, failed: int, warnings: int, message: ?string, draft_changes: bool, progress?: array{locale: string, done: int, total: int}}>
     */
    public array $items = [];

    public function __construct(
        public string $id,
        public string $collection,
        /** @var list<string> */
        public array $locales,
        public Scope $scope,
        public ?string $note,
        public Estimate $estimate,
        /** 'planned' | 'queued' | 'running' | 'stopped' | 'done' */
        public string $state = 'planned',
        /** Actual cost in USD of this job's own priced requests (see $costUnknown). */
        public float $costUsd = 0.0,
        /** Why the job stopped, when it did not stop by request. */
        public ?string $message = null,
        /** A request had no price (the model is not in the price table): $costUsd is then a lower bound. */
        public bool $costUnknown = false,
        /** Key prefix the translated fields are written under (null: the collection's default). */
        public ?string $prefix = null,
        /** Someone confirmed that this run may write to live content. */
        public bool $confirmedLive = false,
        public ?string $queuedBy = null,
        /** ISO 8601 */
        public ?string $queuedAt = null,
        /** ISO 8601: when the store last saved the job (any change, progress too); null for jobs saved before 0.48.1. */
        public ?string $updatedAt = null,
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
            'prefix' => $this->prefix,
            'confirmed_live' => $this->confirmedLive,
            'queued_by' => $this->queuedBy,
            'queued_at' => $this->queuedAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    /** @param array<string, mixed> $d */
    public static function fromArray(array $d): self
    {
        $e = $d['estimate'] ?? [];
        $state = (string) ($d['state'] ?? 'planned');
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
            $state,
            (float) ($d['cost_usd'] ?? 0.0),
            isset($d['message']) ? (string) $d['message'] : null,
            (bool) ($d['cost_unknown'] ?? false),
            isset($d['prefix']) ? (string) $d['prefix'] : null,
            // A 0.47 job already running was started under the rule that confirmed live writes up front.
            (bool) ($d['confirmed_live'] ?? $state === 'running'),
            isset($d['queued_by']) ? (string) $d['queued_by'] : null,
            isset($d['queued_at']) ? (string) $d['queued_at'] : null,
            isset($d['updated_at']) ? (string) $d['updated_at'] : null,
        );
        $job->items = (array) ($d['items'] ?? []);

        return $job;
    }
}
