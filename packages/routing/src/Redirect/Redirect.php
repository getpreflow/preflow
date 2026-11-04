<?php

declare(strict_types=1);

namespace Preflow\Routing\Redirect;

/** One redirect entry: an address (or an area below it) and where it now points. */
final readonly class Redirect
{
    /**
     * @param string  $locale '' means any language
     * @param bool    $area   true when the entry covers everything below $path
     *
     * The target is either a record ($targetType and $targetId, looked up when served) or a
     * fixed address or URL ($targetUrl); with neither the entry is broken until an editor picks one.
     */
    public function __construct(
        public string $id,
        public string $path,
        public string $locale,
        public bool $area,
        public AreaMode $mode,
        public ?string $targetType,
        public ?string $targetId,
        public ?string $targetUrl,
        public int $status,
    ) {
        if ($status !== 301 && $status !== 302) {
            throw new \InvalidArgumentException("Redirect status must be 301 or 302, got {$status}.");
        }
    }

    /** @param 'exact'|'area' $kind */
    public static function id(string $kind, string $locale, string $path): string
    {
        return substr(hash('sha256', implode("\n", [$kind, $locale, $path])), 0, 32);
    }
}
