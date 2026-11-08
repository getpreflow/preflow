<?php

declare(strict_types=1);

namespace Preflow\Components\Tests\Fixtures\Live;

use Preflow\Components\Component;
use Preflow\Components\Live;
use Preflow\Components\PrefetchesLive;

/** "In your album" for one object: live, its state from the visitor's album, prefetched per page. */
#[Live]
final class AlbumButton extends Component implements PrefetchesLive
{
    /** @var list<int> the visitor's album (the test's stand-in for the cookie) */
    public static array $album = [];

    /** @var list<list<array<string, mixed>>> each prefetchLive() call's props */
    public static array $prefetched = [];

    /** @var array<int, bool> what the prefetch loaded */
    private static array $loaded = [];

    public static bool $fail = false;

    public string $state = '';

    public function resolveState(): void
    {
        if (self::$fail) {
            throw new \RuntimeException('album down');
        }
        $number = (int) ($this->props['number'] ?? 0);
        $this->state = $number . (in_array($number, self::$album, true) ? ' in your album' : ' add') . (isset(self::$loaded[$number]) ? '' : ' (unloaded)');
    }

    public static function reset(): void
    {
        self::$album = [];
        self::$prefetched = [];
        self::$loaded = [];
        self::$fail = false;
    }

    public function prefetchLive(array $propsList): void
    {
        self::$prefetched[] = $propsList;
        foreach ($propsList as $props) {
            self::$loaded[(int) $props['number']] = true;
        }
    }
}
