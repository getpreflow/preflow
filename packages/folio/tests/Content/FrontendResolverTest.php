<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Content;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Locales;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\FrontendConfigException;
use Preflow\Folio\Content\FrontendResolver;
use Preflow\Folio\Content\FrontendRoute;
use Preflow\Folio\Content\TypeCatalog;

final class FrontendResolverTest extends TestCase
{
    private string $models;
    private string $store;
    private DataManager $dm;

    protected function setUp(): void
    {
        $this->models = sys_get_temp_dir() . '/folio_fr_models_' . bin2hex(random_bytes(4));
        $this->store = sys_get_temp_dir() . '/folio_fr_store_' . bin2hex(random_bytes(4));
        mkdir($this->models, 0777, true);
        mkdir($this->store, 0777, true);

        file_put_contents($this->models . '/page.json', json_encode([
            'key' => 'page',
            'table' => 'page',
            'storage' => 'json',
            'id_field' => 'uuid',
            'fields' => [
                'title' => ['type' => 'string'],
                'slug' => ['type' => 'string', 'searchable' => true],
                'body' => ['type' => 'text'],
                'status' => ['type' => 'string'],
            ],
        ]));

        $registry = new TypeRegistry($this->models);
        $this->dm = new DataManager(
            drivers: ['json' => new JsonFileDriver($this->store)],
            defaultDriver: 'json',
            typeRegistry: $registry,
        );

        $this->seed('1', 'home', 'published');
        $this->seed('2', 'draft-page', 'draft');
    }

    private function seed(string $id, string $slug, string $status): void
    {
        $registry = new TypeRegistry($this->models);
        $rec = DynamicRecord::fromArray($registry->get('page'), [
            'uuid' => $id, 'title' => ucfirst($slug), 'slug' => $slug, 'body' => 'x', 'status' => $status,
        ]);
        $this->dm->saveType($rec, validate: false);
    }

    public function test_resolves_published_by_slug(): void
    {
        $resolver = new FrontendResolver($this->dm, 'page');
        $rec = $resolver->resolve('/home');

        $this->assertNotNull($rec);
        $this->assertSame('home', $rec->get('slug'));
    }

    public function test_unknown_slug_is_null(): void
    {
        $resolver = new FrontendResolver($this->dm, 'page');
        $this->assertNull($resolver->resolve('/missing'));
    }

    public function test_draft_is_not_resolvable(): void
    {
        $resolver = new FrontendResolver($this->dm, 'page');
        $this->assertNull($resolver->resolve('/draft-page'));
    }

    /**
     * A type without publishing: a render that reads working copies (a whole-page preview) finds a
     * record whose status is not published yet; matchPublished() and a visitor's render do not.
     */
    public function test_a_working_copies_render_finds_an_unpublished_record_of_a_type_without_publishing(): void
    {
        $live = new \Preflow\Publishing\LiveContent($this->dm, new \Preflow\Publishing\SchemaPublishingTypes($this->dm));
        $resolver = new FrontendResolver($this->dm, 'page', null, null, $live);
        $visitor = \Preflow\Testing\ContextFactory::forTest();

        $preview = \Preflow\Core\Context\Contexts::run($visitor->workingCopies(), static fn (): array => [
            $resolver->match('/draft-page')?->record->get('slug'),
            $resolver->matchPublished('/draft-page'),
        ]);
        $this->assertSame(['draft-page', null], $preview);
        $this->assertNull(\Preflow\Core\Context\Contexts::run($visitor, static fn () => $resolver->match('/draft-page')), 'a visitor never finds it');
    }

    public function test_two_types_with_different_patterns_resolve(): void
    {
        $this->writeType('page', ['route' => '/{slug}', 'home' => 'home']);
        $this->writeType('article', ['route' => '/blog/{slug}']);
        $this->seedType('article', 'a1', 'x', 'published');
        $this->seedType('page', 'p9', 'about', 'published');

        $resolver = FrontendResolver::fromCatalog(new TypeCatalog($this->models), $this->dm);

        $blog = $resolver->match('/blog/x');
        $this->assertSame('article', $blog?->route->type);
        $this->assertSame('a1', $blog->record->getId());
        $this->assertSame('page', $resolver->match('/about')?->route->type);
        $this->assertSame('home', $resolver->match('')?->record->get('slug'));
    }

    public function test_static_segments_win(): void
    {
        $this->writeType('page', ['route' => '/{...slug}']);
        $this->writeType('article', ['route' => '/blog/{slug}']);
        $this->seedType('article', 'a1', 'x', 'published');
        $this->seedType('page', 'p9', 'blog/x', 'published');

        $resolver = FrontendResolver::fromCatalog(new TypeCatalog($this->models), $this->dm);

        $this->assertSame('article', $resolver->match('/blog/x')?->route->type);
    }

    public function test_duplicate_home_is_a_boot_error(): void
    {
        $this->writeType('page', ['route' => '/{slug}', 'home' => 'home']);
        $this->writeType('article', ['route' => '/blog/{slug}', 'home' => 'start']);

        $this->expectException(FrontendConfigException::class);
        $this->expectExceptionMessage('Folio types "article" and "page" both declare frontend.home.');
        FrontendResolver::fromCatalog(new TypeCatalog($this->models), $this->dm);
    }

