<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Layouts;

use Preflow\Blocks\Layouts\LayoutConfigException;
use Preflow\Blocks\Layouts\PageLayouts;
use Preflow\Blocks\Tests\BlocksAppTestCase;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeRegistry;
use Preflow\Validation\ValidationException;

/**
 * The page layout rules, through a booted app without Folio: BlocksServiceProvider registers them
 * with the data package (RecordRules) for types with "layouts", so every validated save checks the
 * layout choice, a pinned page's slug and the areas' contents; a draft (validateStorable()) checks
 * the slug.
 */
final class LayoutRulesTest extends BlocksAppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->write('config/data.php', "<?php\nreturn ['drivers' => ['json' => ['driver' => \\Preflow\\Data\\Driver\\JsonFileDriver::class, 'path' => __DIR__ . '/../storage/data']], 'default' => 'json', 'models_path' => __DIR__ . '/models'];\n");
        $this->write('config/blocks.php', "<?php\nreturn ['layouts' => [\\Preflow\\Blocks\\Tests\\Fixtures\\Layouts\\Standard::class, \\Preflow\\Blocks\\Tests\\Fixtures\\Layouts\\Promo::class, \\Preflow\\Blocks\\Tests\\Fixtures\\Layouts\\Home::class]];\n");
        $this->model('page', ['allowed' => ['promo', 'standard'], 'default' => 'standard', 'pinned' => ['home' => 'home']]);
        $this->model('note', null);
    }

    public function test_page_layouts_are_bound_with_a_type_registry(): void
    {
        $layouts = $this->app()->container()->get(PageLayouts::class);

        $this->assertSame($layouts, $this->app()->container()->get(PageLayouts::class));
        $this->assertSame(['promo', 'standard'], $layouts->forType('page')?->allowed);
    }

    public function test_changed_layout_must_be_allowed(): void
    {
        $this->assertSame(['_layout' => ['The layout "landing" is not one of the layouts this type allows (promo, standard). Choose one of them.']], $this->errors('page', ['_layout' => 'landing']), 'a layout the app has, but the type does not allow');
        $this->assertSame(['_layout' => ['The layout "nope" is not one of the layouts this type allows (promo, standard). Choose one of them.']], $this->errors('page', ['_layout' => 'nope']));
        $this->assertSame([], $this->errors('page', ['_layout' => 'promo']));
        $this->assertSame([], $this->errors('page', []), 'no layout: the default');
        $this->assertSame([], $this->errors('note', ['_layout' => 'nope']), 'a type without "layouts" gets no rules');
    }

    public function test_unchanged_stale_layout_saves(): void
    {
        // Stored when "landing" was allowed (the type's list changed since).
        $this->store('page', ['uuid' => 'p1', 'title' => 'About', 'slug' => ['en' => 'about'], '_layout' => 'landing']);

        $this->assertSame([], $this->errors('page', ['title' => 'About us', 'slug' => ['en' => 'about'], '_layout' => 'landing']), 'the stored key, unchanged');
        $this->assertSame(['_layout'], array_keys($this->errors('page', ['title' => 'About us', '_layout' => 'nope'])), 'a change still has to be allowed');
        $this->save('page', ['title' => 'About us', 'slug' => ['en' => 'about'], '_layout' => 'landing']);
        $this->assertSame('landing', $this->data()->findType('page', 'p1')?->get('_layout'));
    }

    public function test_pinned_page_takes_only_its_pin(): void
    {
        $this->assertSame(['_layout' => ['The layout "promo" is not one of the layouts this type allows (home). Choose one of them.']], $this->errors('page', ['slug' => ['en' => 'home'], '_layout' => 'promo', '_areas' => ['top' => [['_uid' => 'a1', '_block' => 'quote']]]]));
        $this->assertSame([], $this->errors('page', ['slug' => ['en' => 'home'], '_layout' => 'home', '_areas' => ['top' => [['_uid' => 'a1', '_block' => 'quote']]]]), 'its pin, which "allowed" need not list');
        $this->assertSame([], $this->errors('page', ['slug' => ['en' => 'home'], '_areas' => ['top' => [['_uid' => 'a1', '_block' => 'quote']]]]));
    }

    public function test_renaming_a_pinned_page_is_refused_also_as_a_draft(): void
    {
        $this->store('page', ['uuid' => 'p1', 'slug' => ['en' => 'home', 'de' => 'start'], '_layout' => 'home', '_areas' => ['top' => [['_uid' => 'a1', '_block' => 'quote']]]]);
        $message = 'This page is pinned to the layout "home" by the entry "home" of "layouts.pinned" in the type "page". Change or remove that entry first, then rename the page.';
        $areas = ['_areas' => ['top' => [['_uid' => 'a1', '_block' => 'quote']]]];

        $this->assertSame(['slug' => [$message]], $this->errors('page', ['slug' => ['en' => 'start-page', 'de' => 'start']] + $areas));
        $this->assertSame([], $this->errors('page', ['slug' => ['en' => 'home', 'de' => 'anfang']] + $areas), 'another language\'s slug may change');
        $this->assertSame([], $this->errors('page', ['title' => 'Welcome'] + $areas), 'a save without the slug keeps it');

        $draft = DynamicRecord::fromArray($this->types()->get('page'), ['uuid' => 'p1', 'slug' => ['en' => 'start-page']]);
        try {
            $this->data()->validateStorable($draft);
            $this->fail('a renamed draft of a pinned page was accepted');
        } catch (ValidationException $e) {
            $this->assertSame(['slug' => [$message]], $e->errors());
        }
        $this->data()->validateStorable(DynamicRecord::fromArray($this->types()->get('page'), ['uuid' => 'p1', 'slug' => ['en' => 'home'], '_layout' => 'nope']));
        $this->addToAssertionCount(1); // a draft is checked for its slug only
        $this->data()->validateStorable(DynamicRecord::fromArray($this->types()->get('page'), ['uuid' => 'p2', 'slug' => ['en' => 'start-page']]));
    }

    public function test_area_limits_apply_to_placed_areas_only(): void
    {
        $banner = static fn (string $uid): array => ['_uid' => $uid, '_block' => 'banner'];
        $quote = static fn (string $uid): array => ['_uid' => $uid, '_block' => 'quote'];

        $this->assertSame(['_areas' => ['The area "hero" takes at most 1 blocks.']], $this->errors('page', ['_layout' => 'promo', '_areas' => ['hero' => [$banner('b1'), $banner('b2')]]]));
        $this->assertSame(['_areas' => ['The area "hero" does not take "quote" blocks. Move it to another area or remove it.']], $this->errors('page', ['_layout' => 'promo', '_areas' => ['hero' => [$quote('q1')]]]));
        $this->assertSame(['_areas' => ['The area "main" does not take "banner" blocks. Move it to another area or remove it.']], $this->errors('page', ['_layout' => 'promo', '_areas' => json_encode(['main' => [$banner('b1')]])]), 'JSON text too');
        $this->assertSame(['_areas' => ['The area "top" takes at least 1 blocks.']], $this->errors('page', ['slug' => ['en' => 'home'], '_areas' => ['top' => []]]), 'the pinned layout\'s areas');
        $this->assertSame([], $this->errors('page', ['_layout' => 'standard', '_areas' => ['hero' => [$quote('q1'), $quote('q2')], 'main' => [$banner('b1')]]]), 'an area the layout does not show is not checked');
        $this->assertSame([], $this->errors('page', ['_layout' => 'promo', '_areas' => ['hero' => [['_uid' => 'r1', '_block' => 'retired']]]]), 'a block no class declares any more is kept, as in a blocks field');
        $this->assertSame([], $this->errors('page', ['_layout' => 'promo', '_areas' => ['hero' => [$banner('b1')], 'main' => [$quote('q1')]]]));
    }

    public function test_area_narrowed_after_storing_keeps_the_page_savable(): void
    {
        // Stored before "main" excluded banners.
        $b1 = ['_uid' => 'b1', '_block' => 'banner', 'title' => ['en' => 'Old']];
        $q1 = ['_uid' => 'q1', '_block' => 'quote', 'text' => ['en' => 'Hi']];
        $this->store('page', ['uuid' => 'p1', '_layout' => 'promo', '_areas' => ['hero' => [], 'main' => [$b1, $q1]]]);

        $this->assertSame([], $this->errors('page', ['title' => 'Edited', '_layout' => 'promo', '_areas' => ['hero' => [], 'main' => [$b1, $q1]]]), 'the area as stored');
        $this->assertSame([], $this->errors('page', ['_layout' => 'promo', '_areas' => ['hero' => [], 'main' => [['title' => ['en' => 'New text']] + $b1, $q1]]]), 'its stored banner edited in place');
        $this->assertSame([], $this->errors('page', ['_layout' => 'promo', '_areas' => ['hero' => [], 'main' => [$b1]]]), 'another block removed: the stored banner stays');
        $this->assertSame(['_areas' => ['The area "main" does not take "banner" blocks. Move it to another area or remove it.']], $this->errors('page', ['_layout' => 'promo', '_areas' => ['hero' => [], 'main' => [$b1, $q1, ['_uid' => 'b2', '_block' => 'banner']]]]), 'a new one is refused');
        $this->assertSame(['_areas' => ['The area "hero" does not take "quote" blocks. Move it to another area or remove it.']], $this->errors('page', ['_layout' => 'promo', '_areas' => ['hero' => [$q1], 'main' => [$b1]]]), 'one moved in is refused');
    }

    public function test_lowered_max_does_not_block_an_unchanged_area(): void
    {
        // Stored when the hero took more than one block.
        $b1 = ['_uid' => 'b1', '_block' => 'banner', 'title' => ['en' => 'One']];
        $b2 = ['_uid' => 'b2', '_block' => 'banner', 'title' => ['en' => 'Two']];
        $this->store('page', ['uuid' => 'p1', '_layout' => 'promo', '_areas' => json_encode(['hero' => [$b1, $b2], 'main' => []])]);

        $this->assertSame([], $this->errors('page', ['title' => 'Edited', '_layout' => 'promo', '_areas' => ['hero' => [$b1, $b2], 'main' => []]]));
        $this->assertSame([], $this->errors('page', ['_layout' => 'promo', '_areas' => ['hero' => [['title' => ['en' => 'Edited']] + $b1, $b2], 'main' => []]]), 'editing a block keeps the list');
        $this->assertSame(['_areas' => ['The area "hero" takes at most 1 blocks.']], $this->errors('page', ['_layout' => 'promo', '_areas' => ['hero' => [$b1, $b2, ['_uid' => 'b3', '_block' => 'banner']], 'main' => []]]), 'a changed list is checked');
    }

    public function test_removing_a_block_from_an_over_full_area_is_allowed(): void
    {
        $banner = static fn (string $uid): array => ['_uid' => $uid, '_block' => 'banner'];
        $this->store('page', ['uuid' => 'p1', '_layout' => 'promo', '_areas' => ['hero' => [$banner('b1'), $banner('b2'), $banner('b3')], 'main' => []]]);

        $this->assertSame([], $this->errors('page', ['_layout' => 'promo', '_areas' => ['hero' => [$banner('b1'), $banner('b2')], 'main' => []]]), 'still over max 1, but fewer than stored');
        $this->assertSame([], $this->errors('page', ['_layout' => 'promo', '_areas' => ['hero' => [$banner('b3'), $banner('b1'), $banner('b2')], 'main' => []]]), 'reordered: the same count');
    }

    public function test_adding_to_an_over_full_area_is_refused(): void
    {
        $banner = static fn (string $uid): array => ['_uid' => $uid, '_block' => 'banner'];
        $this->store('page', ['uuid' => 'p1', '_layout' => 'promo', '_areas' => ['hero' => [$banner('b1'), $banner('b2')], 'main' => []]]);

        $this->assertSame(['_areas' => ['The area "hero" takes at most 1 blocks.']], $this->errors('page', ['_layout' => 'promo', '_areas' => ['hero' => [$banner('b1'), $banner('b2'), $banner('b3')], 'main' => []]]));
        $this->assertSame(['_areas' => ['The area "hero" takes at most 1 blocks.']], $this->errors('page', ['_layout' => 'promo', '_areas' => ['hero' => [$banner('b1'), $banner('b3'), $banner('b4')], 'main' => []]]), 'swapped and grown past the stored count');
    }

    public function test_a_below_min_area_may_grow_but_not_shrink(): void
    {
        $quote = static fn (string $uid): array => ['_uid' => $uid, '_block' => 'quote'];
        // The pinned home page's "top" takes at least 1; stored empty before the limit.
        $this->store('page', ['uuid' => 'p1', 'slug' => ['en' => 'home'], '_layout' => 'home', '_areas' => ['top' => []]]);

        $this->assertSame([], $this->errors('page', ['slug' => ['en' => 'home'], '_areas' => ['top' => []]]), 'unchanged');
        $this->assertSame(['_areas' => ['The area "top" takes at least 1 blocks.']], $this->errors('page', ['uuid' => 'p9', 'slug' => ['en' => 'home'], '_areas' => ['top' => []]]), 'a new record is checked plainly');
        $this->assertSame([], $this->errors('page', ['slug' => ['en' => 'home'], '_areas' => ['top' => [$quote('q1')]]]));
    }

    public function test_a_broken_layouts_option_stops_the_boot(): void
    {
        $this->model('broken', ['allowed' => ['standard'], 'default' => 'promo']);

        $this->expectException(LayoutConfigException::class);
        $this->expectExceptionMessage('Type "broken": "layouts.default" is "promo", which "layouts.allowed" does not list (standard); choose one of them, or add it to "layouts.allowed".');
        $this->app();
    }

    private function data(): DataManager
    {
        return $this->app()->container()->get(DataManager::class);
    }

    private function types(): TypeRegistry
    {
        return $this->app()->container()->get(TypeRegistry::class);
    }

    /** @param array<string, mixed> $values a record stored without validation */
    private function store(string $type, array $values): void
    {
        $this->data()->saveType(DynamicRecord::fromArray($this->types()->get($type), $values), validate: false);
    }

    /** @param array<string, mixed> $values */
    private function save(string $type, array $values): void
    {
        $this->data()->saveType(DynamicRecord::fromArray($this->types()->get($type), ['uuid' => 'p1'] + $values));
    }

    /**
     * The errors of a validated save of these values (record p1 unless they name another uuid).
     *
     * @param array<string, mixed> $values
     * @return array<string, list<string>>
     */
    private function errors(string $type, array $values): array
    {
        try {
            $this->data()->validate(DynamicRecord::fromArray($this->types()->get($type), $values + ['uuid' => 'p1']));
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return [];
    }

    /** @param array<string, mixed>|null $layouts */
    private function model(string $key, ?array $layouts): void
    {
        $schema = ['key' => $key, 'table' => $key, 'storage' => 'json', 'id_field' => 'uuid', 'fields' => [
            'title' => ['type' => 'string'],
            'slug' => ['type' => 'string', 'translatable' => true],
        ]];
        if ($layouts !== null) {
            $schema['layouts'] = $layouts;
        }
        $this->write('config/models/' . $key . '.json', (string) json_encode($schema));
    }
}
