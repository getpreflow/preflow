<?php

declare(strict_types=1);

namespace Preflow\Media\Tests\Usage;

use PHPUnit\Framework\TestCase;
use Preflow\Media\Usage\AssetReferenceSource;
use Preflow\Media\Usage\AssetUsage;
use Preflow\Media\Usage\AssetUsageEntry;
use Preflow\Media\Usage\LazyReferenceSource;

final class AssetUsageTest extends TestCase
{
    /** @param array<string, list<AssetUsageEntry>> $byAsset */
    public static function source(string $name, array $byAsset, bool $everything = false): AssetReferenceSource
    {
        return new class($name, $byAsset, $everything) implements AssetReferenceSource {
            /** @param array<string, list<AssetUsageEntry>> $byAsset ('*' answers for every id when $everything) */
            public function __construct(private readonly string $name, private readonly array $byAsset, private readonly bool $everything) {}

            public function name(): string
            {
                return $this->name;
            }

            public function usagesOf(string $assetId): array
            {
                return $this->byAsset[$assetId] ?? ($this->everything ? ($this->byAsset['*'] ?? []) : []);
            }
        };
    }

    public function test_sources_report_usages_in_registration_order(): void
    {
        $a = new AssetUsageEntry('Home page', '/admin/page/1', 'pages');
        $b = new AssetUsageEntry('Footer', null, 'pages', 'published version');
        $c = new AssetUsageEntry('Flow 7', '/flows/7', 'flows');
        $usage = new AssetUsage();
        $usage->register(self::source('pages', ['x' => [$a, $b]]));
        $usage->register(self::source('flows', ['x' => [$c], 'y' => [$c]]));

        $this->assertTrue($usage->isUsed('x'));
        $this->assertSame([$a, $b, $c], $usage->of('x'));
        $this->assertSame([$c], $usage->of('y'));
        $this->assertFalse($usage->isUsed('z'));
        $this->assertSame([], $usage->of('z'));
    }

    public function test_no_sources_means_not_used(): void
    {
        $usage = new AssetUsage();

        $this->assertFalse($usage->isUsed('x'));
        $this->assertSame([], $usage->of('x'));
    }

    public function test_sources_registered_last_are_asked_after_the_others(): void
    {
        $poster = new AssetUsageEntry('clip.mp4', null, 'media', 'as poster');
        $page = new AssetUsageEntry('Home page', '/admin/page/1', 'pages');
        $flow = new AssetUsageEntry('Flow 7', '/flows/7', 'flows');
        $usage = new AssetUsage();
        $usage->register(self::source('media', ['x' => [$poster]]), last: true);
        $usage->register(self::source('pages', ['x' => [$page]]));
        $usage->register(self::source('flows', ['x' => [$flow]]));

        $this->assertSame([$page, $flow, $poster], $usage->of('x'));
        $this->assertSame(['pages', 'flows', 'media'], $usage->sourceNames());
    }

    public function test_lazy_source_resolves_its_source_on_first_use(): void
    {
        $entry = new AssetUsageEntry('Home page', '/admin/page/1', 'pages');
        $calls = 0;
        $lazy = new LazyReferenceSource('pages', static function () use (&$calls, $entry): AssetReferenceSource {
            $calls++;

            return self::source('pages', ['x' => [$entry]]);
        });

        $this->assertSame('pages', $lazy->name());
        $this->assertSame(0, $calls);
        $this->assertSame([$entry], $lazy->usagesOf('x'));
        $this->assertSame([], $lazy->usagesOf('y'));
        $this->assertSame(2, $calls); // asked each time: a re-bound source is picked up
    }

    public function test_entry_values(): void
    {
        $entry = new AssetUsageEntry('About', '/folio/page/p1/edit', 'folio', 'published version');

        $this->assertSame('About', $entry->label);
        $this->assertSame('/folio/page/p1/edit', $entry->url);
        $this->assertSame('folio', $entry->source);
        $this->assertSame('published version', $entry->note);
        $this->assertNull((new AssetUsageEntry('x', null, 's'))->note);
    }
}
