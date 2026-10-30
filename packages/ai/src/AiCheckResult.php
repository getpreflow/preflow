<?php

declare(strict_types=1);

namespace Preflow\Ai;

/** What AiCheck found. Usage and cost are set once a request was answered (cost null = no price for the model). */
final readonly class AiCheckResult
{
    /** AI is switched off or the key is missing: no request was sent. */
    public const OFF = 'off';
    /** The request failed; $message says why. */
    public const FAILED = 'failed';
    /** The model answered, but not with {"ok":true}. */
    public const UNEXPECTED = 'unexpected';
    public const OK = 'ok';

    public function __construct(
        public string $state,
        public ?string $message = null,
        public ?TokenUsage $usage = null,
        public ?float $cost = null,
    ) {}

    public function ok(): bool
    {
        return $this->state === self::OK;
    }
}
