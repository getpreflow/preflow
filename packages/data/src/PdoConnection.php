<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * A SQL storage that is backed by a PDO connection and hands it out, for code that needs the
 * connection itself: migrations, Migration\Schema, Grammars::for(). Everything else should use
 * RunsSql through DataManager::raw() and execute().
 *
 * @since 0.78
 */
interface PdoConnection extends RunsSql
{
    public function pdo(): \PDO;
}
