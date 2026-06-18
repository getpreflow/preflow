<?php

declare(strict_types=1);

namespace App\Folio\Overrides\Content;

use Nyholm\Psr7\Response;
use Preflow\Folio\Override\OverridableAction;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class Index implements OverridableAction
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(200, [], 'OVERRIDDEN');
    }
}
