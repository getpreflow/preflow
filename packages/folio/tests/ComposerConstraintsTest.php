<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests;

use PHPUnit\Framework\TestCase;

/**
 * "^0.26 || @dev" in require matches any version (@dev is anything), so only "conflict" keeps
 * Composer from pairing Folio with an auth or data release that lacks the APIs it calls.
 */
final class ComposerConstraintsTest extends TestCase
{
    public function test_conflicts_with_auth_and_data_before_0_26(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame(['preflow/auth' => '<0.26', 'preflow/data' => '<0.26'], $composer['conflict'] ?? null);
    }
}
