<?php

declare(strict_types=1);

namespace Preflow\Media\Tests\Console;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Preflow\Media\AssetVersion;
use Preflow\Media\Console\VersionsPruneCommand;
use Preflow\Media\Tests\Support\MediaApp;

/** media:versions:prune [--keep=N] [--older-than=DAYS] [--id=ID] */
#[RequiresPhpExtension('gd')]
final class VersionsPruneCommandTest extends TestCase
{
    use MediaApp;

    protected function setUp(): void
    {
        $this->scaffoldMediaApp();
    }

    protected function tearDown(): void
    {
        $this->removeMediaApp();
    }

    public function test_the_package_lists_the_command(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);

        $this->assertContains(VersionsPruneCommand::class, $composer['extra']['preflow']['commands'] ?? []);
        $this->assertSame('media:versions:prune', (new VersionsPruneCommand())->getName());
    }

    public function test_keep_prunes_every_asset(): void
    {
        $a = $this->assetWithVersions(3);
        $b = $this->assetWithVersions(1);

        [$code, $out] = $this->runCommand(['--keep=1']);

        $this->assertSame(0, $code);
        $this->assertSame([3], $this->numbers($a));
        $this->assertSame([1], $this->numbers($b));
        $this->assertStringContainsString('Deleted 2 versions', $out);

        [, $again] = $this->runCommand(['--keep=1']);
        $this->assertStringContainsString('Deleted 0 versions', $again);
    }

    public function test_id_limits_it_to_one_asset(): void
    {
        $a = $this->assetWithVersions(2);
        $b = $this->assetWithVersions(2);

        [$code, ] = $this->runCommand(['--keep=0', '--id=' . $a]);

        $this->assertSame(0, $code);
        $this->assertSame([], $this->numbers($a));
        $this->assertSame([2, 1], $this->numbers($b));
    }

    public function test_older_than_alone_spares_recent_versions(): void
    {
        $a = $this->assetWithVersions(2);

        [$code, $out] = $this->runCommand(['--older-than=30']);

        $this->assertSame(0, $code);
        $this->assertSame([2, 1], $this->numbers($a));
        $this->assertStringContainsString('Deleted 0 versions', $out);
    }

    public function test_without_options_it_explains_and_deletes_nothing(): void
    {
        $a = $this->assetWithVersions(1);

        [$code, $out] = $this->runCommand([]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('--keep', $out);
        $this->assertSame([1], $this->numbers($a));
    }

    private function assetWithVersions(int $count): string
    {
        $f = new Psr17Factory();
        $png = function (int $w) use ($f) {
            $image = imagecreatetruecolor($w, 10);
            ob_start();
            imagepng($image);
            $bytes = (string) ob_get_clean();

            return $f->createUploadedFile($f->createStream($bytes), strlen($bytes), UPLOAD_ERR_OK, 'a.png');
        };
        $id = $this->media()->upload($png(10), null)->id;
        for ($i = 1; $i <= $count; $i++) {
            $this->media()->replace($id, $png(10 + $i), null);
        }

        return $id;
    }

    /** @return list<int> */
    private function numbers(string $id): array
    {
        return array_map(static fn (AssetVersion $v) => $v->number, $this->media()->versions($id));
    }

    /**
     * @param list<string> $args
     * @return array{0: int, 1: string}
     */
    private function runCommand(array $args): array
    {
        ob_start();
        $code = (new VersionsPruneCommand(fn () => $this->mediaApp()))->execute($args);

        return [$code, (string) ob_get_clean()];
    }
}
