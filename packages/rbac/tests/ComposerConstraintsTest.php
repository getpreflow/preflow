<?php

declare(strict_types=1);

namespace Preflow\Rbac\Tests;

use PHPUnit\Framework\TestCase;

/**
 * "^0.27 || @dev" in require matches any version (@dev is anything), so only "conflict" keeps
 * Composer from pairing rbac with a release that lacks the APIs it calls: auth 0.69 (AccountLinks, StaffAccounts' password links;
 * since 0.65 ListsRoles,
 * which RbacResolver implements; since 0.27 Access::population(), ConfigUserProvider::entries(), the chain provider), core 0.62 (builds
 * chains from config/auth.php; Application::configFile()), data 0.69 (DataManager::hasTable(), whether rbac is installed), i18n 0.37 (translation scopes,
 * the translator's locale as the default of Messages::get()), notify 0.69 (MailText::siteName(); since 0.68 the Mailer StaffMail
 * composes with). rbac needs no Folio: Folio carries its admin (the built-in extension "rbac"), and a Folio before 0.69 still
 * expects rbac's own extension, field types and texts, so it conflicts. devtools runs the commands where it is installed
 * (suggested, not required; a devtools before 0.26 conflicts: its command interface differs).
 */
final class ComposerConstraintsTest extends TestCase
{
    public function test_conflicts_with_releases_before_the_apis_it_calls(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame(
            ['preflow/auth' => '<0.69', 'preflow/core' => '<0.62', 'preflow/data' => '<0.69', 'preflow/devtools' => '<0.26', 'preflow/folio' => '<0.69', 'preflow/i18n' => '<0.37', 'preflow/notify' => '<0.69'],
            $composer['conflict'] ?? null,
        );
        foreach (array_diff_key($composer['conflict'], ['preflow/folio' => true, 'preflow/devtools' => true]) as $package => $below) {
            $this->assertSame('^' . substr($below, 1) . ' || @dev', $composer['require'][$package] ?? null, $package);
        }
    }

    public function test_requires_no_admin_and_no_console(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        foreach (['preflow/folio', 'preflow/devtools'] as $package) {
            $this->assertArrayNotHasKey($package, $composer['require'], $package);
            $this->assertArrayHasKey($package, $composer['suggest'] ?? [], $package);
        }
        $this->assertArrayNotHasKey('folio', $composer['extra'] ?? [], 'Folio carries the rbac admin itself');
    }

    /** Folio is the admin's adapter: the package's code names none of it. */
    public function test_the_source_names_no_folio_class(): void
    {
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__) . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            foreach (file((string) $file) ?: [] as $i => $line) {
                if (str_contains($line, 'Preflow\\Folio')) {
                    $found[] = basename((string) $file) . ':' . ($i + 1);
                }
            }
        }

        $this->assertSame([], $found);
    }
}
