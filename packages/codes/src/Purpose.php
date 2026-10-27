<?php

declare(strict_types=1);

namespace Preflow\Codes;

/** What a code grants, from config/codes.php: readable codes (book codes) only where allowed. */
final readonly class Purpose
{
    public function __construct(
        public string $name,
        public string $label,
        public bool $readable,
        /** Days a code of this purpose lives when issued without an expiry; null: forever. */
        public ?int $ttl = null,
    ) {}
}
