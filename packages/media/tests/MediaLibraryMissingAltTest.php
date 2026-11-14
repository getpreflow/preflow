<?php

declare(strict_types=1);

namespace Preflow\Media\Tests;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\Locales;
use Preflow\Data\Schema\RecordTableSync;
use Preflow\Data\TypeRegistry;
use Preflow\Media\MediaLibrary;
use Preflow\Media\Tests\Support\MediaFixtures;

/**
 * "Missing alt text": query(missingAlt: true) and missingAltCount() name the same set: live
 * images (not binned, copies hidden as in every unsearched listing, private ones too) whose alt
 * text in the default locale is null or empty. Documents and videos are never in it.
 */
#[RequiresPhpExtension('gd')]
#[RequiresPhpExtension('fileinfo')]
final class MediaLibraryMissingAltTest extends TestCase
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

    public function test_on_json_storage(): void
    {
        $this->assertMissingAlt($this->library($this->config()));
    }

    public function test_on_sqlite(): void
    {
        mkdir($this->assetRoot . '/models');
        $dm = new DataManager(drivers: ['sqlite' => new SqliteDriver(new \PDO('sqlite::memory:'))], defaultDriver: 'sqlite', typeRegistry: new TypeRegistry($this->assetRoot . '/models'));
        $lib = new MediaLibrary($dm, new Locales(['en', 'de'], 'en'), $this->assetRoot . '/media', '/media', ['storage' => 'sqlite'] + $this->config());
        (new RecordTableSync($dm))->sync($lib->definition());

        $this->assertMissingAlt($lib);
    }

    private function assertMissingAlt(MediaLibrary $lib): void
    {
        $described = $lib->upload($this->upload($this->png(40, 30), 'described.png'), null);
        $lib->update($described->id, ['alt' => ['en' => 'A red barn']]);
        $fresh = $lib->upload($this->upload($this->png(40, 30), 'fresh.png'), null); // never had one
        $germanOnly = $lib->upload($this->upload($this->png(40, 30), 'german.png'), null);
        $lib->update($germanOnly->id, ['alt' => ['de' => 'Eine Scheune']]);
        $cleared = $lib->upload($this->upload($this->png(40, 30), 'cleared.png'), null);
        $lib->update($cleared->id, ['alt' => ['en' => 'Was here']]);
        $lib->update($cleared->id, ['alt' => ['en' => '  ']]); // trimmed to ''
        $private = $lib->upload($this->upload($this->png(40, 30), 'private.png'), null, private: true);
        $document = $lib->upload($this->upload("notes\n", 'notes.txt'), null);
        $binned = $lib->upload($this->upload($this->png(40, 30), 'binned.png'), null);
        $lib->delete($binned->id);
        $copy = $lib->makeCopy($described->id, null);
        $lib->update($copy->id, ['alt' => ['en' => '']]);

        $missing = self::sorted([$fresh->id, $germanOnly->id, $cleared->id, $private->id]);
        $page = $lib->query(missingAlt: true);
        $this->assertSame($missing, self::idsOf($page->items));
        $this->assertSame(4, $page->total);
        $this->assertSame($page->total, $lib->missingAltCount(), 'the toggle counts what the filter lists');
        $this->assertNotContains($document->id, self::idsOf($page->items));

        // Combines with the other conditions; without it, nothing changes.
        $this->assertSame([$fresh->id], self::idsOf($lib->query(search: 'fresh', missingAlt: true)->items));
        $this->assertSame([], $lib->query(kind: 'document', missingAlt: true)->items);
        $this->assertSame([$private->id], self::idsOf($lib->query(filters: ['private' => true], missingAlt: true)->items));
        // The count takes the same conditions as the list, so a toggle beside a search counts what it shows.
        $this->assertSame(1, $lib->missingAltCount(search: 'fresh'));
        $this->assertSame(0, $lib->missingAltCount(kind: 'document'));
        $this->assertSame(4, $lib->missingAltCount(kind: 'image'));
        $this->assertSame(1, $lib->missingAltCount(filters: ['private' => true]));
        $this->assertSame(6, $lib->query()->total);
        $this->assertSame(6, $lib->query(missingAlt: false)->total);

        // Writing the alt text takes it out of the set.
        $lib->update($fresh->id, ['alt' => ['en' => 'A fresh field']]);
        $this->assertSame(3, $lib->missingAltCount());
        $this->assertSame(3, $lib->query(missingAlt: true)->total);
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        return [
            'versions_dir' => $this->assetRoot . '/versions',
            'private_dir' => $this->assetRoot . '/private',
            'bin_dir' => $this->assetRoot . '/bin',
        ];
    }

    /**
     * @param list<\Preflow\Media\Asset> $items
     * @return list<string>
     */
    private static function idsOf(array $items): array
    {
        return self::sorted(array_map(static fn ($a) => $a->id, $items));
    }

    /**
     * @param list<string> $ids
     * @return list<string>
     */
    private static function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }
}
