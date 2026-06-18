<?php

declare(strict_types=1);

namespace Preflow\Folio\Override;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

interface OverridableAction
{
    public function handle(ServerRequestInterface $request): ResponseInterface;
}
