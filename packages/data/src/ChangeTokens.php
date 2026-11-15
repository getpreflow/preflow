<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * A storage that can say cheaply whether a type's records may have changed: a token that stays
 * the same while nothing writes the type through a driver and moves with every save or delete.
 * A reader keeps what it computed from the records with the token it read first and reuses it
 * while the token is the same (StoredActivityLog::people() does). The JSON driver gives its cache
 * generation (with a cache directory); SQL storage answers queries from its indexes and has no
 * need of it. DataManager::changeToken() is the way to ask.
 *
 * @since 0.77
 */
interface ChangeTokens extends StorageDriver
{
    /**
     * The type's current token, read before the records it guards; null when the storage cannot
     * tell now (then nothing may be reused).
     */
    public function changeToken(string $type): ?string;
}
