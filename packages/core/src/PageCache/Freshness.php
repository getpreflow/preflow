<?php

declare(strict_types=1);

namespace Preflow\Core\PageCache;

/**
 * How quickly a cached page follows a change to one of its dependencies (a tag).
 *
 * - Fresh: the page is rebuilt right away (the default).
 * - NextVisit: the page is marked stale; the next visitor gets it while a fresh one is built.
 * - Timed: changes are ignored; the page refreshes on its schedule. The seconds live in the
 *   tag record (TagIndex), not in the enum.
 *
 * Strictness: Fresh > NextVisit > Timed. A page that depends on a changed tag in several ways
 * follows the strictest.
 */
enum Freshness: string
{
    case Fresh = 'fresh';
    case NextVisit = 'next_visit';
    case Timed = 'timed';

    /** Higher is stricter. */
    public function strictness(): int
    {
        return match ($this) {
            self::Fresh => 3,
            self::NextVisit => 2,
            self::Timed => 1,
        };
    }

    public function stricterThan(self $other): bool
    {
        return $this->strictness() > $other->strictness();
    }

    public static function strictest(self $a, self $b): self
    {
        return $b->stricterThan($a) ? $b : $a;
    }

    /**
     * The freshness a template names: 'fresh', 'next-visit' (or 'next_visit') or 'timed'; a
     * Freshness passes as it is.
     *
     * @throws \InvalidArgumentException any other name
     */
    public static function named(self|string $name): self
    {
        if ($name instanceof self) {
            return $name;
        }

        return self::tryFrom(str_replace('-', '_', strtolower(trim($name))))
            ?? throw new \InvalidArgumentException("Unknown freshness '{$name}': use 'fresh', 'next-visit' or 'timed'.");
    }
}
