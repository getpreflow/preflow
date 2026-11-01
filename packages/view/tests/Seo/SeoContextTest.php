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
}
