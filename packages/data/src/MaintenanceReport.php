<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * A Maintenance store that also tells what its check could not do or found worth knowing: lines
 * for the operator, not problems (they never make `preflow db:check` fail).
 *
 * Optional: Maintenance itself stays as it was. A store lists what it has no equivalent of as
 * "not applicable: …", so an operator never takes a skipped check for a passed one.
 *
 * @since 0.84.0
 */
interface MaintenanceReport extends Maintenance
{
    /**
     * @return list<string>
     */
    public function report(): array;
}
