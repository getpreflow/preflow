<?php

declare(strict_types=1);

namespace Preflow\Media\Tests;

use PHPUnit\Framework\TestCase;

/** The media core stands alone: Folio depends on it, never the other way round. */
final class NoFolioDependencyTest extends TestCase
{
    public function test_no_source_file_references_folio(): void
    {
        $hits = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__) . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            if (str_contains($code, 'Preflow\Folio') || str_contains($code, 'Preflow\\\\Folio')) {
                $hits[] = $file->getPathname();
            }
        }

        $this->assertSame([], $hits);
    }
}
