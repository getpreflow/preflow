<?php

declare(strict_types=1);

namespace Preflow\Media\Tests;

use PHPUnit\Framework\TestCase;

/**
 * "^0.54 || @dev" in require matches any version (@dev is anything), so only "conflict" keeps
 * Composer from pairing media with a release that lacks what it calls: core 0.54 (InternalRender,
 * honoured by VariantController), data 0.76 (a JSON-stored locale map without the queried locale reads as null, which
 * the missing-alt query needs; since 0.60 QueryBuilder::byIds(), since 0.42 RecordTables) and routing 0.59
 * (Router::compiled(), the routes compiled into the boot manifest) and view 0.67 (PdfImageSource, which media binds); i18n is suggested, and a release before 0.47 lacks the text sources (TextSource, TranslationUnit, WriteOutcome with leftAlone) that AltTextSource implements.
 */
final class ComposerConstraintsTest extends TestCase
{
    public function test_conflicts_with_releases_before_the_apis_it_calls(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame(
            ['preflow/core' => '<0.54', 'preflow/data' => '<0.76', 'preflow/i18n' => '<0.47', 'preflow/routing' => '<0.59', 'preflow/view' => '<0.67'],
            $composer['conflict'] ?? null,
        );
        // Each conflict matches its require's lower bound; i18n is only suggested.
        $this->assertArrayNotHasKey('preflow/i18n', $composer['require']);
        foreach (array_diff_key($composer['conflict'], ['preflow/i18n' => true]) as $package => $below) {
            $this->assertSame('^' . substr($below, 1) . ' || @dev', $composer['require'][$package] ?? null, $package);
        }
        $this->assertArrayHasKey('preflow/i18n', $composer['suggest'] ?? []);
    }
}
