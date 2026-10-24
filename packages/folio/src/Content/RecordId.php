<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

/**
 * Record ids taken from a request (ids[], relation filter values): only ids that are valid
 * UTF-8 and hold no path ("/", "\", NUL or "..") are used. A storage that keeps a record per
 * file never sees one that could leave the type's folder, and SQL storages never get bytes
 * they cannot encode.
 */
final class RecordId
{
    public static function usable(string $id): bool
    {
        return $id !== ''
            && mb_check_encoding($id, 'UTF-8')
            && strpbrk($id, "/\\\0") === false
            && !str_contains($id, '..');
    }
}
