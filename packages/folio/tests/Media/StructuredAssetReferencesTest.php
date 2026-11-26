<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Media;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Preflow\Blocks\BlockRegistry;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Assets\AssetReferences;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\HandlesUpload;
use Preflow\Folio\Field\ReferencesAssets;
use Preflow\Folio\Field\Types\AssetFieldType;
use Preflow\Folio\Field\Types\EmbedFieldType;
use Preflow\Folio\Field\Types\LinkFieldType;
use Preflow\Folio\Field\Types\StringFieldType;
use Preflow\Folio\Media\FolioRecordReferences;
use Preflow\Folio\Tests\Fixtures\Blocks\CallToActionBlock;
use Preflow\Folio\Tests\Fixtures\Blocks\VenueBlock;
use Preflow\Folio\Tests\Support\AssetFixtures;
use Preflow\Media\Cleanup\AssetCleanup;
use Preflow\Media\Usage\AssetInUseException;
use Preflow\Media\Usage\AssetUsage;

/**
 * Files held by an embed's poster and a link to a file count as used (ReferencesAssets, 0.91):
 * on the record and inside blocks, a delete is refused and cleanup never bins them; an unused
 * file still goes.
 */
#[RequiresPhpExtension('gd')]
final class StructuredAssetReferencesTest extends TestCase
{
    use AssetFixtures;

    private DataManager $dm;

    private TypeRegistry $registry;

    protected function setUp(): void
    {
        $this->makeAssetRoot();
        mkdir($this->assetRoot . '/models', 0777, true);
        file_put_contents($this->assetRoot . '/models/venue.json', (string) json_encode([
            'key' => 'venue', 'table' => 'venue', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Venues',
            'fields' => [
                'title' => ['type' => 'string'],
                'video' => ['type' => 'embed'],
                'download' => ['type' => 'link'],
                'cover' => ['type' => 'asset'],
                'sections' => ['type' => 'blocks'],
            ],
        ]));
        $this->registry = new TypeRegistry($this->assetRoot . '/models');
        $this->dm = new DataManager(drivers: ['json' => new JsonFileDriver($this->assetRoot . '/store')], defaultDriver: 'json', typeRegistry: $this->registry);
    }

    protected function tearDown(): void
    {
        $this->removeAssetRoot();
    }

    public function test_the_walker_finds_posters_and_file_links_on_the_record_and_in_blocks(): void
    {
        $data = $this->data('p1', 'l1', 'p2', 'l2');

        $this->assertSame(['p1', 'l1', 'c1', 'p2', 'l2'], $this->references()->collect($this->registry->get('venue'), $data));

        $mapped = $this->references()->map($this->registry->get('venue'), array_replace($data, ['download' => '{"kind":"asset","target":"l1","label":null,"new_tab":false}']), static fn (string $id): string => strtoupper($id));
        $this->assertSame('P1', $mapped['video']['poster']);
        $this->assertSame('{"kind":"asset","target":"L1","label":null,"new_tab":false}', $mapped['download'], 'JSON text stays JSON text');
        $this->assertSame('L2', $mapped['sections'][1]['download']['target']);
        $this->assertSame('https://example.org', $mapped['sections'][1]['button']['target'], 'a link to a web address holds no file');
    }

    public function test_a_poster_and_a_linked_file_cannot_be_deleted_and_cleanup_keeps_them(): void
    {
        $usage = new AssetUsage();
        $library = $this->library(usage: $usage);
        $usage->register($this->usage());
        $ids = [];
        foreach (['poster', 'linked', 'blockposter', 'blocklinked', 'unused'] as $name) {
            $ids[$name] = $library->upload($this->upload($this->png(10, 10), $name . '.png'), null)->id;
        }
        $this->dm->saveType(DynamicRecord::fromArray($this->registry->get('venue'), ['uuid' => 'v1', 'title' => 'Hall'] + $this->data($ids['poster'], $ids['linked'], $ids['blockposter'], $ids['blocklinked'])), false);

        foreach (['poster', 'linked', 'blockposter', 'blocklinked'] as $name) {
            try {
                $library->delete($ids[$name]);
                $this->fail("deleted the {$name} file a record uses");
            } catch (AssetInUseException $e) {
                $this->assertSame('Hall', $e->usages[0]->label, $name);
            }
            $this->assertNotNull($library->find($ids[$name]), $name);
        }

        $cleanup = new AssetCleanup($library, $usage, ['enabled' => true, 'min_age_days' => 0, 'bin_days' => 30], static fn (): \DateTimeImmutable => new \DateTimeImmutable('+1 day'));
        $candidates = array_map(static fn ($c): string => $c->asset->id, $cleanup->candidates());
        $this->assertSame([$ids['unused']], $candidates, 'only the unused file is a cleanup candidate');
        $library->delete($ids['unused']);
        $this->assertNull($library->find($ids['unused']), 'an unused file still goes');
    }

