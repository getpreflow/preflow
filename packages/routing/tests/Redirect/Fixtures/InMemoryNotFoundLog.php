<?php

declare(strict_types=1);

namespace Preflow\Routing\Tests\Redirect\Fixtures;

use Preflow\Routing\Redirect\NotFoundLog;

/** Keeps every recorded 404 as [path, locale]; `$fail` makes record() throw. */
final class InMemoryNotFoundLog implements NotFoundLog
{
    /** @var list<array{string, string}> */
    public array $recorded = [];

    public bool $fail = false;

    public function record(string $path, string $locale, \DateTimeImmutable $at): void
    {
        if ($this->fail) {
            throw new \RuntimeException('not writable');
        }

        $this->recorded[] = [$path, $locale];
    }
}
