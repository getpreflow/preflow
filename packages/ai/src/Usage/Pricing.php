<?php

declare(strict_types=1);

namespace Preflow\Ai\Usage;

use Preflow\Ai\TokenUsage;

/**
 * What a call costs in USD, from a table of per-million-token prices. A model missing from the
 * table has no known cost (null), never 0: a silent "free" would hide spending.
 */
final class Pricing
{
    /** Cache reads are billed at a tenth of the input price (both providers' rule of thumb). */
    private const CACHED_FACTOR = 0.1;

    /** USD per million tokens, [input, output]. */
    public const DEFAULTS = [
        'gemini-2.5-flash' => [0.30, 2.50],
        'claude-haiku-5-5' => [0.10, 0.50],
        'claude-sonnet-5-5' => [2.00, 10.00],
        'claude-opus-5-5' => [4.00, 20.00],
    ];

    /** @param array<string, array{0: float|int, 1: float|int}> $perMillion model => [input, output] */
    public function __construct(private readonly array $perMillion) {}

    /** The default table with a project's config/ai.php `pricing` merged over it. */
    public static function withOverrides(array $overrides): self
    {
        return new self(array_merge(self::DEFAULTS, $overrides));
    }

    public function has(string $model): bool
    {
        return isset($this->perMillion[$model]);
    }

    /**
     * $u->input counts every prompt token, cached ones included (cachedInput is the cached part
     * of it), so the cached tokens are billed at the reduced rate and the rest at the full one.
     */
    public function cost(string $model, TokenUsage $u): ?float
    {
        if (!$this->has($model)) {
            return null;
        }
        [$in, $out] = $this->perMillion[$model];
        $cached = min($u->cachedInput, $u->input);

        return (($u->input - $cached) * $in + $cached * $in * self::CACHED_FACTOR + $u->output * $out) / 1_000_000;
    }
}
