<?php

declare(strict_types=1);

namespace Preflow\Fields\Tests;

use PHPUnit\Framework\TestCase;

/**
 * "^0.91.0 || @dev" in require matches any version (@dev is anything), so only "conflict" keeps
 * Composer from pairing fields with a release that lacks the APIs it calls:
 * Since 0.91.0 (the package's first release): data 0.91.0 (FieldKinds::rules(), with which the link
 * field's check runs on every write of a typed record); validation 0.91.0 (Rules\Phone and the
 * validation.phone and validation.link.* messages); view 0.91.0 (raised with the release; the
 * template functions are TemplateFunctionDefinitions); core 0.84.0 (Context\Contexts::peek(), the
 * render's locale the template functions read; the floor data 0.91 needs).
 */
final class ComposerConstraintsTest extends TestCase
{
    public function test_conflicts_with_releases_before_the_apis_it_calls(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame([
            'preflow/core' => '<0.84.0',
            'preflow/data' => '<0.91.0',
            'preflow/validation' => '<0.91.0',
            'preflow/view' => '<0.91.0',
        ], $composer['conflict'] ?? null);
        foreach ($composer['conflict'] as $package => $below) {
            $this->assertSame('^' . substr($below, 1) . ' || @dev', $composer['require'][$package] ?? null, $package);
        }
    }
}
