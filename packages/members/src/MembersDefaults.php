<?php

declare(strict_types=1);

namespace Preflow\Members;

/**
 * config/members.php defaults a package gives the sites it runs on, between the site's file
 * (which wins) and the core's own: Folio sets its frontend layout, so the member pages of a Folio
 * site keep the site's page layout. Bound as an instance before the app boots its providers.
 */
final readonly class MembersDefaults
{
    /** @param array<string, mixed> $config keys as in config/members.php */
    public function __construct(
        public array $config,
    ) {}
}
