<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * Which types keep a version field (a whole number that counts their writes): every save of such a
 * record through DataManager (saveType(), and save()/insert()/update() of a class-backed type)
 * writes it as the stored version + 1, so a content change is never stored under an old version.
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
}
