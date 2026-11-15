<?php

declare(strict_types=1);

namespace Preflow\Media\Tests;

use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Activity\Activity;
use Preflow\Core\Activity\ActivityLog;
use Preflow\Core\Activity\ActivityPage;
use Preflow\Data\Activity\StoredActivityLog;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Locales;
use Preflow\Media\Image\GdBackend;
use Preflow\Media\Image\VariantGenerator;
use Preflow\Media\MediaLibrary;
use Preflow\Media\Tests\Support\MediaFixtures;

/** Upload, replace and delete (bin or for good) tell the activity log; a failing log fails none. */
#[RequiresPhpExtension('gd')]
#[RequiresPhpExtension('fileinfo')]
#[RequiresFunction('imagewebp')]
final class ActivityUploadTest extends TestCase
{
    use MediaFixtures;

    protected function setUp(): void
    {
        $this->makeAssetRoot();
    }

    protected function tearDown(): void
    {
        $this->removeAssetRoot();
    }

    /** @param array<string, mixed> $config */
    private function lib(ActivityLog $log, array $config = [], ?\Psr\Log\LoggerInterface $logger = null): MediaLibrary
    {
        $dm = new DataManager(drivers: ['json' => new JsonFileDriver($this->assetRoot . '/store')], defaultDriver: 'json');

        return new MediaLibrary(
            $dm,
            new Locales(['en', 'de'], 'en'),
            $this->assetRoot . '/media',
            '/media',
            ['versions_dir' => $this->assetRoot . '/versions'] + $config,
            new VariantGenerator(new GdBackend(), [480], 75),
            activity: $log,
            logger: $logger,
        );
    }

    private function storedLog(): StoredActivityLog
    {
        return new StoredActivityLog(new DataManager(drivers: ['json' => new JsonFileDriver($this->assetRoot . '/activity')], defaultDriver: 'json'), 'json');
    }

    /** @return list<string> "kind subject:title by" newest first */
    private static function rows(ActivityLog $log): array
    {
        return array_map(
            static fn (Activity $a): string => "{$a->kind->value} {$a->subject}:{$a->title} " . ($a->by ?? '-'),
            $log->recent(20)->items,
        );
    }

    public function test_upload_replace_and_bin(): void
    {
        $log = $this->storedLog();
        $library = $this->lib($log, ['bin_dir' => $this->assetRoot . '/bin']);
        $asset = $library->upload($this->upload($this->png(40, 30), 'Beach.png'), 'ana');
        $library->update($asset->id, ['title' => 'A day at the sea']);
        $library->replace($asset->id, $this->upload($this->png(30, 40), 'Beach-2.png'), 'ben');
        $library->delete($asset->id, 'cleo');

        $this->assertSame([
            'deleted asset:Beach-2.png cleo',
            'replaced asset:Beach-2.png ben',
            'uploaded asset:Beach.png ana',
        ], self::rows($log));
        $this->assertSame([$asset->id], array_values(array_unique(array_map(static fn (Activity $a): string => $a->id, $log->recent(5)->items))));
    }

    public function test_a_delete_for_good_is_deleted_too(): void
    {
        $log = $this->storedLog();
        $library = $this->lib($log);
        $asset = $library->upload($this->upload($this->png(40, 30), 'Gone.png'), 'ana');
        $library->delete($asset->id, 'ana');

        $this->assertNull($library->find($asset->id));
        $this->assertSame(['deleted asset:Gone.png ana', 'uploaded asset:Gone.png ana'], self::rows($log));
    }

    public function test_restoring_an_earlier_file_is_restored(): void
    {
        $log = $this->storedLog();
        $library = $this->lib($log);
        $asset = $library->upload($this->upload($this->png(40, 30), 'One.png'), 'ana');
        $library->replace($asset->id, $this->upload($this->png(30, 40), 'Two.png'), 'ana');
        $library->restore($asset->id, $library->versions($asset->id)[0]->number, 'ben');

        $this->assertSame('restored asset:One.png ben', self::rows($log)[0]);
    }

    public function test_a_private_file_is_never_named(): void
    {
        $log = $this->storedLog();
        $library = $this->lib($log, ['private_dir' => $this->assetRoot . '/private']);
        $asset = $library->upload($this->upload($this->png(40, 30), 'salary-2026-jane.png'), 'ana', private: true);
        $library->delete($asset->id, 'ana');

        $this->assertSame(['deleted asset:Private file ana', 'uploaded asset:Private file ana'], self::rows($log));
        $this->assertSame(['media.activity.private_file', 'media.activity.private_file'], array_map(static fn (Activity $a): ?string => $a->titleKey, $log->recent(5)->items));
    }

    public function test_an_imported_file_is_uploaded(): void
    {
        $log = $this->storedLog();
        $library = $this->lib($log);
        $file = $this->assetRoot . '/import.png';
        file_put_contents($file, $this->png(40, 30));
        $library->importFile($file, 'Imported.png', 'cli');

        $this->assertSame(['uploaded asset:Imported.png cli'], self::rows($log));
    }

    public function test_a_discarded_upload_leaves_no_row(): void
    {
        $log = $this->storedLog();
        $library = $this->lib($log);
        $kept = $library->upload($this->upload($this->png(40, 30), 'Kept.png'), 'ana');
        $refused = $library->upload($this->upload($this->png(40, 30), 'Refused.png'), 'ana');
        $library->discard($refused->id);

        $this->assertSame(['uploaded asset:Kept.png ana'], self::rows($log));
        $this->assertNotNull($library->find($kept->id));
    }

    public function test_a_failing_log_fails_no_upload(): void
    {
        $failing = new class implements ActivityLog {
            public function record(Activity $activity): void
            {
                throw new \RuntimeException('the log is down');
            }

            public function recent(int $limit, ?array $subjects = null, ?string $before = null, ?string $by = null): ActivityPage
            {
                return new ActivityPage();
            }

            public function people(?array $subjects = null): array
            {
                return [];
            }

            public function prune(): int
            {
                return 0;
            }

            public function forget(string $subject, string $id): void
            {
            }
        };
        $logger = new class extends \Psr\Log\AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->lines[] = $level . ': ' . $message;
            }
        };
        $library = $this->lib($failing, ['bin_dir' => $this->assetRoot . '/bin'], $logger);

        $asset = $library->upload($this->upload($this->png(40, 30), 'Safe.png'), 'ana');
        $library->replace($asset->id, $this->upload($this->png(30, 40), 'Safe-2.png'), 'ana');
        $library->delete($asset->id, 'ana');

        $this->assertNull($library->find($asset->id));
        $this->assertCount(3, $logger->lines);
        $this->assertStringContainsString('"uploaded" of asset ' . $asset->id . ' not recorded: the log is down', $logger->lines[0]);
    }
}
