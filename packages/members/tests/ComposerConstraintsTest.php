<?php

declare(strict_types=1);

namespace Preflow\Members\Tests;

use PHPUnit\Framework\TestCase;

/**
 * "^0.37 || @dev" in require matches any version (@dev is anything), so only "conflict" keeps
 * Composer from pairing members with a release that lacks the APIs it calls: folio 0.37
 * (#[FolioExtension(translations: …)], attribute labels as keys, DisplayLabels), i18n 0.37 (translation scopes:
 * the public members group and the admin members-admin group). auth and data keep their older bounds.
 */
final class ComposerConstraintsTest extends TestCase
{
    public function test_conflicts_with_releases_before_the_apis_it_calls(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame(
            ['preflow/auth' => '<0.26', 'preflow/data' => '<0.26', 'preflow/folio' => '<0.37', 'preflow/i18n' => '<0.37'],
            $composer['conflict'] ?? null,
        );
        foreach (['preflow/i18n'] as $package) {
            $this->assertSame('^' . substr($composer['conflict'][$package], 1) . ' || @dev', $composer['require'][$package] ?? null, $package);
        }
    }
}
