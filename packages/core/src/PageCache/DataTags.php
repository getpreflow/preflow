<?php

declare(strict_types=1);

namespace Preflow\Core\PageCache;

/**
 * The tags pages depend on and writes invalidate, for data read through the data layer.
 *
 * What a page depends on (recorded while it renders):
 *   {storage}:{table}        a listing of the table (a query, an aggregate, a raw read naming it)
 *   {storage}:{table}#{id}   one record
 *   {storage}:{table}#       any record of the table: recorded with every record tag, so that a
 *                            change of many records (a bulk write) reaches the record pages too
 *   {storage}:*              something of the storage it could not tell (a raw read with a CTE)
 *
 * What a write invalidates (changes()):
 *   a record                 {storage}:{table}#{id}, {storage}:{table}, {storage}:*
 *   a table (bulk, raw)      {storage}:{table}, {storage}:{table}#, {storage}:*
 *   unknown ({storage}:*)    * (TagIndex::EVERYTHING): a raw write whose tables are unsure
 *
 * Exact tags start with '~' ("~address:/jobs/carpenter"): read and changed as they are, never
 * expanded (address() is Folio's: the page at a public path).
 *
 * {storage} is the driver name ('default'...), {table} the table as the driver sees it, lowercased
 * ("object__published" for a published copy), so a raw read of `object__published` and a write of
 * its published records meet. Other tags (no colon: "menu") are a site's own and pass as they are.
 */
final class DataTags
{
    public static function listing(string $storage, string $table): string
    {
        return $storage . ':' . strtolower($table);
    }

    public static function record(string $storage, string $table, string $id): string
    {
        return $storage . ':' . strtolower($table) . '#' . $id;
    }

    public static function anyRecord(string $storage, string $table): string
    {
        return $storage . ':' . strtolower($table) . '#';
    }

    public static function storage(string $storage): string
    {
        return $storage . ':*';
    }

    /**
     * The page at the public path $path (as the router sees it, without a locale prefix):
     * decoded and lowercased (multibyte when mbstring is there), so "/Jobs%20A" and "/jobs a" are
     * one address, in every locale. Accents are kept: a database collation that matches "café"
     * to "cafe" finds a record whose address tag differs (its record tag still counts).
     */
    public static function address(string $path): string
    {
        $path = '/' . trim(rawurldecode($path), '/');

        $path = function_exists('mb_strtolower') ? mb_strtolower($path) : strtolower($path);

        return '~address:' . str_replace('#', '%23', $path);
    }

    /** Whether $tag is an exact tag (address()): never expanded. */
    public static function exact(string $tag): bool
    {
        return str_starts_with($tag, '~');
    }

    /**
     * The tags a page records for a read of $tag: a record also records "any record of its table".
     *
     * @return list<string>
     */
    public static function dependencies(string $tag): array
    {
        if (self::exact($tag)) {
            return [$tag];
        }
        $hash = strpos($tag, '#');
        if ($hash === false || $hash === strlen($tag) - 1 || !str_contains(substr($tag, 0, $hash), ':')) {
            return [$tag];
        }

        return [$tag, substr($tag, 0, $hash + 1)];
    }

    /**
     * The tags invalidated when $tag changed: a record, a table (bulk), unknown, or a site tag.
     *
     * @return list<string>
     */
    public static function changes(string $tag): array
    {
        if ($tag === TagIndex::EVERYTHING) {
            return [TagIndex::EVERYTHING];
        }
        if (self::exact($tag)) {
            return [$tag];
        }
        $colon = strpos($tag, ':');
        if ($colon === false) {
            return [$tag];
        }
        $storage = substr($tag, 0, $colon);
        $rest = substr($tag, $colon + 1);
        if ($rest === '*') {
            return [TagIndex::EVERYTHING];
        }
        $hash = strpos($rest, '#');
        if ($hash === false || $hash === strlen($rest) - 1) {
            $table = $hash === false ? $rest : substr($rest, 0, $hash);

            return self::tableChanged($storage, $table);
        }

        return self::recordChanged($storage, substr($rest, 0, $hash), substr($rest, $hash + 1));
    }

    /** @return list<string> */
    public static function recordChanged(string $storage, string $table, string $id): array
    {
        return [self::record($storage, $table, $id), self::listing($storage, $table), self::storage($storage)];
    }

    /** @return list<string> */
    public static function tableChanged(string $storage, string $table): array
    {
        return [self::listing($storage, $table), self::anyRecord($storage, $table), self::storage($storage)];
    }
}
