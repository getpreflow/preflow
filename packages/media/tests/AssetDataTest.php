<?php

declare(strict_types=1);

namespace Preflow\Media\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DynamicRecord;
use Preflow\Media\Asset;
use Preflow\Media\AssetVersion;
use Preflow\Media\Preview;
use Preflow\Media\Tests\Support\MediaFixtures;

final class AssetDataTest extends TestCase
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

    /** @param array<string, mixed> $data */
    private function record(array $data): DynamicRecord
    {
        return DynamicRecord::fromArray($this->library()->definition(), $data);
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
        $this->assertSame([], $asset->extra);
        $this->assertNull($asset->note);
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
        $lib = $this->library();
        $asset = $lib->upload($this->upload($this->png(10, 10), 'a.png'), null);
        $data = $asset->toData();
        $data['version'] = 3;
        $data['versions'] = json_encode([(new AssetVersion(1, 'p', 'image/png', 1, null, null, [], 'now', null))->toArray()]);
        $data['preview'] = json_encode((new Preview('p.png', 4, 5))->toArray());
        $data['palette'] = json_encode(['#000000']);
        $data['extra'] = json_encode(['a' => 1]);

        $back = Asset::fromRecord($this->record($data));
        $this->assertSame(3, $back->version);
        $this->assertCount(1, $back->versions);
        $this->assertSame(4, $back->preview?->width);
        $this->assertSame(['#000000'], $back->palette);
        $this->assertSame(['a' => 1], $back->extra);
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
}
