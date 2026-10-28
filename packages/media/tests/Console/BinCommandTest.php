<?php

declare(strict_types=1);

namespace Preflow\Media\Tests\Console;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Preflow\Media\Asset;
use Preflow\Media\Console\BinCommand;
use Preflow\Media\MediaLibrary;
use Preflow\Media\Tests\Support\MediaApp;
use Preflow\Media\Tests\Usage\AssetUsageTest;
use Preflow\Media\Usage\AssetUsage;
use Preflow\Media\Usage\AssetUsageEntry;

/** media:bin lists the bin, --restore=<id> brings an asset back, --empty deletes for good after cleanup.bin_days. */
#[RequiresPhpExtension('gd')]
final class BinCommandTest extends TestCase
{
    use MediaApp;

    protected function setUp(): void
    {
        $this->scaffoldMediaApp();
        $this->writeFile('config/media.php', "<?php\nreturn ['bin_dir' => __DIR__ . '/../storage/media-bin', 'cleanup' => ['bin_days' => 10]];\n");
    }

    protected function tearDown(): void
    {
        $this->removeMediaApp();
    }

    private function png(string $name): Asset
    {
        $image = imagecreatetruecolor(20, 10);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        $f = new Psr17Factory();

        return $this->media()->upload($f->createUploadedFile($f->createStream($bytes), strlen($bytes), UPLOAD_ERR_OK, $name), null);
    }

    /** Bin the asset as if it had been deleted $days ago. */
    private function binnedDaysAgo(Asset $asset, int $days): void
    {
        $this->media()->delete($asset->id);
        $binned = $this->media()->findBinned($asset->id);
        $at = (new \DateTimeImmutable())->modify("-{$days} days")->format(DATE_ATOM);
        (new \ReflectionMethod(MediaLibrary::class, 'save'))->invoke($this->media(), [...$binned->toData(), 'binned_at' => $at]);
    }

    /**
     * @param list<string> $args
     * @return array{0: int, 1: string, 2: string} exit code, output, errors
     */
    private function runCommand(array $args): array
    {
        $stderr = fopen('php://memory', 'w+');
        ob_start();
        $code = (new BinCommand(fn () => $this->mediaApp(), $stderr))->execute($args);
        $out = (string) ob_get_clean();
        rewind($stderr);

        return [$code, $out, (string) stream_get_contents($stderr)];
    }

    public function test_the_package_lists_the_command(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);

        $this->assertContains(BinCommand::class, $composer['extra']['preflow']['commands'] ?? []);
        $this->assertSame('media:bin', (new BinCommand())->getName());
    }

    public function test_list_prints_id_name_kind_and_binned_date(): void
    {
        $a = $this->png('first.png');
        $b = $this->png('second.png');
        $this->png('live.png');
        $this->binnedDaysAgo($a, 3);
        $this->binnedDaysAgo($b, 1);
        $date = static fn (int $days): string => (new \DateTimeImmutable())->modify("-{$days} days")->format('Y-m-d');

        [$code, $out, $err] = $this->runCommand([]);

        $this->assertSame([0, ''], [$code, $err]);
        $lines = explode("\n", trim($out));
        $this->assertSame('2 assets in the bin:', $lines[0]);
        $this->assertCount(3, $lines);
        // Newest binned first.
        $this->assertStringStartsWith($b->id . '  image  ' . $date(1), $lines[1]);
        $this->assertStringEndsWith('second.png', $lines[1]);
        $this->assertStringStartsWith($a->id . '  image  ' . $date(3), $lines[2]);
        $this->assertStringEndsWith('first.png', $lines[2]);
    }

    public function test_list_in_chunks(): void
    {
        foreach (['a.png', 'b.png', 'c.png'] as $name) {
            $this->media()->delete($this->png($name)->id);
        }

        [, $first] = $this->runCommand(['--limit=2']);
        [, $rest] = $this->runCommand(['--limit=2', '--offset=2']);

        $this->assertSame(4, count(explode("\n", trim($first))));
        $this->assertStringEndsWith("1 asset left: run again with --limit=2 --offset=2.\n", $first);
        $this->assertSame(2, count(explode("\n", trim($rest))));
    }

    public function test_an_empty_bin(): void
    {
        $this->png('live.png');

        $this->assertSame([0, "The bin is empty.\n", ''], $this->runCommand([]));
    }

    public function test_restore_restores(): void
    {
        $asset = $this->png('photo.png');
        $this->media()->delete($asset->id);

        [$code, $out] = $this->runCommand(['--restore=' . $asset->id]);

        $this->assertSame([0, "Restored photo.png ({$asset->id}).\n"], [$code, $out]);
        $this->assertNotNull($this->media()->find($asset->id));
        $this->assertFileExists((string) $this->media()->file($this->media()->find($asset->id)));
    }

    public function test_restore_of_an_asset_not_in_the_bin_fails(): void
    {
        $asset = $this->png('live.png');

        $this->assertSame([1, '', "Asset {$asset->id} is not in the bin.\n"], $this->runCommand(['--restore=' . $asset->id]));
    }

    public function test_empty_prints_deleted_and_kept_with_reasons(): void
    {
        $old = $this->png('old.png');
        $recent = $this->png('recent.png');
        $kept = $this->png('kept.png');
        $this->mediaApp()->container()->get(AssetUsage::class)->register(AssetUsageTest::source('revisions', [$kept->id => [
            new AssetUsageEntry('Home page', null, 'revisions', 'earlier revision', blocksDelete: false),
        ]]));
        $this->binnedDaysAgo($old, 11);
        $this->binnedDaysAgo($recent, 9);
        $this->binnedDaysAgo($kept, 11);

        [$code, $out, $err] = $this->runCommand(['--empty']);

        $this->assertSame([0, ''], [$code, $err]);
        $this->assertSame(
            "Deleted 1 asset for good (in the bin longer than 10 days).\n"
            . "Kept 1 asset:\n"
            . "  {$kept->id} kept.png: still used: Home page (revisions, earlier revision)\n",
            $out,
        );
        $this->assertNull($this->media()->findBinned($old->id));
        $this->assertNotNull($this->media()->findBinned($recent->id));
        $this->assertNotNull($this->media()->findBinned($kept->id));
    }

    public function test_refuses_without_bin_dir(): void
    {
        $this->writeFile('config/media.php', "<?php\nreturn [];\n");

        foreach ([[], ['--empty'], ['--restore=' . str_repeat('0', 32)]] as $args) {
            [$code, $out, $err] = $this->runCommand($args);
            $this->booted = null;

            $this->assertSame([1, ''], [$code, $out]);
            $this->assertSame("bin_dir is not set in config/media.php, so there is no bin: deleted assets are removed for good.\n", $err);
        }
    }

    public function test_bad_limit_is_refused(): void
    {
        $this->assertSame([1, '', "--limit needs a positive number.\n"], $this->runCommand(['--limit=0']));
    }
}
