<?php

declare(strict_types=1);

namespace Preflow\Codes\Tests;

use PHPUnit\Framework\TestCase;

/**
 * "^0.37 || @dev" in require matches any version (@dev is anything), so only "conflict" keeps
 * Composer from pairing codes with a release that lacks the APIs it calls: folio 0.37
 * (#[FolioExtension(translations: …)], attribute labels as keys, folio_date()), i18n 0.37 (the admin-scoped
 * codes group).
 */
final class ComposerConstraintsTest extends TestCase
{
    public function test_conflicts_with_releases_before_the_apis_it_calls(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame(
            ['preflow/folio' => '<0.37', 'preflow/i18n' => '<0.37'],
            $composer['conflict'] ?? null,
        );
        foreach (['preflow/folio', 'preflow/i18n'] as $package) {
            $this->assertSame('^' . substr($composer['conflict'][$package], 1) . ' || @dev', $composer['require'][$package] ?? null, $package);
        }
    }
}
