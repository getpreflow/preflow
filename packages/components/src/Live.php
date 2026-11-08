<?php

declare(strict_types=1);

namespace Preflow\Components;

/**
 * Marks a component as a live part of cached pages: it differs per visitor (a guest's album
 * count, "in your album", a cart count). While a page renders for the page cache it is not
 * rendered: the page keeps a signed placeholder of its class and props, and the component is
 * rendered for every visitor when the page is served, with the visitor's request in
 * Preflow\Core\Http\RequestContext. Without the cache it renders in place as any component.
 *
 *     #[Live]
 *     final class AlbumCount extends Component { ... }
 *
 * Its props must be scalars and arrays (they are stored in the page) and the same for every
 * visitor: what identifies the part (an object number), never the personal value itself, which
 * the component reads when it renders. What it reads then is not a dependency of the page.
 * Its CSS and JS belong in the page (an asset added while filling is not printed). Many
 * instances on one page are prefetched together when the class implements PrefetchesLive.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Live
{
}
