<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Seo;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeDefinition;
use Preflow\Folio\Seo\SeoSection;

/** The reserved `_seo` meta: its stored shape, the per-locale merge of a form save, the flags. */
final class SeoSectionTest extends TestCase
{
    private static function record(mixed $seo): DynamicRecord
    {
        $type = new TypeDefinition('page', 'page', 'json', []);

        return DynamicRecord::fromArray($type, ['uuid' => 'p1', '_seo' => $seo]);
    }

    public function test_nothing_stored_means_the_defaults(): void
    {
        $seo = SeoSection::fromRecord(self::record(null));

        $this->assertSame('', $seo->title('en'));
        $this->assertSame('', $seo->description('en'));
        $this->assertNull($seo->image());
        $this->assertFalse($seo->noindex());
        $this->assertTrue($seo->inSitemap());
        $this->assertTrue($seo->inLlms());
        $this->assertTrue($seo->isEmpty());
    }

    public function test_reads_the_stored_shape_as_array_and_as_json_text(): void
    {
        $stored = ['title' => ['en' => 'Share me', 'de' => 'Teil mich'], 'description' => ['en' => 'About'], 'image' => 'a1b2', 'noindex' => true, 'sitemap' => false, 'llms' => false];

        foreach ([$stored, json_encode($stored)] as $value) {
            $seo = SeoSection::fromRecord(self::record($value));
            $this->assertSame('Share me', $seo->title('en'));
            $this->assertSame('Teil mich', $seo->title('de'));
            $this->assertSame('', $seo->title('fr'), 'no fallback: the caller decides');
            $this->assertSame('About', $seo->description('en'));
            $this->assertSame('a1b2', $seo->image());
            $this->assertTrue($seo->noindex());
            $this->assertFalse($seo->inSitemap());
            $this->assertFalse($seo->inLlms());
            $this->assertFalse($seo->isEmpty());
        }
    }

    public function test_any_one_setting_makes_it_not_empty(): void
    {
        foreach ([['title' => ['en' => 'x']], ['description' => ['de' => 'y']], ['image' => 'a1'], ['noindex' => true], ['sitemap' => false], ['llms' => false]] as $stored) {
            $this->assertFalse(SeoSection::fromRecord(self::record($stored))->isEmpty(), (string) json_encode($stored));
        }
        $this->assertTrue(SeoSection::fromRecord(self::record(['title' => ['en' => ''], 'image' => '', 'sitemap' => true]))->isEmpty());
        $this->assertTrue(SeoSection::fromRecord(self::record('not json'))->isEmpty());
    }

    public function test_submission_builds_the_stored_shape(): void
    {
        $value = SeoSection::fromSubmission([
            'title' => '  Share me ',
            'description' => 'About',
            'image' => 'a1b2',
            'noindex' => '1',
            'exclude_sitemap' => '1',
            'exclude_llms' => '1',
        ], [], 'en', true);

        $this->assertSame([
            'title' => ['en' => 'Share me'],
            'description' => ['en' => 'About'],
            'image' => 'a1b2',
            'noindex' => true,
            'sitemap' => false,
            'llms' => false,
        ], $value);
    }

    public function test_unticked_boxes_and_empty_texts_mean_the_defaults(): void
    {
        $value = SeoSection::fromSubmission(['title' => '', 'description' => '   ', 'image' => ''], [], 'en', true);

        $this->assertSame(['title' => [], 'description' => [], 'image' => null, 'noindex' => false, 'sitemap' => true, 'llms' => true], $value);
        $this->assertTrue(SeoSection::fromValue($value)->isEmpty());
    }

    public function test_a_locales_texts_replace_that_locale_only_and_flags_and_image_replace(): void
    {
        $existing = ['title' => ['en' => 'Hello', 'de' => 'Hallo'], 'description' => ['en' => 'About', 'de' => 'Über'], 'image' => 'old', 'noindex' => true, 'sitemap' => false, 'llms' => true];

        $value = SeoSection::fromSubmission(['title' => 'Salut', 'description' => '', 'image' => 'new', 'exclude_llms' => '1'], $existing, 'fr', true);
        $this->assertSame(['en' => 'Hello', 'de' => 'Hallo', 'fr' => 'Salut'], $value['title']);
        $this->assertSame(['en' => 'About', 'de' => 'Über'], $value['description']);
        $this->assertSame('new', $value['image']);
        $this->assertFalse($value['noindex']);
        $this->assertTrue($value['sitemap']);
        $this->assertFalse($value['llms']);

        $cleared = SeoSection::fromSubmission(['title' => '', 'description' => 'Neu'], $existing, 'de', true);
        $this->assertSame(['en' => 'Hello'], $cleared['title'], 'an emptied text is dropped');
        $this->assertSame(['en' => 'About', 'de' => 'Neu'], $cleared['description']);
        $this->assertNull($cleared['image']);
    }

    public function test_a_type_without_translations_keeps_one_text(): void
    {
        $existing = ['title' => ['en' => 'Hello', 'de' => 'Hallo']];

        $value = SeoSection::fromSubmission(['title' => 'Hi'], $existing, 'en', false);

        $this->assertSame(['en' => 'Hi'], $value['title']);
    }

    public function test_non_string_input_is_ignored(): void
    {
        $value = SeoSection::fromSubmission(['title' => ['x'], 'description' => 5, 'image' => ['a']], ['title' => ['en' => ['bad'], 7 => 'x']], 'en', true);

        $this->assertSame([], $value['title']);
        $this->assertSame([], $value['description']);
        $this->assertNull($value['image']);
    }
}
