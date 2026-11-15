<?php

declare(strict_types=1);

namespace Preflow\Ai\Limits;

/**
 * A person has used up their limit for an AI action (see AiLimits). Carries what a screen needs to
 * say when to try again: the action, its limit and the seconds until the window ends (an HTTP
 * answer's Retry-After).
 *
 * @since 0.78
 */
final class AiLimitReached extends \RuntimeException
{
    public function __construct(
        public readonly string $action,
        public readonly int $max,
        public readonly int $retryAfter,
    ) {
        parent::__construct("The AI limit for \"{$action}\" ({$max}) is reached; try again in {$retryAfter} s.");
    }

    /** The text that says so, in the ai translations: 'ai.limits.{action}'. */
    public function textKey(): string
    {
        return 'ai.limits.' . $this->action;
    }

    /** @return array{max: int, minutes: int} the text's parameters */
    public function textParams(): array
    {
        return ['max' => $this->max, 'minutes' => (int) ceil($this->retryAfter / 60)];
    }
}
