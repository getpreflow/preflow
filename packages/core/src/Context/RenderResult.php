<?php

declare(strict_types=1);

namespace Preflow\Core\Context;

use Psr\Http\Message\ResponseInterface;

/**
 * What an internal render produced: the response, what its frame captured instead of sending
 * (OutboxPolicy::Capture), and the data writes it skipped (WritePolicy::Skip), its nested
 * renders' included.
 *
 * @since 0.81.0
 */
final readonly class RenderResult
{
    /**
     * @param list<CapturedItem> $captured
     * @param list<SkippedWrite> $skippedWrites
     */
    public function __construct(
        public ResponseInterface $response,
        public array $captured = [],
        public array $skippedWrites = [],
    ) {}

    /** How many captured items were queued for $channel ('mail', 'cookies'). */
    public function wouldSend(string $channel): int
    {
        $count = 0;
        foreach ($this->captured as $item) {
            if ($item->channel === $channel) {
                $count++;
            }
        }

        return $count;
    }

    /** How many data writes were skipped. */
    public function wouldWrite(): int
    {
        return count($this->skippedWrites);
    }
}
