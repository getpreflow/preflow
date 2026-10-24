<?php

declare(strict_types=1);

namespace Preflow\Rbac\Tests;

use PHPUnit\Framework\TestCase;

/**
 * "^0.27 || @dev" in require matches any version (@dev is anything), so only "conflict" keeps
 * Composer from pairing rbac with a release that lacks the APIs it calls: auth 0.27
 * (Access::population(), ConfigUserProvider::entries(), the chain provider), core 0.27 (builds
 * chains from config/auth.php), folio 0.27 (opt-in parts, StaffPasswords, SIGNED_IN), i18n 0.26
 * (Translator::addDefaults). devtools is not guarded: an older one only lacks the import command.
 */
final class ComposerConstraintsTest extends TestCase
{
    public function test_conflicts_with_releases_before_the_apis_it_calls(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame(
            ['preflow/auth' => '<0.27', 'preflow/core' => '<0.27', 'preflow/folio' => '<0.27', 'preflow/i18n' => '<0.26'],
            $composer['conflict'] ?? null,
        );
    }
}
