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
    protected function createDriver(): HypermediaDriver
    {
        return new HtmxDriver(new ResponseHeaders());
    }

    protected function fragmentRequest(ServerRequestInterface $request): ServerRequestInterface
    {
        return $request->withHeader('HX-Request', 'true');
    }

    protected function targetRequest(ServerRequestInterface $request, string $targetId): ServerRequestInterface
    {
        return $request->withHeader('HX-Target', $targetId);
    }

    protected function historyRestoreRequest(ServerRequestInterface $request): ?ServerRequestInterface
    {
        return $request->withHeader('HX-Request', 'true')->withHeader('HX-History-Restore-Request', 'true');
    }
}
