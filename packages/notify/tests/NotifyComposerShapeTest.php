<?php

declare(strict_types=1);

namespace Preflow\Notify\Tests;

use PHPUnit\Framework\TestCase;

/**
 * notify stays free of core: it requires only PHP, and its source names no class of preflow/core.
 * Core joins notify through notify's own seams (Deferred\DeferredQueue, Mail\ComposeScope), not
 * the other way round.
 */
final class NotifyComposerShapeTest extends TestCase
{
    public function test_notify_requires_only_php(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);

        $this->assertSame(['php' => '>=8.4'], $composer['require'] ?? null);

        $named = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__) . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (str_ends_with($file->getPathname(), '.php') && preg_match('/Preflow\\\\+Core\\\\/', (string) file_get_contents($file->getPathname())) === 1) {
                $named[] = $file->getFilename();
            }
        }
        $this->assertSame([], $named, 'notify\'s source names no core class');
    }
}
