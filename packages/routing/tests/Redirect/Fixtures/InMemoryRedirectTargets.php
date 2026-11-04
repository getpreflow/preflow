<?php

declare(strict_types=1);

namespace Preflow\Routing\Tests\Redirect\Fixtures;

use Preflow\Routing\Redirect\RedirectTargets;

/** Record URLs keyed "type:id:locale"; remembers the locales it was asked for. */
final class InMemoryRedirectTargets implements RedirectTargets
{
    /** @var list<string> */
    public array $asked = [];

    /** @param array<string, string> $urls */
    public function __construct(private readonly array $urls = []) {}

    public function url(string $type, string $id, string $locale): ?string
    {
        $this->asked[] = "{$type}:{$id}:{$locale}";

        return $this->urls["{$type}:{$id}:{$locale}"] ?? null;
    }
}
