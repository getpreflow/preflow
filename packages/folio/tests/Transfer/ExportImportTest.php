<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Transfer;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\Folio\Transfer\ContentSet;
use Preflow\Folio\Transfer\Exporter;
use Preflow\Folio\Transfer\Importer;
use Preflow\Folio\Transfer\TarWriter;
use Preflow\Folio\Transfer\TransferException;

final class ExportImportTest extends TestCase
{
    use PublishingScaffold;

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $post = $this->working('post', 'p1', ['title' => ['en' => 'Hello'], 'slug' => ['en' => 'hello']]);
        $this->publisher()->publish($post, 'tester');
        $this->working('site', 'site', ['tagline' => ['en' => 'Live tagline']]);
        $this->driver()->save('folio_assets', 'a1', ['uuid' => 'a1', 'path' => '2026/10/a1.jpg', 'mime' => 'image/jpeg']);
        $this->driver()->save('user_tokens', 't1', ['uuid' => 't1', 'name' => 'not content']);
        $this->put('public/media/2026/10/a1.jpg', 'JPEGBYTES');
        $this->put('public/media/2026/10/a1-480.webp', 'WEBP');
        $this->put('storage/uploads/2025/01/old.pdf', 'PDF');
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    private function driver(): \Preflow\Data\StorageDriver
    {
        return $this->app()->container()->get(DataManager::class)->driver('json');
    }

    private function put(string $rel, string $bytes): void
    {
        @mkdir(dirname($this->dir . '/' . $rel), 0777, true);
        file_put_contents($this->dir . '/' . $rel, $bytes);
    }

    /** @return resource */
    private function export(string $only = 'all')
    {
        $app = $this->app();
        $s = fopen('php://temp', 'w+b');
        (new Exporter(ContentSet::fromApp($app), $app->container()->get(DataManager::class)))->export($s, $only);
        rewind($s);

        return $s;
    }

    private function import($stream, string $only = 'all'): array
    {
        $app = $this->app();
        $importer = new Importer(ContentSet::fromApp($app), $app->container()->get(DataManager::class));
        $plan = $importer->read($stream, $only);
        try {
            $summary = $plan->summary();
            $importer->apply($plan);
        } finally {
            $plan->cleanup();
        }

        return $summary;
    }

    /** @return array<string, string> rel path => bytes, for everything content-like under the app */
    private function snapshot(): array
    {
        $out = [];
        foreach (['storage/data', 'public/media', 'storage/uploads'] as $root) {
            $dir = $this->dir . '/' . $root;
            if (!is_dir($dir)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
                $out[substr($f->getPathname(), strlen($this->dir) + 1)] = $root === 'storage/data'
                    ? json_encode(json_decode((string) file_get_contents($f->getPathname()), true))
                    : (string) file_get_contents($f->getPathname());
            }
        }
        ksort($out);

        return $out;
    }

    private function wipe(string $rel): void
    {
        $dir = $this->dir . '/' . $rel;
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
    }

    public function test_private_media_travels_with_its_records(): void
    {
        $this->driver()->save('folio_assets', 'p1', ['uuid' => 'p1', 'path' => '2026/10/p1.pdf', 'mime' => 'application/pdf', 'private' => 1]);
        $this->put('storage/private-media/2026/10/p1.pdf', 'PRIVATEPDF');
        $archive = $this->export();

        $this->wipe('storage/private-media');
        $this->driver()->delete('folio_assets', 'p1', 'uuid');
        $summary = $this->import($archive);

        $this->assertSame('PRIVATEPDF', file_get_contents($this->dir . '/storage/private-media/2026/10/p1.pdf'));
        $this->assertSame(1, $this->driver()->findOne('folio_assets', 'p1')['private'] ?? null);
        $this->assertFileDoesNotExist($this->dir . '/public/media/2026/10/p1.pdf');
        $this->assertSame(['added' => [], 'changed' => [], 'removed' => []], $summary['media']);
    }

