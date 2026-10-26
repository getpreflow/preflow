<?php

declare(strict_types=1);

namespace Preflow\Members\Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\I18n\Console\TranslationsCheckCommand;
use Preflow\Members\Tests\Support\MembersScaffold;

/**
 * `preflow translations:check` on a site with Folio, rbac, members, codes and validation
 * installed (their real composer.json declarations): each of the packages' groups is complete
 * in en, de and fr, model labels and the extensions' settings types included.
 */
final class PackageTranslationsCheckTest extends TestCase
{
    use MembersScaffold;

    private const array PACKAGES = ['folio', 'rbac', 'members', 'codes', 'validation', 'i18n'];

    protected function tearDown(): void
    {
        if (($this->dir ?? '') !== '') {
            $this->removeScaffold();
        }
    }

    #[RunInSeparateProcess]
    public function test_the_groups_of_members_codes_rbac_and_validation_are_complete(): void
    {
        $this->scaffold(['members' => 'sqlite', 'auth' => 'sqlite', 'throttle' => 'sqlite', 'codes' => 'sqlite'], ['en', 'de', 'fr']);
        $packages = [];
        foreach (self::PACKAGES as $name) {
            $root = dirname(__DIR__, 2) . '/' . $name;
            $packages[] = [...json_decode((string) file_get_contents($root . '/composer.json'), true), 'install-path' => $root];
        }
        $this->write('vendor/composer/installed.json', (string) json_encode(['packages' => $packages]));
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class, \\Preflow\\Rbac\\RbacServiceProvider::class, \\Preflow\\Members\\MembersServiceProvider::class, \\Preflow\\Codes\\CodesServiceProvider::class];\n");
        $this->write('config/folio/extensions.json', '{"enabled": {"members": {"disabled_parts": []}, "rbac": {"disabled_parts": [], "enabled_parts": ["model:staff"]}, "codes": {"disabled_parts": []}}}');
        $this->write('config/codes.php', "<?php\nreturn ['purposes' => ['members.book' => ['label' => 'Book code', 'readable' => true]]];\n");
        $dir = $this->dir;

        foreach (['members', 'members-admin', 'codes', 'rbac', 'validation'] as $group) {
            ob_start();
            $code = (new TranslationsCheckCommand(static function () use ($dir): Application {
                $app = Application::create($dir);
                $app->boot();

                return $app;
            }))->execute(['--group=' . $group]);
            $out = (string) ob_get_clean();

            $this->assertSame(0, $code, "{$group}: {$out}");
            $this->assertStringContainsString('en, de, fr', $out, $group);
        }
    }
}
