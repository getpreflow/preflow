<?php

declare(strict_types=1);

namespace Preflow\Media\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DynamicRecord;
use Preflow\Media\Asset;
use Preflow\Media\AssetVersion;
use Preflow\Media\CropRect;
use Preflow\Media\Preview;
use Preflow\Media\Tests\Support\MediaFixtures;

final class AssetDataTest extends TestCase
{
    use MediaFixtures;

    /** Extra fields: each is a column of its own. */
    private const array FIELDS = ['tags' => ['type' => 'relation', 'relation' => ['to' => 'tag', 'multiple' => true]], 'rating' => ['type' => 'number']];

    protected function setUp(): void
    {
        $this->makeAssetRoot();
    }

    protected function tearDown(): void
    {
        $this->removeAssetRoot();
    }

    /** @param array<string, mixed> $data */
    private function record(array $data): DynamicRecord
    {
        return DynamicRecord::fromArray($this->library(['fields' => self::FIELDS])->definition(), $data);
    }

    public function test_a_record_without_the_new_keys_reads_as_version_one(): void
    {
        $asset = Asset::fromRecord($this->record(['uuid' => 'a', 'path' => '2026/10/a.jpg', 'mime' => 'image/jpeg']));

        $this->assertSame(1, $asset->version);
        $this->assertSame([], $asset->versions);
        $this->assertNull($asset->preview);
        $this->assertNull($asset->color);
        $this->assertSame([], $asset->palette);
        $this->assertNull($asset->textColor);
        $this->assertSame(['tags' => [], 'rating' => ''], $asset->extra);
        $this->assertNull($asset->note);
        $this->assertSame(0, $asset->rotation);
        $this->assertFalse($asset->mirrored);
        $this->assertNull($asset->crop);
        $this->assertSame([], $asset->crops);
        $this->assertNull($asset->master);
        $this->assertFalse($asset->isEdited());
    }

    public function test_every_new_property_round_trips(): void
    {
        $version = new AssetVersion(1, 'ab/v1.jpg', 'image/jpeg', 10, 20, 30, ['k' => 'v'], '2026-10-25T10:00:00+00:00', 'u1');
        $asset = new Asset(
            id: 'a', path: '2026/10/a-v2.jpg', originalName: 'a.jpg', mime: 'image/jpeg', size: 5, width: 2, height: 3,
            duration: null, alt: [], title: '', focalX: 50, focalY: 50, posterId: null, variants: [],
            createdAt: '2026-10-25T10:00:00+00:00', createdBy: null,
            version: 2, versions: [$version], preview: new Preview('2026/10/a-v2-preview.png', 1131, 1600),
            color: '#112233', palette: ['#112233', '#445566', '#778899', '#aabbcc', '#ddeeff'], textColor: '#ffffff',
            extra: ['tags' => ['x'], 'rating' => 4], note: 'needs Imagick for colour-accurate variants',
        );

        $data = $asset->toData();
        $this->assertSame(2, $data['version']);
        $this->assertSame('#112233', $data['color']);
        $this->assertSame(['x'], $data['tags']); // an extra field is a column of its own
        $this->assertSame(4, $data['rating']);
        $this->assertArrayNotHasKey('extra', $data);

        $back = Asset::fromRecord($this->record($data));
        $this->assertSame(2, $back->version);
        $this->assertEquals([$version], $back->versions);
        $this->assertEquals(new Preview('2026/10/a-v2-preview.png', 1131, 1600), $back->preview);
        $this->assertSame('#112233', $back->color);
        $this->assertSame($asset->palette, $back->palette);
        $this->assertSame('#ffffff', $back->textColor);
        $this->assertSame(['tags' => ['x'], 'rating' => 4], $back->extra);
        $this->assertSame('needs Imagick for colour-accurate variants', $back->note);
    }

    public function test_new_values_survive_json_text_storage(): void
    {
        $lib = $this->library(['fields' => self::FIELDS]);
        $asset = $lib->upload($this->upload($this->png(10, 10), 'a.png'), null);
        $data = $asset->toData();
        $data['version'] = 3;
        $data['versions'] = json_encode([(new AssetVersion(1, 'p', 'image/png', 1, null, null, [], 'now', null))->toArray()]);
        $data['preview'] = json_encode((new Preview('p.png', 4, 5))->toArray());
        $data['palette'] = json_encode(['#000000']);
        $data['tags'] = json_encode(['t1', 't2']);
        $data['rating'] = '7';

        $back = Asset::fromRecord($this->record($data));
        $this->assertSame(3, $back->version);
        $this->assertCount(1, $back->versions);
        $this->assertSame(4, $back->preview?->width);
        $this->assertSame(['#000000'], $back->palette);
        $this->assertSame(['tags' => ['t1', 't2'], 'rating' => '7'], $back->extra);
    }

