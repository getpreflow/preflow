<?php

declare(strict_types=1);

namespace Preflow\Ai;

final readonly class AiResponse
{
    public function __construct(
        public ?string $text,
        public StopReason $stop,
        public TokenUsage $usage,
        public string $provider,
        public string $model,
        public ?string $detail = null,
    ) {}

    /**
     * Decoded JSON answer. Models sometimes wrap JSON in a ``` or ```json fence even when
     * asked not to, so one surrounding fence is stripped.
     *
     * @throws AiResponseInvalid when there is no text or it is not valid JSON
     */
    public function json(): mixed
    {
        if ($this->text === null) {
            throw new AiResponseInvalid('The response has no text to decode.');
        }

        $text = trim($this->text);
        if (preg_match('/\A```(?:json)?[ \t]*\R(.*?)\R?```\z/s', $text, $m) === 1) {
            $text = trim($m[1]);
        }

        try {
            return json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new AiResponseInvalid('The response is not valid JSON: ' . $e->getMessage(), 0, $e);
        }
    }
}
