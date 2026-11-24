<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** The app's code says what a call does (hd.post with neutral options), never how a driver does it. */
final class TemplatesTest extends TestCase
{
    public function test_no_htmx_attributes_in_templates(): void
    {
        $app = dirname(__DIR__, 2) . '/app';
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($app, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            $text = (string) file_get_contents($file->getPathname());
            foreach (['hx-', 'htmx-', 'SwapStrategy'] as $needle) {
                if (str_contains($text, $needle)) {
                    $found[] = substr($file->getPathname(), strlen($app) + 1) . ': ' . $needle;
                }
            }
        }

        $this->assertSame([], $found);
    }
}
