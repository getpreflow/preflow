<?php

declare(strict_types=1);

namespace Preflow\Media\Tests\Api;

use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\TestCase;
use Preflow\Blocks\BlockRegistry;
use Preflow\Blocks\Layouts\AreaDefinition;
use Preflow\Blocks\Layouts\AreaLayout;
use Preflow\Blocks\Layouts\AreaLayoutSet;
use Preflow\Blocks\Layouts\AreaLayoutSets;
use Preflow\Blocks\Layouts\LayoutRegistry;
use Preflow\Blocks\Layouts\LayoutResource;
use Preflow\Blocks\Layouts\PageLayouts;
use Preflow\Blocks\Values\AreasFieldValue;
use Preflow\Blocks\Values\BlocksFieldValue;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Locales;
use Preflow\Data\Records\OutputPart;
use Preflow\Data\Records\ReadOptions;
use Preflow\Data\Records\RecordOutput;
use Preflow\Data\Records\RecordParts;
use Preflow\Data\TypeRegistry;
use Preflow\Media\Api\MediaOutputPart;
use Preflow\Media\Asset;
use Preflow\Media\Image\GdBackend;
use Preflow\Media\Image\ImageWidths;
use Preflow\Media\Image\VariantGenerator;
use Preflow\Media\ImageTag;
use Preflow\Media\MediaLibrary;
use Preflow\Media\MediaServiceProvider;
use Preflow\Media\Tests\Fixtures\Api\MediaCard;
use Preflow\Media\Tests\Fixtures\Api\MediaColumns;
use Preflow\Media\Tests\Support\FixedLocale;
use Preflow\Media\Tests\Support\MediaFixtures;
use Preflow\View\AssetCollector;
use Preflow\View\NonceGenerator;

/**
 * The media package's part of the read output (0.93): with ReadOptions(with: ['media']) a
 * top-level "_media" map of every asset the record's fields reference (blocks and their columns
 * included) to its URL, kind, MIME type, size, alt text and image sizes. Field values stay ids.
 */
#[RequiresFunction('imagewebp')]
final class MediaOutputPartTest extends TestCase
{
    use MediaFixtures;

    private MediaLibrary $lib;
    private DataManager $dm;
    private RecordOutput $output;

    protected function setUp(): void
    {
        $this->makeAssetRoot();
        $this->lib = $this->library([], new VariantGenerator(new GdBackend(), [480, 768], 75));
        mkdir($this->assetRoot . '/models', 0777, true);
        file_put_contents($this->assetRoot . '/models/post.json', (string) json_encode([
            'key' => 'post', 'table' => 'post', 'storage' => 'json', 'id_field' => 'uuid',
            'fields' => [
                'kind' => ['type' => 'string'],
                'cover' => ['type' => 'asset'],
                'gallery' => ['type' => 'asset', 'asset' => ['multiple' => true]],
                'video' => ['type' => 'asset', 'show_when' => ['field' => 'kind', '=' => 'video']],
                'sections' => ['type' => 'blocks'],
            ],
        ]));
        $locales = new Locales(['en', 'de'], 'en');
        $this->dm = new DataManager(['json' => new JsonFileDriver($this->assetRoot . '/posts')], 'json', new TypeRegistry($this->assetRoot . '/models', $locales));
        $this->registerBlocks($this->dm);
        $parts = new RecordParts();
        $this->output = new RecordOutput($this->dm, $parts);
        $parts->output(fn (): OutputPart => new MediaOutputPart(fn (): RecordOutput => $this->output, fn (): MediaLibrary => $this->lib, fn (): ImageTag => $this->tag(), $locales));
    }

    protected function tearDown(): void
    {
        $this->removeAssetRoot();
    }

