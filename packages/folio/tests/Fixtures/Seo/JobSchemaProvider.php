<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Seo;

use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\View\Seo\PageMeta;
use Preflow\View\Seo\SchemaRegistry;

/** A site's own schema for its "job" records, as a site would register one. */
final class JobSchemaProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        $container->get(SchemaRegistry::class)->forType('job', static fn (array $record, string $locale, PageMeta $meta): array => [
            '@type' => 'JobPosting',
            'title' => $record['title'] ?? '',
            'description' => $meta->description,
            'url' => $meta->url,
            'employmentType' => $record['employment'] ?? 'PART_TIME',
        ]);
    }
}
