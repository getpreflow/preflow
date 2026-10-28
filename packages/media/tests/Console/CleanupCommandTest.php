<?php

declare(strict_types=1);

namespace Preflow\Media\Tests\Console;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Preflow\Media\Asset;
use Preflow\Media\Console\CleanupCommand;
use Preflow\Media\MediaLibrary;
use Preflow\Media\Tests\Support\MediaApp;
use Preflow\Media\Tests\Usage\AssetUsageTest;
use Preflow\Media\Usage\AssetUsage;
use Preflow\Media\Usage\AssetUsageEntry;

/** media:cleanup lists unused assets, --delete moves them into the bin; off until config says otherwise. */
#[RequiresPhpExtension('gd')]
final class CleanupCommandTest extends TestCase
{
    use MediaApp;

    private const string ENABLED = "<?php\nreturn ['bin_dir' => __DIR__ . '/../storage/media-bin', 'cleanup' => ['enabled' => true, 'min_age_days' => 7]];\n";

    protected function setUp(): void
    {
        $this->scaffoldMediaApp();
        $this->writeFile('config/media.php', self::ENABLED);
    }

    protected function tearDown(): void
    {
        $this->removeMediaApp();
    }

    /** An upload made $days ago. */
    private function png(string $name, int $days = 10): Asset
    {
        $image = imagecreatetruecolor(20, 10);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        $f = new Psr17Factory();
        $asset = $this->media()->upload($f->createUploadedFile($f->createStream($bytes), strlen($bytes), UPLOAD_ERR_OK, $name), 'ed');
        $at = (new \DateTimeImmutable())->modify("-{$days} days")->format(DATE_ATOM);

        return (new \ReflectionMethod(MediaLibrary::class, 'save'))->invoke($this->media(), [...$asset->toData(), 'created_at' => $at]);
    }

    /**
     * @param list<string> $args
     * @return array{0: int, 1: string, 2: string} exit code, output, errors
     */
    private function runCommand(array $args): array
    {
        $stderr = fopen('php://memory', 'w+');
        ob_start();
        $code = (new CleanupCommand(fn () => $this->mediaApp(), $stderr))->execute($args);
        $out = (string) ob_get_clean();
        rewind($stderr);

        return [$code, $out, (string) stream_get_contents($stderr)];
    }

    private static function row(Asset $asset, string $reason = 'no source reports it (asked: media, copies)'): string
    {
        return "{$asset->id}  {$asset->kind()}  " . substr($asset->createdAt, 0, 10) . "  {$asset->label()}: {$reason}";
    }

    public function test_the_package_lists_the_command(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);