    public function test_version_files_travel_and_an_archive_without_them_leaves_local_versions_alone(): void
    {
        $this->put('storage/media-versions/a1/v1.jpg', 'OLDJPEG');
        $archive = $this->export();

        $this->wipe('storage/media-versions');
        $this->assertSame(['a1/v1.jpg'], $this->importer()->read($archive)->files['media-versions']['added']);
        rewind($archive);
        $this->import($archive);
        $this->assertSame('OLDJPEG', file_get_contents($this->dir . '/storage/media-versions/a1/v1.jpg'));
        $this->assertFileDoesNotExist($this->dir . '/public/media/a1/v1.jpg');

        // A current archive carries the root: a version only here is removed.
        $this->put('storage/media-versions/a1/v2.jpg', 'STRAY');
        $this->import($this->exportWithout('storage/media-versions/a1/v2.jpg'));
        $this->assertFileDoesNotExist($this->dir . '/storage/media-versions/a1/v2.jpg');

        // An archive from before versions names no "media-versions" root: local versions stay.
        $this->put('storage/media-versions/a1/v2.jpg', 'KEPT');
        $this->import($this->craft('media/2026/10/a1.jpg', 'JPEGBYTES'));
        $this->assertSame('KEPT', file_get_contents($this->dir . '/storage/media-versions/a1/v2.jpg'));
        $this->assertSame('OLDJPEG', file_get_contents($this->dir . '/storage/media-versions/a1/v1.jpg'));
    }

    /** @return resource an export made while $rel is moved away */
    private function exportWithout(string $rel)
    {
        $tmp = $this->dir . '/aside.tmp';
        rename($this->dir . '/' . $rel, $tmp);
        try {
            return $this->export();
        } finally {
            rename($tmp, $this->dir . '/' . $rel);
        }
    }

    public function test_round_trip_restores_records_shadows_assets_and_files(): void
    {
        $before = $this->snapshot();
        $this->assertArrayHasKey('storage/data/post__published/p1.json', $before);
        $this->assertNotEmpty(preg_grep('#^storage/data/post__revisions/#', array_keys($before)));
        $archive = $this->export();

        foreach (['post', 'post__published', 'post__revisions', 'site', 'folio_assets'] as $table) {
            $this->wipe('storage/data/' . $table);
        }
        $this->wipe('public/media');
        $this->wipe('storage/uploads');
        $this->import($archive);

        $this->assertSame($before, $this->snapshot());
    }

    public function test_import_removes_local_only_records_and_files_but_not_other_tables(): void
    {
        $archive = $this->export();
        $this->working('post', 'p9', ['title' => ['en' => 'Local only'], 'slug' => ['en' => 'local']]);
        $this->put('public/media/2026/10/stray.jpg', 'X');
        $this->driver()->save('user_tokens', 't2', ['uuid' => 't2']);

        $summary = $this->import($archive);

        $this->assertFileDoesNotExist($this->dir . '/storage/data/post/p9.json');
        $this->assertFileDoesNotExist($this->dir . '/public/media/2026/10/stray.jpg');
        $this->assertFileExists($this->dir . '/storage/data/user_tokens/t2.json'); // not Folio's
        $this->assertFileExists($this->dir . '/storage/data/user_tokens/t1.json');
        $this->assertSame(['p9'], $summary['tables']['post']['removed']);
        $this->assertSame(['2026/10/stray.jpg'], $summary['media']['removed']);
    }

    public function test_import_without_private_root_keeps_local_private_files(): void
    {
        // An archive from a server before private assets: its manifest has no "private-media" root.
        $this->put('storage/private-media/2026/10/p1.pdf', 'PRIVATEPDF');
        $summary = $this->import($this->craft('media/2026/10/a1.jpg', 'JPEGBYTES'));

        $this->assertSame('PRIVATEPDF', file_get_contents($this->dir . '/storage/private-media/2026/10/p1.pdf'));
        $this->assertSame([], $this->importer()->read($this->craft('media/2026/10/a1.jpg', 'JPEGBYTES'))->files['private-media']['removed'] ?? []);

        // A current archive carries the root, so a file only here is removed as before.
        $archive = $this->export();
        $this->put('storage/private-media/2026/10/stray.pdf', 'X');
        $this->import($archive);
        $this->assertFileDoesNotExist($this->dir . '/storage/private-media/2026/10/stray.pdf');
        $this->assertFileExists($this->dir . '/storage/private-media/2026/10/p1.pdf');
        $this->assertArrayHasKey('media', $summary);
    }

