<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Users;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Record\RecordUser;
use Preflow\Auth\Record\RecordUserProvider;
use Preflow\Auth\SessionGuard;
use Preflow\Auth\UserProviderInterface;
use Preflow\Core\Application;
use Preflow\Core\Debug\DebugCollector;
use Preflow\Folio\Users\RecordProviders;

/** RecordUser and RecordUserProvider moved to preflow/auth in 0.38; the Folio names are gone since 0.72. */
final class LegacyRecordUserNamesTest extends TestCase
{
    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir === '' || !is_dir($this->dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    public function test_the_old_names_are_gone(): void
    {
        $this->assertFalse(class_exists('Preflow\Folio\Users\RecordUser'));
        $this->assertFalse(class_exists('Preflow\Folio\Users\RecordUserProvider'));
    }

    /** @return list<string> the deprecations the booted app logged */
    private function boot(string $providerClass): array
    {
        $dir = $this->dir = sys_get_temp_dir() . '/legacy_recuser_' . bin2hex(random_bytes(4));
        $w = function (string $rel, string $content) use ($dir): void {
            @mkdir(dirname($dir . '/' . $rel), 0777, true);
            file_put_contents($dir . '/' . $rel, $content);
        };
        $w('config/app.php', "<?php\nreturn ['name' => 'T', 'debug' => 1, 'engine' => 'twig', 'key' => 'legacy-record-user-test-key-0123'];\n");
        $w('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class];\n");
        $w('config/folio.php', "<?php\nreturn ['path' => '/folio'];\n");
        $w('config/data.php', "<?php\nreturn " . var_export([
            'default' => 'sqlite', 'models_path' => $dir . '/config/models', 'drivers' => ['sqlite' => ['path' => $dir . '/app.sqlite']],
        ], true) . ";\n");
        $w('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'member',
            'guards' => ['member' => ['class' => SessionGuard::class, 'provider' => 'members']],
            'providers' => ['members' => ['class' => $providerClass, 'type' => 'member']],
            'session' => ['cookie' => 'legacy_test', 'path' => '/', 'secure' => false],
        ], true) . ";\n");
        $w('config/models/member.json', (string) json_encode([
            'key' => 'member', 'table' => 'member', 'storage' => 'sqlite', 'id_field' => 'uuid', 'users' => 'members',
            'fields' => ['email' => ['type' => 'string'], 'password_hash' => ['type' => 'string'], 'roles' => ['type' => 'string']],
        ]));
        $w('app/pages/index.twig', 'HOME');

        $app = Application::create($dir);
        $app->boot();
        $c = $app->container();
        $this->assertSame([RecordUserProvider::class], array_map(get_class(...), RecordProviders::of($c->get(UserProviderInterface::class))), 'built through Folio');

        return $c->get(DebugCollector::class)->getDeprecations();
    }

    #[RunInSeparateProcess]
    public function test_the_new_name_works_without_a_deprecation(): void
    {
        $deprecations = $this->boot(RecordUserProvider::class);

        $this->assertSame([], array_values(array_filter($deprecations, static fn (string $m): bool => str_contains($m, 'RecordUserProvider'))));
    }
}
