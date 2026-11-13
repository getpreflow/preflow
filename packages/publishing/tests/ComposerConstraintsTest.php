<?php

declare(strict_types=1);

namespace Preflow\Publishing\Tests;

use PHPUnit\Framework\TestCase;

/**
 * "^0.70 || @dev" in require matches any version (@dev is anything), so only "conflict" keeps
 * Composer from pairing publishing with a release that lacks the APIs it calls: core 0.70
 * (Application::configFile() and hasConfigFile(), the service providers), data 0.72
 * (DataManager::saveTypeIfVersion(), validateStorable(), findAs()/queryAs(), TypeRegistry::schema()
 * and keys(), RecordTables; TypeDefinition::$indexes for the published tables), validation 0.70 (ValidationException, ValidationResult), devtools 0.26
 * (CommandInterface, extra.preflow.commands) for the commands. Folio is not required: it binds its rules
 * and registers this package itself since 0.71; folio 0.71 is the conflict, because a Folio before it builds its
 * own Publisher and LiveContent.
 */
final class ComposerConstraintsTest extends TestCase
{
    public function test_conflicts_with_releases_before_the_apis_it_calls(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame([
            'preflow/core' => '<0.70',
            'preflow/data' => '<0.72',
            'preflow/devtools' => '<0.26',
            'preflow/folio' => '<0.71',
            'preflow/validation' => '<0.70',
        ], $composer['conflict'] ?? null);
        foreach (['preflow/core', 'preflow/data', 'preflow/validation'] as $package) {
            $this->assertSame('^' . substr($composer['conflict'][$package], 1) . ' || @dev', $composer['require'][$package] ?? null, $package);
        }
    }
}
