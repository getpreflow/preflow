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
     * @param 'unknown'|'expired'|'used_up'|'revoked'|'throttled'|'wrong_recipient'|null $reason
     * @param array<string, mixed> $payload the code's payload, on success only
     * @param ?string $recipient the address a bound code is for: set when the code was found and is live
     *                           (success and wrong_recipient), never on other failures
     */
    public function __construct(
        public bool $ok,
        public ?string $reason = null,
        public array $payload = [],
        public ?string $codeId = null,
        public ?string $recipient = null,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function success(array $payload, string $codeId, ?string $recipient = null): self
    {
        return new self(true, null, $payload, $codeId, $recipient);
    }

    /** @param 'unknown'|'expired'|'used_up'|'revoked'|'throttled'|'wrong_recipient' $reason */
    public static function failure(string $reason, ?string $codeId = null, ?string $recipient = null): self
    {
        return new self(false, $reason, [], $codeId, $recipient);
    }
}
