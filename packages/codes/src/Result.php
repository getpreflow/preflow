<?php

declare(strict_types=1);

namespace Preflow\Codes;

/**
 * The outcome of Codes::check() or redeem(). The reason is for the site and the admin;
 * visitors get one generic failure message whatever it is (codes are bearer tokens).
 */
final readonly class Result
{
    /**
     * @param 'unknown'|'expired'|'used_up'|'revoked'|'throttled'|null $reason
     * @param array<string, mixed> $payload the code's payload, on success only
     */
    public function __construct(
        public bool $ok,
        public ?string $reason = null,
        public array $payload = [],
        public ?string $codeId = null,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function success(array $payload, string $codeId): self
    {
        return new self(true, null, $payload, $codeId);
    }

    /** @param 'unknown'|'expired'|'used_up'|'revoked'|'throttled' $reason */
    public static function failure(string $reason, ?string $codeId = null): self
    {
        return new self(false, $reason, [], $codeId);
    }
}
