<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Since 0.93.0: folio 0.93.0 (Folio before 0.93 has its own StoredDate class: its form would not find the core's date
 * rule to put its input rule in its place).
 * Since 0.92.0: validation 0.92.0 (validation.options_from, the message of a select's "options_from" the core checks
 * now; WhenFilled around a select's options); folio 0.92.0 (Folio before 0.92 adds its own select option rules, which
 * the core now runs for every select: each message would show twice).
 * Since 0.81: core 0.81.0 (Context\Writes and WritePolicy: every write entry point asks the render's write policy).
 * Since 0.80: devtools 0.80 (db:seed honours #[Seed(debugOnly: true)]; an older devtools would run demo seeders with app.debug off).
 * Since 0.78: core 0.78 (the 0.78 upgrade path runs through core: Application registers DeclaredTables and the migrate steps, Throttle
 * counts through incrementIn() with exact columns, and a JSON storage entry reads 'cache' => false).
 * Since 0.77: core 0.77 (Activity\ActivityLog, which StoredActivityLog implements, and BootManifest::isRecording(), which TypeRegistry reads).
 *
 * "^0.52 || @dev" in require matches any version (@dev is anything), so only "conflict" keeps
 * Composer from pairing data with a validation release that lacks the texts it raises
 * (validation.unique, shipped in validation 0.42; Slug::clean() for Slugs, in 0.52), or with a core release before 0.58, which lacks
 * Config\ParsedJsonFiles (TypeRegistry reads model files through it), or with a folio release before 0.67, which still reads the
 * TypeDefinition's matrixable and views that 0.67 dropped, or with a publishing release before 0.72, whose shadow
 * tables mark translatable unique fields as indexed (data 0.72 warns about those on every migrate).
 */
final class ComposerConstraintsTest extends TestCase
{
    public function test_conflicts_with_validation_before_its_unique_text(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame(['preflow/core' => '<0.84.0', 'preflow/validation' => '<0.92.0', 'preflow/folio' => '<0.93.0', 'preflow/publishing' => '<0.72', 'preflow/devtools' => '<0.80'], $composer['conflict'] ?? null);
        $this->assertSame('^0.84.0 || @dev', $composer['require']['preflow/core'] ?? null);
        $this->assertSame('^0.92.0 || @dev', $composer['require']['preflow/validation'] ?? null);
    }
}
