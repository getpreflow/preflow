<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Layouts;

use PHPUnit\Framework\TestCase;
use Preflow\Blocks\BlockRegistry;
use Preflow\Blocks\Layouts\LayoutConfigException;
use Preflow\Blocks\Layouts\LayoutRegistry;
use Preflow\Blocks\Layouts\PageLayouts;
use Preflow\Blocks\Layouts\ResolvedLayout;
use Preflow\Blocks\Tests\Fixtures\Layouts\Home;
use Preflow\Blocks\Tests\Fixtures\Layouts\PackageHome;
use Preflow\Blocks\Tests\Fixtures\Layouts\Promo;
use Preflow\Blocks\Tests\Fixtures\Layouts\Standard;
use Preflow\Blocks\Tests\Fixtures\ShowWhen\Offer;
use Preflow\Blocks\Tests\Fixtures\ShowWhen\OfferLine;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Locales;
use Preflow\Data\TypeRegistry;

/** A page's layout, resolved from its type's "layouts", its stored "_layout" and its slug. */
final class PageLayoutsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/page_layouts_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        $this->model('page', [
            // "gone": allowed, but no class declares it; "missing": a pin to a layout without a class.
            'allowed' => ['promo', 'standard', 'gone'],
            'default' => 'standard',
            'pinned' => ['home' => 'home', 'legacy' => 'missing'],
        ], ['slug' => ['type' => 'string', 'translatable' => true]]);
        $this->model('post', ['allowed' => ['gone', 'promo'], 'default' => 'gone']);
        $this->model('note', ['allowed' => ['gone'], 'default' => 'gone']);
        $this->model('plain', null);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    public function test_type_layouts_and_the_selectable_ones(): void
    {
        $layouts = $this->layouts();

        $this->assertSame(['promo', 'standard', 'gone'], $layouts->forType('page')?->allowed);
        $this->assertNull($layouts->forType('plain'), 'a type without "layouts"');
        $this->assertNull($layouts->forType('unknown'), 'a type the registry does not have');
        $this->assertSame(['promo', 'standard'], array_map(static fn ($l): string => $l->key, $layouts->selectable('page')), 'allowed with a class, in allowed order');
        $this->assertSame([], $layouts->selectable('plain'));
        $this->assertSame(['_layout', '_areas'], PageLayouts::COLUMNS);
    }

    public function test_resolution_order(): void
    {
        $layouts = $this->layouts();
        $resolve = fn (string $type, array $values): ?ResolvedLayout => $layouts->resolve(DynamicRecord::fromArray($this->types()->get($type), ['uuid' => 'r1'] + $values));
        $summary = static fn (?ResolvedLayout $r): array => [$r?->layout?->key, $r?->storedKey, $r?->pinned, $r?->fallback];

        $this->assertSame(['home', 'promo', true, false], $summary($resolve('page', ['slug' => 'home', '_layout' => 'promo'])), 'a pin wins over the stored key');
        $this->assertSame(['promo', 'promo', true, true], $summary($resolve('page', ['slug' => 'legacy', '_layout' => 'promo'])), 'a pin without a class: the stored key, as a fallback');
        $this->assertSame(['promo', 'promo', false, false], $summary($resolve('page', ['slug' => 'about', '_layout' => 'promo'])), 'the stored key');
        $this->assertSame(['standard', 'gone', false, true], $summary($resolve('page', ['_layout' => 'gone'])), 'a stored key without a class: the default');
        $this->assertSame(['standard', 'package_home', false, true], $summary($resolve('page', ['_layout' => 'package_home'])), 'a stored key the type does not allow: the default');
        $this->assertSame(['standard', null, false, false], $summary($resolve('page', [])), 'a new record without "_layout" is the default, no fallback');
        $this->assertSame(['standard', null, false, false], $summary($resolve('page', ['_layout' => ''])));
        $this->assertSame(['promo', null, false, false], $summary($resolve('post', [])), 'a default without a class: the first allowed layout that exists');
        $this->assertSame(['promo', 'gone', false, true], $summary($resolve('post', ['_layout' => 'gone'])));
        $this->assertSame([null, null, false, false], $summary($resolve('note', [])), 'no allowed layout exists: none');
        $this->assertNull($resolve('plain', ['_layout' => 'promo']), 'a type without "layouts" has no layout');

        $pinned = $resolve('page', ['slug' => 'home']);
        $this->assertSame('home', $pinned?->pinnedSlug);
        $this->assertSame('layouts/home.twig', $pinned?->template());
        $this->assertNull($resolve('page', ['slug' => 'about'])?->pinnedSlug);
        $none = $resolve('note', ['_areas' => ['main' => [['_uid' => 'a1', '_block' => 'offer']]]]);
        $this->assertNull($none?->template());
        $this->assertSame([], $none?->placement->placed());
        $this->assertSame(['main'], array_keys($none?->placement->unplaced() ?? []), 'without a layout every area is unplaced');
        $this->assertSame(['layout' => null, 'areas' => []], $none?->context());
    }

    public function test_pin_uses_the_default_locale_slug(): void
    {
        $layouts = $this->layouts();
        $def = $this->types()->get('page');

        $this->assertSame('home', $layouts->resolveValues($def, ['slug' => ['en' => 'home', 'de' => 'start']])?->layout?->key);
        $this->assertSame('standard', $layouts->resolveValues($def, ['slug' => ['en' => 'about', 'de' => 'home']])?->layout?->key, 'another language\'s slug does not pin');
        $this->assertSame('home', $layouts->resolveValues($def, ['slug' => '{"en":"home","de":"start"}'])?->layout?->key, 'stored as JSON text');
    }

    public function test_context_holds_only_placed_areas_with_hidden_fields_null(): void
    {
        $layouts = $this->layouts(new BlockRegistry([Offer::class, OfferLine::class]));
        $offer = ['_uid' => 'a1', '_block' => 'offer', 'kind' => 'text', 'button_url' => 'https://example.org', 'lines' => []];
        $resolved = $layouts->resolveValues($this->types()->get('page'), [
            '_layout' => 'standard',
            '_areas' => json_encode(['hero' => [['_uid' => 'h1', '_block' => 'offer']], 'main' => [$offer]]),
        ]);

        $context = $resolved?->context();
        $this->assertSame(['key' => 'standard', 'label' => 'Standard', 'pinned' => false], $context['layout'] ?? null);
        $this->assertSame(['main'], array_keys($context['areas'] ?? []), 'only the areas the layout shows');
        $this->assertNull($context['areas']['main'][0]['button_url'], 'a field its show_when hides reads null');
        $this->assertSame('text', $context['areas']['main'][0]['kind']);
        $this->assertSame(['hero'], array_keys($resolved?->placement->unplaced() ?? []), 'the hero is kept, unplaced');

        $plain = $this->layouts()->resolveValues($this->types()->get('page'), ['_layout' => 'standard', '_areas' => ['main' => [$offer]]]);
        $this->assertSame('https://example.org', $plain?->context()['areas']['main'][0]['button_url'], 'without a block registry the blocks are as stored');
        $this->assertSame(['main' => []], $this->layouts()->resolveValues($this->types()->get('page'), [])?->context()['areas'], 'an area with nothing stored is an empty list');
    }

    public function test_assert_types_refuses_a_broken_type(): void
    {
        $this->layouts()->assertTypes();
        $this->model('broken', ['allowed' => ['standard']]);

        $this->expectException(LayoutConfigException::class);
        $this->expectExceptionMessage('Type "broken": "layouts.default" is missing');
        $this->layouts()->assertTypes();
    }

    private function layouts(?BlockRegistry $blocks = null): PageLayouts
    {
        return new PageLayouts(new LayoutRegistry([Standard::class, Promo::class, Home::class, PackageHome::class]), $this->types(), $blocks);
    }

    private function types(): TypeRegistry
    {
        return new TypeRegistry($this->dir, new Locales(['en', 'de'], 'en'));
    }

    /**
     * @param array<string, mixed>|null $layouts
     * @param array<string, array<string, mixed>> $fields
     */
    private function model(string $key, ?array $layouts, array $fields = []): void
    {
        $schema = ['key' => $key, 'table' => $key, 'storage' => 'json', 'id_field' => 'uuid', 'fields' => ['title' => ['type' => 'string']] + $fields];
        if ($layouts !== null) {
            $schema['layouts'] = $layouts;
        }
        file_put_contents($this->dir . '/' . $key . '.json', json_encode($schema));
    }
}
