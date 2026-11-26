<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Layouts;

use Nyholm\Psr7\Factory\Psr17Factory;
use Preflow\Blocks\Layouts\LayoutInput;
use Preflow\Blocks\Layouts\LayoutResource;
use Preflow\Blocks\Tests\BlocksAppTestCase;
use Preflow\Core\Application;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeRegistry;
use Preflow\Testing\TestResponse;
use Preflow\Validation\ValidationException;

/**
 * Page layouts through a JSON API, without Folio: the fixture app's App\Controllers\Api\PagesController
 * is written the way a site writes its API (Json::data(), its own fields), reads with LayoutResource
 * and writes with LayoutInput::merge() and DataManager::saveType(), so the core rules decide as they
 * do for Folio's form.
 */
final class LayoutApiTest extends BlocksAppTestCase
{
    private const A = 'aaaaaaaaaaaaaaaa';
    private const B = 'bbbbbbbbbbbbbbbb';
    private const C = 'cccccccccccccccc';

    protected function setUp(): void
    {
        parent::setUp();
        $this->write('config/data.php', "<?php\nreturn ['drivers' => ['json' => ['driver' => \\Preflow\\Data\\Driver\\JsonFileDriver::class, 'path' => __DIR__ . '/../storage/data']], 'default' => 'json', 'models_path' => __DIR__ . '/models'];\n");
        $this->write('config/blocks.php', "<?php\nreturn [\n"
            . "    'classes' => [\\Preflow\\Blocks\\Tests\\Fixtures\\Checks\\Section::class, \\Preflow\\Blocks\\Tests\\Fixtures\\Checks\\SectionQuote::class],\n"
            . "    'layouts' => [\\Preflow\\Blocks\\Tests\\Fixtures\\Layouts\\Standard::class, \\Preflow\\Blocks\\Tests\\Fixtures\\Layouts\\Promo::class, \\Preflow\\Blocks\\Tests\\Fixtures\\Layouts\\Home::class],\n"
            . "];\n");
        $this->write('config/models/page.json', (string) json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => 'json', 'id_field' => 'uuid',
            'fields' => ['title' => ['type' => 'string'], 'slug' => ['type' => 'string']],
            'layouts' => ['allowed' => ['landing', 'promo', 'standard'], 'default' => 'standard', 'pinned' => ['home' => 'home']],
        ]));
        $this->write('config/models/note.json', (string) json_encode([
            'key' => 'note', 'table' => 'note', 'storage' => 'json', 'id_field' => 'uuid',
            'fields' => ['title' => ['type' => 'string']],
        ]));
    }

    public function test_api_read_gives_layout_placed_and_unplaced_areas(): void
    {
        // A link button stored while the section was a link; it is a text section now, so the field is hidden.
        $main = self::section(self::B, ['kind' => 'text', 'button_url' => 'https://example.com/old']);
        $this->store(['uuid' => 'p1', 'title' => 'About', 'slug' => 'about', '_layout' => 'standard', '_areas' => ['hero' => [self::section(self::A)], 'main' => [$main]]]);

        $response = $this->json('GET', '/api/pages/p1')->assertOk();

        $this->assertSame('p1', $response->json('data.id'));
        $this->assertSame(['key' => 'standard', 'label' => 'Standard', 'pinned' => false, 'fallback' => false, 'stored' => 'standard'], $response->json('data.layout'));
        $this->assertSame([self::B], array_column($response->json('data.areas.main'), '_uid'));
        $this->assertSame(['en' => 'Hello'], $response->json('data.areas.main.0.title'), 'locale maps as stored: the API picks the locale');
        $this->assertNull($response->json('data.areas.main.0.button_url'), 'a field its show_when hides reads null');
        $this->assertSame([self::A], array_column($response->json('data.unplaced.hero'), '_uid'), 'the hero the standard layout lacks: kept, not shown');
        $this->assertSame(['main'], array_keys($response->json('data.areas')));
    }

    public function test_api_read_of_a_pinned_page_and_of_a_removed_layout(): void
    {
        $this->store(['uuid' => 'home', 'title' => 'Home', 'slug' => 'home', '_areas' => ['top' => [self::section(self::A)]]]);
        $this->store(['uuid' => 'p2', 'title' => 'Old', 'slug' => 'old-page', '_layout' => 'old']);

        $home = $this->json('GET', '/api/pages/home')->assertOk();
        $this->assertSame(['key' => 'home', 'label' => 'Home', 'pinned' => true, 'fallback' => false, 'stored' => null], $home->json('data.layout'));
        $this->assertSame([self::A], array_column($home->json('data.areas.top'), '_uid'));

        $old = $this->json('GET', '/api/pages/p2')->assertOk();
        $this->assertSame(['key' => 'standard', 'label' => 'Standard', 'pinned' => false, 'fallback' => true, 'stored' => 'old'], $old->json('data.layout'), 'a layout no class declares any more: the default, marked');
        $this->assertSame(['main' => []], $old->json('data.areas'));
        $this->assertStringContainsString('"unplaced":{}', $old->body(), 'an empty map is an object in JSON, never a list');
    }

    public function test_api_write_changes_layout_and_keeps_unnamed_areas(): void
    {
        $this->store(['uuid' => 'p1', 'title' => 'About', 'slug' => 'about', '_layout' => 'landing', '_areas' => ['hero' => [self::section(self::A)], 'main' => [self::section(self::B)]]]);

        $response = $this->json('PATCH', '/api/pages/p1', [
            'title' => 'About us',
            'layout' => 'standard',
            'areas' => ['main' => [self::section(self::B, ['title' => ['en' => 'Edited']]), ['_block' => 'section', 'title' => ['en' => 'New']]]],
        ])->assertOk();

        $this->assertSame('standard', $response->json('data.layout.key'));
        $this->assertSame('About us', $response->json('data.title'));
        $main = $response->json('data.areas.main');
        $this->assertSame([self::B], [$main[0]['_uid']]);
        $this->assertSame(['en' => 'Edited'], $main[0]['title']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $main[1]['_uid'], 'a new block gets a fresh uid');
        $this->assertSame([self::A], array_column($response->json('data.unplaced.hero'), '_uid'), 'the area the write did not name keeps its blocks');

        $stored = $this->stored('p1');
        $this->assertSame('standard', $stored?->get('_layout'));
        $this->assertSame(self::A, $this->areasOf($stored)['hero'][0]['_uid']);

        $this->json('PATCH', '/api/pages/p1', ['layout' => 'landing', 'areas' => ['main' => []]])->assertOk();
        $back = $this->areasOf($this->stored('p1'));
        $this->assertSame([], $back['main'], 'a list [] empties the area');
        $this->assertSame(self::A, $back['hero'][0]['_uid'], 'switching back finds the hero where it was');
    }

    public function test_api_write_refuses_like_folio(): void
    {
        $this->store(['uuid' => 'p1', 'title' => 'About', 'slug' => 'about', '_layout' => 'standard', '_areas' => ['main' => []]]);
        $this->store(['uuid' => 'home', 'title' => 'Home', 'slug' => 'home', '_areas' => ['top' => [self::section(self::A)]]]);
        $banner = static fn (string $uid): array => ['_uid' => $uid, '_block' => 'banner'];

        $layout = $this->json('PATCH', '/api/pages/p1', ['layout' => 'home'])->assertStatus(422);
        $this->assertSame(['_layout' => ['The layout "home" is not one of the layouts this type allows (landing, promo, standard). Choose one of them.']], $layout->json('errors'));

        $rename = $this->json('PATCH', '/api/pages/home', ['slug' => 'start'])->assertStatus(422);
        $this->assertSame(['slug'], array_keys($rename->json('errors')));
        $this->assertStringContainsString('pinned to the layout "home"', $rename->json('errors.slug.0'));

        $full = $this->json('PATCH', '/api/pages/p1', ['layout' => 'promo', 'areas' => ['hero' => [$banner(self::B), $banner(self::C)]]])->assertStatus(422);
        $this->assertSame(['_areas' => ['The area "hero" takes at most 1 blocks.']], $full->json('errors'));

        $required = $this->json('PATCH', '/api/pages/p1', ['areas' => ['main' => [self::section(self::B, ['title' => ['en' => '']])]]])->assertStatus(422);
        $this->assertSame(['_areas.main.' . self::B . '.title' => ['This field is required.']], $required->json('errors'), 'the key Folio\'s form shows');

        $this->assertSame('standard', $this->stored('p1')?->get('_layout'), 'nothing was saved');
        $this->assertSame([], $this->areasOf($this->stored('p1'))['main']);
    }

    public function test_api_write_keeps_a_hidden_block_fields_stored_value(): void
    {
        $link = self::section(self::B, ['kind' => 'link', 'button_url' => 'https://example.com/kept']);
        $this->store(['uuid' => 'p1', 'title' => 'About', 'slug' => 'about', '_layout' => 'standard', '_areas' => ['main' => [$link]]]);

        // Switched to text: the button's address is hidden, and what the client sends for it does not count.
        $this->json('PATCH', '/api/pages/p1', ['areas' => ['main' => [
            ['kind' => 'text', 'button_url' => 'https://example.com/sent'] + $link,
            ['_block' => 'section', 'title' => ['en' => 'New'], 'kind' => 'text', 'button_url' => 'https://example.com/new'],
        ]]])->assertOk();

        $main = $this->areasOf($this->stored('p1'))['main'];
        $this->assertSame('text', $main[0]['kind']);
        $this->assertSame('https://example.com/kept', $main[0]['button_url'], 'the stored value stays, so switching back brings it back');
        $this->assertArrayNotHasKey('button_url', $main[1], 'a new block keeps nothing for a hidden field');

        $this->json('PATCH', '/api/pages/p1', ['areas' => ['main' => [['kind' => 'link'] + $main[0]]]])->assertOk();
        $this->assertSame('https://example.com/kept', $this->json('GET', '/api/pages/p1')->json('data.areas.main.0.button_url'));
    }

    public function test_api_write_refuses_an_unknown_area_and_a_doubled_uid(): void
    {
        $this->store(['uuid' => 'p1', 'title' => 'About', 'slug' => 'about', '_layout' => 'standard', '_areas' => ['hero' => [self::section(self::A)], 'main' => []]]);

        $unknown = $this->json('PATCH', '/api/pages/p1', ['areas' => ['sidebar' => [self::section(self::B)]]])->assertStatus(422);
        $this->assertSame(['_areas.sidebar' => ['The layout "standard" has no area "sidebar". Use one of: main.']], $unknown->json('errors'));

        $this->json('PATCH', '/api/pages/p1', ['areas' => ['hero' => [self::section(self::A)], 'main' => [self::section(self::B)]]])->assertOk();
        $this->addToAssertionCount(1); // a stored area the layout lacks may still be written

        $twice = $this->json('PATCH', '/api/pages/p1', ['areas' => ['main' => [self::section(self::B), self::section(self::B)]]])->assertStatus(422);
        $this->assertSame(['_areas.main.1._uid'], array_keys($twice->json('errors')));
        $this->assertSame('The uid "' . self::B . '" is given to more than one block. Give each block its own "_uid", or leave it out for a new one.', $twice->json('errors')['_areas.main.1._uid'][0]);

        $kept = $this->json('PATCH', '/api/pages/p1', ['areas' => ['main' => [self::section(self::A)]]])->assertStatus(422);
        $this->assertSame(['_areas.main.0._uid'], array_keys($kept->json('errors')), 'the hero, which the write does not name, still holds it: name both to move a block');

        $shape = $this->json('PATCH', '/api/pages/p1', ['layout' => 7, 'areas' => ['main' => [['title' => 'no block key'], 'text']]])->assertStatus(422);
        $this->assertSame(['_layout', '_areas.main.0', '_areas.main.1'], array_keys($shape->json('errors')));

        $unknownBlock = $this->json('PATCH', '/api/pages/p1', ['areas' => ['main' => [['_block' => 'nope']]]])->assertStatus(422);
        $this->assertSame(['_areas.main.0._block'], array_keys($unknownBlock->json('errors')));
    }

    public function test_api_write_takes_only_hex_uids(): void
    {
        $this->store(['uuid' => 'p1', 'title' => 'About', 'slug' => 'about', '_layout' => 'standard', '_areas' => ['main' => []]]);
        $message = 'The uid "intro" is not a block uid: a uid is 16 hex digits (0-9, a-f), such as "3f9a1c2b4d5e6f70". Leave "_uid" out to get a fresh one.';

        $refused = $this->json('PATCH', '/api/pages/p1', ['areas' => ['main' => [self::section(self::A), self::section('intro')]]])->assertStatus(422);
        $this->assertSame(['_areas.main.1._uid' => [$message]], $refused->json('errors'));

        $nested = $this->json('PATCH', '/api/pages/p1', ['areas' => ['main' => [self::section(self::A, ['quotes' => [['_uid' => 'intro', '_block' => 'section_quote', 'text' => 'Hi']]])]]])->assertStatus(422);
        $this->assertSame(['_areas.main.0.quotes.0._uid' => [$message]], $nested->json('errors'), 'nested blocks too');
        $this->assertCount(0, $this->areasOf($this->stored('p1'))['main'], 'nothing was saved');

        $this->json('PATCH', '/api/pages/p1', ['areas' => ['main' => [
            self::section(self::A),
            ['_block' => 'section', 'title' => ['en' => 'New'], 'quotes' => [['_block' => 'section_quote', 'text' => 'Hi']]],
            ['_uid' => null] + self::section(self::B),
        ]]])->assertOk();
        $main = $this->areasOf($this->stored('p1'))['main'];
        $this->assertSame(self::A, $main[0]['_uid'], 'a hex uid is kept');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $main[1]['_uid'], 'an omitted uid gets a fresh one');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $main[1]['quotes'][0]['_uid'], 'nested too');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $main[2]['_uid'], 'null is left out');
        $this->assertNotSame(self::B, $main[2]['_uid']);
    }

    public function test_type_without_layouts_has_no_layout_resource(): void
    {
        $note = DynamicRecord::fromArray($this->types()->get('note'), ['uuid' => 'n1', 'title' => 'Hi']);

        $this->assertNull($this->app()->container()->get(LayoutResource::class)->toArray($note));

        $input = $this->app()->container()->get(LayoutInput::class);
        $this->assertSame(['uuid' => 'n1', 'title' => 'Hi'], $input->merge($this->types()->get('note'), $note->toArray(), ['title' => 'Ignored: the caller writes its own fields']));
        try {
            $input->merge($this->types()->get('note'), $note->toArray(), ['layout' => 'standard', 'areas' => []]);
            $this->fail('a layout for a type without layouts was taken');
        } catch (ValidationException $e) {
            $this->assertSame(['_layout', '_areas'], array_keys($e->errors()));
            $this->assertSame('The type "note" has no layouts; leave out "layout" and "areas".', $e->errors()['_layout'][0]);
        }
    }

    /**
     * A valid section, with $values over it.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function section(string $uid, array $values = []): array
    {
        return array_replace(['_uid' => $uid, '_block' => 'section', 'title' => ['en' => 'Hello']], $values);
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function areasOf(?DynamicRecord $record): array
    {
        $areas = $record?->get('_areas');

        return is_string($areas) ? (array) json_decode($areas, true) : (array) $areas;
    }

    /** @param array<string, mixed> $values a page stored without validation */
    private function store(array $values): void
    {
        $this->data()->saveType(DynamicRecord::fromArray($this->types()->get('page'), $values), validate: false);
    }

    /** @param array<string, mixed>|null $body */
    private function json(string $method, string $uri, ?array $body = null): TestResponse
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest($method, 'http://localhost' . $uri)->withHeader('Accept', 'application/json');
        if ($body !== null) {
            // An API client's write: a bearer token (a site checks it with TokenAuthMiddleware), no cookies.
            $request = $request->withHeader('Authorization', 'Bearer api-test-token')->withHeader('Content-Type', 'application/json')->withBody($factory->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
        }

        return new TestResponse($this->fresh()->handle($request));
    }

    /** The page as stored now, read by a fresh app (as the next request would). */
    private function stored(string $id): ?DynamicRecord
    {
        return $this->fresh()->container()->get(DataManager::class)->findType('page', $id);
    }

    /** A newly booted app on the same files: one per request, as the server boots one. */
    private function fresh(): Application
    {
        $app = Application::create($this->dir);
        $app->boot();

        return $app;
    }

    private function data(): DataManager
    {
        return $this->app()->container()->get(DataManager::class);
    }

    private function types(): TypeRegistry
    {
        return $this->app()->container()->get(TypeRegistry::class);
    }
}
