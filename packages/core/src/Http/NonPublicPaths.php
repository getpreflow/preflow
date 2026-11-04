<?php

declare(strict_types=1);

namespace Preflow\Core\Http;

/**
 * Path prefixes that are not public pages (admin, APIs, component endpoint). Packages add
 * theirs; features that would treat an unknown address as a page (redirects, the not-found
 * list) leave these alone. A prefix matches whole segments: `/folio` covers `/folio/x`, not `/folios`.
 */
final class NonPublicPaths
{
    /** @var array<string, true> */
    private array $prefixes = [];

    public function __construct()
    {
        $this->add('/--component');
    }

    public function add(string $prefix): void
    {
        $prefix = rtrim($prefix, '/');

        // '' and '/' would hide the whole site.
        if ($prefix === '') {
            return;
        }

        $this->prefixes[$prefix] = true;
    }

    public function matches(string $path): bool
    {
        foreach (array_keys($this->prefixes) as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function all(): array
    {
        return array_keys($this->prefixes);
    }
}
