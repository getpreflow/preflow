<?php

declare(strict_types=1);

namespace Preflow\Media;

/** What MediaLibrary::emptyBin() did: how many assets it deleted for good, and which it kept in the bin and why. */
final readonly class BinEmptyResult
{
    /** @param list<array{id: string, reason: string}> $kept */
    public function __construct(
        public int $deleted,
        public array $kept,
    ) {}
}
