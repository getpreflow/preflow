<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests;

use PHPUnit\Framework\TestCase;

/**
 * "^0.27 || @dev" in require matches any version (@dev is anything), so only "conflict" keeps
 * Composer from pairing Folio with a release that lacks the APIs it calls: auth 0.27 (chain
 * provider, ClaimsCredentials, SessionGuard::remembers()), data 0.26, i18n 0.26
 * (Translator::addDefaults), notify 0.24 (Notifier::sendLater).
 */
final class ComposerConstraintsTest extends TestCase
{
    public function test_conflicts_with_releases_before_the_apis_it_calls(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame(
            ['preflow/auth' => '<0.27', 'preflow/data' => '<0.26', 'preflow/i18n' => '<0.26', 'preflow/notify' => '<0.24'],
            $composer['conflict'] ?? null,
        );
    }
}
