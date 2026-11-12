<?php

declare(strict_types=1);

namespace Preflow\Rbac\Console;

use Preflow\DevTools\Command\CommandInterface;

/**
 * @deprecated since 0.69, use "staff:import-config" (ImportConfigStaffCommand).
 * folio:staff:import-config still works for one release: it prints a note and runs staff:import-config.
 */
final class ImportConfigStaffAliasCommand implements CommandInterface
{
    use RbacCommand;

    public function getName(): string { return 'folio:staff:import-config'; }
    public function getDescription(): string { return 'Deprecated: use staff:import-config'; }

    public function execute(array $args): int
    {
        echo "Note: \"folio:staff:import-config\" is deprecated; use \"staff:import-config\".\n";

        return (new ImportConfigStaffCommand($this->appFactory))->execute($args);
    }
}