    public function test_media_only_when_asked(): void
    {
        $sea = $this->image('sea.png', ['en' => 'Sea', 'de' => 'Meer']);
        $sky = $this->image('sky.png', ['en' => 'Sky']);
        $record = $this->post(['cover' => $sea->id, 'gallery' => [$sky->id, 'gone']]);

        $plain = $this->output->toArray($record);
        $this->assertArrayNotHasKey('_media', $plain, 'not asked: no URLs, no lookups');
        $this->assertSame($sea->id, $plain['cover']);

        $with = $this->output->toArray($record, new ReadOptions(with: [MediaOutputPart::NAME]));
        $this->assertSame('media', MediaOutputPart::NAME);
        $this->assertSame($sea->id, $with['cover'], 'a field\'s value stays its id: its shape never depends on the query');
        $this->assertSame([$sky->id, 'gone'], $with['gallery']);
        $this->assertSame([$sea->id, $sky->id], array_keys($with['_media']), 'every referenced asset once; a missing one is left out');
        $base = '/media/' . dirname($sea->path) . '/' . $sea->id;
        $this->assertSame([
            'url' => '/media/' . $sea->path,
            'kind' => 'image',
            'mime' => 'image/png',
            'width' => 1200,
            'height' => 800,
            'alt' => ['en' => 'Sea', 'de' => 'Meer'],
            'sizes' => [480 => $base . '-480.webp', 768 => $base . '-768.webp'],
        ], $with['_media'][$sea->id]);
        $this->assertSame(['en' => 'Sky', 'de' => null], $with['_media'][$sky->id]['alt'], 'every language, null without a text');
        $json = (string) json_encode($with['_media'][$sea->id], JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('"sizes":{"480":"' . $base . '-480.webp","768":', $json, 'sizes is an object of width => URL');

        $de = $this->output->toArray($record, new ReadOptions(locale: 'de', with: ['media']));
        $this->assertSame('Meer', $de['_media'][$sea->id]['alt'], 'with a language: its alt text');
        $this->assertSame('Sky', $de['_media'][$sky->id]['alt'], 'without one: the fallback language\'s');

        $none = $this->output->toArray($this->post(['uuid' => 'p2']), new ReadOptions(with: ['media']));
        $this->assertSame('{}', json_encode($none['_media']), 'asked, but nothing referenced: an empty object');
    }

    public function test_media_covers_block_and_column_assets(): void
    {
        [$a, $b, $c, $d] = [$this->image('a.png'), $this->image('b.png'), $this->image('c.png'), $this->image('d.png')];
        $record = $this->post([
            'kind' => 'text',
            'video' => $d->id, // hidden by its show_when: it reads null, so its asset is not listed
            'sections' => [
                ['_uid' => 'aaaaaaaaaaaaaaaa', '_block' => 'media_card', 'image' => $a->id],
                ['_uid' => 'bbbbbbbbbbbbbbbb', '_block' => 'media_columns', 'columns' => ['layout' => '1-1', 'areas' => [
                    'c2' => [['_uid' => 'cccccccccccccccc', '_block' => 'media_card', 'gallery' => [$b->id, $c->id, $a->id]]],
                ]]],
            ],
        ]);

        $out = $this->output->toArray($record, new ReadOptions(with: ['media']));

        $this->assertNull($out['video']);
        $this->assertSame([$a->id, $b->id, $c->id], array_keys($out['_media']));
        $this->assertSame($a->id, $out['sections'][0]['image'], 'block values stay ids too');
    }

    public function test_sizes_follow_the_preset_widths(): void
    {
        $asset = $this->image('wide.png');
        $base = '/media/' . dirname($asset->path) . '/' . $asset->id;
        $presets = ['card' => ['widths' => [320, 640]]];
        $tag = $this->tag($presets);

        $this->assertSame([320 => $base . '-320.webp', 640 => $base . '-640.webp'], $tag->sizes($asset, 'card'));
        $this->assertSame([480 => $base . '-480.webp', 768 => $base . '-768.webp'], $tag->sizes($asset), 'without a preset: the default widths');
        $html = $tag->render($asset->id, 'card');
        foreach ($tag->sizes($asset, 'card') as $width => $url) {
            $this->assertStringContainsString($url . ' ' . $width . 'w', $html, 'the widths the image tag\'s WebP srcset lists');
        }
        $small = $this->image('small.png', [], 600, 400);
        $this->assertSame([480, 600], array_keys($tag->sizes($small)), 'up to the original\'s width');
        $doc = $this->lib->importFile($this->pdfFile(), 'a.pdf', null);
        $this->assertSame([], $tag->sizes($doc), 'a document has no image sizes');
    }

    public function test_media_service_provider_registers_the_part(): void
    {
        $app = $this->appInAssetRoot(['app' => ['locale' => 'en']]);
        $app->container()->instance(RecordParts::class, new RecordParts());
        $app->registerProvider(new MediaServiceProvider());

        $parts = $app->container()->get(RecordParts::class)->outputs();
        $this->assertCount(1, $parts);
        $this->assertInstanceOf(MediaOutputPart::class, $parts[0]);
    }

    /** @param array<string, mixed> $values */
    private function post(array $values): DynamicRecord
    {
        $this->dm->saveType(DynamicRecord::fromArray($this->dm->getTypeRegistry()->get('post'), ['uuid' => 'p1', ...$values]), validate: false);

        return $this->dm->findType('post', (string) ($values['uuid'] ?? 'p1')) ?? self::fail('no post');
    }

    /** @param array<string, string> $alt */
    private function image(string $name, array $alt = [], int $width = 1200, int $height = 800): Asset
    {
        $asset = $this->lib->upload($this->upload($this->png($width, $height), $name), null);

        return $alt === [] ? $asset : $this->lib->update($asset->id, ['alt' => $alt]);
    }

    private function pdfFile(): string
    {
        $file = $this->assetRoot . '/a.pdf';
        file_put_contents($file, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");

        return $file;
    }

    /** @param array<string, array<string, mixed>> $presets */
    private function tag(array $presets = []): ImageTag
    {
        return new ImageTag($this->lib, new FixedLocale('en'), $presets, new AssetCollector(new NonceGenerator()), '', ImageWidths::fromConfig($presets, [480, 768]));
    }

    /** The blocks and areas values on the record manager's kinds, as BlocksServiceProvider registers them. */
    private function registerBlocks(DataManager $dm): void
    {
        $kinds = $dm->fieldKinds();
        $registry = new BlockRegistry([MediaCard::class, MediaColumns::class]);
        $sets = new AreaLayoutSets();
        $sets->add('pair', static fn (): AreaLayoutSet => new AreaLayoutSet('pair', [
            new AreaLayout('1-1', 'Two columns', ['c1' => new AreaDefinition('c1', 'One'), 'c2' => new AreaDefinition('c2', 'Two')]),
        ], fallback: '1-1'));
        $blocks = new BlocksFieldValue(static fn (): BlockRegistry => $registry, $kinds);
        $kinds->value('blocks', $blocks);
        $resource = new LayoutResource(new PageLayouts(new LayoutRegistry(), $dm->getTypeRegistry() ?? self::fail('no types'), $registry), $registry, $sets);
        $kinds->value('areas', new AreasFieldValue(
            $blocks,
            static fn (): LayoutResource => $resource,
            static fn (): never => throw new \LogicException('no writes here'),
        ));
    }
}
