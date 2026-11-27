<?php

declare(strict_types=1);

namespace Preflow\Validation\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Since 0.81: core 0.81.0 (Deprecations, for the useTranslator() shim) and i18n 0.81.0 (the translator follows the
 * render context, so validation messages are in the context's language).
 *
 * "^0.37 || @dev" in require matches any version (@dev is anything), so only "conflict" keeps
 * Composer from pairing validation with a release that lacks the APIs it calls: i18n 0.37 (the
 * public-scoped validation group its Message texts come from).
 *
 * Since 0.92.1 i18n 0.92.1 (PluralResolver's three forms by the language, which Translator::choice()
 * passes: the Russian and Polish area limits have them; an older i18n picks 1 against the rest).
 * Since 0.92.1 it conflicts with blocks before 0.92.1, which it does not require: the area limits'
 * messages ("validation.layout.area_max", "area_min") have plural forms, which only a Message with
 * a count chooses; blocks before 0.92.1 gives none, so its messages would show both forms.
 */
final class ComposerConstraintsTest extends TestCase
{
    public function test_conflicts_with_releases_before_the_apis_it_calls(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame(
            ['preflow/core' => '<0.81.0', 'preflow/i18n' => '<0.92.1', 'preflow/blocks' => '<0.92.1'],
            $composer['conflict'] ?? null,
        );
        foreach (['preflow/core', 'preflow/i18n'] as $package) {
            $this->assertSame('^' . substr($composer['conflict'][$package], 1) . ' || @dev', $composer['require'][$package] ?? null, $package);
        }
    }
}