    public function test_duplicate_pattern_is_a_boot_error(): void
    {
        $this->writeType('page', ['route' => '/{slug}']);
        $this->writeType('article', ['route' => '/{title}']);

        $this->expectException(FrontendConfigException::class);
        $this->expectExceptionMessage('Folio types "article" and "page" both declare the frontend route "/{slug}".');
        FrontendResolver::fromCatalog(new TypeCatalog($this->models), $this->dm);
    }

    public function test_unpublished_is_404(): void
    {
        $this->writeType('page', ['route' => '/{slug}', 'home' => 'home']);
        $resolver = FrontendResolver::fromCatalog(new TypeCatalog($this->models), $this->dm);

        $this->assertNull($resolver->match('/draft-page'));
    }

    public function test_custom_published_field(): void
    {
        $this->writeType('page', ['route' => '/{slug}', 'published' => ['field' => 'visible', 'value' => 'yes']], ['visible' => ['type' => 'string']]);
        $registry = new TypeRegistry($this->models);
        $this->dm->saveType(DynamicRecord::fromArray($registry->get('page'), [
            'uuid' => 'v1', 'title' => 'V', 'slug' => 'shown', 'body' => 'x', 'status' => 'draft', 'visible' => 'yes',
        ]), validate: false);

        $resolver = FrontendResolver::fromCatalog(new TypeCatalog($this->models), $this->dm);

        $this->assertSame('v1', $resolver->match('/shown')?->record->getId());
        $this->assertNull($resolver->match('/home')); // status=published but visible missing
    }

    public function test_legacy_page_without_frontend_block(): void
    {
        $resolver = FrontendResolver::fromCatalog(new TypeCatalog($this->models), $this->dm);

        $this->assertSame('home', $resolver->match('')?->record->get('slug'));
        $this->assertNotNull($resolver->routeFor('page'));
        $this->assertNull($resolver->routeFor('article'));
    }

    public function test_path_for_is_the_path_after_the_locale_prefix_is_stripped(): void
    {
        file_put_contents($this->models . '/lpage.json', json_encode([
            'key' => 'lpage', 'table' => 'lpage', 'storage' => 'json', 'id_field' => 'uuid',
            'frontend' => ['route' => '/{slug}', 'home' => 'start'],
            'publishing' => false,
            'fields' => [
                'title' => ['type' => 'string', 'translatable' => true],
                'slug' => ['type' => 'string', 'translatable' => true],
            ],
        ]));
        $type = (new TypeRegistry($this->models, new Locales(['en', 'de'], 'en')))->get('lpage');
        $resolver = new FrontendResolver($this->dm, [FrontendRoute::fromConfig('lpage', ['route' => '/{slug}', 'home' => 'start'])]);

        $record = DynamicRecord::fromArray($type, [
            'uuid' => 'a', 'title' => ['en' => 'Hello', 'de' => 'Hallo'], 'slug' => ['en' => 'hello', 'de' => 'hallo'], '_locales' => ['en', 'de'],
        ]);
        $this->assertSame('/hallo', $resolver->pathFor($record, 'de'));
        $this->assertSame('/hello', $resolver->pathFor($record, 'en'));
        $this->assertSame('/de/hallo', $resolver->urlFor($record, 'de'));
        $this->assertSame('/hello', $resolver->urlFor($record, 'en'));

        $home = DynamicRecord::fromArray($type, [
            'uuid' => 'b', 'title' => ['en' => 'Home', 'de' => 'Start'], 'slug' => ['en' => 'start', 'de' => 'start'], '_locales' => ['en', 'de'],
        ]);
        $this->assertSame('/', $resolver->pathFor($home, 'en'));
        $this->assertSame('/', $resolver->pathFor($home, 'de'));
        $this->assertSame('/de', $resolver->urlFor($home, 'de'));
    }

    /** A bilingual "lpage" type (en default, de) at "/{slug}", home "start", and its resolver. */
    private function bilingual(?\Preflow\Core\Http\LocaleUrls $urls): array
    {
        file_put_contents($this->models . '/lpage.json', json_encode([
            'key' => 'lpage', 'table' => 'lpage', 'storage' => 'json', 'id_field' => 'uuid',
            'frontend' => ['route' => '/{slug}', 'home' => 'start'],
            'publishing' => false,
            'fields' => [
                'title' => ['type' => 'string', 'translatable' => true],
                'slug' => ['type' => 'string', 'translatable' => true],
            ],
        ]));
        $type = (new TypeRegistry($this->models, new Locales(['en', 'de'], 'en')))->get('lpage');
        $resolver = new FrontendResolver($this->dm, [FrontendRoute::fromConfig('lpage', ['route' => '/{slug}', 'home' => 'start'])], null, $urls);

        return [
            $resolver,
            DynamicRecord::fromArray($type, ['uuid' => 'a', 'title' => ['en' => 'Hello', 'de' => 'Hallo'], 'slug' => ['en' => 'hello', 'de' => 'hallo'], '_locales' => ['en', 'de']]),
            DynamicRecord::fromArray($type, ['uuid' => 'b', 'title' => ['en' => 'Home', 'de' => 'Start'], 'slug' => ['en' => 'start', 'de' => 'start'], '_locales' => ['en', 'de']]),
        ];
    }

