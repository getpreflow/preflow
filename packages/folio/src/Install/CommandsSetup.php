<?php

declare(strict_types=1);

namespace Preflow\Folio\Install;

use Preflow\Folio\Extension\ExtensionException;
use Preflow\Folio\Extension\ExtensionsFile;

/**
 * Spec step 8: `migrate`, `rbac:install`, `folio:extensions:enable rbac` and
 * `folio:extensions:apply`, each in a fresh PHP process (AppConsole), so the app boots with the
 * config files written before. All are safe to repeat; enabling rbac is skipped when
 * config/folio/extensions.json lists it already, so a second run leaves that file as it is. The
 * first command that fails stops the rest.
 *
 * Folio's and rbac's commands need no config/commands.php line: they register from their
 * packages' composer.json (extra.preflow.commands).
 *
 * @since 0.85.0
 */
final class CommandsSetup
{
    public function __construct(
        private readonly Setup $setup,
    ) {}

    /** @return list<Outcome> */
    public function run(): array
    {
        $out = [];
        foreach ([['migrate'], ['rbac:install'], ['folio:extensions:enable', 'rbac'], ['folio:extensions:apply']] as $command) {
            $subject = 'php preflow ' . implode(' ', $command);
            if ($command[0] === 'folio:extensions:enable' && $this->rbacEnabled()) {
                $out[] = new Outcome(Outcome::ALREADY, $subject, 'Folio\'s rbac extension is enabled (config/folio/extensions.json)');
                continue;
            }
            if ($this->setup->dryRun) {
                $out[] = new Outcome(Outcome::PLANNED, $subject, 'run it');
                continue;
            }
            [$code, $output] = $this->setup->console->run($command[0], array_slice($command, 1));
            if ($code !== 0) {
                $out[] = new Outcome(Outcome::FAILED, $subject, "it exited with {$code}; run it by hand once the cause is fixed, then run folio:install again", output: $output);

                return $out;
            }
            $out[] = new Outcome(Outcome::RAN, $subject, '', output: $output);
        }

        return $out;
    }

    private function rbacEnabled(): bool
    {
        try {
            return isset((new ExtensionsFile($this->setup->root . '/config/folio/extensions.json'))->read()['rbac']);
        } catch (ExtensionException) {
            return false; // the command says what is wrong with the file
        }
    }
}
