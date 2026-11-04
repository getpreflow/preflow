<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Redirects;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Locales;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\FrontendResolver;
use Preflow\Folio\Content\RecordValidator;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\Types\StringFieldType;
use Preflow\Folio\Publishing\Publisher;
use Preflow\Folio\Redirects\FolioRedirectTargets;
use Preflow\Folio\Redirects\LiveRecords;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidatorFactory;

/**
 * A record target's URL as the public site has it: a publishing "page" at /{slug} (en, de),
 * a non-publishing "post" at /news/{slug} shown when its status is "published", and a
 * "memo" without a frontend route.
 */
final class FolioRedirectTargetsTest extends TestCase
{
    private string $dir;

    private DataManager $dm;

    private Publisher $publisher;

    private LiveRecords $live;

    private FolioRedirectTargets $targets;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/folio_targets_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0777, true);
        $translatable = [
            'title' => ['type' => 'string', 'translatable' => true],
            'slug' => ['type' => 'string', 'translatable' => true],
        ];
        $this->model('page', ['publishing' => true, 'frontend' => ['route' => '/{slug}'], 'fields' => $translatable]);
        $this->model('post', ['publishing' => false, 'frontend' => ['route' => '/news/{slug}'], 'fields' => [
            'slug' => ['type' => 'string'],
            'status' => ['type' => 'string'],
        ]]);
        $this->model('memo', ['publishing' => true, 'fields' => $translatable]);

        $registry = new TypeRegistry($this->dir . '/models', new Locales(['en', 'de'], 'en'));
        $this->dm = new DataManager(
            drivers: ['json' => new JsonFileDriver($this->dir . '/store'), 'default' => new JsonFileDriver($this->dir . '/internal')],
            defaultDriver: 'json',
            typeRegistry: $registry,
            validatorFactory: new ValidatorFactory(new RuleFactory()),
        );
        $catalog = new TypeCatalog($this->dir . '/models');
        $fieldTypes = new FieldTypeRegistry();
        $fieldTypes->register(new StringFieldType());
        $this->publisher = new Publisher($this->dm, $catalog, new RecordValidator($fieldTypes, $this->dm));
        $resolver = FrontendResolver::fromCatalog($catalog, $this->dm);
        $this->live = new LiveRecords($this->dm, $this->publisher, $resolver);
        $this->targets = new FolioRedirectTargets($this->live, $resolver);
    }

    protected function tearDown(): void
    {
        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($rii as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }

    /** @param array<string, mixed> $config */
    private function model(string $key, array $config): void
    {
        file_put_contents($this->dir . "/models/{$key}.json", (string) json_encode(
            ['key' => $key, 'table' => $key, 'storage' => 'json', 'id_field' => 'uuid'] + $config,
        ));
    }

    /** @param array<string, mixed> $data */
    private function working(string $type, string $id, array $data): DynamicRecord
    {
        $record = DynamicRecord::fromArray($this->dm->getTypeRegistry()->get($type), ['uuid' => $id] + $data);
        $this->dm->saveType($record, false);

        return $record;
    }

    /** @param array<string, string> $slug */
    private function page(string $id, array $slug, bool $publish = true, string $type = 'page'): void
    {
        $record = $this->working($type, $id, ['title' => $slug, 'slug' => $slug, '_locales' => array_keys($slug)]);
        if ($publish) {
            $this->publisher->publish($record, null);
        }
    }

    public function test_url_in_the_requested_language(): void
    {
        $this->page('p1', ['en' => 'about', 'de' => 'ueber-uns']);

        $this->assertSame('/de/ueber-uns', $this->targets->url('page', 'p1', 'de'));
        $this->assertSame('/about', $this->targets->url('page', 'p1', 'en'));
    }

    public function test_falls_back_to_the_default_language(): void
    {
        $this->page('p2', ['en' => 'contact']);

        $this->assertSame('/contact', $this->targets->url('page', 'p2', 'de'));
        $this->assertSame('/contact', $this->targets->url('page', 'p2', 'fr'), 'a language the site does not have');
    }

    public function test_unpublished_or_deleted_target_gives_null(): void
    {
        $this->page('draft', ['en' => 'draft'], publish: false);
        $this->assertNull($this->live->find('page', 'draft'), 'a working copy is not public');
        $this->assertNull($this->targets->url('page', 'draft', 'en'));

        $this->page('p3', ['en' => 'old-news']);
        $this->assertSame('/old-news', $this->targets->url('page', 'p3', 'en'));
        // The working copy moves on; the public site still shows the snapshot.
        $this->working('page', 'p3', ['title' => ['en' => 'x'], 'slug' => ['en' => 'renamed-draft'], '_locales' => ['en']]);
        $this->assertSame('/old-news', $this->targets->url('page', 'p3', 'en'));
        $this->assertSame('old-news', $this->live->find('page', 'p3')?->localized('en')->get('slug'));

        $this->publisher->unpublish('page', 'p3');
        $this->assertNull($this->targets->url('page', 'p3', 'en'));

        $this->assertNull($this->targets->url('page', 'missing', 'en'), 'deleted');

        $this->page('m1', ['en' => 'memo'], type: 'memo');
        $this->assertNull($this->live->find('memo', 'm1'), 'no frontend route');
        $this->assertNull($this->targets->url('memo', 'm1', 'en'));
        $this->assertNull($this->targets->url('nonexistent', 'x', 'en'));
    }

    public function test_non_publishing_type_respects_its_published_field(): void
    {
        $this->working('post', 'n1', ['slug' => 'hello', 'status' => 'draft']);
        $this->assertNull($this->live->find('post', 'n1'));
        $this->assertNull($this->targets->url('post', 'n1', 'en'));

        $this->working('post', 'n1', ['slug' => 'hello', 'status' => 'published']);
        $this->assertSame('n1', $this->live->find('post', 'n1')?->getId());
        $this->assertSame('/news/hello', $this->targets->url('post', 'n1', 'en'));
        $this->assertSame('/de/news/hello', $this->targets->url('post', 'n1', 'de'), 'untranslated types have every language');
    }
}
