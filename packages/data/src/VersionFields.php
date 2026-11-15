<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * Which types keep a version field (a whole number that counts their content changes): a save of
 * such a record through DataManager (saveType(), and save()/insert()/update() of a class-backed
 * type) that changes a value writes it as the stored version + 1, so a content change is never
 * stored under an old version; a save that changes nothing but nonContent() keys keeps it (an
 * import re-saving unchanged records moves nothing on).
 * The checked writes (saveTypeIfVersion(), updateFieldsIfVersion()) write the version they are
 * given. Writes past DataManager (raw SQL through execute(), a driver called directly) are out of
 * its reach. preflow/publishing gives `_version` to the working copies of the types that publish.
 *
 * @since 0.77
 */
interface VersionFields
{
    /** The version field of records of $def (its own table), or null when it keeps none. */
    public function of(TypeDefinition $def): ?string;

    /**
     * Keys of records of $def that are no content: a save that changes only these (and the version
     * field) keeps the version.
     *
     * @return list<string>
     */
    public function nonContent(TypeDefinition $def): array;
}