    private function importer(): Importer
    {
        $app = $this->app();

        return new Importer(ContentSet::fromApp($app), $app->container()->get(DataManager::class));
    }

    /** An archive with a valid manifest plus one extra entry. */
    private function craft(string $entry, string $bytes, array $tables = ['post' => 0]): mixed
    {
        $s = fopen('php://temp', 'w+b');
        $w = new TarWriter($s);
        $w->addString('folio-export.json', json_encode(['format' => 1, 'created_at' => 'now', 'tables' => $tables, 'media' => 0, 'uploads' => 0]));
        $w->addString($entry, $bytes);
        $w->close();
        rewind($s);

        return $s;
    }

    public function test_import_rejects_unknown_table_and_writes_nothing(): void
    {
        $before = $this->snapshot();

        try {
            $this->import($this->craft('data/user_tokens/evil.json', '{"uuid":"evil"}', ['user_tokens' => 1]));
            $this->fail('expected a TransferException');
        } catch (TransferException) {
        }

        $this->assertSame($before, $this->snapshot());
    }

    public function test_import_rejects_path_outside_content_and_writes_nothing(): void
    {
        $before = $this->snapshot();

        foreach (['config/app.php' => '<?php', 'data/post/../../x.json' => '{}', 'data/post/bad id.json' => '{}', 'media' => 'x'] as $entry => $bytes) {
            try {
                $this->import($this->craft($entry, $bytes));
                $this->fail("expected a TransferException for {$entry}");
            } catch (TransferException) {
            }
        }

        $this->assertSame($before, $this->snapshot());
    }

    public function test_import_rejects_executable_or_config_files_in_media(): void
    {
        $before = $this->snapshot();

        foreach (['media/x.php', 'media/2026/shell.PHTML', 'uploads/a.phar', 'media/.htaccess', 'media/2026/.user.ini', 'media/.hidden/a.jpg', 'uploads/run.sh',
            'media/x.php.jpg', 'media/a.php.', 'media/a.php ', 'media/web.config', 'media/2026/php.ini'] as $entry) {
            try {
                $this->import($this->craft($entry, 'x'));
                $this->fail("expected a TransferException for {$entry}");
            } catch (TransferException) {
            }
        }

        $this->assertSame($before, $this->snapshot());
    }

    public function test_import_without_manifest_is_rejected(): void
    {
        $s = fopen('php://temp', 'w+b');
        $w = new TarWriter($s);
        $w->addString('data/post/p1.json', '{"uuid":"p1"}');
        $w->close();
        rewind($s);

        $this->expectException(TransferException::class);
        $this->import($s);
    }

    public function test_only_media_leaves_records_and_only_data_leaves_files(): void
    {
        $archive = $this->export();
        $this->working('post', 'p9', ['title' => ['en' => 'Local only'], 'slug' => ['en' => 'local']]);
        $this->put('public/media/2026/10/stray.jpg', 'X');

        $this->import($archive, 'media');
        $this->assertFileExists($this->dir . '/storage/data/post/p9.json');
        $this->assertFileDoesNotExist($this->dir . '/public/media/2026/10/stray.jpg');

        $this->put('public/media/2026/10/stray.jpg', 'X');
        rewind($archive);
        $this->import($archive, 'data');
        $this->assertFileDoesNotExist($this->dir . '/storage/data/post/p9.json');
        $this->assertFileExists($this->dir . '/public/media/2026/10/stray.jpg');
    }

