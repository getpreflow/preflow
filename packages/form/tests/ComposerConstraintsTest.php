<?php

declare(strict_types=1);

namespace Preflow\Form\Tests;

use PHPUnit\Framework\TestCase;

/**
 * "^0.86.0 || @dev" in require matches any version (@dev is anything), so only "conflict" keeps
 * Composer from pairing form with a view release that lacks what it calls.
 * Since 0.87: view 0.86.0 (SafeHtml, which FieldRenderer and FieldCall take for a field's short call,
 * form.field(name, {call: hd.post(...)}); view 0.86.0 added it, and with an older view the class is missing).
 */
final class ComposerConstraintsTest extends TestCase
{
    public function test_requires_view_with_the_apis_it_calls(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame('^0.86.0 || @dev', $composer['require']['preflow/view'] ?? null);
        $this->assertSame(['preflow/view' => '<0.86.0'], $composer['conflict'] ?? null);
    }
}
