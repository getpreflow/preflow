<?php

declare(strict_types=1);

namespace Preflow\Data\Records;

/**
 * What a read asks for (RecordOutput::toArray()): $locale picks one language of every translatable
 * field (through the type's fallback chain; null: each such field is a map of locale => value),
 * $with names the extras the output parts add only on request (the media package's "media":
 * the "_media" map of URLs and sizes). A name no part knows is ignored. The fields' shapes never
 * depend on $with.
 *
 * @since 0.93.0
 */
final readonly class ReadOptions
{
    /**
     * @param ?string      $locale one language of every translatable field, or null for the map
     * @param list<string> $with   the extras asked for, by name ("media")
     */
    public function __construct(
        public ?string $locale = null,
        public array $with = [],
    ) {}

    /** Whether the extra $name was asked for ($with holds it). */
    public function wants(string $name): bool
    {
        return in_array($name, $this->with, true);
    }
}
