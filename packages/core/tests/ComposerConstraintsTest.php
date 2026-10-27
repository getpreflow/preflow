<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Core requires neither auth nor data, but boots them when they are installed, so only "conflict"
 * keeps Composer from pairing core with a release that lacks the APIs it calls: auth 0.38
 * (bootAuth binds Preflow\Auth\Record\RecordUserProvider::factory() for record users from
 * config/auth.php), data 0.28 (Application passes cacheDir: to JsonFileDriver).
 */
final class ComposerConstraintsTest extends TestCase
{
    public function test_conflicts_with_releases_before_the_apis_it_calls(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame(['preflow/auth' => '<0.38', 'preflow/data' => '<0.28'], $composer['conflict'] ?? null);
    }
}
