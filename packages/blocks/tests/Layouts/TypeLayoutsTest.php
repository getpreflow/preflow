<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Layouts;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Blocks\Layouts\LayoutConfigException;
use Preflow\Blocks\Layouts\TypeLayouts;

/** A type's "layouts" option: read from its schema, refused at boot when it cannot work. */
final class TypeLayoutsTest extends TestCase
{
    private const array FIELDS = ['title' => ['type' => 'string'], 'slug' => ['type' => 'string', 'translatable' => true]];

    public function test_type_without_layouts_has_none(): void
    {
        $this->assertNull(TypeLayouts::fromSchema('page', ['key' => 'page', 'fields' => self::FIELDS]));
    }

    public function test_reads_allowed_default_pins_and_slug_field(): void
    {
        $layouts = TypeLayouts::fromSchema('page', ['fields' => self::FIELDS, 'layouts' => [
            'allowed' => ['landing', 'standard'],
            'default' => 'standard',
            'pinned' => ['home' => 'home'],
        ]]);

        $this->assertNotNull($layouts);
        $this->assertSame('page', $layouts->type);
        $this->assertSame(['landing', 'standard'], $layouts->allowed);
        $this->assertSame('standard', $layouts->default);
        $this->assertSame(['home' => 'home'], $layouts->pinned);
        $this->assertSame('slug', $layouts->slugField, 'the slug field defaults to "slug" (translatable is fine)');
        $this->assertSame('home', $layouts->pinFor('home'));
        $this->assertNull($layouts->pinFor('about'));
        $this->assertNull($layouts->pinFor(null));
    }

    public function test_a_slug_field_need_not_exist_without_pins(): void
    {
        $layouts = TypeLayouts::fromSchema('post', ['fields' => ['title' => ['type' => 'string']], 'layouts' => ['allowed' => ['standard'], 'default' => 'standard']]);

        $this->assertSame([], $layouts?->pinned);
        $this->assertSame('slug', $layouts?->slugField);
    }

    public function test_an_own_slug_field(): void
    {
        $layouts = TypeLayouts::fromSchema('page', ['fields' => ['path' => ['type' => 'string']], 'layouts' => ['allowed' => ['standard'], 'default' => 'standard', 'pinned' => ['home' => 'standard'], 'slug_field' => 'path']]);

        $this->assertSame('path', $layouts?->slugField);
    }

    /** @param array<string, mixed> $layouts */
    #[DataProvider('refusals')]
    public function test_refusals_name_the_type_and_what_to_do(mixed $layouts, string $message): void
    {
        $this->expectException(LayoutConfigException::class);
        $this->expectExceptionMessage($message);
        TypeLayouts::fromSchema('page', ['fields' => self::FIELDS, 'layouts' => $layouts]);
    }

    /** @return array<string, array{mixed, string}> */
    public static function refusals(): array
    {
        $allowed = 'Type "page": "layouts.allowed" must be a non-empty list of layout keys, such as ["landing", "standard"].';
        $pinned = 'Type "page": "layouts.pinned" must map page slugs to layout keys, such as {"home": "home"}.';

        return [
            'layouts not an object' => ['standard', 'Type "page": "layouts" must be an object such as {"allowed": ["landing", "standard"], "default": "standard"}.'],
            'no allowed' => [['default' => 'standard'], $allowed],
            'empty allowed' => [['allowed' => [], 'default' => 'standard'], $allowed],
            'allowed not a list' => [['allowed' => ['a' => 'standard'], 'default' => 'standard'], $allowed],
            'allowed holding a number' => [['allowed' => ['standard', 3], 'default' => 'standard'], $allowed],
            'no default' => [['allowed' => ['landing', 'standard']], 'Type "page": "layouts.default" is missing; name the layout a new page starts with, one of "layouts.allowed" (landing, standard).'],
            'default not allowed' => [['allowed' => ['landing', 'standard'], 'default' => 'home'], 'Type "page": "layouts.default" is "home", which "layouts.allowed" does not list (landing, standard); choose one of them, or add it to "layouts.allowed".'],
            'pinned a list' => [['allowed' => ['standard'], 'default' => 'standard', 'pinned' => ['home']], $pinned],
            'pinned to no key' => [['allowed' => ['standard'], 'default' => 'standard', 'pinned' => ['home' => '']], $pinned],
            'pinned not an object' => [['allowed' => ['standard'], 'default' => 'standard', 'pinned' => 'home'], $pinned],
            'slug field not a name' => [['allowed' => ['standard'], 'default' => 'standard', 'slug_field' => ['slug']], 'Type "page": "layouts.slug_field" must be the name of the field that holds the page\'s slug, such as "slug".'],
            'slug field not a field while pinned' => [['allowed' => ['standard'], 'default' => 'standard', 'pinned' => ['home' => 'home'], 'slug_field' => 'path'], 'Type "page": "layouts.pinned" pins pages by the field "path" ("layouts.slug_field"), which the type does not have; add that field, or set "layouts.slug_field" to the field that holds the page\'s slug.'],
        ];
    }
}
