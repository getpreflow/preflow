<?php

declare(strict_types=1);

namespace Preflow\Ai;

final readonly class AiRequest
{
    /**
     * @param string               $purpose         usage-log label, e.g. 'translate'
     * @param string               $prompt          the user content
     * @param Tuning               $tuning          the system part; stable across a batch, so providers can cache it
     * @param array<string,mixed>|null $schema      JSON schema of the expected answer, null for free text
     * @param float|null           $temperature     providers that cannot take it ignore it
     */
    public function __construct(
        public string $purpose,
        public string $prompt,
        public Tuning $tuning = new Tuning(),
        public ?array $schema = null,
        public ?float $temperature = null,
        public int $maxOutputTokens = 8000,
    ) {}
}
