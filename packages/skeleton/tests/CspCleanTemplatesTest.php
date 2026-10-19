<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Preflow\DevTools\CspInspector;

/**
 * The skeleton ships with Preflow's strict CSP on, so its own pages and
 * components must not use inline handlers, style attributes or un-nonced tags.
 */
final class CspCleanTemplatesTest extends TestCase
{
    public function test_skeleton_templates_are_csp_clean(): void
    {
        $root = dirname(__DIR__) . '/app';
        $problems = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'twig') {
                continue;
            }
            foreach (CspInspector::issues((string) file_get_contents($file->getPathname()), null) as $issue) {
                $problems[] = substr($file->getPathname(), strlen($root) + 1) . ': ' . $issue;
            }
        }

        $this->assertSame([], $problems, "CSP-unsafe markup in the skeleton:\n" . implode("\n", $problems));
    }
}
