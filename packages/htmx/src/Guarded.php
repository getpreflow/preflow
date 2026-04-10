<?php

declare(strict_types=1);

namespace Preflow\Htmx;

use Psr\Http\Message\ServerRequestInterface;

interface Guarded
{
    /**
     * Authorize the given action. Throw an exception if unauthorized.
     *
     * @throws \Preflow\Core\Exceptions\ForbiddenHttpException
     */
    public function authorize(string $action, ServerRequestInterface $request): void;
}
