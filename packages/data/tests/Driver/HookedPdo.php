<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Driver;

/** A PDO that runs a hook before each prepare(): tests pause a driver or fail a statement with it. */
final class HookedPdo extends \PDO
{
    /** @var (\Closure(string): void)|null */
    public ?\Closure $beforePrepare = null;

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        if ($this->beforePrepare !== null) {
            ($this->beforePrepare)($query);
        }

        return parent::prepare($query, $options);
    }
}
