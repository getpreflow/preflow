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

        // The monorepo installs its packages from path repositories: a dev version with a reference.
        $this->assertSame(Version::format(InstalledVersions::getPrettyVersion('preflow/core'), InstalledVersions::getReference('preflow/core')), Version::label('preflow/core'));
        $this->assertStringStartsWith('dev', (string) Version::label('preflow/core'));
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
