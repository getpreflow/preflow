<?php

declare(strict_types=1);

namespace Preflow\Core\Context;

/**
 * Which version of publishing types a render reads: the published one, the working copies
 * (drafts), or the published state as of a date (reserved: needs revision reads). Core only
 * carries it; publishing interprets it.
 *
 * @since 0.81.0
 */
final readonly class ReadMode
{
    private function __construct(
        private bool $workingCopies,
        public ?\DateTimeImmutable $at,
    ) {}

    public static function published(): self
    {
        return new self(false, null);
    }

    public static function workingCopies(): self
    {
        return new self(true, null);
    }

    public static function asOf(\DateTimeImmutable $at): self
    {
        return new self(false, $at);
    }

    public function readsWorkingCopies(): bool
    {
        return $this->workingCopies;
    }

    /** 'published', 'working' or 'asof:{the date in UTC, to the microsecond}'. */
    public function key(): string
    {
        if ($this->at !== null) {
            return 'asof:' . $this->at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        }

        return $this->workingCopies ? 'working' : 'published';
    }
}
