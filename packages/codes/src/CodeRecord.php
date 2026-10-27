<?php

declare(strict_types=1);

namespace Preflow\Codes;

/** A stored code, as Codes::record(), get() and forRecipient() hand it out. */
final readonly class CodeRecord
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $id,
        public string $purpose,
        public string $code,
        public ?string $recipient,
        public array $payload,
        public ?\DateTimeImmutable $expiresAt,
        public int $uses,
        public ?int $maxUses,
        public ?\DateTimeImmutable $revokedAt,
        public string $form,
    ) {}

    /** Not revoked, not used up and not expired at $now. */
    public function isOpen(\DateTimeImmutable $now): bool
    {
        return $this->revokedAt === null
            && ($this->maxUses === null || $this->uses < $this->maxUses)
            && ($this->expiresAt === null || $now < $this->expiresAt);
    }
}
