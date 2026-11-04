<?php

declare(strict_types=1);

namespace Preflow\Routing\Tests\Redirect\Fixtures;

use Preflow\Routing\Redirect\Redirect;
use Preflow\Routing\Redirect\RedirectStore;

/** Redirect entries held in an array; `$fail` makes every call throw like a missing table. */
final class InMemoryRedirectStore implements RedirectStore
{
    /** @var list<string> ids of the entries counted by hit() */
    public array $hits = [];

    public bool $fail = false;

    public bool $failHit = false;

    /** @param list<Redirect> $entries */
    public function __construct(private array $entries = []) {}

    public function exact(string $path, string $locale): ?Redirect
    {
        $this->failIfAsked();

        foreach ([$locale, ''] as $wanted) {
            foreach ($this->entries as $entry) {
                if (!$entry->area && $entry->path === $path && $entry->locale === $wanted) {
                    return $entry;
                }
            }
        }

        return null;
    }

    public function areas(string $locale): array
    {
        $this->failIfAsked();

        return array_values(array_filter(
            $this->entries,
            static fn (Redirect $entry): bool => $entry->area && ($entry->locale === $locale || $entry->locale === ''),
        ));
    }

    public function hit(Redirect $redirect, \DateTimeImmutable $at): void
    {
        if ($this->failHit) {
            throw new \RuntimeException('hit failed');
        }

        $this->hits[] = $redirect->id;
    }

    private function failIfAsked(): void
    {
        if ($this->fail) {
            throw new \RuntimeException('Table redirects does not exist');
        }
    }
}
