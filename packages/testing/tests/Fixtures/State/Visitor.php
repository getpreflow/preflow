<?php

declare(strict_types=1);

namespace Preflow\Testing\Tests\Fixtures\State;

use Preflow\Components\Component;
use Preflow\Htmx\Guarded;
use Psr\Http\Message\ServerRequestInterface;

/** A component without state whose action greets the visitor named by the "visitor" cookie. */
final class Visitor extends Component implements Guarded
{
    public string $line = '';

    public function actions(): array
    {
        return ['hello'];
    }

    public function authorize(string $action, ServerRequestInterface $request): void
    {
        $this->line = 'Visitor ' . ($request->getCookieParams()['visitor'] ?? 'none')
            . ' / header ' . $request->getHeaderLine('Cookie');
    }

    public function actionHello(): void
    {
    }

    public function getTemplatePath(string $extension = 'twig'): string
    {
        return __DIR__ . '/Visitor.' . $extension;
    }
}
