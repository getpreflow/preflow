<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Fixtures\Geo;

use Preflow\Components\Component;

/** Fixture components for MarkdownViewTest; each template sits next to this file. */
class GeoMdCard extends Component
{
    public string $title = '';

    public function resolveState(): void
    {
        $this->title = $this->props['title'] ?? 'Card';
    }
}

class GeoMdPlain extends Component {}

/** Counts its renders: LlmsEndpointsTest checks llms-full.txt serves cached pages without rendering them. */
class GeoRenderCount extends Component
{
    public static int $renders = 0;

    public function resolveState(): void
    {
        self::$renders++;
    }
}
