<?php

declare(strict_types=1);

namespace Preflow\Components\Tests\Fixtures\Geo;

use Preflow\Components\Component;

/** Fixture components for ComponentRendererGeoTest; each template sits next to this file. */
class GeoCard extends Component
{
    public string $title = '';

    public function resolveState(): void
    {
        $this->title = $this->props['title'] ?? 'Card';
    }
}

class GeoPlain extends Component {}

class GeoParentWithBlock extends Component {}

class GeoParentWithoutBlock extends Component {}

class GeoEmptyBlock extends Component {}

class GeoBroken extends Component {}