    public function test_partial_exports_never_remove_what_they_did_not_carry(): void
    {
        $dataOnly = $this->export('data');
        $mediaOnly = $this->export('media');

        $this->import($dataOnly);
        $this->assertFileExists($this->dir . '/public/media/2026/10/a1.jpg');
        $this->assertFileExists($this->dir . '/storage/uploads/2025/01/old.pdf');

        $this->import($mediaOnly);
        $this->assertFileExists($this->dir . '/storage/data/post/p1.json');
        $this->assertFileExists($this->dir . '/storage/data/site/site.json');
    }

    public function test_export_refuses_ids_it_cannot_carry(): void
    {
        // Skipping them would delete the record locally on the next import (backup included).
        foreach (['über-uns', '.draft'] as $id) {
            $this->driver()->save('post', 'x', ['uuid' => $id, 'title' => ['en' => 'Odd']]);
            try {
                $this->export();
                $this->fail("expected a TransferException for {$id}");
            } catch (TransferException $e) {
                $this->assertStringContainsString($id, $e->getMessage());
            }
            $this->driver()->delete('post', 'x');
        }
    }

    public function test_plan_summary_counts_added_changed_removed(): void
    {
        $archive = $this->export();
        $this->working('post', 'p1', ['title' => ['en' => 'Changed locally'], 'slug' => ['en' => 'hello']]);
        $this->driver()->delete('site', 'site');
        $this->put('public/media/2026/10/a1-480.webp', 'OTHER');

        $summary = $this->import($archive);

        $this->assertSame(['p1'], $summary['tables']['post']['changed']);
        $this->assertSame(['site'], $summary['tables']['site']['added']);
        $this->assertSame(['2026/10/a1-480.webp'], $summary['media']['changed']);
        $this->assertSame([], $summary['uploads']['added']);
    }

    public function test_identical_content_is_an_empty_plan(): void
    {
        $app = $this->app();
        $importer = new Importer(ContentSet::fromApp($app), $app->container()->get(DataManager::class));
        $plan = $importer->read($this->export());

        $this->assertTrue($plan->isEmpty());
        $plan->cleanup();
    }

    private function addMemberType(array $extra = []): void
    {
        $this->writeModel('member', ['users' => 'members', 'fields' => ['email' => ['type' => 'string']]] + $extra);
        $this->driver()->save('member', 'm1', ['uuid' => 'm1', 'email' => 'a@example.com']);
    }

    public function test_export_leaves_out_user_type(): void
    {
        $this->addMemberType();

        $manifest = $this->manifestOf($this->export());

        $this->assertArrayNotHasKey('member', $manifest['tables']);
        $this->assertArrayHasKey('post', $manifest['tables']);
    }

    public function test_import_refuses_user_type_table(): void
    {
        $this->addMemberType();

        try {
            $this->import($this->craft('data/member/m1.json', '{"uuid":"m1"}', ['member' => 1]));
            $this->fail('expected a TransferException');
        } catch (TransferException $e) {
            $this->assertSame('member is not transferred (user data or not a Folio type)', $e->getMessage());
        }
    }

    public function test_backup_leaves_out_user_type(): void
    {
        $this->addMemberType();

        $file = \Preflow\Folio\Transfer\Backups::write($this->app(), 'test');

        $this->assertArrayNotHasKey('member', $this->manifestOf(\Preflow\Folio\Transfer\Backups::open($file))['tables']);
    }

    public function test_user_type_with_transfer_true_is_exported(): void
    {
        $this->addMemberType(['transfer' => true]);

        $this->assertArrayHasKey('member', $this->manifestOf($this->export())['tables']);
    }

    /** @return array{tables: array<string, int>} */
    private function manifestOf($stream): array
    {
        foreach ((new \Preflow\Folio\Transfer\TarReader($stream))->entries() as [$name, $size, $copyTo]) {
            $this->assertSame('folio-export.json', $name);
            $buf = fopen('php://temp', 'w+b');
            $copyTo($buf);
            rewind($buf);

            return json_decode((string) stream_get_contents($buf), true);
        }
        $this->fail('empty archive');
    }
}
