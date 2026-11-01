<?php

declare(strict_types=1);

namespace Preflow\View\Tests\Seo;

use PHPUnit\Framework\TestCase;
use Preflow\View\Seo\PageMeta;
use Preflow\View\Seo\SchemaRegistry;

final class SchemaRegistryTest extends TestCase
{
    public function test_type_without_a_builder_has_no_nodes(): void
    {
        $this->assertSame([], (new SchemaRegistry())->nodesFor('post', [], 'en', new PageMeta()));
    }

    public function test_builder_returning_one_node(): void
    {
        $registry = new SchemaRegistry();
        $registry->forType('event', static fn (array $record, string $locale, PageMeta $meta): array => [
            '@type' => 'Event',
            'name' => $record['title'] . ' ' . $locale,
            'url' => $meta->url,
        ]);

        $nodes = $registry->nodesFor('event', ['title' => 'Game night'], 'de', new PageMeta(url: 'https://example.com/e'));

        $this->assertSame([['@type' => 'Event', 'name' => 'Game night de', 'url' => 'https://example.com/e']], $nodes);
    }

    public function test_builder_returning_a_list_of_nodes(): void
    {
        $registry = new SchemaRegistry();
        $registry->forType('event', static fn (): array => [['@type' => 'Event'], ['@type' => 'Place']]);

        $this->assertSame([['@type' => 'Event'], ['@type' => 'Place']], $registry->nodesFor('event', [], 'en', new PageMeta()));
    }

    public function test_builder_returning_nothing(): void
    {
        $registry = new SchemaRegistry();
        $registry->forType('event', static fn (): array => []);

        $this->assertSame([], $registry->nodesFor('event', [], 'en', new PageMeta()));
    }

    public function test_owner_builder_wins_over_config(): void
    {
        $config = ['type' => 'Organization', 'name' => 'From config', 'url' => null, 'logo' => null, 'same_as' => []];
        $registry = new SchemaRegistry();

        $this->assertSame($config, $registry->ownerFor('en', $config));

        $registry->owner(static fn (string $locale): array => ['type' => 'Person', 'name' => 'Built ' . $locale]);

        $this->assertSame(['type' => 'Person', 'name' => 'Built fr'], $registry->ownerFor('fr', $config));
    }
}
