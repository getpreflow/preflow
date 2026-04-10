<?php

declare(strict_types=1);

namespace Preflow\Core\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class MiddlewarePipeline
{
    /** @var MiddlewareInterface[] */
    private array $middleware = [];

    public function pipe(MiddlewareInterface $middleware): self
    {
        $this->middleware[] = $middleware;
        return $this;
    }

    /**
     * @param callable(ServerRequestInterface): ResponseInterface $coreHandler
     */
    public function process(ServerRequestInterface $request, callable $coreHandler): ResponseInterface
    {
        $handler = new class($coreHandler) implements RequestHandlerInterface {
            /** @var callable(ServerRequestInterface): ResponseInterface */
            private $callback;

            public function __construct(callable $callback)
            {
                $this->callback = $callback;
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ($this->callback)($request);
            }
        };

        $stack = $handler;
        foreach (array_reverse($this->middleware) as $mw) {
            $stack = new class($mw, $stack) implements RequestHandlerInterface {
                public function __construct(
                    private readonly MiddlewareInterface $middleware,
                    private readonly RequestHandlerInterface $next,
                ) {}

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return $this->middleware->process($request, $this->next);
                }
            };
        }

        return $stack->handle($request);
    }
}
