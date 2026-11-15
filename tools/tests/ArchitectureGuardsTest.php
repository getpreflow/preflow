<?php

declare(strict_types=1);

namespace Preflow\Tools\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Architecture guards across the whole monorepo. Each test is one guard; its name is the id that
 * principle records cite as their enforcement.
 *
 * architecture.no-folio-in-core: the packages run without Folio. No package's source names the
 * Preflow\Folio namespace, except preflow/folio itself and the packages whose composer.json
 * *requires* preflow/folio (today: starter-folio). A package that only suggests Folio (members,
 * rbac) is core and stays Folio-free; it offers Folio integration through its own public
 * interfaces that Folio binds. The source of a package is every directory its composer.json maps
 * under autoload.psr-4 (src/ for libraries, app/ for the starters and the skeleton), all files.
 * The packages' own NoFolioDependency tests stay: they ship with each split repository and also
 * pin that package's composer shape; this guard is the single complete one across all packages.
 *
 * mail.no-folio-name: no mail says "Folio" (a recipient never sees the admin's product name). The
 * mail words live in two places, and both are checked in every package and locale:
 *  - mail Twig templates: every *.twig below a templates/mail/ directory of a package (today
 *    notify's templates/mail/layout.twig, the HTML part of every composed mail); Twig comments
 *    are removed first, since they never reach a mail;
 *  - mail texts in the lang files: the whole of every packages/{pkg}/lang/{locale}/*mail*.php
 *    file (rbac's rbac-mail.php) and the 'mail' key of every other lang file (folio.php's
 *    folio.mail.*, members.php's members.mail.*).
 * Mail-sending classes (MemberMailer, StaffMail, Folio's StaffMailer, notify's BasicMailLayout)
 * take every word from these keys and add markup only, so their PHP is not scanned.
 */
final class ArchitectureGuardsTest extends TestCase
{
    private const FOLIO = 'preflow/folio';

    private static function packages(): string
    {
        return dirname(__DIR__, 2) . '/packages';
    }

    public function test_architecture_no_folio_in_core(): void
    {
        $hits = [];
        $scanned = [];
        $exempt = [];
        foreach (glob(self::packages() . '/*/composer.json') ?: [] as $composerFile) {
            $dir = dirname($composerFile);
            $composer = json_decode((string) file_get_contents($composerFile), true, flags: JSON_THROW_ON_ERROR);
            if (($composer['name'] ?? null) === self::FOLIO || isset($composer['require'][self::FOLIO])) {
                $exempt[] = basename($dir);
                continue;
            }
            $roots = array_values((array) ($composer['autoload']['psr-4'] ?? []));
            $roots = $roots === [] ? ['src/'] : $roots;
            foreach ($roots as $root) {
                foreach ((array) $root as $path) {
                    $sourceDir = $dir . '/' . rtrim($path, '/');
                    if (!is_dir($sourceDir)) {
                        continue;
                    }
                    $scanned[basename($dir)] = true;
                    foreach ($this->files($sourceDir) as $file) {
                        foreach (file($file) ?: [] as $n => $line) {
                            if (preg_match('/Preflow\\\\{1,2}Folio\b/', $line) === 1) {
                                $hits[] = substr($file, strlen(self::packages()) + 1) . ':' . ($n + 1) . ': ' . trim($line);
                            }
                        }
                    }
                }
            }
        }

        sort($exempt);
        $this->assertSame(['folio', 'starter-folio'], $exempt, 'the packages allowed to name Folio');
        $this->assertArrayHasKey('core', $scanned);
        $this->assertArrayHasKey('members', $scanned, 'suggesting Folio does not exempt a package');
        $this->assertArrayHasKey('starter', $scanned, 'the starters are scanned by their app/ source');
        $this->assertSame([], $hits, 'core packages must not name Preflow\\Folio');
    }

    public function test_mail_no_folio_name(): void
    {
        $hits = [];
        $sources = [];

        foreach (glob(self::packages() . '/*/templates/mail') ?: [] as $mailDir) {
            foreach ($this->files($mailDir) as $file) {
                if (!str_ends_with($file, '.twig')) {
                    continue;
                }
                $sources[] = $this->relative($file);
                $text = (string) preg_replace('/\{#.*?#\}/s', '', (string) file_get_contents($file));
                if (preg_match('/\bfolio\b/i', $text) === 1) {
                    $hits[] = $this->relative($file);
                }
            }
        }

        foreach (glob(self::packages() . '/*/lang/*/*.php') ?: [] as $file) {
            $texts = require $file;
            if (!is_array($texts)) {
                continue;
            }
            $prefix = basename($file, '.php');
            if (!str_contains($prefix, 'mail')) {
                if (!isset($texts['mail'])) {
                    continue;
                }
                $texts = ['mail' => $texts['mail']];
            }
            $sources[] = $this->relative($file);
            array_walk_recursive($texts, function (mixed $value, int|string $key) use (&$hits, $file): void {
                if (is_string($value) && preg_match('/\bfolio\b/i', $value) === 1) {
                    $hits[] = $this->relative($file) . ' [' . $key . ']: ' . $value;
                }
            });
        }

        foreach (['notify/templates/mail/layout.twig', 'rbac/lang/en/rbac-mail.php', 'folio/lang/en/folio.php', 'members/lang/en/members.php'] as $known) {
            $this->assertContains($known, $sources, 'a known mail source is checked');
        }
        $this->assertSame([], $hits, 'mails must not say Folio');
    }

    /** @return list<string> every file below $dir */
    private function files(string $dir): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    private function relative(string $file): string
    {
        return substr($file, strlen(self::packages()) + 1);
    }
}