    public function test_malformed_version_and_preview_data_is_ignored(): void
    {
        $asset = Asset::fromRecord($this->record([
            'uuid' => 'a', 'path' => 'a.jpg', 'versions' => [['number' => 'x'], 'junk'], 'preview' => ['path' => 'p'],
        ]));

        $this->assertSame([], $asset->versions);
        $this->assertNull($asset->preview);
    }

    public function test_versioned_paths(): void
    {
        $lib = $this->library();
        $id = str_repeat('ab', 16);

        $this->assertSame("2026/10/{$id}.jpg", $lib->versionedPath($id, 'jpg', 1, '2026/10'));
        $this->assertSame("2026/10/{$id}-v3.jpg", $lib->versionedPath($id, 'jpg', 3, '2026/10'));
    }

    public function test_edit_data_round_trips(): void
    {
        $asset = new Asset(
            id: 'a', path: 'a.jpg', originalName: 'a.jpg', mime: 'image/jpeg', size: 5, width: 2, height: 3,
            duration: null, alt: [], title: '', focalX: 50, focalY: 50, posterId: null, variants: [],
            createdAt: 'now', createdBy: null,
            rotation: 3, mirrored: true, crop: CropRect::of(10, 20, 30, 40),
            crops: ['cover-thumb' => CropRect::of(0, 0, 50, 50)], master: new Preview('a-edit-1a2b3c4d.jpg', 30, 40),
        );
        $this->assertTrue($asset->isEdited());

        $data = $asset->toData();
        $this->assertSame(3, $data['rotation']);
        $this->assertSame('h', $data['flip']);
        $this->assertSame(['x' => 10.0, 'y' => 20.0, 'width' => 30.0, 'height' => 40.0], $data['crop']);

        foreach ([$data, array_map(static fn ($v) => is_array($v) ? json_encode($v) : $v, $data)] as $stored) {
            $back = Asset::fromRecord($this->record($stored));
            $this->assertSame(3, $back->rotation);
            $this->assertTrue($back->mirrored);
            $this->assertEquals(CropRect::of(10, 20, 30, 40), $back->crop);
            $this->assertEquals(['cover-thumb' => CropRect::of(0, 0, 50, 50)], $back->crops);
            $this->assertEquals(new Preview('a-edit-1a2b3c4d.jpg', 30, 40), $back->master);
        }
    }

    public function test_is_edited_per_property_and_unedited_defaults_store_empty(): void
    {
        $make = static fn (array $over) => new Asset(...[
            'id' => 'a', 'path' => 'a.jpg', 'originalName' => 'a.jpg', 'mime' => 'image/jpeg', 'size' => 1, 'width' => 2, 'height' => 3,
            'duration' => null, 'alt' => [], 'title' => '', 'focalX' => 50, 'focalY' => 50, 'posterId' => null, 'variants' => [],
            'createdAt' => 'now', 'createdBy' => null,
        ] + $over);

        $this->assertFalse($make([])->isEdited());
        $this->assertTrue($make(['rotation' => 1])->isEdited());
        $this->assertTrue($make(['mirrored' => true])->isEdited());
        $this->assertTrue($make(['crop' => CropRect::of(0, 0, 50, 50)])->isEdited());
        $this->assertFalse($make(['crops' => ['x-y' => CropRect::of(0, 0, 50, 50)]])->isEdited());

        $data = $make([])->toData();
        $this->assertSame(0, $data['rotation']);
        $this->assertSame('', $data['flip']);
        $this->assertSame([], $data['crop']);
        $this->assertSame([], $data['crops']);
        $this->assertSame([], $data['master']);
    }

    public function test_malformed_edit_data_reads_as_unedited(): void
    {
        $asset = Asset::fromRecord($this->record([
            'uuid' => 'a', 'path' => 'a.jpg', 'rotation' => '9', 'flip' => 'x', 'crop' => 'junk', 'crops' => ['bad' => 'x', 'Bad Name' => ['x' => 1]], 'master' => ['path' => 'p'],
        ]));

        $this->assertSame(1, $asset->rotation); // 9 turns = 1 turn
        $this->assertFalse($asset->mirrored);
        $this->assertNull($asset->crop);
        $this->assertSame([], $asset->crops);
        $this->assertNull($asset->master);
    }
}
