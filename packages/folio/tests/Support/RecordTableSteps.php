<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Support;

use Preflow\Core\Application;
use Preflow\Data\Migration\MigrationRegistry;

/**
 * The tables `preflow migrate` makes for Folio content: preflow/publishing's step (the tables of
 * publishing types) and Folio's (the rest, and its internal tables), as one list of lines.
 */
final class RecordTableSteps
{
    /** @return list<string> */
    public static function run(Application $app): array
    {
        $steps = $app->container()->get(MigrationRegistry::class)->steps();
        $lines = [];
        foreach (['publishing.tables', 'folio.tables'] as $name) {
            array_push($lines, ...(($steps[$name] ?? throw new \RuntimeException("step {$name} not registered"))()));
        }

        return $lines;
    }
}
