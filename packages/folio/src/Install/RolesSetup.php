<?php

declare(strict_types=1);

namespace Preflow\Folio\Install;

/**
 * Spec step 7, config/roles.php: a starting file with the staff roles admin (everything) and
 * editor (what an editor needs to get in and work). The editor's list comes from the app booted
 * with Folio, media and rbac in a fresh PHP process (ProbeCommand `editor`), after
 * config/providers.php lists them, so only declared permissions are written and rbac:install
 * reports none as undeclared. An existing config/roles.php is kept.
 *
 * @since 0.85.0
 */
final class RolesSetup
{
    public function __construct(
        private readonly Setup $setup,
    ) {}

    public function run(): Outcome
    {
        $subject = 'config/roles.php';
        if ($this->setup->exists('roles.php')) {
            return new Outcome(Outcome::ALREADY, $subject, 'the file is there; rbac:install seeds its roles');
        }
        if ($this->setup->dryRun) {
            return new Outcome(Outcome::PLANNED, $subject, 'write it: admin (everything) and editor (folio.dashboard, the asset library, "<type>.*" per content type; declared permissions only, read from the app once Folio is listed)');
        }
        $answer = $this->setup->console->probe('editor');
        $editor = $answer['editor'] ?? null;
        if (!is_array($editor)) {
            $error = is_string($answer['error'] ?? null) ? $answer['error'] : 'no answer';

            return new Outcome(Outcome::FAILED, $subject, "not written: the app did not boot with Folio to say which permissions it declares ({$error}). rbac:install seeds the admin role without it.");
        }
        $lines = '';
        foreach ($editor as $permission) {
            $lines .= "\n            " . Setup::literal((string) $permission) . ',';
        }
        $contents = Setup::template('roles.php', ['EDITOR' => $editor === [] ? '' : $lines . "\n        "]);

        return Outcome::fromEdit($this->setup->editor->create('roles.php', $contents), 'roles.php', 'admin (everything), editor (' . implode(', ', $editor) . ')');
    }
}