        $this->assertContains(CleanupCommand::class, $composer['extra']['preflow']['commands'] ?? []);
        $this->assertSame('media:cleanup', (new CleanupCommand())->getName());
    }

    public function test_refuses_while_cleanup_is_off(): void
    {
        $this->writeFile('config/media.php', "<?php\nreturn ['bin_dir' => __DIR__ . '/../storage/media-bin'];\n");
        $asset = $this->png('old.png');

        foreach ([[], ['--delete']] as $args) {
            $this->assertSame(
                [1, '', "Cleanup is off (config/media.php cleanup.enabled). Read the media README first: cleanup only knows usages reported by usage sources.\n"],
                $this->runCommand($args),
            );
        }
        $this->assertNotNull($this->media()->find($asset->id));
    }

    public function test_lists_candidates_and_deletes_nothing(): void
    {
        $old = $this->png('old.png');
        $this->png('recent.png', days: 3);
        $used = $this->png('used.png');
        $this->mediaApp()->container()->get(AssetUsage::class)->register(AssetUsageTest::source('pages', [$used->id => [new AssetUsageEntry('Home page', null, 'pages')]]));

        [$code, $out, $err] = $this->runCommand([]);

        $this->assertSame([0, ''], [$code, $err]);
        $this->assertSame(
            "Checked 2 assets uploaded more than 7 days ago: 1 unused.\n"
            . self::row($old, 'no source reports it (asked: pages, media, copies)') . "\n"
            . "Nothing was moved: run again with --delete to move them into the bin.\n",
            $out,
        );
        $this->assertNotNull($this->media()->find($old->id));
        $this->assertSame([0, "Checked 0 assets uploaded more than 7 days ago: 0 unused.\n", ''], $this->runCommand(['--private']));
        $this->assertStringStartsWith('Checked 2 assets', $this->runCommand(['--public'])[1]);
    }

    public function test_delete_bins_candidates_and_prints_the_count(): void
    {
        $a = $this->png('a.png', days: 12);
        $b = $this->png('b.png', days: 11);
        $recent = $this->png('recent.png', days: 1);

        [$code, $out, $err] = $this->runCommand(['--delete']);

        $this->assertSame([0, ''], [$code, $err]);
        $this->assertSame(
            "Checked 2 assets uploaded more than 7 days ago: 2 unused.\n"
            . self::row($a) . "\n" . self::row($b) . "\n"
            . "Moved 2 assets into the bin (media:bin --restore=<id> brings one back).\n",
            $out,
        );
        $this->assertNotNull($this->media()->findBinned($a->id));
        $this->assertNotNull($this->media()->findBinned($b->id));
        $this->assertNotNull($this->media()->find($recent->id));
    }

    public function test_delete_without_bin_dir_refuses(): void
    {
        $this->writeFile('config/media.php', "<?php\nreturn ['cleanup' => ['enabled' => true]];\n");
        $asset = $this->png('old.png');

        [$code, $out, $err] = $this->runCommand(['--delete']);

        $this->assertSame([1, ''], [$code, $out]);
        $this->assertSame("bin_dir is not set in config/media.php: cleanup only moves assets into the bin and never deletes them for good.\n", $err);
        $this->assertNotNull($this->media()->find($asset->id));
        [$listCode] = $this->runCommand([]);
        $this->assertSame(0, $listCode, 'listing needs no bin');
    }

    public function test_filters_and_chunks(): void
    {
        $a = $this->png('a.png', days: 13);
        $anns = $this->png('anns.png', days: 12);
        (new \ReflectionMethod(MediaLibrary::class, 'save'))->invoke($this->media(), [...$anns->toData(), 'created_by' => 'ann']);
        $b = $this->png('b.png', days: 11);
        $c = $this->png('c.png', days: 10);

        [, $first] = $this->runCommand(['--delete', '--by=ed', '--older-than=9', '--limit=2']);
        [, $rest] = $this->runCommand(['--delete', '--by=ed', '--older-than=9', '--limit=2']);

        $this->assertSame(
            "Checked 2 assets uploaded more than 9 days ago: 2 unused.\n"
            . self::row($a) . "\n" . self::row($b) . "\n"
            . "Moved 2 assets into the bin (media:bin --restore=<id> brings one back).\n"
            . "1 asset left to check: run again with --delete --by=ed --older-than=9 --limit=2.\n",
            $first,
        );
        $this->assertStringContainsString(self::row($c), $rest);
        $this->assertStringNotContainsString('left to check', $rest);
        $this->assertNotNull($this->media()->find($anns->id), 'uploaded by someone else');
    }

    public function test_listing_in_chunks_continues_with_an_offset(): void
    {
        foreach (['a.png' => 13, 'b.png' => 12, 'c.png' => 11] as $name => $days) {
            $this->png($name, $days);
        }

        [, $out] = $this->runCommand(['--kind=image', '--limit=2']);

        $this->assertStringEndsWith("1 asset left to check: run again with --kind=image --limit=2 --offset=2.\n", $out);
    }

    public function test_bad_options_are_refused(): void
    {
        $this->assertSame([1, '', "Use --private or --public, not both.\n"], $this->runCommand(['--private', '--public']));
        $this->assertSame([1, '', "--older-than needs a non-negative number.\n"], $this->runCommand(['--older-than=x']));
        $this->assertSame([1, '', "Unknown kind \"sheet\": use image, video, document.\n"], $this->runCommand(['--kind=sheet']));
    }
}
