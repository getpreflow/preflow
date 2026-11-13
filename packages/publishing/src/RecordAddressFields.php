<?php

declare(strict_types=1);

namespace Preflow\Publishing;

/**
 * Which fields make up a type's public address: the parameters of its route ("/{handle}",
 * "/{...path}"). RecordWriter::duplicate() gives a copy free values in them, so the copy never
 * takes over its record's address. The RecordTypes binding answers it when it implements this
 * interface too (Folio's does, from each type's route); without it, a type has no address
 * fields and only its slug (a field named "slug", or with the "slug" rule) is freed.
 *
 * @since 0.75
 */
interface RecordAddressFields
{
    /**
     * @return array<string, bool> field name => whether it is a catch-all (a path with slashes)
     */
    public function addressFields(string $type): array;
}