    public function test_every_built_in_type_that_holds_files_is_in_the_file_holding_list(): void
    {
        $checked = 0;
        foreach (glob(dirname(__DIR__, 2) . '/src/Field/Types/*FieldType.php') ?: [] as $file) {
            $reflection = new \ReflectionClass('Preflow\\Folio\\Field\\Types\\' . basename($file, '.php'));
            if ($reflection->isAbstract()) {
                continue;
            }
            $key = $reflection->newInstanceWithoutConstructor()->key(); // key() returns a literal
            $holds = $reflection->implementsInterface(ReferencesAssets::class)
                || $reflection->implementsInterface(HandlesUpload::class)
                || in_array($key, ['blocks', 'matrix'], true); // they nest other fields / have assets()
            $this->assertSame($holds, in_array($key, TypeCatalog::FILE_HOLDING_FIELD_TYPES, true), "field type \"{$key}\"");
            $checked++;
        }
        $this->assertGreaterThan(20, $checked, 'every built-in field type was looked at');
        $dir = $this->assetRoot . '/kept';
        mkdir($dir);
        file_put_contents($dir . '/memo.json', (string) json_encode(['key' => 'memo', 'transfer' => false, 'fields' => ['video' => ['type' => 'embed']]]));
        try {
            (new TypeCatalog($dir))->all();
            $this->fail('a server-only type with an embed field was accepted');
        } catch (\Preflow\Folio\Content\FrontendConfigException $e) {
            $this->assertStringContainsString('file fields are not supported on it yet', $e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function data(string $poster, string $linked, string $blockPoster, string $blockLinked): array
    {
        return [
            'video' => ['provider' => 'youtube', 'id' => 'aqz-KE-bpKQ', 'url' => 'https://youtu.be/aqz-KE-bpKQ', 'poster' => $poster],
            'download' => ['kind' => 'asset', 'target' => $linked, 'label' => 'Prices', 'new_tab' => false],
            'cover' => 'c1',
            'sections' => [
                ['_uid' => 'aaaaaaaaaaaaaaaa', '_block' => 'venue_info', 'video' => (string) json_encode(['provider' => 'vimeo', 'id' => '76979871', 'url' => 'https://vimeo.com/76979871', 'poster' => $blockPoster])],
                ['_uid' => 'bbbbbbbbbbbbbbbb', '_block' => 'call_to_action', 'heading' => 'Go',
                    'button' => ['kind' => 'url', 'target' => 'https://example.org', 'label' => 'Go', 'new_tab' => false],
                    'download' => ['kind' => 'asset', 'target' => $blockLinked, 'label' => 'PDF', 'new_tab' => false]],
            ],
        ];
    }

    private function references(): AssetReferences
    {
        $types = new FieldTypeRegistry();
        $types->register(new StringFieldType());
        $types->register(new AssetFieldType($this->assetRoot . '/uploads', '/uploads'));
        $types->register(new EmbedFieldType());
        $types->register(new LinkFieldType());

        return new AssetReferences(new BlockRegistry([VenueBlock::class, CallToActionBlock::class]), static fn (): FieldTypeRegistry => $types);
    }

    private function usage(): FolioRecordReferences
    {
        return new FolioRecordReferences($this->references(), $this->dm, new TypeCatalog($this->assetRoot . '/models'), $this->registry, '/folio');
    }
}