    public function test_url_for_prefixes_the_default_locale_with_prefix_default(): void
    {
        [$resolver, $record] = $this->bilingual(new \Preflow\Core\Http\LocaleUrls(['en', 'de'], 'en', prefixDefault: true));

        $this->assertSame('/en/hello', $resolver->urlFor($record, 'en'));
        $this->assertSame('/de/hallo', $resolver->urlFor($record, 'de'));
        $this->assertSame('/hello', $resolver->pathFor($record, 'en'), 'the path after the prefix is stripped stays the same');
        $this->assertSame('/en', $resolver->localePrefix('en'));
        $this->assertSame('/de', $resolver->localePrefix('de'));
    }

    public function test_url_for_home_in_default_locale_is_its_prefix(): void
    {
        [$resolver, , $home] = $this->bilingual(new \Preflow\Core\Http\LocaleUrls(['en', 'de'], 'en', prefixDefault: true));

        $this->assertSame('/en', $resolver->urlFor($home, 'en'));
        $this->assertSame('/de', $resolver->urlFor($home, 'de'));
    }

    public function test_url_for_unchanged_without_locale_urls(): void
    {
        foreach ([null, new \Preflow\Core\Http\LocaleUrls(['en', 'de'], 'en')] as $urls) {
            [$resolver, $record, $home] = $this->bilingual($urls);

            $this->assertSame('/hello', $resolver->urlFor($record, 'en'));
            $this->assertSame('/de/hallo', $resolver->urlFor($record, 'de'));
            $this->assertSame('/', $resolver->urlFor($home, 'en'));
            $this->assertSame('/de', $resolver->urlFor($home, 'de'));
            $this->assertSame('', $resolver->localePrefix('en'));
            $this->assertSame('/de', $resolver->localePrefix('de'));
        }
    }

    /**
     * @param array<string, mixed> $frontend
     * @param array<string, mixed> $extraFields
     */
    private function writeType(string $key, array $frontend, array $extraFields = []): void
    {
        file_put_contents($this->models . '/' . $key . '.json', json_encode([
            'key' => $key,
            'table' => $key,
            'storage' => 'json',
            'id_field' => 'uuid',
            'fields' => [
                'title' => ['type' => 'string'],
                'slug' => ['type' => 'string', 'searchable' => true],
                'body' => ['type' => 'text'],
                'status' => ['type' => 'string'],
            ] + $extraFields,
            'frontend' => $frontend,
            'publishing' => false, // these tests pin the frontend.published convention
        ]));
    }

    private function seedType(string $type, string $id, string $slug, string $status): void
    {
        $registry = new TypeRegistry($this->models);
        $this->dm->saveType(DynamicRecord::fromArray($registry->get($type), [
            'uuid' => $id, 'title' => ucfirst($slug), 'slug' => $slug, 'body' => 'x', 'status' => $status,
        ]), validate: false);
    }

    public function test_boolean_published_value_matches_stored_boolean(): void
    {
        $this->writeType('page', ['route' => '/{slug}', 'published' => ['field' => 'visible', 'value' => true]], ['visible' => ['type' => 'boolean']]);
        $registry = new TypeRegistry($this->models);
        $this->dm->saveType(DynamicRecord::fromArray($registry->get('page'), [
            'uuid' => 'b1', 'title' => 'B', 'slug' => 'bool', 'body' => 'x', 'status' => 'draft', 'visible' => true,
        ]), validate: false);

        $resolver = FrontendResolver::fromCatalog(new TypeCatalog($this->models), $this->dm);

        $this->assertSame('b1', $resolver->match('/bool')?->record->getId());
    }

    /** @return iterable<string, array{string, string}> */
    public static function malformedRoutes(): iterable
    {
        yield 'unclosed brace' => ['/a{b', 'unclosed'];
        yield 'invalid parameter name' => ['/{my-slug}', 'parameter name'];
        yield 'catch-all not last' => ['/{...rest}/x', 'catch-all'];
        yield 'two catch-alls' => ['/{...a}/{...b}', 'catch-all'];
        yield 'root route' => ['/', 'parameter'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedRoutes')]
    public function test_malformed_route_is_a_clear_boot_error(string $route, string $fragment): void
    {
        $this->writeType('article', ['route' => $route]);

        try {
            FrontendResolver::routesFromCatalog(new TypeCatalog($this->models));
            $this->fail('Expected a FrontendConfigException for ' . $route);
        } catch (FrontendConfigException $e) {
            $this->assertStringContainsString('"article"', $e->getMessage());
            $this->assertStringContainsString($fragment, $e->getMessage());
        }
    }
}
