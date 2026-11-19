<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * Marks a seeder class (app/Seeds) for `preflow db:seed`. With debugOnly, a full run skips the
 * seeder while app.debug is off (demo data never reaches production), and `--class=<Name>` runs it
 * there only with `--force`. A seeder without the attribute runs as before.
 *
 * @since 0.80.0
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Seed
{
    public function __construct(
        public bool $debugOnly = false,
    ) {}
}
