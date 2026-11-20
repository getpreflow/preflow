<?php

declare(strict_types=1);

namespace Preflow\Codes\Tests;

use PHPUnit\Framework\TestCase;

/**
 * "^0.37 || @dev" in require matches any version (@dev is anything), so only "conflict" keeps
 * Composer from pairing codes with a release that lacks the APIs it calls: core 0.38 (ensureTypeRegistry, Container::bound, RecordTableSync), data 0.66.1 (Dialect::wholeNumber() for the use counting; since 0.42 RecordTables, unique fields and the ValidationException they raise, typed columns), validation 0.26 (ValidationException).
 * Since 0.81: core 0.81.0 (Context\Contexts::clock(), the render's clock that expiry and redemption times are read from);
 * data 0.81.0 (DataManager::updateIf(), the conditional writes behind a redeem, a revoke and an update, which follow
 * the write policy the same on every storage).
 * Folio is not required (it carries the codes admin and conflicts with codes before 0.40).
 */
final class ComposerConstraintsTest extends TestCase
{
    public function test_conflicts_with_releases_before_the_apis_it_calls(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame([
            'preflow/core' => '<0.81.0',
            'preflow/data' => '<0.81.0',
            'preflow/folio' => '<0.40',
        ], $composer['conflict'] ?? null);
        $this->assertSame('^0.81.0 || @dev', $composer['require']['preflow/data'] ?? null);
        $this->assertSame('^0.81.0 || @dev', $composer['require']['preflow/core'] ?? null);
        $this->assertArrayHasKey('preflow/validation', $composer['require']);
    }
}
