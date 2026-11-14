<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use Composer\InstalledVersions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Version;

final class VersionTest extends TestCase
{
    /** @return iterable<string, array{?string, ?string, ?string}> pretty version, reference, label */
    public static function labels(): iterable
    {
        yield 'a release' => ['0.75.0', 'a71f2b11690f4b24d099d6b16690a90ae14fc6f3', '0.75.0'];
        yield 'a tag with v' => ['v0.75.0', null, '0.75.0'];
        yield 'a pre-release' => ['v1.0.0-beta.2', null, '1.0.0-beta.2'];
        yield 'a branch' => ['dev-main', '01c613de0aa90e607d8a1c2a398b024c20a180c0', 'dev · 01c613d'];
        yield 'a branch alias' => ['0.75.x-dev', '01c613de0aa90e607d8a1c2a398b024c20a180c0', 'dev · 01c613d'];
        yield 'a dev branch without a reference' => ['dev-feature/x', null, 'dev'];
        yield 'a root without a version' => ['No version set (parsed as 1.0.0)', '01c613de', 'dev · 01c613d'];
        yield 'a root without a version, Composer 2.7' => ['1.0.0+no-version-set', null, 'dev'];
        yield 'a reference that is no hash' => ['dev-main', 'main', 'dev'];
        yield 'nothing installed' => [null, null, null];
    }

    #[DataProvider('labels')]
    public function test_format_turns_a_composer_version_into_a_label(?string $pretty, ?string $reference, ?string $label): void
    {
        $this->assertSame($label, Version::format($pretty, $reference));
    }

    public function test_installed_reads_composer(): void
    {
        $phpunit = Version::installed('phpunit/phpunit');
        $this->assertNotNull($phpunit);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', $phpunit, 'no leading "v"');
        $this->assertSame($phpunit, Version::label('phpunit/phpunit'), 'a release is its own label');

        $this->assertNull(Version::installed('preflow/not-a-package'));
        $this->assertNull(Version::label('preflow/not-a-package'));

    }

    #[RunInSeparateProcess]
    public function test_a_path_install_is_plain_dev(): void
    {
        // A path repository (the monorepo, a local checkout): Composer symlinks it, or mirrors it
        // (installed.json says dist type "path"). Its reference is Composer's content hash, no commit.
        $dir = sys_get_temp_dir() . '/preflow-version-' . bin2hex(random_bytes(4));
        mkdir($dir . '/packages/part', 0777, true);
        mkdir($dir . '/vendor/acme/mirrored', 0777, true);
        mkdir($dir . '/vendor/acme/git', 0777, true);
        mkdir($dir . '/vendor/composer', 0777, true);
        symlink($dir . '/packages/part', $dir . '/vendor/acme/linked');
        try {
            InstalledVersions::reload([
                'root' => ['name' => 'acme/site', 'pretty_version' => '1.0.0', 'version' => '1.0.0.0', 'reference' => null, 'type' => 'project', 'install_path' => $dir, 'aliases' => [], 'dev' => true],
                'versions' => [
                    'acme/linked' => ['pretty_version' => 'dev-main', 'version' => 'dev-main', 'reference' => '0aa90e607d8a1c2a398b024c20a180c0af0f0514', 'type' => 'library', 'install_path' => $dir . '/vendor/composer/../acme/linked', 'aliases' => [], 'dev_requirement' => false],
                    'acme/linked-guessed' => ['pretty_version' => '0.70.99', 'version' => '0.70.99.0', 'reference' => '0aa90e607d8a1c2a398b024c20a180c0af0f0514', 'type' => 'library', 'install_path' => $dir . '/vendor/acme/linked/', 'aliases' => [], 'dev_requirement' => false],
                    'acme/mirrored' => ['pretty_version' => 'dev-main', 'version' => 'dev-main', 'reference' => '0aa90e607d8a1c2a398b024c20a180c0af0f0514', 'type' => 'library', 'install_path' => $dir . '/vendor/acme/mirrored', 'aliases' => [], 'dev_requirement' => false],
                    'acme/git' => ['pretty_version' => 'dev-main', 'version' => 'dev-main', 'reference' => '01c613de0aa90e607d8a1c2a398b024c20a180c0', 'type' => 'library', 'install_path' => $dir . '/vendor/acme/git', 'aliases' => [], 'dev_requirement' => false],
                ],
            ]);
            // What installed.json says about mirrored path installs (read once, next to InstalledVersions).
            (new \ReflectionProperty(Version::class, 'pathInstalls'))->setValue(null, ['acme/mirrored' => true]);

            $this->assertSame('dev', Version::label('acme/linked'), 'symlinked: no content hash');
            $this->assertSame('dev', Version::label('acme/linked-guessed'), 'a version Composer guessed for a checkout is no release');
            $this->assertSame('0.70.99', Version::installed('acme/linked-guessed'), 'installed() stays Composer\'s version');
            $this->assertSame('dev', Version::label('acme/mirrored'), 'mirrored path install');
            $this->assertSame('dev · 01c613d', Version::label('acme/git'), 'a VCS install keeps its commit');
        } finally {
            unlink($dir . '/vendor/acme/linked');
            foreach (['/vendor/acme/git', '/vendor/acme/mirrored', '/vendor/acme', '/vendor/composer', '/vendor', '/packages/part', '/packages', ''] as $sub) {
                @rmdir($dir . $sub);
            }
        }
    }

    public function test_installed_json_marks_path_installs(): void
    {
        // This monorepo installs every preflow package from its path repository.
        $paths = (new \ReflectionMethod(Version::class, 'pathInstalls'))->invoke(null);
        $this->assertIsArray($paths);
        $this->assertArrayHasKey('preflow/core', $paths);
        $this->assertArrayNotHasKey('phpunit/phpunit', $paths);
    }

    #[RunInSeparateProcess]
    public function test_a_package_the_root_replaces_has_the_roots_version(): void
    {
        InstalledVersions::reload([
            'root' => ['name' => 'acme/monorepo', 'pretty_version' => 'dev-main', 'version' => 'dev-main', 'reference' => '01c613de0aa90e607d8a1c2a398b024c20a180c0', 'type' => 'project', 'install_path' => __DIR__, 'aliases' => [], 'dev' => true],
            'versions' => [
                'acme/monorepo' => ['pretty_version' => 'dev-main', 'version' => 'dev-main', 'reference' => '01c613de0aa90e607d8a1c2a398b024c20a180c0', 'type' => 'project', 'install_path' => __DIR__, 'aliases' => [], 'dev_requirement' => false],
                'acme/part' => ['dev_requirement' => false, 'replaced' => ['dev-main']],
                'acme/tagged' => ['pretty_version' => 'v2.1.0', 'version' => '2.1.0.0', 'reference' => 'abc1234', 'type' => 'library', 'install_path' => __DIR__, 'aliases' => [], 'dev_requirement' => false],
                'acme/virtual' => ['dev_requirement' => false, 'provided' => ['1.0']],
            ],
        ]);

        $this->assertSame('dev-main', Version::installed('acme/part'));
        $this->assertSame('dev · 01c613d', Version::label('acme/part'));
        $this->assertSame('2.1.0', Version::installed('acme/tagged'));
        $this->assertSame('2.1.0', Version::label('acme/tagged'));
        $this->assertNull(Version::installed('acme/virtual'), 'only provided: nothing is installed under that name');
    }
}
