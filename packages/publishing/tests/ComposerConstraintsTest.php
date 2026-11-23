<?php

declare(strict_types=1);

namespace Preflow\Publishing\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Since 0.81: core 0.81 (Contexts, RenderContext's read mode and clock, Deprecations: LiveContent
 * reads the current render's read mode, the publish:scheduled command its console context's now,
 * the clock seams the render's clock); devtools 0.81 (its console runs every command in a console frame:
 * publish:scheduled reads Contexts::current(), which throws without one, so no scheduled record would publish).
 * Since 0.82.0: data 0.82.0 (TypeDefinition's idKind, which ShadowTypes carries to the version-column view and clears on the published copy).
 * Since 0.78: data 0.78 (TypeFieldDefinition's exact, which ShadowTypes carries to the published copy and Publisher gives the version column).
 * Since 0.77: core 0.77 (Activity\ActivityLog and ActivityActors, which ActivityNotes, Publisher and PublishActors use); data 0.77 (RecordTitle, which ActivityNotes uses; VersionFields, which PublishingVersionFields implements; updateFieldsIfVersion(), which Publisher calls). Since 0.77.1: data 0.77.1 (MigrationRegistry::afterMigrate()'s sqlOnly, which publishing.published_versions passes).
 *
 * "^0.70 || @dev" in require matches any version (@dev is anything), so only "conflict" keeps
 * Composer from pairing publishing with a release that lacks the APIs it calls: core 0.70
 * (Application::configFile() and hasConfigFile(), the service providers), data 0.72
 * (DataManager::saveTypeIfVersion(), validateStorable(), findAs()/queryAs(), TypeRegistry::schema()
 * and keys(), RecordTables; TypeDefinition::$indexes for the published tables), validation 0.70 (ValidationException, ValidationResult), devtools 0.26
 * (CommandInterface, extra.preflow.commands) for the commands. Folio is not required: it binds its rules
 * and registers this package itself since 0.71; folio 0.72 is the conflict, because Folio 0.71 extends Publisher and
 * RecordDeleter, which are final again since 0.72, and a Folio before 0.71 builds its own Publisher and LiveContent.
 * testing 0.83.0 since 0.83: the isolation contract before 0.83 calls the removed LiveContent::showWorkingCopies().
 */
final class ComposerConstraintsTest extends TestCase
{
    public function test_conflicts_with_releases_before_the_apis_it_calls(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame([
            'preflow/core' => '<0.81.0',
            'preflow/data' => '<0.84.0',
            'preflow/devtools' => '<0.81.0',
            'preflow/folio' => '<0.72',
            'preflow/testing' => '<0.83.0',
            'preflow/validation' => '<0.70',
        ], $composer['conflict'] ?? null);
        foreach (['preflow/core', 'preflow/data', 'preflow/validation'] as $package) {
            $this->assertSame('^' . substr($composer['conflict'][$package], 1) . ' || @dev', $composer['require'][$package] ?? null, $package);
        }
    }
}
