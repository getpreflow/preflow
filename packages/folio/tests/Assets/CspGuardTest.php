<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Assets;

use PHPUnit\Framework\TestCase;

/**
 * Folio's admin must work under the strict CSP: no inline event handlers, no
 * style attributes, and every inline <script>/<style> carries a nonce.
 * Guards the templates and the PHP that builds editor HTML.
 */
final class CspGuardTest extends TestCase
{
    public function test_admin_templates_have_no_inline_handlers_or_styles(): void
    {
        $root = dirname(__DIR__, 2);
        $files = array_merge(
            $this->files($root . '/templates', 'twig'),
            $this->files($root . '/src', 'php'),
        );

        $violations = [];
        foreach ($files as $file) {
            foreach (file($file) ?: [] as $i => $line) {
                foreach ($this->problems($line) as $problem) {
                    $violations[] = sprintf('%s:%d %s', substr($file, strlen($root) + 1), $i + 1, $problem);
                }
            }
        }

        $this->assertSame([], $violations, "CSP-unsafe markup:\n" . implode("\n", $violations));
    }

    /** @return list<string> */
    private function problems(string $line): array
    {
        $problems = [];

        if (preg_match('/<[a-z][^>]*\son[a-z]+\s*=/i', $line) === 1) {
            $problems[] = 'inline event handler';
        }
        if (preg_match('/<[a-z][^>]*\sstyle\s*=/i', $line) === 1) {
            $problems[] = 'style attribute';
        }
        if (preg_match_all('/<script\b[^>]*>/i', $line, $tags) > 0) {
            foreach ($tags[0] as $tag) {
                if (!preg_match('/\b(nonce|src)\s*=|type="application\/json"/i', $tag)) {
                    $problems[] = 'script without nonce';
                }
            }
        }
        if (preg_match_all('/<style\b[^>]*>/i', $line, $tags) > 0) {
            foreach ($tags[0] as $tag) {
                if (!preg_match('/\bnonce\s*=/i', $tag)) {
                    $problems[] = 'style without nonce';
                }
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function files(string $dir, string $ext): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === $ext) {
                $out[] = $file->getPathname();
            }
        }
        sort($out);

        return $out;
    }
}
