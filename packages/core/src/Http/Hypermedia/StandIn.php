<?php

declare(strict_types=1);

namespace Preflow\Core\Http\Hypermedia;

/**
 * A client that stands in for another until it is built (Preflow\Htmx\LazyHypermediaDriver):
 * HypermediaProtocols tells clients apart by the class it names, not by the stand-in's own.
 */
interface StandIn
{
    /** @return class-string the class of the client it stands in for */
    public function standsInFor(): string;
}
