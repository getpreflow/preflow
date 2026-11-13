<?php

declare(strict_types=1);

namespace Preflow\View\Tests\Seo;

use PHPUnit\Framework\TestCase;
use Preflow\View\Seo\PageMeta;
use Preflow\View\Seo\SeoContext;

final class SeoContextTest extends TestCase
{
    public function test_merge_creates_and_then_changes_the_meta(): void
    {
        $context = new SeoContext();
        $this->assertNull($context->get());

        $context->merge(['title' => 'A']);
        $context->merge(['description' => 'B']);

        $this->assertSame('A', $context->get()?->title);
        $this->assertSame('B', $context->get()?->description);
    }

    public function test_with_rejects_unknown_keys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PageMeta())->with(['titel' => 'A']);
    }

    public function test_scoped_restores_the_outer_meta(): void
    {
        $context = new SeoContext();
        $context->set(new PageMeta(title: 'Outer'));

        $inner = $context->scoped(static function () use ($context): ?string {
            $before = $context->get();
            $context->merge(['title' => 'Inner']);

            return ($before === null ? 'empty' : 'leaked') . ':' . $context->get()?->title;
        });

        $this->assertSame('empty:Inner', $inner);
        $this->assertSame('Outer', $context->get()?->title);
    }

    public function test_scoped_restores_after_an_exception(): void
    {
        $context = new SeoContext();
        $context->set(new PageMeta(title: 'Outer'));

        try {
            $context->scoped(static function () use ($context): never {
                $context->merge(['title' => 'Inner']);
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame('Outer', $context->get()?->title);
    }

    public function test_merge_template_maps_snake_case_keys_images_and_schema_nodes(): void
    {
        $context = new SeoContext();
        $context->mergeTemplate(['title' => 'A', 'og_type' => 'article', 'alternate_locales' => ['de'], 'image' => '/a.jpg', 'schema' => ['@type' => 'Event']]);
        $context->mergeTemplate(['schema' => [['@type' => 'Place'], 'junk']]);

        $meta = $context->get();
        $this->assertSame('A', $meta?->title);
        $this->assertSame('article', $meta->ogType);
        $this->assertSame(['de'], $meta->alternateLocales);
        $this->assertSame(['url' => '/a.jpg'], $meta->image);
        $this->assertSame([['@type' => 'Event'], ['@type' => 'Place']], $meta->schemas);

        $context->mergeTemplate(['image' => '']);
        $this->assertNull($context->get()?->image);
    }

    public function test_merge_template_takes_modified_as_a_date_time_or_a_real_date(): void
    {
        $context = new SeoContext();
        $context->mergeTemplate(['modified' => new \DateTimeImmutable('2026-11-05 10:00:00', new \DateTimeZone('+01:00'))]);
        $this->assertSame('2026-11-05T10:00:00+01:00', $context->get()->modified);

        // Anything that isn't a real date leaves it unknown, never an error page.
        foreach ([null, 'yesterday', '2026-02-30', 42] as $value) {
            $context->mergeTemplate(['modified' => $value]);
            $this->assertSame('', $context->get()->modified, var_export($value, true));
        }
        $context->mergeTemplate(['modified' => '2026-11-05']);
        $this->assertStringStartsWith('2026-11-05T00:00:00', $context->get()->modified);
    }

    public function test_merge_template_ignores_unknown_keys_and_reports_them(): void
    {
        $reported = [];
        $context = new SeoContext(static function (array $keys) use (&$reported): void {
            $reported[] = $keys;
        });
        $context->mergeTemplate(['titel' => 'B', 'description' => 'D', 'og_typ' => 'article']);

        // A typo in a template is no 500: the known keys apply, the others are reported (debug mode logs them).
        $this->assertSame('D', $context->get()?->description);
        $this->assertSame('', $context->get()->title);
        $this->assertSame([['titel', 'og_typ']], $reported);

        $silent = new SeoContext();
        $silent->mergeTemplate(['titel' => 'B']);
        $this->assertSame('', $silent->get()?->title);
    }

    public function test_description_is_the_pages_own_else_the_sites_as_plain_text(): void
    {
        $context = new SeoContext();
        $context->resolveDefaultsWith(static fn (): PageMeta => new PageMeta(description: "<b>Site</b>  default\n" . str_repeat('x', 300)));

        $this->assertSame('Site default ' . str_repeat('x', 300), $context->description());

        $context->merge(['description' => '<p>Own   text</p>']);
        $this->assertSame('Own text', $context->description());
        $this->assertSame('<p>Own   text</p>', $context->mergedDescription());
    }

    public function test_description_is_empty_without_page_or_site_description(): void
    {
        $context = new SeoContext();
        $this->assertSame('', $context->description());

        $context->resolveDefaultsWith(static fn (): ?PageMeta => null);
        $this->assertSame('', $context->description());
    }
}
