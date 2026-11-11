<?php

declare(strict_types=1);

namespace Preflow\Htmx\Tests;

use Preflow\Htmx\HtmxDriver;
use Preflow\Htmx\HypermediaDriver;
use Preflow\Htmx\ResponseHeaders;
use Preflow\Testing\Contract\HypermediaDriverContract;
use Psr\Http\Message\ServerRequestInterface;

/** The hypermedia driver contract on htmx. */
final class HtmxContractTest extends HypermediaDriverContract
{
    private ResponseHeaders $headers;

    protected function createDriver(): HypermediaDriver
    {
        return new HtmxDriver($this->headers = new ResponseHeaders());
    }

    protected function fragmentRequest(ServerRequestInterface $request): ServerRequestInterface
    {
        return $request->withHeader('HX-Request', 'true');
    }

    protected function effects(): array
    {
        $effects = [];
        foreach ($this->headers->all() as $name => $value) {
            $effects[] = "{$name}: {$value}";
        }

        return $effects;
    }
}
